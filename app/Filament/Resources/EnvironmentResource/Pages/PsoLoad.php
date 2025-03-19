<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use Carbon\Carbon;
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

use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Http;

class PsoLoad extends Page

{

    use InteractsWithRecord;

    protected static string $resource = EnvironmentResource::class;
    protected static string $view = 'filament.resources.environment-resource.pages.pso-load';
    protected static ?string $breadcrumb = 'Tools';
    public ?array $data = [];

    protected static ?string $title = 'Tools';

//    private Environment $environment;


    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (blank($data['base_url'])) {
            $data['base_url'] = 'http://www.dcotc.com';
        }
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url('/environments/' . $this->record->id . '/edit')

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
                            ->label('Base URL')
                            ->dehydrated(false),
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
                            ->label('Send to PSO'),
                        Toggle::make('keep_pso_data')
                            ->dehydrated(false)
                            ->label('Keep PSO Data')
                            ->requiredIf('send_to_pso', true),
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
//        dd($data('base_url'));
        Http::post('https://webhook.site/7f7b00cc-813f-42ff-9511-586fa3a62a5b',
            [
                'dataset_id' => $data('dataset_id'),
                'datetime' => $data('datetime'),
                'dse_duration' => $data('dse_duration'),
                'input_mode ' => $data('input_mode'),

            ]);
    }

    private function buildPayLoad()
    {

    }
}
