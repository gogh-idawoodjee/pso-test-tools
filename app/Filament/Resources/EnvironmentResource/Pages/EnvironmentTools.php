<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastPlanType;
use App\Enums\BroadcastType;
use App\Enums\HttpMethod;
use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Enums\PsoGatewayUploadStatus;
use App\Filament\Resources\EnvironmentResource;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\PsoGatewayUpload;
use App\Support\GatewayUploadPath;
use App\Support\ScheduleData\BroadcastSettings;
use App\Traits\EnvironmentToolsPayloadTrait;
use App\Traits\EnvironmentToolsUsageTrait;
use App\Traits\PSOInteractionsTrait;
use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;

class EnvironmentTools extends Page
{
    use EnvironmentToolsPayloadTrait, EnvironmentToolsUsageTrait, Forms\Concerns\InteractsWithForms, InteractsWithRecord, PSOInteractionsTrait;

    protected static string $resource = EnvironmentResource::class;

    protected string $view = 'filament.resources.environment-resource.pages.envtools';

    protected static ?string $breadcrumb = 'Tools';

    public ?array $context_data = [];

    public ?array $load_rota_data = [];

    public ?array $system_usage_data = [];

    public ?array $services_data = [];

    public ?array $gateway_upload_data = [];

    public mixed $response = null;

    public ?array $systemUsageGroups = null;

    /**
     * Locked: without it this is a client-writable Livewire property, and the
     * status panel would happily render any upload row whose id was pushed in
     * from the browser.
     */
    #[Locked]
    public ?string $gatewayUploadId = null;

    protected static ?string $title = 'Tools';

    /**
     * @var array<string>
     */
    private const array GATEWAY_UPLOAD_EXTENSIONS = ['json', 'xml', 'zip'];

    private const int GATEWAY_UPLOAD_MAX_KILOBYTES = 204800; // 200MB

    /**
     * One line of text that XML 1.0 can carry: no control characters (not even
     * tab or newline) and no U+FFFE or U+FFFF. Also fails on invalid UTF-8.
     */
    private const string XML_SAFE_LINE_PATTERN = '/\A[\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u';

