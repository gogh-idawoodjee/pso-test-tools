<?php

namespace App\Filament\Pages\Modelling;

use App\Filament\BasePages\ModellingBasePage;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use JsonException;
use Override;

class ARPRegion extends ModellingBasePage
{
    protected string $view = 'filament.pages.modelling.arp-region';

    protected static ?string $navigationLabel = 'Generate Regions';

    protected static ?string $title = 'Generate Regions';

    protected static ?string $slug = 'arp-region';

    public ?array $region_data = [];

    #[Override]
    protected function getForms(): array
    {
        return ['env_form', 'region_form'];
    }

    public function region_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Regions')
                    ->description('Each row creates one region (RAM_Division) in the ARP.')
                    ->schema([
                        Repeater::make('regions')
                            ->hiddenLabel()
                            ->addActionLabel('Add Region')
                            ->required()
                            ->minItems(1)
                            ->schema([
                                TextInput::make('region_id')
                                    ->label('Region ID')
                                    ->prefixIcon(Heroicon::OutlinedTag)
                                    ->required(),
                                TextInput::make('description')
                                    ->label('Description')
                                    ->prefixIcon(Heroicon::OutlinedDocumentText)
                                    ->helperText('Leave blank to auto-generate from the Region ID.'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Options')
                    ->columns()
                    ->schema([
                        TextInput::make('region_parent')
                            ->label('Region Parent')
                            ->prefixIcon(Heroicon::OutlinedRectangleGroup)
                            ->helperText('Parent division id applied to every region above.'),
                        TextInput::make('region_category')
                            ->label('Region Category')
                            ->prefixIcon(Heroicon::OutlinedSquares2x2)
                            ->helperText('Division type id applied to every region above.'),
                        Toggle::make('send')
                            ->label('Send to Scheduling Engine')
                            ->helperText('Whether these divisions are sent through as regions.')
                            ->default(true),
                    ]),
                Actions::make([
                    Action::make('generate_regions')
                        ->label('Generate Regions')
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->action(function () {
                            $this->generateRegions();
                        }),
                ]),
            ])
            ->statePath('region_data');
    }

    /**
     * Builds the data.* fragment for the region-create request from raw
     * region_form state. Descriptions are only included if every row has
     * one filled in — the API only honours the descriptions array when its
     * length matches regions exactly, so a partial array would silently
     * misalign rather than falling back per-row.
     */
    public function buildRegionsData(array $regionData): array
    {
        $rows = collect($regionData['regions'] ?? []);
        $descriptions = $rows->pluck('description');

        return array_filter([
            'regions' => $rows->pluck('region_id')->all(),
            'descriptions' => $descriptions->every(fn ($description) => filled($description)) ? $descriptions->all() : null,
            'regionParent' => $regionData['region_parent'] ?? null,
            'regionCategory' => $regionData['region_category'] ?? null,
            'send' => $regionData['send'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @throws JsonException
     */
    public function generateRegions(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());

        $payload = $this->buildPayload(required: $this->buildRegionsData($this->region_data));

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew('region', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }
    }
}
