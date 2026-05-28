<?php

namespace App\Filament\Pages\Resource;

use App\Enums\EventType;
use App\Filament\BasePages\PSOResourceBasePage;
use App\Support\GeocodeHelper;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use JsonException;

class ResourceEvent extends PSOResourceBasePage
{
    // Navigation

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-arrow-path';

    // Page Information
    protected static ?string $title = 'Generate Event';

    protected static ?string $slug = 'resource-event';

    protected string $view = 'filament.pages.resource-event';

    public function resource_form(Schema $form): Schema
    {

        return $form
            ->schema([

                Section::make('Resource Event')
                    ->schema([
                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-user')
                            ->label('Resource ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Select::make('event_type')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Event Type')
                            ->enum(EventType::class)
                            ->options(EventType::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('event_date_time')
                            ->label('Event Date/Time')
                            ->helperText('Optional. Defaults to current datetime if not set.'),
                        Fieldset::make('location')
                            ->visible(static function (Get $get) {
                                return $get('event_type') === EventType::GPSFIX;
                            })
                            ->live()
                            ->label('Location')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('latitude')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required(static function (Get $get) {
                                        return $get('event_type') === EventType::GPSFIX;
                                    })
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required(static function (Get $get) {
                                        return $get('event_type') === EventType::GPSFIX;
                                    })
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address')
                                    ->prefixIcon('heroicon-s-map')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->action(static function (Get $get, Set $set) {
                                                GeocodeHelper::geocodeFormAddress($get, $set);

                                            }))
                                    ->hint('click the map icon to geocode this!'),
                            ]),
                        Actions::make([
                            Action::make('generate_event')
                                ->label('Generate Event')
                                ->action(function () {
                                    $this->generateEvent();
                                }),
                        ]),
                    ])
                    ->columns(3),
            ])
            ->statePath('resource_data');
    }

    /**
     * @throws JsonException
     */
    public function generateEvent(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());

        $payload = $this->buildPayload(
            required: [
                'resourceId' => $this->resource_data['resource_id'],
                'eventType' => $this->resource_data['event_type'],
            ],
            optional: [
                'eventDateTime' => $this->resource_data['event_date_time'] ?? null,
                'lat' => $this->resource_data['latitude'] ?? null,
                'long' => $this->resource_data['longitude'] ?? null,
            ]
        );

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSONew('resource/'.$this->resource_data['resource_id'].'/event', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }

    }
}
