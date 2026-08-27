<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastParameterType;
use App\Enums\BroadcastPlanType;
use App\Enums\BroadcastType;
use App\Enums\HttpMethod;
use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Enums\ScheduleDataUsageType;
use App\Filament\Resources\EnvironmentResource;
use App\Traits\PSOInteractionsTrait;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Override;

class EnvironmentTools extends Page
{
    use Forms\Concerns\InteractsWithForms, InteractsWithRecord, PSOInteractionsTrait;

    protected static string $resource = EnvironmentResource::class;

    protected string $view = 'filament.resources.environment-resource.pages.envtools';

    protected static ?string $breadcrumb = 'Tools';

    public ?array $data = [];

    public mixed $response = null;

    public ?array $systemUsageGroups = null;

    protected static ?string $title = 'Tools';

    #[Override]
    protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url('/environments/'.$this->record->getRouteKey().'/edit'),

        ];
    }

    protected function getForms(): array
    {
        return ['psoload', 'form'];
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->setDefaults();
        $this->psoload->fill($this->record->toArray());
    }

    private function setDefaults(): void
    {
        $this->record->dse_duration = 3;
        $this->record->input_mode = InputMode::LOAD;
        $this->record->appointment_window = 7;
        $this->record->process_type = ProcessType::APPOINTMENT;
        $this->record->datetime = Carbon::now();
        $this->record->commit_url = 'https://'.config('psott.pso-services-api').'/api/commit/'.$this->record->id;
    }

    private function rotaIdForDataset(?string $datasetName): ?string
    {
        if (blank($datasetName)) {
            return null;
        }

        return $this->record->datasets()->where('name', $datasetName)->value('rota');
    }

    public function psoload(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Mode and Dataset')
                    ->columns()
                    ->schema([
                        Select::make('dataset_id')
                            ->label('Dataset')
                            ->required()
                            ->native(false)
                            ->live()
                            ->placeholder('Select Dataset')
                            ->options($this->record->datasets()->get()->pluck('name', 'name')->toArray())
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                if ($get('include_arp_data')) {
                                    $set('rota_id', $this->rotaIdForDataset($get('dataset_id')));
                                }
                            }),
                        Select::make('input_mode')
                            ->dehydrated(false)
                            ->label('Input Mode')
                            ->native(false)
                            ->required()
                            ->live()
                            ->enum(InputMode::class)
                            ->options(InputMode::class)
                            ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),

                    ]),
                Section::make('Environment Properties')
                    ->description('These properties are used by these tools when sending to PSO or fetching System Usage. Please click Return to environment above to update properties.')
                    ->icon(Heroicon::OutlinedCircleStack)
                    ->collapsible()
                    ->collapsed()
                    ->columns()
                    ->schema([
                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->prefixIcon(Heroicon::OutlinedGlobeAlt),
                        TextInput::make('account_id')
                            ->label('Account ID')
                            ->prefixIcon(Heroicon::OutlinedIdentification),
                        TextInput::make('username')
                            ->label('Username')
                            ->prefixIcon(Heroicon::OutlinedUser),
                        TextInput::make('password')
                            ->label('Password')
                            ->prefixIcon(Heroicon::OutlinedLockClosed)
                            ->password(),
                    ]),
                Tabs::make('activity_tabs')->tabs([
                    Tab::make('load_rota_tab')
//                Section::make('PSO Input Reference Settings')
                        ->schema([
                            Toggle::make('send_to_pso')
                                ->dehydrated(false)
                                ->label('Send to PSO')
                                ->live(),
                            Toggle::make('keep_pso_data')
                                ->dehydrated(false)
                                ->label('Keep PSO Data')
                                ->requiredIf('send_to_pso', true)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->disabled(static function (Get $get) {
                                    return ! $get('send_to_pso');
                                }),
                            TextInput::make('dse_duration')
                                ->dehydrated(false)
                                ->label('DSE Duration')
                                ->integer()
                                ->minValue(3)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->placeholder(3)
                                ->prefixIcon(Heroicon::OutlinedCubeTransparent),
                            TextInput::make('appointment_window')
                                ->dehydrated(false)
                                ->label('Appointment Window')
                                ->integer()
                                ->minValue(7)
                                ->placeholder(7)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->prefixIcon(Heroicon::OutlinedCalendarDateRange),
                            Select::make('process_type')
                                ->enum(ProcessType::class)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->options(ProcessType::class)
                                ->live()
                                ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                                ->prefixIcon(Heroicon::OutlinedAdjustmentsHorizontal),
                            DateTimePicker::make('datetime')
                                ->dehydrated(false)
                                ->label('Input Date Time')
                                ->prefixIcon(Heroicon::OutlinedClock),
                            Section::make('Advanced Options')
                                ->description('Additional PSO Input Reference options.')
                                ->icon(Heroicon::OutlinedAdjustmentsVertical)
                                ->collapsible()
                                ->collapsed()
                                ->columnSpan(2)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->columns()
                                ->schema([
                                    Select::make('pso_api_version')
                                        ->dehydrated(false)
                                        ->label('PSO API Version')
                                        ->native(false)
                                        ->prefixIcon(Heroicon::OutlinedCodeBracket)
                                        ->options([
                                            1 => 'v1 (Legacy)',
                                            2 => 'v2 (6.15+)',
                                        ]),
                                    Toggle::make('include_arp_data')
                                        ->dehydrated(false)
                                        ->label('Include ARP Data')
                                        ->inline(false)
                                        ->live()
                                        ->afterStateUpdated(function (Get $get, Set $set, ?bool $state) {
                                            if ($state) {
                                                $set('rota_id', $this->rotaIdForDataset($get('dataset_id')));
                                            }
                                        }),
                                    TextInput::make('rota_id')
                                        ->dehydrated(false)
                                        ->label('Rota ID')
                                        ->prefixIcon(Heroicon::OutlinedTag)
                                        ->requiredIf('include_arp_data', true)
                                        ->visible(fn (Get $get) => (bool) $get('include_arp_data')),
                                ]),
                            Section::make('Broadcasts')
                                ->description('Attach Broadcast entities to communicate plans/changes to external systems (email, file, REST, web service, FTP, WCF).')
                                ->icon(Heroicon::OutlinedMegaphone)
                                ->collapsible()
                                ->collapsed()
                                ->columnSpan(2)
                                ->visible(fn (Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->schema([
                                    Repeater::make('broadcasts')
                                        ->dehydrated(false)
                                        ->hiddenLabel()
                                        ->addActionLabel('Add Broadcast')
                                        ->collapsible()
                                        ->collapsed()
                                        ->itemLabel(static function (array $state): ?string {
                                            $type = $state['broadcast_type_id'] ?? null;

                                            return match (true) {
                                                $type instanceof BroadcastType => $type->getLabel(),
                                                filled($type) => (string) $type,
                                                default => 'New Broadcast',
                                            };
                                        })
                                        ->schema([
                                            Toggle::make('active')
                                                ->default(true)
                                                ->helperText('Whether the broadcast is active.'),
                                            Select::make('broadcast_type_id')
                                                ->label('Broadcast Type')
                                                ->native(false)
                                                ->required()
                                                ->live()
                                                ->default(BroadcastType::REST)
                                                ->enum(BroadcastType::class)
                                                ->options(BroadcastType::class)
                                                ->helperText('How the plan/change is delivered to the external system, and which parameters below are required.')
                                                ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                            Select::make('plan_type')
                                                ->label('Plan Type')
                                                ->native(false)
                                                ->required()
                                                ->live()
                                                ->enum(BroadcastPlanType::class)
                                                ->options(BroadcastPlanType::class)
                                                ->helperText(static function (Get $get) {
                                                    $planType = $get('plan_type');

                                                    return $planType instanceof BroadcastPlanType ? $planType->description() : null;
                                                })
                                                ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                            CheckboxList::make('allocation_type')
                                                ->label('Allocation Type')
                                                ->options(BroadcastAllocationType::class)
                                                ->columns(2)
                                                ->columnSpanFull()
                                                ->live()
                                                ->afterStateUpdated(static function (Set $set, ?array $state) {
                                                    $set('description', collect($state)
                                                        ->map(static function ($value) {
                                                            $type = $value instanceof BroadcastAllocationType
                                                                ? $value
                                                                : BroadcastAllocationType::from((int) $value);

                                                            return $type->getLabel();
                                                        })
                                                        ->implode(', '));
                                                })
                                                ->helperText('Restricts which scheduling engine\'s plan data this broadcast includes. Select more than one to combine them.'),
                                            Textarea::make('description')
                                                ->maxLength(2000)
                                                ->columnSpanFull(),
                                            Toggle::make('once_only')
                                                ->helperText('If on, the plan is only broadcast once, the first time it\'s required, then discarded. STATIC schedules always broadcast once only.'),
                                            Grid::make(3)
                                                ->columnSpanFull()
                                                ->schema([
                                                    TextInput::make('minimum_plan_quality')
                                                        ->label('Minimum Plan Quality')
                                                        ->numeric()
                                                        ->minValue(0)
                                                        ->maxValue(100)
                                                        ->suffix('%')
                                                        ->helperText('The plan will only be broadcast when the Plan Quality is greater than or equal to this value. Defaults to 100 if left blank.'),
                                                    TextInput::make('minimum_step_interval')
                                                        ->label('Minimum Step Interval')
                                                        ->integer()
                                                        ->helperText('A broadcast will only be sent every \'x\' plans, e.g. 3 sends on every 3rd plan. Defaults to 1 if left blank.'),
                                                    TextInput::make('minimum_visit_status')
                                                        ->label('Minimum Visit Status')
                                                        ->integer()
                                                        ->helperText('Allocation rows with a visit_status below this value are removed from the broadcast.'),
                                                    TextInput::make('maximum_frequency')
                                                        ->label('Maximum Frequency')
                                                        ->integer()
                                                        ->minValue(1)
                                                        ->suffix('minutes')
                                                        ->helperText('Minimum time since the previous broadcast before sending an updated one.'),
                                                    TextInput::make('maximum_wait')
                                                        ->label('Maximum Wait')
                                                        ->integer()
                                                        ->minValue(1)
                                                        ->suffix('minutes')
                                                        ->helperText('The plan is broadcast once the minimum plan quality is met, or once this wait elapses, whichever comes first.'),
                                                ]),
                                            DateTimePicker::make('expiry_datetime')
                                                ->label('Expiry Date Time')
                                                ->helperText('If the schedule time passes this, the broadcast is skipped and no plan is generated.'),
                                            DateTimePicker::make('time_filter_start')
                                                ->label('Time Filter Start')
                                                ->helperText('Activities ending at or before this time are excluded from the broadcast.'),
                                            DateTimePicker::make('time_filter_end')
                                                ->label('Time Filter End')
                                                ->helperText('Activities starting at or after this time are excluded from the broadcast.'),
                                            TextInput::make('to_address')
                                                ->label('To Address')
                                                ->email()
                                                ->helperText('Email address of the broadcast recipient.')
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::EMAIL)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::EMAIL),
                                            TextInput::make('smtp_server')
                                                ->label('SMTP Server')
                                                ->helperText('Full SMTP server name of the recipient.')
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::EMAIL)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::EMAIL),
                                            TextInput::make('file_path')
                                                ->label('File Path')
                                                ->helperText('File path to output the plan. A folder path keeps each broadcast as a separate file instead of overwriting the last one.')
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::FILE)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::FILE),
                                            Select::make('mediatype')
                                                ->label('Media Type')
                                                ->native(false)
                                                ->default('application/json')
                                                ->helperText('The media type for the content of the request and response message.')
                                                ->options([
                                                    'application/json' => 'application/json',
                                                    'text/json' => 'text/json',
                                                    'application/xml' => 'application/xml',
                                                    'text/xml' => 'text/xml',
                                                    'application/octet-stream' => 'application/octet-stream',
                                                ])
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::REST)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::REST),
                                            TextInput::make('url')
                                                ->label('URL')
                                                ->url()
                                                ->helperText('Path to the FTP site, web service, or REST endpoint.')
                                                ->visible(fn (Get $get) => in_array($get('broadcast_type_id'), [BroadcastType::REST, BroadcastType::WEBSERVICE, BroadcastType::FTP], true))
                                                ->required(fn (Get $get) => in_array($get('broadcast_type_id'), [BroadcastType::REST, BroadcastType::WEBSERVICE, BroadcastType::FTP], true)),
                                            TextInput::make('wsid')
                                                ->label('Web Service ID')
                                                ->helperText('The defined id for the webservice data to be sent back to.')
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::WEBSERVICE)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::WEBSERVICE),
                                            TextInput::make('address')
                                                ->label('Address')
                                                ->helperText('Path to the WCF receiving service.')
                                                ->visible(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::WCF)
                                                ->required(fn (Get $get) => $get('broadcast_type_id') === BroadcastType::WCF),
                                            TextInput::make('application_type_id')
                                                ->label('Application Type ID')
                                                ->helperText('The application type this admin broadcast is for.')
                                                ->visible(fn (Get $get) => $get('plan_type') === BroadcastPlanType::ADMIN)
                                                ->required(fn (Get $get) => $get('plan_type') === BroadcastPlanType::ADMIN),
                                            TextInput::make('check_in_expired_time')
                                                ->label('Check-In Expired Time')
                                                ->helperText('Amount of time to have expired since the application last checked in before this broadcast is sent. IFS docs don\'t specify a unit/format for this field.')
                                                ->visible(fn (Get $get) => $get('plan_type') === BroadcastPlanType::ADMIN)
                                                ->required(fn (Get $get) => $get('plan_type') === BroadcastPlanType::ADMIN),
                                        ])
                                        ->columns(2),
                                ]),
                            Actions::make([Action::make('push_it')->slideOver()
                                ->action(function (Get $get) {
                                    //                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                    // the update status thingy
                                    $this->initPSO($get);

                                })
                                ->label(function () {
                                    return $this->data['input_mode'] === InputMode::LOAD->value ? 'Send Initial Load' : 'Update Rota';
                                }),

                            ])->columnSpan(2),
                        ])->columns()
                        ->icon(Heroicon::OutlinedArrowUpOnSquare)
                        ->label('Initial Load and Rota'),

                    Tab::make('system_usage_tab')
                        ->schema([
                            DateTimePicker::make('usage_min_date')
                                ->dehydrated(false)
                                ->label('Min Date Time')
                                ->helperText('Optional. Must be provided together with Max Date Time, or leave both blank.'),
                            DateTimePicker::make('usage_max_date')
                                ->dehydrated(false)
                                ->label('Max Date Time')
                                ->helperText('Optional. Must be provided together with Min Date Time, or leave both blank.'),
                            Actions::make([
                                Action::make('fetch_system_usage')
                                    ->label('Get System Usage')
                                    ->icon(Heroicon::OutlinedArrowPath)
                                    ->action(function (Get $get) {
                                        $this->fetchSystemUsage($get);
                                    }),
                            ])->columnSpanFull(),
                            View::make('filament.resources.environment-resource.pages.partials.system-usage-stats')
                                ->viewData(fn (): array => ['groups' => $this->systemUsageGroups])
                                ->columnSpanFull(),
                        ])
                        ->columns()
                        ->icon(Heroicon::OutlinedCog)
                        ->label('System Usage'),
                    Tab::make('services_tab')
                        ->schema([
                            TextInput::make('commit_url')
                                ->label('Commit Broadcast URL (SDS)')
                                ->hint('Ask for  more details')
                                ->disabled()
                                ->suffixAction(
                                    Action::make('copy')
                                        ->icon(Heroicon::OutlinedClipboard)
                                        ->action(function ($livewire, $state) {
                                            $livewire->dispatch('copy-to-clipboard', text: $state);
                                        })
                                )
                                ->extraAttributes([
                                    'x-data' => "{
            copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        \$tooltip('Copied to clipboard', { timeout: 1500 });
                    }).catch(() => {
                        \$tooltip('Failed to copy', { timeout: 1500 });
                    });
                } else {
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    textArea.style.position = 'fixed';
                    textArea.style.opacity = '0';
                    document.body.appendChild(textArea);
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        \$tooltip('Copied to clipboard', { timeout: 1500 });
                    } catch (err) {
                        \$tooltip('Failed to copy', { timeout: 1500 });
                    }
                    document.body.removeChild(textArea);
                }
            }
        }",
                                    'x-on:copy-to-clipboard.window' => 'copyToClipboard($event.detail.text)',
                                ]),
                        ])
                        ->icon(Heroicon::OutlinedCog)
                        ->label('Services'),

                ]),

            ])->statePath('data');
    }

    /**
     * @throws JsonException
     */
    public function initPSO($data): void
    {
        $this->response = null;

        foreach ($this->getForms() as $form) {
            $this->{$form}->getState();
        }

        $inputMode = $data('input_mode');
        $segment = $inputMode === InputMode::LOAD ? InputMode::LOAD->getSegment() : InputMode::CHANGE->getSegment();
        $method = $inputMode === InputMode::LOAD ? HttpMethod::POST : HttpMethod::PATCH;

        $sendToPso = data_get($this->data, 'send_to_pso');

        $payload = $this->buildPayLoad($data);

        $environmentProperties = [
            'base_url' => $data('base_url'),
            'account_id' => $data('account_id'),
            'username' => $data('username'),
            'password' => $data('password'),
        ];

        if ($tokenized_payload = $this->prepareTokenizedPayload($sendToPso, $payload, $environmentProperties)) {

            $this->response = $this->sendToPSONew($segment, $tokenized_payload, [], $method, true);

            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }

    }

    private function buildPayLoad($data): array
    {
        $schema = [
            'base_url' => $data('base_url'),
            'dse_duration' => $data('dse_duration'),
            'dataset_id' => $data('dataset_id'),
            'description' => $data('input_mode') === InputMode::CHANGE ? 'Update Rota From Tool Box' : 'Load From Tool Box',
            'send_to_pso' => $data('send_to_pso'),
            'keep_pso_data' => $data('keep_pso_data'),
            'account_id' => $data('account_id'),
            'appointment_window' => $data('appointment_window'),
            'process_type' => ProcessType::from($data('process_type')?->value ?? ProcessType::APPOINTMENT->value)->value,
            'datetime' => $data('datetime'),
            'input_mode' => $data('input_mode'),
            'pso_api_version' => $data('pso_api_version'),
            'include_arp_data' => $data('include_arp_data'),
            'rota_id' => $data('rota_id'),
            'broadcasts' => $data('broadcasts'),
        ];

        return $this->initialize_payload($schema);
    }

    public function initialize_payload($data): array
    {
        $payload = [
            'environment' => [
                'baseUrl' => data_get($data, 'base_url'),
                'datetime' => filled(data_get($data, 'datetime'))
                    ? Carbon::parse(data_get($data, 'datetime'))->toAtomString()
                    : Carbon::now()->toAtomString(),
                'description' => data_get($data, 'description'),
                'datasetId' => data_get($data, 'dataset_id'),
                'sendToPso' => data_get($data, 'send_to_pso'),
            ],
        ];

        if (filled(data_get($data, 'pso_api_version'))) {
            $payload = Arr::add($payload, 'environment.psoApiVersion', (int) data_get($data, 'pso_api_version'));
        }

        if (data_get($data, 'input_mode') === InputMode::LOAD) {
            $payload = Arr::add($payload, 'data.dseDuration', data_get($data, 'dse_duration'));
            $payload = Arr::add($payload, 'data.keepPsoData', data_get($data, 'keep_pso_data'));
            $payload = Arr::add($payload, 'data.processType', data_get($data, 'process_type'));
            $payload = Arr::add($payload, 'data.appointmentWindow', data_get($data, 'appointment_window'));

            if (data_get($data, 'include_arp_data')) {
                $payload = Arr::add($payload, 'data.includeArpData', true);
                $payload = Arr::add($payload, 'data.rotaId', data_get($data, 'rota_id'));
            }

            $broadcasts = $this->buildBroadcastsPayload((array) data_get($data, 'broadcasts', []));

            if (filled($broadcasts)) {
                $payload = Arr::add($payload, 'data.broadcasts', $broadcasts);
            }
        }

        if (data_get($data, 'send_to_pso')) {
            $payload = Arr::add($payload, 'environment.accountId', data_get($data, 'account_id'));
        }

        return $payload;
    }

    /**
     * Transforms the raw `broadcasts` repeater state into the `data.broadcasts[]`
     * shape the PSO-Services load endpoint expects, including the `parameters[]`
     * pairs required for the chosen broadcastTypeId/planType.
     */
    public function buildBroadcastsPayload(array $broadcasts): array
    {
        return collect($broadcasts)
            ->values()
            ->map(function (array $broadcast) {
                $type = $broadcast['broadcast_type_id'] ?? null;
                $type = $type instanceof BroadcastType ? $type : BroadcastType::tryFrom((string) $type);

                $planType = $broadcast['plan_type'] ?? null;
                $planType = $planType instanceof BroadcastPlanType ? $planType : BroadcastPlanType::tryFrom((string) $planType);

                $requiredParameterNames = $type?->requiredParameters() ?? [];

                if ($planType === BroadcastPlanType::ADMIN) {
                    $requiredParameterNames = [
                        ...$requiredParameterNames,
                        BroadcastParameterType::APPLICATION_TYPE_ID,
                        BroadcastParameterType::CHECK_IN_EXPIRED_TIME,
                    ];
                }

                $parameters = collect($requiredParameterNames)
                    ->map(static fn (BroadcastParameterType $parameter) => [
                        'name' => $parameter->value,
                        'value' => data_get($broadcast, $parameter->value),
                    ])
                    ->filter(static fn (array $parameter) => filled($parameter['value']))
                    ->values()
                    ->all();

                return array_filter([
                    'active' => $broadcast['active'] ?? true,
                    'broadcastTypeId' => $type?->value,
                    'planType' => $planType?->value,
                    'allocationType' => filled($broadcast['allocation_type'] ?? null)
                        ? array_map(
                            static fn ($value) => $value instanceof BroadcastAllocationType ? $value->value : (int) $value,
                            $broadcast['allocation_type']
                        )
                        : null,
                    'description' => $broadcast['description'] ?? null,
                    'onceOnly' => $broadcast['once_only'] ?? null,
                    'minimumPlanQuality' => $broadcast['minimum_plan_quality'] ?? null,
                    'minimumStepInterval' => $broadcast['minimum_step_interval'] ?? null,
                    'expiryDatetime' => $broadcast['expiry_datetime'] ?? null,
                    'maximumFrequency' => filled($broadcast['maximum_frequency'] ?? null) ? (int) $broadcast['maximum_frequency'] : null,
                    'maximumWait' => filled($broadcast['maximum_wait'] ?? null) ? (int) $broadcast['maximum_wait'] : null,
                    'minimumVisitStatus' => $broadcast['minimum_visit_status'] ?? null,
                    'timeFilterStart' => $broadcast['time_filter_start'] ?? null,
                    'timeFilterEnd' => $broadcast['time_filter_end'] ?? null,
                    'parameters' => $parameters,
                ], static fn ($value) => $value !== null);
            })
            ->all();
    }

    public function fetchSystemUsage($get): void
    {
        $minDate = $get('usage_min_date');
        $maxDate = $get('usage_max_date');

        if (filled($minDate) xor filled($maxDate)) {
            $this->notifyPayloadSent('System Usage Failed', 'Provide both Min Date Time and Max Date Time, or leave both blank.', false);

            return;
        }

        $baseUrl = $get('base_url');
        $accountId = $get('account_id');
        $username = $get('username');
        $password = $get('password');
        $datasetId = $get('dataset_id');

        if (blank($baseUrl) || blank($accountId) || blank($username) || blank($password)) {
            $this->notifyPayloadSent('System Usage Failed', 'Base URL, Account ID, Username and Password are all required (see Environment Properties above).', false);

            return;
        }

        $token = $this->authenticatePSO($baseUrl, $accountId, $username, Crypt::decryptString($password));

        if (! $token) {
            $this->notifyPayloadSent('System Usage Failed', 'Please see the event log', false);
            $this->systemUsageGroups = null;

            return;
        }

        $headers = [
            'environment' => [
                'baseUrl' => $baseUrl,
                'accountId' => $accountId,
                'datasetId' => $datasetId,
                'token' => $token,
            ],
        ];

        $query = array_filter([
            'minDate' => $minDate,
            'maxDate' => $maxDate,
        ], filled(...));

        $responseJson = $this->sendToPSONew('usage', null, $headers, HttpMethod::GET, true, $query ?: null);

        $rows = (array) data_get(json_decode($responseJson, true), 'data.ScheduleDataUsages', []);

        $this->systemUsageGroups = $this->groupUsageRows($rows);
    }

    /**
     * Groups raw ScheduleDataUsages rows by usage type, keeping the most
     * recent value per type plus how many readings fell in the requested range.
     */
    public function groupUsageRows(array $rows): array
    {
        return collect($rows)
            ->groupBy('ScheduleDataUsageType')
            ->map(function ($rowsForType) {
                $sorted = collect($rowsForType)->sortByDesc('DatetimeStamp')->values();
                $latest = $sorted->first();

                return [
                    'type' => ScheduleDataUsageType::tryFrom((int) data_get($latest, 'ScheduleDataUsageType')),
                    'latestValue' => data_get($latest, 'Value'),
                    'latestDatetime' => data_get($latest, 'DatetimeStamp'),
                    'readingCount' => $sorted->count(),
                ];
            })
            ->sortBy(fn (array $group) => $group['type']?->value ?? PHP_INT_MAX)
            ->values()
            ->all();
    }
}
