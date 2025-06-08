<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResourceBasePage;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use JsonException;


class ResourceUpdateUnavailability extends PSOResourceBasePage
{

    protected static ?string $title = 'Update Unavailablity';
    protected static ?string $slug = 'resource-update-unavailability';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.resource-update-unavailability';

    public bool $isAuthenticationRequired = true;

    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([

                Section::make('Unavailability Properties')
                    ->schema([
                        TextInput::make('category_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Category ID')
                            ->helperText('This value must exist in the ARP (resource data / unavailabilty categories)')
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('base_time')
                            ->label('Base Date/Time'),
                        TextInput::make('duration')
                            ->prefixIcon('heroicon-s-arrows-up-down')
                            ->minValue(1)
                            ->maxValue(24)
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
                            ->prefixIcon('heroicon-s-map'),
                        Repeater::make('unavailabiltiies')
                            ->simple(
                                TextInput::make('unavailability_id')
                                    ->label('Unavailability ID')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required(),

                            )->addActionLabel('Add another unavailability')
                            ->reorderable(false),

                        Actions::make([Actions\Action::make('update_unavailability')
                            ->label('Update Unavailabilities')
                            ->action(function () {
                                $this->updateUnavailability();
                            })
                        ])->columnSpan(2),
                    ])
                    ->columns()
            ])
            ->statePath('resource_data');
    }

    /**
     * @throws JsonException
     */
    public function updateUnavailability(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());


        $payload =

            $this->buildPayload(
                required: ['unavailability' => $this->resource_data['unavailability_id']], // todo see where the unavail ID is, I don't see it
                optional: ['time_zone' => $this->resource_data['time_zone'] ?? null,
                    'description' => $this->resource_data['description'] ?? null,
                    'duration' => $this->resource_data['duration'] ?? null,
                    'category_id' => $this->resource_data['category_id'] ?? null,
                    'base_time' => $this->resource_data['base_time'] ?? null,],
            );

//
//        $payload = array_merge(
//            $this->environnment_payload_data(),
//            array_filter([
//                'time_zone' => $this->resource_data['time_zone'] ?? null,
//                'description' => $this->resource_data['description'] ?? null,
//                'duration' => $this->resource_data['duration'] ?? null,
//                'category_id' => $this->resource_data['category_id'] ?? null,
//                'base_time' => $this->resource_data['base_time'] ?? null,
//            ])
//        );

        $apiSegment = 'unavailability/' . $this->resource_data['resource_id'] . '/unavailability';

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew($apiSegment, $tokenized_payload);
            $this->dispatch('json-updated'); // Add this line
            // todo this method is not complete
            $this->dispatch('open-modal', id: 'show-json');
        }


    }
}
