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

    public ?Collection $environments;
    public ?array $environment_data = [];
    public ?Environment $selectedEnvironment;
    public mixed $response = null;
    public bool $isDataSetHidden = false;
    public bool $isDataSetRequired = false;

    public bool $isAuthenticationRequired = false;


    public function validateForms(array $forms): void
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
                    ->description($this->isAuthenticationRequired ? 'This function requires PSO Authentication. Send to PSO must be selected.' : null)
                    ->icon('heroicon-s-circle-stack')
                    ->schema([
                        Toggle::make('send_to_pso')
                            ->label('Send to PSO')
                            ->inline(false)
                            ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                            ->live()
                            ->default($this->isAuthenticationRequired ? 'checked' : null)
                            ->disabled($this->isAuthenticationRequired),
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
                            ->afterStateUpdated(static function ($livewire, $component) {
                                $livewire->validateOnly($component->getStatePath());
                            })
                            ->options(fn(Get $get) => $this->getDatasetOptions($get))->live()
                    ])->columns(3),

            ])->statePath('environment_data');

    }

    private function getDatasetOptions(Get $get): array
    {
        return $this->environments
            ->find($get('environment_id'))
            ?->datasets
            ->pluck('name', 'name')
            ->toArray() ?? [];
    }

    private function setCurrentEnvironment($id): void
    {
        $this->selectedEnvironment = $this->environments->find($id);

    }

    public function environnment_payload_data(): array
    {
        return [

            'dataset_id' => $this->environment_data['dataset_id'],
            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
            'send_to_pso' => $this->environment_data['send_to_pso'],
            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
            'username' => $this->selectedEnvironment->getAttribute('username'),
            'password' => $this->selectedEnvironment->getAttribute('password')

        ];
    }

}
