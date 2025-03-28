<?php

namespace App\Filament\Pages;

use App\Enums\HttpMethod;
use App\Enums\TaskStatus;
use App\Models\Environment;
use App\Traits\FormTrait;
use App\Traits\PSOPayloads;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;


class ActivityGenerateActivities extends Page
{
    use InteractsWithForms, FormTrait, PSOPayloads;


// View
    protected static string $view = 'filament.pages.activity-generate-activities';

// Navigation
    protected static ?string $navigationParentItem = 'Activity Services';
    protected static ?string $navigationGroup = 'Services';
    protected static ?string $navigationIcon = 'heroicon-o-document-plus';
    protected static ?string $activeNavigationIcon = 'heroicon-s-document-plus';

// Page Information
    protected static ?string $title = 'Generate Activities';
    protected static ?string $slug = 'activity-generate';

// Data
    public ?array $activity_data = [];


    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
//        $this->selectedEnvironment = new Environment();
        $this->env_form->fill();
        $this->activity_form->fill();
    }


    protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
    }

    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->description('For the timing section, select either a relative day start/end (i.e. SLA starts on day 0 and ends on day 7) or pick an appt window size for the current day')
                    ->schema([
                        Forms\Components\Fieldset::make('datetime')
                            ->label('Details')
                            ->schema([
                                TextInput::make('activity_type_id')
                                    ->prefixIcon('heroicon-o-pencil-square')
                                    ->label('Activity Type ID')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                TextInput::make('relative_day')
                                    ->label('Relative Day Start')
                                    ->minValue(0)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('relative_day_end')
                                    ->label('Relative Day End')
                                    ->minValue(0)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('sla_type_id')
                                    ->label('SLA Type ID')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                Forms\Components\Select::make('window_size')
                                    ->label('Appointment Window Size')
                                    ->options([0 => 'All Day', 3 => '3 Hour', 4 => '4 Hour'])
                                    ->live(),
                                TextInput::make('time_zone')
                                    ->label('Time Zone Offset from UTC')
                                    ->minValue(-24)
                                    ->maxValue(24)
                                    ->step(1)
                                    ->numeric()
                                    ->live()
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('clear_time_zone')
                                            ->icon('heroicon-m-x-circle')
                                            ->requiresConfirmation()
                                            ->action(function (Forms\Set $set) {
                                                $set('time_zone', null);
                                            })),
                            ])->columns(3),
                        Forms\Components\Fieldset::make('location')
                            ->label('Location')
                            ->schema([
                                TextInput::make('latitude')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address')
                                    ->prefixIcon('heroicon-s-map')
//                                    ->helperText('use an address and geocode it')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->requiresConfirmation()
                                            ->action(function (Forms\Get $get) {
                                                $this->geocodeAddress($get('latitude'), $get('longitude'));
                                            }))
                                    ->hint('click the map icon to geocode this!'),

                            ]),
                        Forms\Components\Fieldset::make('optional')
                            ->label('Optional')
                            ->schema([
                                TextInput::make('activity_id')
                                    ->helperText('will be a UUID if not included'),
                                Forms\Components\Repeater::make('skills')
                                    ->simple(
                                        TextInput::make('skills')
                                    )->addActionLabel('Add Skill'),
                                Forms\Components\Repeater::make('regions')
                                    ->simple(TextInput::make('region'))->addActionLabel('Add Region'),

                            ])->columns(3),

                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('update_status')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $this->updateTaskStatus();
                            }),
                        ]),

                    ])->columns(),

            ])->statePath('activity_data');
    }

    public function updateTaskStatus(): void
    {
        // validate
        $this->validateForms($this->getForms());

        $payload = $this->generateActivitiesPayload();

        $status = TaskStatus::from($this->activity_data['status'])->ishServicesValue();

        $this->response = $this->sendToPSO('activity/' . $this->activity_data['activity_id'] . '/' . $status, $payload, HttpMethod::PATCH);

    }

    public function geocodeAddress()
    {

    }


    private function generateActivitiesPayload(): array
    {
        $payload = [

            'dataset_id' => $this->environment_data['dataset_id'],
            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
            'send_to_pso' => $this->environment_data['send_to_pso'],
            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
            'username' => $this->selectedEnvironment->getAttribute('username'),
            'password' => $this->selectedEnvironment->getAttribute('password'),

        ];

        return $payload;
    }
}
