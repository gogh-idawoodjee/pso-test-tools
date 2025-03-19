<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Enums\ProcessType;
use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\Page;

class PsoLoad extends Page

{
    protected static string $resource = EnvironmentResource::class;
    protected static string $view = 'filament.resources.environment-resource.pages.pso-load';
//    protected static ?string $title = 'Tools';
    protected static ?string $breadcrumb = 'Tools';
    public ?array $data = [];

    protected static ?string $title = 'Tools';

    private Environment $environment;
    

    protected function getHeaderActions(): array
    {
        return [

            Action::make('Return to Environment')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url('/environments/' . $this->environment->id . '/edit')

        ];
    }

    public function mount(Environment $environment): void
    {
        $this->environment = $environment;
        $this->form->fill();

    }

    public function form(Form $form): Form
    {

        return $form
            ->schema([
                Section::make('Select Dataset')->schema([
                    Select::make('dataset_id')
                        ->dehydrated(false)
                        ->label('Dataset')
                        ->placeholder('Select Dataset')
                        ->options($this->environment->datasets()->get()->pluck('name', 'id')->toArray()),
                ]),
                Section::make('Environment Properties')
                    ->collapsible()
                    ->collapsed()
                    ->columns()
                    ->schema([
                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->default($this->environment->base_url),
                        TextInput::make('account_id')
                            ->label('Account ID')
                            ->default($this->environment->account_id),
                        TextInput::make('username')
                            ->label('Username')
                            ->default($this->environment->username),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->default($this->environment->password),]),
                Fieldset::make('PSO Initialization Settings')
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
                            ->default(3)
                            ->integer()
                            ->minValue(3)
                            ->placeholder(3),
                        TextInput::make('appointment_window')
                            ->dehydrated(false)
                            ->label('Appointment Window')
                            ->integer()
                            ->minValue(7)
                            ->default(7)
                            ->placeholder(7),
                        Select::make('process_type')
                            ->enum(ProcessType::class)
                            ->dehydrated(false)
                            ->options(ProcessType::class)
                            ->default(ProcessType::APPOINTMENT),
                        DateTimePicker::make('datetime')
                            ->dehydrated(false)

                    ])
            ])->statePath('data');
    }


}
