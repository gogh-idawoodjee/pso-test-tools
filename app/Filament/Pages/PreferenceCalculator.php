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
    public ?array $preference_data = [];

    protected static ?string $navigationGroup = 'Additional Tools';

    #[Override] protected function getForms(): array
    {
        return ['preference_form'];
    }


    public ?string $allocationValueTech1Class = null;
    public ?string $allocationValueTech2Class = null;

    public ?string $allocationValueTech1Style = null;
    public ?string $allocationValueTech2Style = null;

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
                        Fieldset::make('activity')
                            ->schema([
                                TextInput::make('sla_allocation_value')
                                    ->label('Allocation Value')
                                    ->numeric()
                                    ->default(2000)
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Found on the SLA tab'),
                                TextInput::make('activity_duration')
                                    ->label('Duration')
                                    ->numeric()
                                    ->default(75)
                                    ->minValue(10)
                                    ->maxValue(3600)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Duration in Minutes'),
                            ])
                            ->label('Activity Data'),
                        Fieldset::make('resource_parameters')
                            ->schema([
                                Toggle::make('same_cost_per_hour')
                                    ->label('Same Cost Per Hour For Both')
                                    ->inline(false)
                                    ->default('checked')
//                                    ->afterStateUpdated(static fn(Get $get, Set $set) => $get('same_cost_per_hour') && !$get('same_cost_per_hour_old') ? $set('cost_per_hour_tech2', $get('cost_per_hour_tech1')) : null)
                                    ->afterStateUpdated(static function (Get $get, Set $set) {
                                        if ($get('same_cost_per_hour')) {
                                            $set('cost_per_hour_tech2', $get('cost_per_hour_tech1'));
//                                            $this->performCalculation();  // Perform the calculations
                                        }
                                    })
                                    ->live(),
                                TextInput::make('cost_per_hour_tech1')
                                    ->label('Cost Per Hour Technician 1')
                                    ->numeric()
                                    ->minValue(0.1)
                                    ->step(0.1)
                                    ->afterStateUpdated(static fn(Get $get, Set $set) => $get('same_cost_per_hour') ? $set('cost_per_hour_tech2', $get('cost_per_hour_tech1')) : null)
                                    ->live(onBlur: true)
                                    ->default(20)
                                    ->required(),
                                TextInput::make('cost_per_hour_tech2')
                                    ->label('Cost Per Hour Technician 2')
                                    ->disabled(static fn(Get $get) => $get('same_cost_per_hour'))
                                    ->numeric()
                                    ->minValue(0.1)
                                    ->step(0.1)
                                    ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                                    ->live(onBlur: true)
                                    ->default(20)
                                    ->required(static fn(Get $get) => $get('same_cost_per_hour') === false),
                                Toggle::make('same_cost_per_km')
                                    ->label('Same Cost Per KM For Both')
                                    ->inline(false)
                                    ->default('checked')
                                    ->afterStateUpdated(static fn(Get $get, Set $set) => $get('same_cost_per_km') && !$get('same_cost_per_km_old') ? $set('cost_per_km_tech2', $get('cost_per_km_tech1')) : null)
                                    ->live(onBlur: true),
                                TextInput::make('cost_per_km_tech1')
                                    ->label('Cost Per KM Technician 1')
                                    ->numeric()
                                    ->minValue(0.1)
                                    ->step(0.1)
                                    ->afterStateUpdated(static fn(Get $get, Set $set) => $get('same_cost_per_km') ? $set('cost_per_km_tech2', $get('cost_per_km_tech1')) : null)
                                    ->live(onBlur: true)
                                    ->default(.25)
                                    ->required(),
                                TextInput::make('cost_per_km_tech2')
                                    ->label('Cost Per KM Technician 2')
                                    ->disabled(static fn(Get $get) => $get('same_cost_per_km'))
                                    ->numeric()
                                    ->minValue(0.1)
                                    ->step(0.1)
                                    ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                                    ->live(onBlur: true)
                                    ->default(.25)
                                    ->required(static fn(Get $get) => $get('same_cost_per_km') === false)
                            ])->columns(3)
                            ->label('Resource Parameters'),
                        Fieldset::make('preference_details')
                            ->label('Preference Details')
                            ->schema([
                                TextInput::make('preference_tech1')
                                    ->label('Preference Tech 1')
                                    ->required()
                                    ->default(0.5)
                                    ->live(debounce: 500)
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(1)
                                    ->step(0.1),
                                TextInput::make('preference_tech2')
                                    ->label('Preference Tech 2')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->default(0.7)
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(1)
                                    ->step(0.1),
                                TextInput::make('distance_tech1')
                                    ->label('Distance Away From Activity Tech 1')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->default(10)
                                    ->numeric()
                                    ->minValue(0.5),
                                TextInput::make('distance_tech2')
                                    ->label('Distance Away From Activity Tech 2')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->default(5)
                                    ->numeric()
                                    ->minValue(0.5),
                                TextInput::make('drive_time_tech1')
                                    ->label('Minutes Away From Activity Tech 1')
                                    ->helperText('leave blank if unknown')
                                    ->live(onBlur: true)
                                    ->numeric(),
                                TextInput::make('drive_time_tech2')
                                    ->label('Minutes Away From Activity Tech 2')
                                    ->helperText('leave blank if unknown')
                                    ->live(onBlur: true)
                                    ->numeric(),
                            ])->columnSpan(1),
                        Fieldset::make('calculations')
                            ->label('Results')
                            ->schema([
                                TextInput::make('travel_cost_tech1')
                                    ->label('Travel Cost Tech 1')
                                    ->disabled(),
                                TextInput::make('travel_cost_tech2')
                                    ->label('Travel Cost Tech 2')
                                    ->disabled(),
                                TextInput::make('wrench_time_cost_tech1')->disabled()->label('Wrench Time Cost Tech 1'),
                                TextInput::make('wrench_time_cost_tech2')->disabled()->label('Wrench Time Cost Tech 1'),
                                TextInput::make('drive_time_cost_tech1')->disabled()->label('Drive Time Cost Tech 1'),
                                TextInput::make('drive_time_cost_tech2')->disabled()->label('Drive Time Cost Tech 2'),
                                TextInput::make('allocation_value_tech1')->readOnly()
                                    ->label('Allocation Value Tech 1')
                                    ->extraAttributes([
                                        'class' => $this->allocationValueTech1Class,
                                        'style' => $this->allocationValueTech1Style ?? '',
                                    ]),
                                TextInput::make('allocation_value_tech2')->readOnly()->label('Allocation Value Tech 2')
                                    ->extraAttributes([
                                        'class' => $this->allocationValueTech2Class,
                                        'style' => $this->allocationValueTech2Style ?? '',
                                    ]),
                            ])->columnSpan(1),

                    ])
                    ->columns()
                    ->afterStateUpdated(fn() => $this->performCalculation())
            ])
            ->statePath('preference_data');
    }

    private function calculateOnSiteTimeCost(): void
    {


        $activityDuration = empty($this->preference_data['activity_duration']) ? 0 : $this->preference_data['activity_duration'];
        $costPerHourTech1 = empty($this->preference_data['cost_per_hour_tech1']) ? 0 : $this->preference_data['cost_per_hour_tech1'];
        $costPerHourTech2 = empty($this->preference_data['cost_per_hour_tech2']) ? 0 : $this->preference_data['cost_per_hour_tech2'];

        $this->preference_data['wrench_time_cost_tech1'] = $activityDuration * $costPerHourTech1 / 60;
        $this->preference_data['wrench_time_cost_tech2'] = $activityDuration * $costPerHourTech2 / 60;

    }


    private function calculateTravelCost(): void
    {

        $distanceTech1 = empty($this->preference_data['distance_tech1']) ? 0 : $this->preference_data['distance_tech1'];
        $distanceTech2 = empty($this->preference_data['distance_tech2']) ? 0 : $this->preference_data['distance_tech2'];
        $costPerKmTech1 = empty($this->preference_data['cost_per_km_tech1']) ? 0 : $this->preference_data['cost_per_km_tech1'];
        $costPerKmTech2 = empty($this->preference_data['cost_per_km_tech2']) ? 0 : $this->preference_data['cost_per_km_tech2'];

        $this->preference_data['travel_cost_tech1'] = $distanceTech1 * $costPerKmTech1;
        $this->preference_data['travel_cost_tech2'] = $distanceTech2 * $costPerKmTech2;


    }

    private function calculateDriveTimeCost(): void
    {

        $driveTimeTech1 = empty($this->preference_data['drive_time_tech1']) ? 0 : $this->preference_data['drive_time_tech1'];
        $costPerHourTech1 = empty($this->preference_data['cost_per_hour_tech1']) ? 0 : $this->preference_data['cost_per_hour_tech1'];
        $driveTimeTech2 = empty($this->preference_data['drive_time_tech2']) ? 0 : $this->preference_data['drive_time_tech2'];
        $costPerHourTech2 = empty($this->preference_data['cost_per_hour_tech2']) ? 0 : $this->preference_data['cost_per_hour_tech2'];

        $this->preference_data['drive_time_cost_tech1'] = $driveTimeTech1 / 60 * $costPerHourTech1;
        $this->preference_data['drive_time_cost_tech2'] = $driveTimeTech2 / 60 * $costPerHourTech2;


    }

    private function calculateScheduleValue(): void
    {


        // Ensure default values for tech1 costs
        $driveTimeCostTech1 = empty($this->preference_data['drive_time_cost_tech1']) ? 0 : $this->preference_data['drive_time_cost_tech1'];
        $travelCostTech1 = empty($this->preference_data['travel_cost_tech1']) ? 0 : $this->preference_data['travel_cost_tech1'];
        $wrenchTimeCostTech1 = empty($this->preference_data['wrench_time_cost_tech1']) ? 0 : $this->preference_data['wrench_time_cost_tech1'];

        // Ensure default values for tech2 costs
        $driveTimeCostTech2 = empty($this->preference_data['drive_time_cost_tech2']) ? 0 : $this->preference_data['drive_time_cost_tech2'];
        $travelCostTech2 = empty($this->preference_data['travel_cost_tech2']) ? 0 : $this->preference_data['travel_cost_tech2'];
        $wrenchTimeCostTech2 = empty($this->preference_data['wrench_time_cost_tech2']) ? 0 : $this->preference_data['wrench_time_cost_tech2'];

        // Calculate tech1 costs
        $tech1_costs = $driveTimeCostTech1 + $travelCostTech1 + $wrenchTimeCostTech1;

// Calculate tech2 costs
        $tech2_costs = $driveTimeCostTech2 + $travelCostTech2 + $wrenchTimeCostTech2;

        // Ensure default values for sla_allocation_value and preference values
        $slaAllocationValue = empty($this->preference_data['sla_allocation_value']) ? 0 : $this->preference_data['sla_allocation_value'];
        $preferenceTech1 = empty($this->preference_data['preference_tech1']) ? 0 : $this->preference_data['preference_tech1'];
        $preferenceTech2 = empty($this->preference_data['preference_tech2']) ? 0 : $this->preference_data['preference_tech2'];

        // Calculate allocation values
        $this->preference_data['allocation_value_tech1'] = ($slaAllocationValue * $preferenceTech1 * 2) - $tech1_costs;
        $this->preference_data['allocation_value_tech2'] = ($slaAllocationValue * $preferenceTech2 * 2) - $tech2_costs;


    }

    private function performCalculation(): void
    {
        $this->preference_form->getState();
        $this->calculateOnSiteTimeCost();
        $this->calculateTravelCost();
        $this->calculateDriveTimeCost();
        $this->calculateScheduleValue();

        $this->allocationValueTech1Class = '';
        $this->allocationValueTech2Class = '';
        $this->allocationValueTech1Style = '';
        $this->allocationValueTech2Style = '';

        $tech1 = $this->preference_data['allocation_value_tech1'] ?? 0;
        $tech2 = $this->preference_data['allocation_value_tech2'] ?? 0;

        if ($tech1 > $tech2) {
            $this->allocationValueTech1Class = 'text-xl font-bold';
            $this->allocationValueTech1Style = 'background-color: #22c55e; color: white; transition: all 0.3s ease; padding: 0.5rem; border-radius: 0.375rem;';
        } elseif ($tech2 > $tech1) {
            $this->allocationValueTech2Class = 'text-xl font-bold';
            $this->allocationValueTech2Style = 'background-color: #22c55e; color: white; transition: all 0.3s ease; padding: 0.5rem; border-radius: 0.375rem;';
        }
    }

}
