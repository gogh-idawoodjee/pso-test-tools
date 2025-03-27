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
                            ->options($this->environments->pluck('name', 'id'))
                            ->required(static fn(Get $get) => $get('send_to_pso'))
//                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                            ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                                $livewire->validateOnly($component->getStatePath());
                                $this->setCurrentEnvironment($state);
                            })
                            ->live(),
                        Select::make('dataset_id')
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

//    private function getEnvironment($selected_environment): array
//    {
//        dd($selected_environment);
//        $myenvdetails = $this->environments->filter(function ($environment) use ($selected_environment) {
//            return $environment->id == $selected_environment;
//        });
//        dd($myenvdetails);
//    }
}
