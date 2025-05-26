<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\HttpMethod;
use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Filament\Resources\EnvironmentResource;
use App\Traits\PSOInteractionsTrait;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Get;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Illuminate\Support\Arr;
use Filament\Forms;
use JsonException;
use Override;
use Filament\Forms\Components\Actions\Action as formAction;

class EnvironmentTools extends Page
{

    use InteractsWithRecord, PSOInteractionsTrait, Forms\Concerns\InteractsWithForms;

    protected static string $resource = EnvironmentResource::class;

    protected static string $view = 'filament.resources.environment-resource.pages.envtools';

    protected static ?string $breadcrumb = 'Tools';
    public ?array $data = [];

    public mixed $response = null;

    protected static ?string $title = 'Tools';

    #[Override] protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url('/environments/' . $this->record->getKey() . '/edit')

        ];
    }

    #[Override] protected function getForms(): array
    {
        return ['psoload', 'form', 'json_form'];
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->setDefaults();
        $this->psoload->fill($this->record->toArray());
        $this->json_form->fill();


    }

    private function setDefaults(): void
    {
        $this->record->dse_duration = 3;
        $this->record->input_mode = InputMode::LOAD;
        $this->record->appointment_window = 7;
        $this->record->process_type = ProcessType::APPOINTMENT;
        $this->record->datetime = Carbon::now();
        $this->record->commit_url = 'https://' . config('psott.pso-services-api') . '/api/v2/commit/' . $this->record->id;
    }

    public function psoload(Form $form): Form
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
                            ->placeholder('Select Dataset')
                            ->options($this->record->datasets()->get()->pluck('name', 'name')->toArray()),
                        Select::make('input_mode')
                            ->dehydrated(false)
                            ->label('Input Mode')
                            ->native(false)
                            ->required()
                            ->live()
                            ->enum(InputMode::class)
                            ->options(InputMode::class)
                            ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))

                    ]),
                Section::make('Environment Properties')
                    ->icon('heroicon-o-circle-stack')
                    ->collapsible()
                    ->collapsed()
                    ->columns()
                    ->schema([
                        TextInput::make('base_url')
                            ->label('Base URL'),
                        TextInput::make('account_id')
                            ->label('Account ID'),
                        TextInput::make('username')
                            ->label('Username'),
                        TextInput::make('password')
                            ->label('Password')
                            ->password(),
                    ]),
                Forms\Components\Tabs::make('activity_tabs')->tabs([
                    Forms\Components\Tabs\Tab::make('load_rota_tab')
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
                                ->disabled(static function (Get $get) {
                                    return !$get('send_to_pso');
                                }),
                            TextInput::make('dse_duration')
                                ->dehydrated(false)
                                ->label('DSE Duration')
                                ->integer()
                                ->minValue(3)
                                ->visible(fn(Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->placeholder(3)
                                ->prefixIcon('heroicon-o-cube-transparent'),
                            TextInput::make('appointment_window')
                                ->dehydrated(false)
                                ->label('Appointment Window')
                                ->integer()
                                ->minValue(7)
                                ->placeholder(7)
                                ->visible(fn(Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->prefixIcon('heroicon-o-calendar-date-range'),
                            Select::make('process_type')
                                ->enum(ProcessType::class)
                                ->visible(fn(Get $get) => $get('input_mode') === InputMode::LOAD)
                                ->options(ProcessType::class)
                                ->live()
                                ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                                ->prefixIcon('heroicon-o-adjustments-horizontal'),
                            DateTimePicker::make('datetime')
                                ->dehydrated(false)
                                ->label('Input Date Time')
                                ->prefixIcon('heroicon-o-clock'),
                            Forms\Components\Actions::make([Forms\Components\Actions\Action::make('push_it')->slideOver()
                                ->action(function (Forms\Get $get) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                    // the update status thingy
                                    $this->initPSO($get);

                                })
                                ->label(function () {
                                    return $this->data['input_mode'] === InputMode::LOAD ? 'Load' : 'Update Rota';
                                }),

                            ])->columnSpan(2)
                        ])->columns()
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->label('Initial Load and Rota'),

                    Forms\Components\Tabs\Tab::make('system_usage_tab')
                        ->schema([])
                        ->icon('heroicon-o-cog')
                        ->label('System Usage'),
                    Forms\Components\Tabs\Tab::make('services_tab')
                        ->schema([
                            TextInput::make('commit_url')
                                ->label('Commit URL')
                                ->disabled()
                                ->suffixAction(
                                    formAction::make('copy')
                                        ->icon('heroicon-o-clipboard')
                                        ->action(function ($livewire, $state) {
                                            $livewire->dispatch('copy-to-clipboard', text: $state);
                                        })
                                )
                                ->extraAttributes([
                                    'x-data' => '{
            copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        $tooltip("Copied to clipboard", { timeout: 1500 });
                    }).catch(() => {
                        $tooltip("Failed to copy", { timeout: 1500 });
                    });
                } else {
                    const textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed";
                    textArea.style.opacity = "0";
                    document.body.appendChild(textArea);
                    textArea.select();
                    try {
                        document.execCommand("copy");
                        $tooltip("Copied to clipboard", { timeout: 1500 });
                    } catch (err) {
                        $tooltip("Failed to copy", { timeout: 1500 });
                    }
                    document.body.removeChild(textArea);
                }
            }
        }',
                                    'x-on:copy-to-clipboard.window' => 'copyToClipboard($event.detail.text)',
                                ]),
                        ])
                        ->icon('heroicon-o-cog')
                        ->label('Services'),

                ])

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

        $inputMode = data_get($this->data, 'input_mode');
        $segment = $inputMode === InputMode::LOAD ? InputMode::LOAD->getSegment() : InputMode::CHANGE->getSegment();
        $method = $inputMode === InputMode::LOAD ? HttpMethod::POST : HttpMethod::PATCH;

        $sendToPso = data_get($this->data, 'send_to_pso');

        $payload = $this->buildPayLoad($data);


        if ($tokenized_payload = $this->prepareTokenizedPayload($sendToPso, $payload)) {

            $this->response = $this->sendToPSO($segment, $tokenized_payload, $method);

            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('open-modal', id: 'show-json');
        }


        // old process
