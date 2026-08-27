<?php

namespace App\Filament\Pages\Modelling;

use App\Filament\BasePages\ModellingBasePage;
use App\Support\GeocodeHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use JsonException;
use Override;

class ARPResource extends ModellingBasePage
{
    protected string $view = 'filament.pages.modelling.arp-resource';

    protected static ?string $navigationLabel = 'Generate Resources';

    protected static ?string $title = 'Generate Resources';

    protected static ?string $slug = 'arp-resources';

    public ?array $resource_data = [];

    #[Override]
    protected function getForms(): array
    {
        return ['env_form', 'resource_form'];
    }

    public function resource_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Resource Type')
                    ->columns()
                    ->schema([
                        TextInput::make('resource_type_id')
                            ->label('Resource Type ID')
                            ->prefixIcon(Heroicon::OutlinedWrenchScrewdriver)
                            ->required(),
                        TagsInput::make('skills')
                            ->label('Skills')
                            ->prefixIcon(Heroicon::OutlinedBookmark)
                            ->helperText('Applied to every resource below.'),
                        TagsInput::make('regions')
                            ->label('Regions')
                            ->prefixIcon(Heroicon::OutlinedGlobeAlt)
                            ->helperText('Applied to every resource below.'),
                    ]),
                Section::make('Resources')
                    ->description('Each row creates one resource (RAM_Resource + RAM_Location).')
                    ->schema([
                        Repeater::make('resources')
                            ->hiddenLabel()
                            ->addActionLabel('Add Resource')
                            ->required()
                            ->minItems(1)
                            ->schema([
                                TextInput::make('address')
                                    ->label('Address')
                                    ->prefixIcon('heroicon-s-map')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Action::make('geocode_address')
                                            ->icon(Heroicon::OutlinedMapPin)
                                            ->action(function (Get $get, Set $set) {
                                                GeocodeHelper::geocodeFormAddress($get, $set);
                                            })
                                    )
                                    ->hint('click the map icon to geocode this!'),
                                TextInput::make('latitude')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required()
                                    ->numeric()
                                    ->minValue(-90)
                                    ->maxValue(90),
                                TextInput::make('longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required()
                                    ->numeric()
                                    ->minValue(-180)
                                    ->maxValue(180),
                                TextInput::make('name')
                                    ->label('Name')
                                    ->prefixIcon(Heroicon::OutlinedUser)
                                    ->helperText('Leave blank to generate a random name.'),
                                TextInput::make('resource_id')
                                    ->label('Resource ID')
                                    ->prefixIcon(Heroicon::OutlinedHashtag)
                                    ->helperText('Leave blank to derive from the name.'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Actions::make([
                    Action::make('generate_resources')
                        ->label('Generate Resources')
                        ->icon(Heroicon::OutlinedUserGroup)
                        ->action(function () {
                            $this->generateResources();
                        }),
                ]),
            ])
            ->statePath('resource_data');
    }

    /**
     * Builds the data.* fragment for the resource-create request from raw
     * resource_form state. names/ids are only included if every row has one
     * filled in, since the API aligns them positionally with lat/long — a
     * partial array would misassign names/ids to the wrong resource rather
     * than falling back per-row.
     */
    public function buildResourcesData(array $resourceData): array
    {
        $rows = collect($resourceData['resources'] ?? []);
        $names = $rows->pluck('name');
        $ids = $rows->pluck('resource_id');

        return array_filter([
            'resourceTypeId' => $resourceData['resource_type_id'] ?? null,
            'lat' => $rows->pluck('latitude')->all(),
            'long' => $rows->pluck('longitude')->all(),
            'names' => $names->every(fn ($name) => filled($name)) ? $names->all() : null,
            'ids' => $ids->every(fn ($id) => filled($id)) ? $ids->all() : null,
            'skills' => filled($resourceData['skills'] ?? null) ? $resourceData['skills'] : null,
            'regions' => filled($resourceData['regions'] ?? null) ? $resourceData['regions'] : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @throws JsonException
     */
    public function generateResources(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());

        $payload = $this->buildPayload(required: $this->buildResourcesData($this->resource_data));

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew('resource', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }
    }
}
