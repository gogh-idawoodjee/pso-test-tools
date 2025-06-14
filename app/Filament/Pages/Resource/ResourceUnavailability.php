<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResourceBasePage;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use JsonException;


class ResourceUnavailability extends PSOResourceBasePage
{

    protected static ?string $title = 'Generate Unavailabiltiy';
    protected static ?string $slug = 'resource-unavailability';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.resource-unavailability';

    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([

                Section::make('Resource Unavailability')
                    ->schema([
                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-user')
                            ->label('Resource ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        TextInput::make('category_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Category ID')
                            ->helperText('This value must exist in the ARP (resource data / unavailabilty categories)')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('base_time')
                            ->label('Base Date/Time')
                            ->required(),
                        TextInput::make('duration')
                            ->label('Duration (minutes)')
                            ->prefixIcon('heroicon-s-arrows-up-down')
                            ->required()
                            ->minValue(5)
                            ->maxValue(1440)
                            ->numeric()
                            ->live(),
                        TextInput::make('time_zone')
                            ->label('Time Zone Offset')
                            ->prefixIcon('heroicon-s-arrows-up-down')
                            ->minValue(-24)
                            ->maxValue(24)
                            ->numeric()
                            ->live(),
                        TextInput::make('description')
                            ->prefixIcon('heroicon-s-document-text'),


                        Actions::make([Actions\Action::make('generate_event')
                            ->label('Generate Event')
                            ->action(function () {
                                $this->generateUnavailability();

                            })->slideOver()
                        ]),
                    ])
                    ->columns()
            ])
            ->statePath('resource_data');
    }

    /**
     * @throws JsonException
     */
    public function generateUnavailability(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());


        $payload = $this->buildPayload(
            required: [
                'resourceId' => $this->resource_data['resource_id'],
                'duration' => $this->resource_data['duration'],
                'categoryId' => $this->resource_data['category_id'],
                'baseDateTime' => $this->resource_data['base_time'],
            ],
            optional: [
                'timeZone' => $this->resource_data['time_zone'] ?? null,
                'description' => $this->resource_data['description'] ?? null,
            ]
        );


        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew('resource/unavailability', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated'); // Add this line
            $this->dispatch('open-modal', id: 'show-json');
        }


    }
}
