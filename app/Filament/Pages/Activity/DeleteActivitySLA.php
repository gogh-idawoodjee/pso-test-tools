<?php

namespace App\Filament\Pages\Activity;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOActivityBasePage;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use JsonException;


class DeleteActivitySLA extends PSOActivityBasePage
{

// View
    protected static string $view = 'filament.pages.activity-delete-sla';

    protected static bool $shouldRegisterNavigation = false;
// Navigation

    protected static ?string $navigationLabel = 'Delete Activity SLA';
    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?string $activeNavigationIcon = 'heroicon-s-trash';

// Page Information
    protected static ?string $title = 'Delete Activity SLA';
    protected static ?string $slug = 'activity-delete-sla';

// Data

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


    private function deleteSLA(): array
    {

        $this->response = null;
        $this->validateForms($this->getForms());


        $payload = array_merge($this->environnment_payload_data(), [
            'sla_type_id' => $this->activity_data['sla_type_id'],
            'start_based' => $this->activity_data['start_based'],
            'activity_id' => $this->activity_data['activity_id'],
            'priority' => $this->activity_data['priority'],
        ]);

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSO('activity/' . $this->activity_data['activity_id'] . '/sla', $tokenized_payload, HttpMethod::DELETE);
            $this->dispatch('open-modal', id: 'show-json');
        }

    }
}
