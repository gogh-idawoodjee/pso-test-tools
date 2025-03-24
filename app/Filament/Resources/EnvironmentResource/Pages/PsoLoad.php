<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Filament\Resources\EnvironmentResource;
use App\Traits\PSOPayloads;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Get;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;


use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PsoLoad extends Page

{

    use InteractsWithRecord, PSOPayloads;

    protected static string $resource = EnvironmentResource::class;
    protected static string $view = 'filament.resources.environment-resource.pages.pso-load';
    protected static ?string $breadcrumb = 'Tools';
    public ?array $data = [];

    public $response;

    protected static ?string $title = 'Tools';

//    private Environment $environment;


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
        $this->response = new Collection();

    }

    private function setDefaults()
    {
        $this->record->dse_duration = 3;
        $this->record->input_mode = InputMode::LOAD;
        $this->record->appointment_window = 7;
        $this->record->process_type = ProcessType::APPOINTMENT;
        $this->record->datetime = Carbon::now();
    }

//    public function mount(Environment $recordment): void
//    {
//        $this->environment = $environment;
//        $this->form->fill();
//    }

    public function psoload(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Mode and Dataset')
                    ->columns()
                    ->schema([
                        Select::make('dataset_id')
                            ->dehydrated(false)
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
                Section::make('PSO Input Reference Settings')
                    ->columns()
                    ->schema([
                        Toggle::make('send_to_pso')
                            ->dehydrated(false)
                            ->label('Send to PSO')
                            ->live(),
                        Toggle::make('keep_pso_data')
                            ->dehydrated(false)
                            ->label('Keep PSO Data')
                            ->requiredIf('send_to_pso', true)
                            ->disabled(function (Get $get) {
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
                            ->prefixIcon('heroicon-o-clock')
                    ])
                    ->footerActions(
                        [FormAction::make('Push It Real Good')
                            ->action(function (Get $get) {
                                $this->sendToPSO($get);
                            }),]
                    )

            ])->statePath('data');
    }


    public function sendToPSO($data)
    {

        $this->response = null;
        $payload = $this->buildPayLoad($data);

        $theresponse = Http::contentType('application/json')
            ->accept('application/json')
            ->post('https://pso-services.test/api/load', $payload);

//        dd($theresponse->body());
        $this->response = $theresponse->collect()->toJson(JSON_PRETTY_PRINT);
//        dd($this->response);


    }

    private function buildPayLoad($data)
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

//    public function render(): \Illuminate\Contracts\View\View
//    {
//        $this->dispatch('rules-filtered');
//        return view('filament.resources.environment-resource.pages.pso-load');
//    }
}
