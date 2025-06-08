<?php

namespace App\Filament\Pages\Activity;

use App\Filament\BasePages\PSOActivityBasePage;

use App\Support\GeocodeHelper;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

use JsonException;

class GenerateActivities extends PSOActivityBasePage
{

// View
    protected static string $view = 'filament.pages.activity-generate-activities';
    protected static ?string $navigationIcon = 'heroicon-o-document-plus';
    protected static ?string $activeNavigationIcon = 'heroicon-s-document-plus';

// Page Information
    protected static ?string $title = 'Generate Activities';
    protected static ?string $slug = 'activity-generate';
    protected static ?string $navigationLabel = 'Generate Activities';

// Data


    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->description('Scheduling Section: Select either a relative day start/end (i.e. SLA starts on day 0 and ends on day 7) or pick an appt window size for the current day')
                    ->schema([
                        Forms\Components\Fieldset::make('details')
                            ->label('Details')
                            ->schema([
                                TextInput::make('activity_type_id')
                                    ->prefixIcon('heroicon-o-pencil-square')
                                    ->label('Activity Type ID')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                TextInput::make('sla_type_id')
                                    ->label('SLA Type ID')
                                    ->required()
                                    ->validationMessages(['required' => "SLA Type ID is required"])
                                    ->live()
                                    ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                                TextInput::make('base_value')
                                    ->label('Base Value')
                                    ->required()
                                    ->minValue(1000)
                                    ->default(3000)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('duration')
                                    ->label('Duration')
                                    ->required()
                                    ->helperText('in minutes')
                                    ->minValue(10)
                                    ->default(60)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('priority')
                                    ->label('Priority')
                                    ->minValue(1)
                                    ->required()
                                    ->default(1)
                                    ->step(1)
                                    ->numeric()
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
                                            ->action(static function (Forms\Set $set) {
                                                $set('time_zone', null);
                                            })),


                            ])->columns(3),
                        Forms\Components\Fieldset::make('scheduling')
                            ->label('Scheduling')
                            ->schema([
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

                                Forms\Components\Select::make('window_size')
                                    ->label('Appointment Window Size')
                                    ->options([0 => 'All Day', 3 => '3 Hour', 4 => '4 Hour'])
                                    ->live(),
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
                                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                                GeocodeHelper::geocodeFormAddress($get, $set);

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
                                    )->addActionLabel('Add Skill')->defaultItems(0),
                                Forms\Components\Repeater::make('regions')
                                    ->simple(TextInput::make('region'))->addActionLabel('Add Region')->defaultItems(0),

                            ])->columns(3),

                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('create_activity')
                            ->action(function () {
                                $this->createActivity();

                            }),
                        ]),

                    ])->columns(),

            ])->statePath('activity_data');
    }

    /**
     * @throws JsonException
     */
    public function createActivity(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());


        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $this->generateActivitiesPayload())) {
            $this->response = $this->sendToPSONew('activity', $tokenized_payload);
            // todo this method is not complete
            $this->dispatch('json-updated'); // Add this line
            $this->dispatch('open-modal', id: 'show-json');
        }

    }


    private function generateActivitiesPayload(): array
    {
        $payload = array_merge($this->environnment_payload_data(),
            ['activity_id' => $this->activity_data['activity_id'],
                'activity_type_id' => $this->activity_data['activity_type_id'],
                'sla_type_id' => $this->activity_data['sla_type_id'],
                'base_value' => $this->activity_data['base_value'],
                'duration' => $this->activity_data['duration'],
                'priority' => $this->activity_data['priority'],
                'lat' => $this->activity_data['latitude'],
                'long' => $this->activity_data['longitude'],
                'relative_day' => $this->activity_data['relative_day'],
                'relative_day_end' => $this->activity_data['relative_day_end'],
                'window_size' => $this->activity_data['window_size']
            ]);

        if ($skills = collect($this->activity_data['skills'])->pluck('skill')->filter()->values()) {
            $payload['skills'] = $skills;
        }

        if ($regions = collect($this->activity_data['regions'])->pluck('region')->filter()->values()) {
            $payload['regions'] = $regions;
        }

        return $payload;
    }
}
