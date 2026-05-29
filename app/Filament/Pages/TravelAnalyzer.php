<?php

namespace App\Filament\Pages;

use App\Models\Environment;
use App\Support\GeocodeHelper;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use UnitEnum;

class TravelAnalyzer extends Page
{
    use FormTrait;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'API Services';

    public ?array $data = [];

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Map;

    protected static ?string $navigationLabel = 'Travel Analyzer';

    protected static ?string $title = 'Travel Analyzer';

    protected string $view = 'filament.pages.travel-analyzer';

    public ?string $travelLogId = null;

    public ?array $travelResults = null;

    public bool $isWaiting = false;

    public ?string $waitingStartedAt = null;

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->env_form->fill();
    }

    protected function getForms(): array
    {
        return ['env_form', 'travel_form'];
    }

    public function travel_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Travel Details')
                    ->icon(Heroicon::Map)
                    ->schema([
                        Fieldset::make('from_details')
                            ->label('From Details')
                            ->schema([
                                TextInput::make('lat_from')
                                    ->prefixIcon(Heroicon::ArrowsUpDown)
                                    ->label('Latitude')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('long_from')
                                    ->label('Longitude')
                                    ->prefixIcon(Heroicon::ArrowsRightLeft)
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address_from')
                                    ->prefixIcon(Heroicon::Map)
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Action::make('geocode_address')
                                            ->icon(Heroicon::MapPin)
                                            ->action(static function (Get $get, Set $set) {
                                                GeocodeHelper::geocodeFormAddress(
                                                    $get,
                                                    $set,
                                                    'lat_from',
                                                    'long_from',
                                                    'address_from'
                                                );
                                            })
                                    )
                                    ->hint('click the map icon to geocode this!'),
                            ])->columnSpan(1),
                        Fieldset::make('to_details')
                            ->label('To Details')
                            ->schema([
                                TextInput::make('lat_to')
                                    ->prefixIcon(Heroicon::ArrowsUpDown)
                                    ->label('Latitude')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('long_to')
                                    ->label('Longitude')
                                    ->prefixIcon(Heroicon::ArrowsRightLeft)
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address_to')
                                    ->prefixIcon(Heroicon::Map)
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Action::make('geocode_address')
                                            ->icon(Heroicon::MapPin)
                                            ->action(static function (Get $get, Set $set) {
                                                GeocodeHelper::geocodeFormAddress(
                                                    $get,
                                                    $set,
                                                    'lat_to',
                                                    'long_to',
                                                    'address_to'
                                                );
                                            })
                                    )
                                    ->hint('click the map icon to geocode this!'),
                            ])->columnSpan(1),

                    ])
                    ->footerActions([
                        Action::make('analyze_travel')
                            ->action(function (Get $get) {
                                $this->analyzeTravel($get);
                            }),
                    ])
                    ->columns(),
            ])->statePath('data');
    }

    /**
     * @throws JsonException
     */
    public function analyzeTravel($get): void
    {
        $this->travelResults = null;
        $this->response = null;
        $this->validateForms($this->getForms());

        $sendToPso = $this->environment_data['send_to_pso'];
        $this->travelLogId = Str::uuid()->toString();

        $callbackUrl = route('travel.callback');

        $payload = array_merge(
            $this->environment_payload_data(),
            [
                'data' => [
                    'latTo' => $get('lat_to'),
                    'latFrom' => $get('lat_from'),
                    'longFrom' => $get('long_from'),
                    'longTo' => $get('long_to'),
                    'sendToPso' => $sendToPso,
                    'googleApiKey' => config('psott.google_api_key'),
                    'travelLogId' => $this->travelLogId,
                    'callbackUrl' => $callbackUrl,
                ],
            ]
        );

        if ($tokenized_payload = $this->prepareTokenizedPayload($sendToPso, $payload)) {
            // Reserve the cache key so the callback controller can validate it
            Cache::put("travel-analysis:{$this->travelLogId}", [
                'status' => 'pending',
            ], now()->addMinutes(10));

            $this->isWaiting = true;
            $this->waitingStartedAt = now()->toIso8601String();

            Log::info('Travel analysis dispatched', ['travelLogId' => $this->travelLogId]);

            $this->response = $this->sendToPSONew('travelanalyzer', $tokenized_payload);
            $this->dispatch('json-updated');
        }
    }

    public function checkTravelResults(): void
    {
        if (! $this->travelLogId || ! $this->isWaiting) {
            return;
        }

        // Check timeout (2 minutes)
        if ($this->waitingStartedAt && now()->diffInSeconds($this->waitingStartedAt) > 120) {
            Cache::forget("travel-analysis:{$this->travelLogId}");
            $this->isWaiting = false;
            $this->travelLogId = null;
            $this->waitingStartedAt = null;

            Notification::make()
                ->title('Travel Analysis Timed Out')
                ->body('No results were received within 2 minutes. Try again or check the raw JSON response.')
                ->danger()
                ->send();

            return;
        }

        $cached = Cache::get("travel-analysis:{$this->travelLogId}");

        if ($cached && ($cached['status'] ?? null) === 'complete') {
            $this->travelResults = $cached['results'] ?? [];
            $this->isWaiting = false;
            $this->waitingStartedAt = null;

            Cache::forget("travel-analysis:{$this->travelLogId}");

            Notification::make()
                ->title('Travel Analysis Complete')
                ->body('Results have been received and are displayed below.')
                ->success()
                ->send();
        }
    }

    public function cancelWaiting(): void
    {
        if ($this->travelLogId) {
            Cache::forget("travel-analysis:{$this->travelLogId}");
        }

        $this->isWaiting = false;
        $this->travelLogId = null;
        $this->waitingStartedAt = null;
        $this->travelResults = null;
    }
}
