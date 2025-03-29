<?php

namespace App\Filament\Pages;

use App\Enums\HttpMethod;

use App\Models\Environment;
use App\Traits\FormTrait;
use App\Traits\PSOPayloads;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use JsonException;
use Override;

class ActivityDeleteSla extends Page
{

    use InteractsWithForms, FormTrait, PSOPayloads;

// View
    protected static string $view = 'filament.pages.activity-delete-sla';

// Navigation
    protected static ?string $navigationParentItem = 'Activity Services';
    protected static ?string $navigationGroup = 'Services';
    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?string $activeNavigationIcon = 'heroicon-s-trash';

// Page Information
    protected static ?string $title = 'Delete Activity SLA';
    protected static ?string $slug = 'activity-delete-sla';

// Data
    public ?array $activity_data = [];

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
//        $this->selectedEnvironment = new Environment();
        $this->env_form->fill();
        $this->activity_form->fill();
    }

    #[Override] protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
    }

    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->schema([
                        TextInput::make('activity_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Activity ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        TextInput::make('sla_type_id')
                            ->prefixIcon('heroicon-o-chart-bar')
                            ->label('SLA Type ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        TextInput::make('priority')
                            ->prefixIcon('heroicon-o-arrow-up')
                            ->required()
                            ->numeric()
                            ->step(1)
                            ->gt(0)
                            ->minValue(1)
                            ->default('1')
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Forms\Components\Toggle::make('start_based')
                            ->inline(false)
                            ->default(true),

                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('update_status')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $this->deleteSLA();
                            })
                        ]),
                    ])->columns(),

            ])->statePath('activity_data');
    }

    /**
     * @throws JsonException
     */
    public function deleteSLA(): void
    {
        // validate
        $this->validateForms($this->getForms());

        $this->response = $this->sendToPSO('activity/' . $this->activity_data['activity_id'] . '/sla', $this->deleteSLAPayload(), HttpMethod::DELETE);

    }

    private function deleteSLAPayload(): array
    {
        return [

            'dataset_id' => $this->environment_data['dataset_id'],
            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
            'send_to_pso' => $this->environment_data['send_to_pso'],
            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
            'username' => $this->selectedEnvironment->getAttribute('username'),
            'password' => $this->selectedEnvironment->getAttribute('password'),
            'start_based' => $this->activity_data['start_based'],
            'sla_type_id' => $this->activity_data['sla_type_id'],
            'activity_id' => $this->activity_data['activity_id'],
            'priority' => $this->activity_data['priority'],
        ];

    }
}
