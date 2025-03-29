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

class EnvironmentTools extends Page
{

    use InteractsWithRecord, PSOInteractionsTrait;

    protected static string $resource = EnvironmentResource::class;

    protected static string $view = 'filament.resources.environment-resource.pages.envtools';

    protected static ?string $breadcrumb = 'Tools';
    public ?array $data = [];

    public $response;

    protected static ?string $title = 'Tools';

    protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url('/environments/' . $this->record->getKey() . '/edit')

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
                            ->placeholder('Select Dataset')
                            ->options($this->record->datasets()->get()->pluck('name', 'name')->toArray()),
                        Select::make('input_mode')
                            ->dehydrated(false)
                            ->label('Input Mode')
                            ->required()
                            ->enum(InputMode::class)
                            ->options(InputMode::class)

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
                                ->placeholder(3)
                                ->prefixIcon('heroicon-o-cube-transparent'),
                            TextInput::make('appointment_window')
                                ->dehydrated(false)
                                ->label('Appointment Window')
                                ->integer()
                                ->minValue(7)
                                ->placeholder(7)
                                ->prefixIcon('heroicon-o-calendar-date-range'),
                            Select::make('process_type')
                                ->enum(ProcessType::class)
                                ->dehydrated(false)
                                ->options(ProcessType::class)
                                ->prefixIcon('heroicon-o-adjustments-horizontal'),
                            DateTimePicker::make('datetime')
                                ->dehydrated(false)
                                ->label('Input Date Time')
                                ->prefixIcon('heroicon-o-clock'),
                            Forms\Components\Actions::make([Forms\Components\Actions\Action::make('push_it')
                                ->action(function (Forms\Get $get) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                    // the update status thingy
                                    $this->initPSO($get);

                                })->label('Push it real good'),
                            ])
                        ])->columns()
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->label('Initial Load and Rota'),

                    Forms\Components\Tabs\Tab::make('system_usage_tab')
                        ->schema([])
                        ->icon('heroicon-o-cog')
                        ->label('System Usage'),

                ])

            ])->statePath('data');
    }


    /**
     * @throws JsonException
     */
    public function initPSO($data): void
    {

        foreach ($this->getForms() as $form) {
            $this->{$form}->getState();
        }
        $segment = $this->data['input_mode'] === InputMode::LOAD ? 'load' : 'rotatodse';
        $method = $this->data['input_mode'] === InputMode::LOAD ? HttpMethod::POST : HttpMethod::PATCH;

        $this->response = $this->sendToPSO($segment, $this->buildPayLoad($data), $method);

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
            'username' => $data('username'),
            'password' => $data('password'),
            'appointment_window' => $data('appointment_window'),
            'process_type' => $data('process_type'),
            'datetime' => $data('datetime'),
            'input_mode' => $data('input_mode'),
        ];

        return $this->initialize_payload($schema);
    }

    public function initialize_payload($data): array
    {
        $payload =
            [
                'base_url' => $data['base_url'],
                'datetime' => Carbon::parse($data['datetime'])->toAtomString() ?: Carbon::now()->toAtomString(),
                'description' => $data['description'],
                'organisation_id' => '2',
                'dataset_id' => $data['dataset_id'],
                'send_to_pso' => $data['send_to_pso'],

            ];

        if ($data['input_mode'] === InputMode::LOAD) {

            $payload = Arr::add($payload, 'dse_duration', $data['dse_duration']);
            $payload = Arr::add($payload, 'keep_pso_data', $data['keep_pso_data']);
            $payload = Arr::add($payload, 'process_type', $data['process_type']);
            $payload = Arr::add($payload, 'appointment_window', $data['appointment_window']);

        }

        if ($data['send_to_pso']) {

            $payload = Arr::add($payload, 'username', $data['username']);
            $payload = Arr::add($payload, 'password', $data['password']);
            $payload = Arr::add($payload, 'account_id', $data['account_id']);

        }

        return $payload;
    }
}
