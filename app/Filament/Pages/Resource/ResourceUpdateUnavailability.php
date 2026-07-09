<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResourceBasePage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use JsonException;

class ResourceUpdateUnavailability extends PSOResourceBasePage
{
    protected static ?string $title = 'Update Unavailability';

    protected static ?string $slug = 'resource-update-unavailability';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-document-text';

    protected string $view = 'filament.pages.resource-update-unavailability';

    public bool $isAuthenticationRequired = true;

    public function resource_form(Schema $form): Schema
    {

        return $form
            ->schema([

                Section::make('Unavailability Properties')
                    ->schema([
                        TextInput::make('category_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Category ID')
                            ->helperText('This value must exist in the ARP (resource data / unavailability categories)')
                            ->live()
                            ->afterStateUpdated(fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('base_time')
                            ->prefixIcon('heroicon-o-calendar-days') // Calendar for datetime
                            ->label('Base Date/Time'),
                        TextInput::make('duration')
                            ->prefixIcon('heroicon-o-clock') // Time duration
                            ->minValue(1)
                            ->maxValue(24)
                            ->numeric()
                            ->live(),
                        TextInput::make('time_zone')
                            ->label('Time Zone Offset')
                            ->prefixIcon('heroicon-o-globe-alt') // Globe for timezone
                            ->minValue(-24)
                            ->maxValue(24)
                            ->numeric()
                            ->live(),
                        TextInput::make('description')
                            ->prefixIcon('heroicon-s-document-text'),
                        Repeater::make('unavailabilities')
                            ->simple(
                                TextInput::make('unavailability_id')
                                    ->label('Unavailability ID')
                                    ->prefixIcon('heroicon-o-no-symbol') // Unavailability/blocked
                                    ->required(),

                            )->addActionLabel('Add another unavailability')
                            ->reorderable(false),

                        Actions::make([Action::make('update_unavailability')
                            ->label('Update Unavailabilities')
                            ->icon('heroicon-o-arrow-path') // Update/refresh
                            ->action(function () {
                                $this->updateUnavailability();
                            }),
                        ])->columnSpan(2),
                    ])
                    ->columns(),
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
                required: ['unavailability' => $this->resource_data['unavailability_id']],
                optional: ['time_zone' => $this->resource_data['time_zone'] ?? null,
                    'description' => $this->resource_data['description'] ?? null,
                    'duration' => $this->resource_data['duration'] ?? null,
                    'category_id' => $this->resource_data['category_id'] ?? null,
                    'base_time' => $this->resource_data['base_time'] ?? null, ],
            );

        $apiSegment = 'unavailability/'.$this->resource_data['resource_id'].'/unavailability';

        if (! $this->environment_data['send_to_pso']) {
            $this->response = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');

            return;
        }

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew($apiSegment, $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }

    }
}
