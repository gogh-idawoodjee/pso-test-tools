<?php

namespace App\Filament\Pages\Resource;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOResourceBasePage;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use JsonException;


class ResourceUpdateShift extends PSOResourceBasePage
{

    protected static ?string $title = 'Update Shift';
    protected static ?string $slug = 'resource-update-shift';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-update-shift';
    public bool $isAuthenticationRequired = true;

    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([

                Section::make('Shift Details')
                    ->schema([
                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Resource ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        TextInput::make('shift_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Shift ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        TextInput::make('shift_type')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Shift Type')
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('start_datetime')
                            ->label('Start Date/Time'),
                        DateTimePicker::make('end_datetime')
                            ->label('End Date/Time'),
                        Toggle::make('turn_manual_scheduling_on')
                            ->label('Manual Scheduling Only')
                            ->inline(false)
                            ->live(),


                        Actions::make([Actions\Action::make('update_shift')
                            ->label('Update Shift')
                            ->action(function () {
                                $this->updateShift();

                            })
                        ]),
                    ])
                    ->columns(3)
            ])
            ->statePath('resource_data');
    }

    /**
     * @throws JsonException
     */
    public function updateShift(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());


        $payload = $this->buildPayload(
            required: [
                'resource_id' => $this->resource_data['resource_id'],
                'shift_id' => $this->resource_data['shift_id'],
            ],
            optional: [
                'shift_type' => $this->resource_data['shift_type'] ?? null,
                'start_datetime' => $this->resource_data['start_datetime'] ?? null,
                'end_datetime' => $this->resource_data['end_datetime'] ?? null,
                'turn_manual_scheduling_on' => $this->resource_data['turn_manual_scheduling_on'] ?? null,
            ]
        );

        $apiSegment = 'resource/' . $this->resource_data['resource_id'] . '/shift';

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew($apiSegment, $tokenized_payload, [], HttpMethod::PATCH);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated'); // Add this line
            $this->dispatch('open-modal', id: 'show-json');
        }


    }
}
