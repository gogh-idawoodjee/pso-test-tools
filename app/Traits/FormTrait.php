<?php

namespace App\Traits;

use App\Models\Environment;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Collection;

trait FormTrait
{

    public Collection $environments;
    public ?array $environment_data = [];
    public Environment $selectedEnvironment;
    public $response;
    public bool $isDataSetHidden, $isDataSetRequired = false;

    public function validateForms($forms): void
    {
        foreach ($forms as $form) {
            $this->{$form}->getState();
        }
    }


    public function env_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Environment')
                    ->icon('heroicon-s-circle-stack')
                    ->schema([
                        Toggle::make('send_to_pso')
                            ->label('Send to PSO')
                            ->inline(false)
                            ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                            ->live(),
                        Select::make('environment_id')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->options($this->environments->pluck('name', 'id'))
                            ->required()
                            ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                                $livewire->validateOnly($component->getStatePath());
                                $this->setCurrentEnvironment($state);
                            })
                            ->live()->columnSpan($this->isDataSetHidden ? 2 : 1),
                        Select::make('dataset_id')
                            ->prefixIcon('heroicon-o-cube-transparent')
                            ->required(!$this->isDataSetRequired)
                            ->hidden($this->isDataSetHidden)
                            ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                                $livewire->validateOnly($component->getStatePath());
                            })
                            ->options(function (Get $get) {
                                return $this->environments->find($get('environment_id'))?->datasets->pluck('name', 'name');
                            })->live()
                    ])->columns(3),

            ])->statePath('environment_data');

    }

    private function setCurrentEnvironment($id): void
    {
        $this->selectedEnvironment = $this->environments->find($id);

    }

}
