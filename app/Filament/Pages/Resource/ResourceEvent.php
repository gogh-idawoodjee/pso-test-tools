<?php

namespace App\Filament\Pages\Resource;

use App\Enums\EventType;
use App\Filament\BasePages\PSOResourceBasePage;
use App\Traits\GeocCodeTrait;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;

use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use JsonException;


class ResourceEvent extends PSOResourceBasePage
{

    use GeocCodeTrait;

    // Navigation

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';

// Page Information
    protected static ?string $title = 'Generate Event';
    protected static ?string $slug = 'resource-event';

    protected static string $view = 'filament.pages.resource-event';


    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([
                // todo update API to make this a multi
                Section::make('Resource Event')
                    ->schema([
                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Resource ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Select::make('event_type')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Event Type')
                            ->enum(EventType::class)
                            ->options(EventType::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        DateTimePicker::make('event_date_time')
                            ->label('Event Date/Time')
                            ->helperText('Optional. Defaults to current datetime if not set.'),
                        Fieldset::make('location')
                            ->visible(static function (Get $get) {
                                return $get('event_type') === EventType::GPSFIX->value;
                            })
                            ->live()
                            ->label('Location')
                            ->schema([
                                TextInput::make('latitude')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required(static function (Get $get) {
                                        return $get('event_type') === EventType::GPSFIX->value;
                                    })
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required(static function (Get $get) {
                                        return $get('event_type') === EventType::GPSFIX->value;
                                    })
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address')
                                    ->prefixIcon('heroicon-s-map')
//                                    ->helperText('use an address and geocode it')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Actions\Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->action(function (Get $get, Set $set) {
                                                $this->geocodeFormAddress($get, $set);
                                            }))
                                    ->hint('click the map icon to geocode this!'),
                            ]),
                        Actions::make([
                            Actions\Action::make('generate_event')
                                ->label('Generate Event')
                                ->action(function () {
                                    $this->generateEvent();
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
    public function generateEvent(): void
    {
        $this->validateForms($this->getForms());


        $payload = array_merge(
            $this->environnment_payload_data(),
            [
                'resource_id' => $this->resource_data['resource_id'],
                'event_type' => $this->resource_data['event_type'],
            ],
            array_filter([
                'event_date_time' => $this->resource_data['event_date_time'] ?? null,
                'lat' => $this->resource_data['latitude'] ?? null,
                'long' => $this->resource_data['longitude'] ?? null
            ])
        );

        $this->response = $this->sendToPSO('resource/' . $this->resource_data['resource_id'] . '/event', $payload);
        $this->dispatch('open-modal', id: 'show-json');

    }


}