    #[Override]
    protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url('/environments/'.$this->record->getRouteKey().'/edit'),

        ];
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // A whitelist of the non-secret values these forms bind: never the
        // whole record, so the password ciphertext, commit_token and the rest
        // of the row stay out of the browser's Livewire state. Actions that
        // log on read the credentials from $this->record instead.
        $recordData = [
            'base_url' => $this->record->base_url,
            'account_id' => $this->record->account_id,
            'username' => $this->record->username,
            'input_mode' => InputMode::LOAD,
            'dse_duration' => 3,
            'appointment_window' => 7,
            'process_type' => ProcessType::APPOINTMENT,
            'datetime' => Carbon::now(),
            'commit_url' => $this->commitUrl(),
        ];

        $this->sharedContextForm->fill(Arr::only($recordData, ['base_url', 'account_id', 'username', 'input_mode']));
        $this->loadRotaForm->fill(Arr::only($recordData, ['dse_duration', 'appointment_window', 'process_type', 'datetime']));
        $this->systemUsageForm->fill();
        $this->servicesForm->fill(Arr::only($recordData, ['commit_url']));
        $this->gatewayUploadForm->fill($this->gatewayUploadDefaults());
    }

    /**
     * The logon details, always from the saved environment and never from
     * form state: the browser never holds the password, and anything it
     * sends back for these fields is ignored.
     *
     * @return array{base_url: ?string, account_id: ?string, username: ?string, password: ?string}
     */
    private function environmentCredentials(): array
    {
        return [
            'base_url' => $this->record->base_url,
            'account_id' => $this->record->account_id,
            'username' => $this->record->username,
            'password' => $this->record->password,
        ];
    }

    /**
     * Built from commit_token (a real, persisted column) on every call:
     * Livewire re-hydrates $record fresh from the database on each request,
     * so nothing set on it at mount survives.
     */
    public function commitUrl(): ?string
    {
        if (blank($this->record->commit_token)) {
            return null;
        }

        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'commit/'.$this->record->commit_token;
    }

    private function rotaIdForDataset(?string $datasetName): ?string
    {
        if (blank($datasetName)) {
            return null;
        }

        return $this->record->datasets()->where('name', $datasetName)->value('rota');
    }

    /**
     * Reads a field's live, cast-aware value from sharedContextForm. Needed
     * by actions/callbacks that live on the other forms but need Mode/Dataset
     * or Environment Properties fields — `Get $get` can only search its own
     * form's root container, not a sibling form, now that each tab is an
     * independent Livewire form rather than one shared schema/statePath.
     */
    private function contextValue(string $path): mixed
    {
        return $this->sharedContextForm->getComponentByStatePath($path)?->getState();
    }

    /**
     * SDS broadcasts commit back through this environment's own commit
     * endpoint, so default the URL to it rather than leaving testers to
     * copy it in manually from the Services tab.
     */
    public function maybeAutofillCommitUrl(Get $get, Set $set): void
    {
        if (filled($get('url'))) {
            return;
        }

        if (! static::broadcastAllocationIncludesSds($get('allocation_type'))) {
            return;
        }

        if (! static::broadcastTypeSupportsUrl($get('broadcast_type_id'))) {
            return;
        }

        if (blank($commitUrl = $this->commitUrl())) {
            return;
        }

        $set('url', $commitUrl);
    }

    private static function broadcastAllocationIncludesSds(?array $allocationType): bool
    {
        return collect($allocationType ?? [])->contains(
            static fn ($value) => ($value instanceof BroadcastAllocationType
                ? $value
                : BroadcastAllocationType::tryFrom((int) $value)) === BroadcastAllocationType::SCHEDULE_DISPATCH_SERVICE
        );
    }

    private static function broadcastTypeSupportsUrl(mixed $broadcastTypeId): bool
    {
        $type = $broadcastTypeId instanceof BroadcastType
            ? $broadcastTypeId
            : BroadcastType::tryFrom((string) $broadcastTypeId);

        return in_array($type, [BroadcastType::REST, BroadcastType::WEBSERVICE, BroadcastType::FTP], true);
    }

    /**
     * Always-visible, above the tabs: Mode/Dataset and Environment Properties
     * fields read by actions on several of the other forms below.
     */
    public function sharedContextForm(Schema $form): Schema
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
                            ->afterStateUpdated(function ($state) {
                                if ($this->loadRotaForm->getComponentByStatePath('include_arp_data')?->getState()) {
                                    $this->loadRotaForm->getComponentByStatePath('rota_id')?->state($this->rotaIdForDataset($state));
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
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedGlobeAlt),
                        TextInput::make('account_id')
                            ->label('Account ID')
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedIdentification),
                        TextInput::make('username')
                            ->label('Username')
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedUser),
                        // Never filled: the password stays on the server.
                        TextInput::make('password')
                            ->label('Password')
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedLockClosed)
                            ->password()
                            ->dehydrated(false)
                            ->placeholder(fn (): string => filled($this->record->password) ? 'Saved with the environment' : 'Not set'),
                    ]),
            ])
            ->statePath('context_data');
    }

    public function loadRotaForm(Schema $form): Schema
    {
        return $form
            ->schema([
                Toggle::make('send_to_pso')
                    ->dehydrated(false)
                    ->label('Send to PSO')
                    ->live(),
                Toggle::make('keep_pso_data')
                    ->dehydrated(false)
                    ->label('Keep PSO Data')
                    ->requiredIf('send_to_pso', true)
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
                    ->disabled(static function (Get $get) {
                        return ! $get('send_to_pso');
                    }),
                TextInput::make('dse_duration')
                    ->dehydrated(false)
                    ->label('DSE Duration')
                    ->integer()
                    ->minValue(3)
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
                    ->placeholder(3)
                    ->prefixIcon(Heroicon::OutlinedCubeTransparent),
                TextInput::make('appointment_window')
                    ->dehydrated(false)
                    ->label('Appointment Window')
                    ->integer()
                    ->minValue(7)
                    ->placeholder(7)
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
                    ->prefixIcon(Heroicon::OutlinedCalendarDateRange),
                Select::make('process_type')
                    ->enum(ProcessType::class)
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
                    ->options(ProcessType::class)
                    ->live()
                    ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsHorizontal),
                DateTimePicker::make('datetime')
                    ->dehydrated(false)
                    ->label('Input Date Time')
                    ->live()
                    ->prefixIcon(Heroicon::OutlinedClock),
                Section::make('Advanced Options')
                    ->description('Additional PSO Input Reference options.')
                    ->icon(Heroicon::OutlinedAdjustmentsVertical)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpan(2)
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
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
                            ->afterStateUpdated(function (Set $set, ?bool $state) {
                                if ($state) {
                                    $set('rota_id', $this->rotaIdForDataset($this->contextValue('dataset_id')));
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
                    ->visible(fn () => $this->contextValue('input_mode') === InputMode::LOAD)
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
                                    ->afterStateUpdated(static function ($livewire, $component, Get $get, Set $set) {
                                        $livewire->validateOnly($component->getStatePath());
                                        $livewire->maybeAutofillCommitUrl($get, $set);
                                    }),
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
                                    ->afterStateUpdated(static function ($livewire, Get $get, Set $set, ?array $state) {
                                        $set('description', collect($state)
                                            ->map(static function ($value) {
                                                $type = $value instanceof BroadcastAllocationType
                                                    ? $value
                                                    : BroadcastAllocationType::from((int) $value);

                                                return $type->getLabel();
                                            })
                                            ->implode(', '));

                                        $livewire->maybeAutofillCommitUrl($get, $set);
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
                                    ->hint(function (Get $get) {
                                        return static::broadcastAllocationIncludesSds($get('allocation_type'))
                                            ? 'For Schedule Dispatch Service: use this environment\'s Commit Broadcast URL (Services tab)'
                                            : null;
                                    })
                                    ->hintIcon(Heroicon::OutlinedInformationCircle)
                                    ->visible(fn (Get $get) => in_array($get('broadcast_type_id'), [BroadcastType::REST, BroadcastType::WEBSERVICE, BroadcastType::FTP], true))
                                    ->required(fn (Get $get) => in_array($get('broadcast_type_id'), [BroadcastType::REST, BroadcastType::WEBSERVICE, BroadcastType::FTP], true))
                                    ->columnSpan(2),
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
                    ->action(function () {
                        $this->initPSO();
                    })
                    ->label(function () {
                        return $this->contextValue('input_mode') === InputMode::LOAD ? 'Send Initial Load' : 'Update Rota';
                    }),

                ])->columnSpan(2),
            ])
            ->columns()
            ->statePath('load_rota_data');
    }

    public function systemUsageForm(Schema $form): Schema
    {
        return $form
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
            ->statePath('system_usage_data');
    }

    public function servicesForm(Schema $form): Schema
    {
        return $form
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
            ->statePath('services_data');
    }

    public function gatewayUploadForm(Schema $form): Schema
    {
        return $form
            ->schema([
                FileUpload::make('gateway_upload_file')
                    ->label('Schedule Data File')
                    ->helperText('Upload a dsScheduleData .json/.xml file, or a .zip containing one.')
                    ->disk('r2')
                    ->directory('gateway-uploads')
                    ->acceptedFileTypes([
                        'application/json',
                        'text/json',
                        'application/xml',
                        'text/xml',
                        'application/zip',
                        'application/x-zip-compressed',
                    ])
                    ->maxSize(self::GATEWAY_UPLOAD_MAX_KILOBYTES)
                    // Client-supplied paths are only ever honoured
                    // if they match the shape this field itself
                    // writes; submitGatewayUpload() applies the
                    // same guard, since a directly-set string (see
                    // its own comment) never goes through this
                    // field's own upload-time validation at all.
                    ->preventFilePathTampering(allowFilePathUsing: static fn (string $file): bool => GatewayUploadPath::isAllowed($file))
                    ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state) {
                        if ($state instanceof TemporaryUploadedFile) {
                            $set('gateway_upload_original_filename', $state->getClientOriginalName());
                        }
                    })
                    // Deliberately not ->required(): Filament's file-upload
                    // validation (required/acceptedFileTypes/maxSize) only
                    // ever runs against a real uploaded file at upload time.
                    // The crafted-path test scenarios in GatewayUploadTabTest
                    // set this field directly to a plain string, bypassing
                    // the upload widget entirely — schema validation would
                    // never see those. submitGatewayUpload() re-checks
                    // required/type/size manually so both paths are covered.
                    ->columnSpanFull(),
                TextInput::make('dataset_id_override')
                    ->label('Dataset ID override')
                    ->helperText('Leave blank to keep the file\'s value. When set, it replaces the dataset ID in every Input_Reference row of a modified copy; your original file is never changed.')
                    ->maxLength(255)
                    ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                    ->columnSpanFull(),
                Toggle::make('skip_webhook')
                    ->label('Skip webhook broadcast')
                    ->helperText('Send the file without the webhook: no webhook endpoint is created and no Broadcast is added.')
                    ->live()
                    ->afterStateUpdated(static function (Set $set, $state): void {
                        // With the webhook added, the file's own rows always go.
                        if (! $state) {
                            $set('strip_existing_broadcast', true);
                        }
                    })
                    ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                    ->columnSpanFull(),
                Toggle::make('strip_existing_broadcast')
                    ->label('Remove existing Broadcast rows')
                    ->default(true)
                    ->helperText(static fn (Get $get): string => $get('skip_webhook')
                        ? 'Turn off to send the file\'s own Broadcast rows unchanged.'
                        : 'Always on when the webhook Broadcast is added, so the file never carries two.')
                    ->live()
                    ->disabled(fn (Get $get): bool => ! $get('skip_webhook') || $this->gatewayUploadInProgress())
                    ->columnSpanFull(),
                Callout::make('Existing Broadcast rows will be kept')
                    ->description(SendPsoScheduleDataJob::KEPT_BROADCAST_WARNING)
                    ->danger()
                    ->visible(static fn (Get $get): bool => (bool) $get('skip_webhook') && ! $get('strip_existing_broadcast'))
                    ->columnSpanFull(),
                Section::make('Broadcast settings')
                    ->description('Any Broadcast and Broadcast_Parameter rows already in the file are removed and replaced by this webhook Broadcast. To send the file with its own rows unchanged, turn on Skip webhook broadcast and turn off Remove existing Broadcast rows.')
                    ->collapsed()
                    ->hidden(static fn (Get $get): bool => (bool) $get('skip_webhook'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('broadcast_id')
                            ->label('Broadcast ID')
                            ->maxLength(255),
                        Select::make('broadcast_plan_type')
                            ->label('Plan Type')
                            ->native(false)
                            ->options(BroadcastPlanType::class),
                        TextInput::make('broadcast_minimum_plan_quality')
                            ->label('Minimum Plan Quality')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        TextInput::make('broadcast_maximum_wait')
                            ->label('Maximum Wait')
                            ->helperText('An ISO 8601 duration, such as PT5M. The longest PSO waits for the minimum plan quality before it broadcasts anyway.'),
                        Select::make('broadcast_allocation_type')
                            ->label('Allocation Type')
                            ->native(false)
                            ->options(BroadcastAllocationType::class),
                        TagsInput::make('broadcast_required_tables')
                            ->label('Required Tables')
                            ->helperText('Sent as the required_tables parameter, joined with commas. Table names only (letters, digits and underscores).')
                            ->columnSpanFull(),
                    ]),
                Hidden::make('gateway_upload_original_filename')
                    ->dehydrated(false),
                Actions::make([
                    Action::make('submit_gateway_upload')
                        ->label('Upload to PSO')
                        ->icon(Heroicon::OutlinedArrowUpOnSquare)
                        ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                        ->action(function (Get $get, Set $set) {
                            $this->submitGatewayUpload($get, $set);
                        }),
                ])->columnSpanFull(),
                View::make('filament.resources.environment-resource.pages.partials.gateway-upload-status')
                    ->viewData(fn (): array => [
                        'uploadId' => $this->currentGatewayUpload()?->id,
                        'history' => $this->gatewayUploadHistory(),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns()
            ->statePath('gateway_upload_data');
    }

    /**
     * @throws JsonException
     */
    public function initPSO(): void
    {
        $this->response = null;

        // Validated for their side effect only — dehydrated(false) fields'
        // actual values are read below via $data(), not this return value.
        $this->sharedContextForm->getState();
        $this->loadRotaForm->getState();

        $sharedContextForm = $this->sharedContextForm;
        $loadRotaForm = $this->loadRotaForm;

        $credentials = $this->environmentCredentials();

        $data = function (string $path) use ($sharedContextForm, $loadRotaForm, $credentials) {
            if (array_key_exists($path, $credentials)) {
                return $credentials[$path];
            }

            $component = $loadRotaForm->getComponentByStatePath($path) ?? $sharedContextForm->getComponentByStatePath($path);

            return $component?->getState();
        };

        $inputMode = $data('input_mode');
        $segment = $inputMode === InputMode::LOAD ? InputMode::LOAD->getSegment() : InputMode::CHANGE->getSegment();
        $method = $inputMode === InputMode::LOAD ? HttpMethod::POST : HttpMethod::PATCH;

        $sendToPso = data_get($this->load_rota_data, 'send_to_pso');

        $payload = $this->buildLoadRotaPayload($data);

        if ($tokenized_payload = $this->prepareTokenizedPayload($sendToPso, $payload, $credentials)) {

            $this->response = $this->sendToPSONew($segment, $tokenized_payload, [], $method, true);

            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }

    }

    public function fetchSystemUsage($get): void
    {
        $minDate = $get('usage_min_date');
        $maxDate = $get('usage_max_date');

        if (filled($minDate) xor filled($maxDate)) {
            $this->notifyPayloadSent('System Usage Failed', 'Provide both Min Date Time and Max Date Time, or leave both blank.', false);

            return;
        }

        [
            'base_url' => $baseUrl,
            'account_id' => $accountId,
            'username' => $username,
            'password' => $password,
        ] = $this->environmentCredentials();
        $datasetId = $this->contextValue('dataset_id');

        if (blank($baseUrl) || blank($accountId) || blank($username) || blank($password)) {
            $this->notifyPayloadSent('System Usage Failed', 'Base URL, Account ID, Username and Password are all required. Return to the environment to set them.', false);

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

        try {
            $responseJson = $this->sendToPSONew('usage', null, $headers, HttpMethod::GET, true, $query ?: null);
        } finally {
            // Best effort: a failed logoff is logged by the trait, never shown.
            $this->logoffPSO($baseUrl, $token);
        }

        $rows = (array) data_get(json_decode($responseJson, true), 'data.ScheduleDataUsages', []);

        $this->systemUsageGroups = $this->groupUsageRows($rows);
    }

    public function submitGatewayUpload(Get $get, Set $set): void
    {
        // `Get::__invoke()` only calls `getState()` on the individual field
        // component it resolves, not the whole schema — this is what lets us
        // avoid re-validating this form's own dataset_id-style required
        // fields (it no longer has any shared with other tabs, but the
        // pattern still applies to any future ones). But that also means a
        // FileUpload's raw state is never routed through the schema-level
        // dehydration pipeline that normally moves the upload from Livewire's
        // temporary disk onto its configured disk/directory. So we trigger
        // that move ourselves, scoped to just this one component, before
        // reading its state.
        $fileUploadComponent = $this->gatewayUploadForm->getComponentByStatePath('gateway_upload_file');

        if ($fileUploadComponent instanceof FileUpload) {
            $fileUploadComponent->saveUploadedFiles();
        }

        $storedPath = $get('gateway_upload_file');
        $originalFilename = $get('gateway_upload_original_filename');

        if (blank($storedPath)) {
            $this->notifyPayloadSent('Upload Failed', 'Please choose a file to upload.', false);

            return;
        }

        // `gateway_upload_data.gateway_upload_file` is a public Livewire
        // property, so this string is whatever the browser sent, and a
        // directly-set value never goes through Filament's own file-upload
        // validation (see the field's comment). It is used as an r2 key to
        // read from and later delete, so it is constrained to the shape this
        // field itself produces before any of that happens.
        if (! GatewayUploadPath::isAllowed($storedPath)) {
            Log::warning('Rejected a gateway upload with an unexpected stored path', [
                'user_id' => auth()->id(),
                'pso_environment_id' => $this->record->id,
                'stored_path' => $storedPath,
            ]);

            $set('gateway_upload_file', null);
            $set('gateway_upload_original_filename', null);

            $this->notifyPayloadSent('Upload Failed', 'That file could not be verified. Please choose the file again.', false);

            return;
        }

        $originalFilename = filled($originalFilename) ? (string) $originalFilename : basename((string) $storedPath);

        // Filament's own `acceptedFileTypes()`/`maxSize()` only validate a
        // real uploaded file at upload time, not a directly-set string state
        // — so the file type and size are re-checked here, server-side.
        if (! in_array(strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)), self::GATEWAY_UPLOAD_EXTENSIONS, true)) {
            $this->notifyPayloadSent('Upload Failed', 'Only .json, .xml and .zip schedule data files can be uploaded.', false);

            return;
        }

        $fileSizeBytes = Storage::disk('r2')->size($storedPath);

        if ($fileSizeBytes > self::GATEWAY_UPLOAD_MAX_KILOBYTES * 1024) {
            $this->notifyPayloadSent('Upload Failed', 'That file is larger than the 200 MB limit for schedule data uploads.', false);

            return;
        }

        [
            'base_url' => $baseUrl,
            'account_id' => $accountId,
            'username' => $username,
            'password' => $password,
        ] = $this->environmentCredentials();

        if (blank($baseUrl) || blank($accountId) || blank($username) || blank($password)) {
            $this->notifyPayloadSent('Upload Failed', 'Base URL, Account ID, Username and Password are all required. Return to the environment to set them.', false);

            return;
        }

        // Decrypted before the row is created: a stored password that cannot
        // be decrypted would otherwise leave behind a permanently queued row
        // with no explanation.
        try {
            $decryptedPassword = Crypt::decryptString($password);
        } catch (DecryptException) {
            $this->notifyPayloadSent('Upload Failed', 'The stored password for this environment could not be read. Re-save the environment password and try again.', false);

            return;
        }

        $datasetIdOverride = filled($get('dataset_id_override')) ? trim((string) $get('dataset_id_override')) : null;
        $skipWebhook = (bool) $get('skip_webhook');
        // Forced on whenever the webhook is added, whatever the client sent.
        $stripExistingBroadcast = ! $skipWebhook || (bool) $get('strip_existing_broadcast');

        if ($datasetIdOverride !== null && mb_strlen($datasetIdOverride) > 255) {
            $this->notifyPayloadSent('Upload Failed', 'The dataset ID override can be at most 255 characters.', false);

            return;
        }

        if ($datasetIdOverride !== null && preg_match(self::XML_SAFE_LINE_PATTERN, $datasetIdOverride) !== 1) {
            $this->notifyPayloadSent('Upload Failed', 'The dataset ID override contains a character that cannot be sent (a control character such as a tab or line break).', false);

            return;
        }

        $broadcastSettings = null;

        if (! $skipWebhook) {
            $broadcastSettings = $this->validatedBroadcastSettings($get);

            if ($broadcastSettings === null) {
                return;
            }
        }

        $upload = PsoGatewayUpload::create([
            'pso_environment_id' => $this->record->id,
            'initiated_by_user_id' => auth()->id(),
            'stored_path' => $storedPath,
            'original_filename' => $originalFilename,
            'file_size_bytes' => $fileSizeBytes,
            'status' => PsoGatewayUploadStatus::QUEUED,
            'queued_at' => now(),
            'dataset_id_override' => $datasetIdOverride,
            'skip_webhook' => $skipWebhook,
            'strip_existing_broadcast' => $stripExistingBroadcast,
            'broadcast_settings' => $broadcastSettings,
        ]);

        SendPsoScheduleDataJob::dispatch(
            $upload->id,
            $baseUrl,
            $accountId,
            $username,
            $decryptedPassword,
        );

        $this->gatewayUploadId = $upload->id;
        $set('gateway_upload_file', null);
        $set('gateway_upload_original_filename', null);
        $set('dataset_id_override', null);

        $this->notifyPayloadSent('Upload Queued', "\"{$upload->original_filename}\" has been queued for upload to PSO.", true);
    }

    /**
     * Whether the tracked upload is still in a non-terminal status — used to
     * disable the file field and submit action so a 100-200MB upload can't
     * be double-submitted while a job is still compressing/uploading it.
     */
    public function gatewayUploadInProgress(): bool
    {
        return $this->currentGatewayUpload() !== null && ! $this->currentGatewayUpload()->status->isTerminal();
    }

    public function currentGatewayUpload(): ?PsoGatewayUpload
    {
        if (blank($this->gatewayUploadId)) {
            return null;
        }

        // Scoped to this environment as well as `#[Locked]`: the panel must
        // never render another environment's upload, whatever the id says.
        return PsoGatewayUpload::query()
            ->where('pso_environment_id', $this->record->id)
            ->whereKey($this->gatewayUploadId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayUploadDefaults(): array
    {
        $settings = BroadcastSettings::defaults();

        return [
            'dataset_id_override' => null,
            'skip_webhook' => false,
            'strip_existing_broadcast' => true,
            'broadcast_id' => $settings->broadcastId,
            'broadcast_plan_type' => $settings->planType,
            'broadcast_minimum_plan_quality' => $settings->minimumPlanQuality,
            'broadcast_maximum_wait' => $settings->maximumWait,
            'broadcast_allocation_type' => $settings->allocationType,
            'broadcast_required_tables' => $settings->requiredTables,
        ];
    }

    /**
     * The status panel (App\Livewire\GatewayUploadStatus) polls itself and
     * tells the page once when the run finishes: the page re-renders then so
     * the form, disabled while the run was in progress, opens again and the
     * recent uploads list shows the final status.
     */
    #[On('gateway-upload-finished')]
    public function gatewayUploadFinished(): void {}

    /**
     * Shows one of this environment's uploads (its summary and stored log) in
     * the status panel. Any other id is ignored.
     */
    public function viewGatewayUpload(string $uploadId): void
    {
        $upload = PsoGatewayUpload::query()
            ->where('pso_environment_id', $this->record->id)
            ->whereKey($uploadId)
            ->first();

        if ($upload === null) {
            return;
        }

        $this->gatewayUploadId = $upload->id;
    }

    /**
     * Validates the broadcast part of the form. BroadcastSettings::fromArray()
     * casts silently, so anything it would mangle is refused here. Returns
     * null (after raising a notification) when a value is invalid.
     *
     * @return array{id: string, plan_type: string, minimum_plan_quality: int, maximum_wait: string, allocation_type: int, required_tables: list<string>}|null
     */
    private function validatedBroadcastSettings(Get $get): ?array
    {
        $normalise = static fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value;

        $values = [
            'broadcast_id' => is_string($get('broadcast_id')) ? trim($get('broadcast_id')) : $get('broadcast_id'),
            'broadcast_plan_type' => $normalise($get('broadcast_plan_type')),
            'broadcast_minimum_plan_quality' => $get('broadcast_minimum_plan_quality'),
            'broadcast_maximum_wait' => is_string($get('broadcast_maximum_wait')) ? trim($get('broadcast_maximum_wait')) : $get('broadcast_maximum_wait'),
            'broadcast_allocation_type' => $normalise($get('broadcast_allocation_type')),
            'broadcast_required_tables' => $get('broadcast_required_tables'),
        ];

        $validator = Validator::make($values, [
            'broadcast_id' => ['required', 'string', 'max:255', 'regex:'.self::XML_SAFE_LINE_PATTERN],
            'broadcast_plan_type' => ['required', Rule::enum(BroadcastPlanType::class)],
            'broadcast_minimum_plan_quality' => ['required', 'integer', 'between:0,100'],
            'broadcast_maximum_wait' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! BroadcastSettings::isValidDuration($value)) {
                        $fail('Maximum Wait must be an ISO 8601 duration, such as PT5M.');
                    }
                },
            ],
            'broadcast_allocation_type' => ['required', Rule::enum(BroadcastAllocationType::class)],
            'broadcast_required_tables' => ['required', 'array', 'min:1'],
            'broadcast_required_tables.*' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_]+\z/'],
        ], [
            'broadcast_id.regex' => 'Broadcast ID contains a character that cannot be sent (a control character such as a tab or line break).',
            'broadcast_required_tables.required' => 'Required Tables needs at least one table name.',
            'broadcast_required_tables.min' => 'Required Tables needs at least one table name.',
            'broadcast_required_tables.*.regex' => 'Each required table must be a single table name (letters, digits and underscores), such as Allocation.',
        ], [
            'broadcast_id' => 'Broadcast ID',
            'broadcast_plan_type' => 'Plan Type',
            'broadcast_minimum_plan_quality' => 'Minimum Plan Quality',
            'broadcast_maximum_wait' => 'Maximum Wait',
            'broadcast_allocation_type' => 'Allocation Type',
            'broadcast_required_tables' => 'Required Tables',
        ]);

        if ($validator->fails()) {
            $this->notifyPayloadSent('Upload Failed', (string) $validator->errors()->first(), false);

            return null;
        }

        return BroadcastSettings::fromArray([
            'id' => $values['broadcast_id'],
            'plan_type' => $values['broadcast_plan_type'],
            'minimum_plan_quality' => (int) $values['broadcast_minimum_plan_quality'],
            'maximum_wait' => $values['broadcast_maximum_wait'],
            'allocation_type' => (int) $values['broadcast_allocation_type'],
            'required_tables' => array_values($values['broadcast_required_tables']),
        ])->toArray();
    }

    public function gatewayUploadHistory(): Collection
    {
        return PsoGatewayUpload::query()
            ->where('pso_environment_id', $this->record->id)
            ->latest('created_at')
            ->limit(10)
            ->get();
    }
}