//        $token = $sendToPso
//            ? $this->authenticatePSO(
//                data_get($this->data, 'base_url'),
//                data_get($this->data, 'account_id'),
//                data_get($this->data, 'username'),
//                Crypt::decryptString(data_get($this->data, 'password'))
//            )
//            : null;
//
//        if ($sendToPso && !$token) {
//            $this->notifyPayloadSent('Send to PSO Failed', 'Please see the event log (when it is actually completed)', false);
//            return false;
//        }
//
//        $payload = $this->buildPayLoad($data);
//
//        if ($token) {
//            $payload = Arr::add($payload, 'token', $token);
//        }
//
//        $this->response = $this->sendToPSO($segment, $payload, $method);
//        $this->dispatch('open-modal', id: 'show-json');
    }


    private function buildPayLoad($data): array
    {
        $schema = [
            'base_url' => $data('base_url'),
            'dse_duration' => $data('dse_duration'),
            'dataset_id' => $data('dataset_id'),
            'rota_id' => $data('dataset_id'), //todo get this from dataset table
            'description' => $data('input_mode') === InputMode::CHANGE ? 'Update Rota From Tool Box' : 'Load From Tool Box',
            'send_to_pso' => $data('send_to_pso'),
            'keep_pso_data' => $data('keep_pso_data'),
            'account_id' => $data('account_id'),
            'appointment_window' => $data('appointment_window'),
            'process_type' => ProcessType::from($data('process_type')?->value ?? ProcessType::APPOINTMENT->value)->value,
            'datetime' => $data('datetime'),
            'input_mode' => $data('input_mode'),
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

        if (data_get($data, 'input_mode') === InputMode::LOAD) {
            $payload = Arr::add($payload, 'data.dseDuration', data_get($data, 'dse_duration'));
            $payload = Arr::add($payload, 'data.keepPsoData', data_get($data, 'keep_pso_data'));
            $payload = Arr::add($payload, 'data.processType', data_get($data, 'process_type'));
            $payload = Arr::add($payload, 'data.appointmentWindow', data_get($data, 'appointment_window'));
        }

        if (data_get($data, 'send_to_pso')) {
            $payload = Arr::add($payload, 'environment.accountId', data_get($data, 'account_id'));
        }

        return $payload;
    }

}
