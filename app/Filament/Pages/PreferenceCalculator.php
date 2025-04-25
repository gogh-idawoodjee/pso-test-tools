<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Override;

class PreferenceCalculator extends Page
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $activeNavigationIcon = 'heroicon-s-calculator';
    protected static string $view = 'filament.pages.preference-calculator';
    protected static ?string $navigationGroup = 'Additional Tools';

    public ?array $preference_data = [];

    #[Override]
    protected function getForms(): array
    {
        return ['preference_form'];
    }

    public function mount(): void
    {
        $this->preference_form->fill();
        $this->performCalculation();
    }


    public function preference_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Preference Calculator')
                    ->schema([
                        $this->getActivityFieldset(),
                        $this->getResourceParametersFieldset(),
                        $this->getPreferenceDetailsFieldset(),
                        $this->getCalculationsFieldset(),
                    ])
                    ->columns()
            ])
            ->statePath('preference_data');
    }

    protected function getActivityFieldset(): Fieldset
    {
        return Fieldset::make('activity')
            ->schema([
                TextInput::make('sla_allocation_value')
                    ->label('Allocation Value')
                    ->numeric()
                    ->default(2000)
                    ->minValue(1)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn() => $this->performCalculation())
                    ->helperText('Found on the SLA tab'),
                TextInput::make('activity_duration')
                    ->label('Duration')
                    ->numeric()
                    ->default(75)
                    ->minValue(10)
                    ->maxValue(3600)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn() => $this->performCalculation())
                    ->helperText('Duration in Minutes'),
            ])
            ->label('Activity Data');
    }

    protected function getResourceParametersFieldset(): Fieldset
    {
        return Fieldset::make('resource_parameters')
            ->schema([
                Toggle::make('same_cost_per_hour')
                    ->label('Same Cost Per Hour For Both')
                    ->inline(false)
                    ->default('checked')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        if ($get('same_cost_per_hour')) {
                            $set('cost_per_hour_tech2', $get('cost_per_hour_tech1'));
                        }
                        $this->performCalculation();
                    }),
                $this->createCostPerField('cost_per_hour_tech1', 'Cost Per Hour Technician 1', 20, 'same_cost_per_hour'),
                $this->createCostPerField('cost_per_hour_tech2', 'Cost Per Hour Technician 2', 20, 'same_cost_per_hour'),
                Toggle::make('same_cost_per_km')
                    ->label('Same Cost Per KM For Both')
                    ->inline(false)
                    ->default('checked')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        if ($get('same_cost_per_km')) {
                            $set('cost_per_km_tech2', $get('cost_per_km_tech1'));
                        }
                        $this->performCalculation();
                    }),
                $this->createCostPerField('cost_per_km_tech1', 'Cost Per KM Technician 1', 0.25, 'same_cost_per_km'),
                $this->createCostPerField('cost_per_km_tech2', 'Cost Per KM Technician 2', 0.25, 'same_cost_per_km'),
            ])
            ->columns(3)
            ->label('Resource Parameters');
    }

    protected function createCostPerField(string $name, string $label, float $default, string $toggleField): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->disabled(fn(Get $get) => $get($toggleField))
            ->numeric()
            ->minValue(0.1)
            ->step(0.1)
            ->default($default)
            ->required(fn(Get $get) => $get($toggleField) === false)
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set) use ($name, $toggleField) {
                // If this is tech1 field and the toggle is on, copy the value to tech2
                if (str_contains($name, 'tech1') && $get($toggleField)) {
                    $tech2Field = str_replace('tech1', 'tech2', $name);
                    $set($tech2Field, $get($name));
                }
                $this->performCalculation();
            });
    }

    protected function getPreferenceDetailsFieldset(): Fieldset
    {
        return Fieldset::make('preference_details')
            ->label('Preference Details')
            ->schema([
                $this->createPreferenceField('preference_tech1', 'Preference Tech 1', 0.5),
                $this->createPreferenceField('preference_tech2', 'Preference Tech 2', 0.7),
                $this->createDistanceField('distance_tech1', 'Distance Away From Activity Tech 1', 10),
                $this->createDistanceField('distance_tech2', 'Distance Away From Activity Tech 2', 5),
                $this->createDriveTimeField('drive_time_tech1', 'Minutes Away From Activity Tech 1'),
                $this->createDriveTimeField('drive_time_tech2', 'Minutes Away From Activity Tech 2'),
            ])
            ->columnSpan(1);
    }

    protected function createPreferenceField(string $name, string $label, float $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->default($default)
            ->numeric()
            ->minValue(0)
            ->maxValue(1)
            ->step(0.1)
            ->inputMode('decimal')
            ->live(onBlur: true)
            ->dehydrateStateUsing(fn($state) => is_numeric($state) ? (float)$state : $default)
            ->afterStateUpdated(function ($state, $set) use ($name, $default) {
                if (!is_numeric($state) || $state === '') {
                    $set($name, $default);
                }
                $this->performCalculation();
            });
    }

    protected function createDistanceField(string $name, string $label, float $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->default($default)
            ->numeric()
            ->minValue(0.5)
            ->live()
            ->afterStateUpdated(fn() => $this->performCalculation());
    }

    protected function createDriveTimeField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->helperText('leave blank if unknown')
            ->numeric()
            ->live()
            ->afterStateUpdated(fn() => $this->performCalculation());
    }

    protected function getCalculationsFieldset(): Fieldset
    {
        return Fieldset::make('calculations')
            ->label('Results')
            ->schema([
                TextInput::make('travel_cost_tech1')->disabled()->label('Travel Cost Tech 1'),
                TextInput::make('travel_cost_tech2')->disabled()->label('Travel Cost Tech 2'),
                TextInput::make('wrench_time_cost_tech1')->disabled()->label('Wrench Time Cost Tech 1'),
                TextInput::make('wrench_time_cost_tech2')->disabled()->label('Wrench Time Cost Tech 2'),
                TextInput::make('drive_time_cost_tech1')->disabled()->label('Drive Time Cost Tech 1'),
                TextInput::make('drive_time_cost_tech2')->disabled()->label('Drive Time Cost Tech 2'),
                TextInput::make('allocation_value_tech1')->readOnly()->label('Allocation Value Tech 1')
                    ->extraAttributes(function () {
                        $tech1 = $this->preference_data['allocation_value_tech1'] ?? 0;
                        $tech2 = $this->preference_data['allocation_value_tech2'] ?? 0;

                        if ($tech1 > $tech2) {
                            return [
                                'class' => 'text-xl font-bold',
                                'style' => 'background-color: #22c55e; color: white; transition: all 0.3s ease; padding: 0.5rem; border-radius: 0.375rem;'
                            ];
                        }

                        return [];
                    }),
                TextInput::make('allocation_value_tech2')->readOnly()->label('Allocation Value Tech 2')
                    ->extraAttributes(function () {
                        $tech1 = $this->preference_data['allocation_value_tech1'] ?? 0;
                        $tech2 = $this->preference_data['allocation_value_tech2'] ?? 0;

                        if ($tech2 > $tech1) {
                            return [
                                'class' => 'text-xl font-bold',
                                'style' => 'background-color: #22c55e; color: white; transition: all 0.3s ease; padding: 0.5rem; border-radius: 0.375rem;'
                            ];
                        }

                        return [];
                    }),
            ])
            ->columnSpan(1);
    }

    private function performCalculation(): void
    {
        $this->preference_form->getState();
        $this->calculateOnSiteTimeCost();
        $this->calculateTravelCost();
        $this->calculateDriveTimeCost();
        $this->calculateScheduleValue();
    }

    private function calculateOnSiteTimeCost(): void
    {
        $activityDuration = $this->preference_data['activity_duration'] ?? 0;
        $costPerHourTech1 = $this->preference_data['cost_per_hour_tech1'] ?? 0;
        $costPerHourTech2 = $this->preference_data['cost_per_hour_tech2'] ?? 0;

        $this->preference_data['wrench_time_cost_tech1'] = $activityDuration * $costPerHourTech1 / 60;
        $this->preference_data['wrench_time_cost_tech2'] = $activityDuration * $costPerHourTech2 / 60;
    }

    private function calculateTravelCost(): void
    {
        $distanceTech1 = $this->preference_data['distance_tech1'] ?? 0;
        $distanceTech2 = $this->preference_data['distance_tech2'] ?? 0;
        $costPerKmTech1 = $this->preference_data['cost_per_km_tech1'] ?? 0;
        $costPerKmTech2 = $this->preference_data['cost_per_km_tech2'] ?? 0;

        $this->preference_data['travel_cost_tech1'] = $distanceTech1 * $costPerKmTech1;
        $this->preference_data['travel_cost_tech2'] = $distanceTech2 * $costPerKmTech2;
    }

    private function calculateDriveTimeCost(): void
    {
        $driveTimeTech1 = $this->preference_data['drive_time_tech1'] ?? 0;
        $costPerHourTech1 = $this->preference_data['cost_per_hour_tech1'] ?? 0;
        $driveTimeTech2 = $this->preference_data['drive_time_tech2'] ?? 0;
        $costPerHourTech2 = $this->preference_data['cost_per_hour_tech2'] ?? 0;

        $this->preference_data['drive_time_cost_tech1'] = $driveTimeTech1 / 60 * $costPerHourTech1;
        $this->preference_data['drive_time_cost_tech2'] = $driveTimeTech2 / 60 * $costPerHourTech2;
    }

    private function calculateScheduleValue(): void
    {
        // Calculate costs
        $driveTimeCostTech1 = $this->preference_data['drive_time_cost_tech1'] ?? 0;
        $travelCostTech1 = $this->preference_data['travel_cost_tech1'] ?? 0;
        $wrenchTimeCostTech1 = $this->preference_data['wrench_time_cost_tech1'] ?? 0;

        $driveTimeCostTech2 = $this->preference_data['drive_time_cost_tech2'] ?? 0;
        $travelCostTech2 = $this->preference_data['travel_cost_tech2'] ?? 0;
        $wrenchTimeCostTech2 = $this->preference_data['wrench_time_cost_tech2'] ?? 0;

        // Calculate total costs
        $tech1_costs = $driveTimeCostTech1 + $travelCostTech1 + $wrenchTimeCostTech1;
        $tech2_costs = $driveTimeCostTech2 + $travelCostTech2 + $wrenchTimeCostTech2;

        // Get allocation and preference values
        $slaAllocationValue = $this->preference_data['sla_allocation_value'] ?? 0;
        $preferenceTech1 = $this->preference_data['preference_tech1'] ?? 0;
        $preferenceTech2 = $this->preference_data['preference_tech2'] ?? 0;

        // Calculate allocation values
        $this->preference_data['allocation_value_tech1'] = ($slaAllocationValue * $preferenceTech1 * 2) - $tech1_costs;
        $this->preference_data['allocation_value_tech2'] = ($slaAllocationValue * $preferenceTech2 * 2) - $tech2_costs;
    }
}
