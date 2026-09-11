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
                        Action::make('random_nyc_spots')
                            ->label('🎲 Random NYC Spots')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->color('gray')
                            ->action(function (Set $set) {
                                [$from, $to] = collect(self::nycRestaurants())->random(2)->values()->all();

                                $set('address_from', "{$from['name']}, {$from['address']}");
                                $set('lat_from', $from['lat']);
                                $set('long_from', $from['lng']);

                                $set('address_to', "{$to['name']}, {$to['address']}");
                                $set('lat_to', $to['lat']);
                                $set('long_to', $to['lng']);
                            }),
                        Action::make('analyze_travel')
                            ->action(function (Get $get) {
                                $this->analyzeTravel($get);
                            }),
                    ])
                    ->columns(),
            ])->statePath('data');
    }

    /**
     * A small, hand-picked set of highly-rated NYC restaurants (Manhattan,
     * Brooklyn, Queens) with real addresses/coordinates baked in, so the
     * "Random NYC Spots" button can fill both address and lat/long instantly
     * without depending on a live geocoding call.
     *
     * @return array<int, array{name: string, address: string, lat: float, lng: float}>
     */
    private static function nycRestaurants(): array
    {
        return [
            // Manhattan
            ['name' => "Katz's Delicatessen", 'address' => '205 E Houston St, New York, NY 10002', 'lat' => 40.7223, 'lng' => -73.9874],
            ['name' => 'Le Bernardin', 'address' => '155 W 51st St, New York, NY 10019', 'lat' => 40.7614, 'lng' => -73.9814],
            ['name' => 'Via Carota', 'address' => '51 Grove St, New York, NY 10014', 'lat' => 40.7328, 'lng' => -74.0028],
            ['name' => "Joe's Pizza", 'address' => '7 Carmine St, New York, NY 10014', 'lat' => 40.7306, 'lng' => -74.0027],
            ['name' => 'Peter Luger Steak House', 'address' => '178 Broadway, Brooklyn, NY 11211', 'lat' => 40.7099, 'lng' => -73.9626],
            ['name' => 'Di Fara Pizza', 'address' => '1424 Avenue J, Brooklyn, NY 11230', 'lat' => 40.6251, 'lng' => -73.9616],
            ['name' => 'Lilia', 'address' => '567 Union Ave, Brooklyn, NY 11211', 'lat' => 40.7178, 'lng' => -73.9556],
            ['name' => "Roberta's", 'address' => '261 Moore St, Brooklyn, NY 11206', 'lat' => 40.7053, 'lng' => -73.9335],
            ['name' => 'Sripraphai', 'address' => '64-13 39th Ave, Woodside, NY 11377', 'lat' => 40.7502, 'lng' => -73.9021],
            ['name' => 'Casa Enrique', 'address' => '5-48 49th Ave, Long Island City, NY 11101', 'lat' => 40.7429, 'lng' => -73.9497],
            ['name' => 'M. Wells Steakhouse', 'address' => '43-15 Crescent St, Long Island City, NY 11101', 'lat' => 40.7462, 'lng' => -73.9433],
        ];
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
                    'callbackUrl' => $callbackUrl,
                ],
            ]
        );

        if ($tokenized_payload = $this->prepareTokenizedPayload($sendToPso, $payload)) {
            $this->response = $this->sendToPSONew('travelanalyzer', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');

            // pso-services generates the travel log id and returns it in the
            // initial response — we must key the cache off that id, not one
            // we invent ourselves, since that's the id DispatchTravelCallback
            // echoes back later.
            $decoded = json_decode($this->response, true, 512, JSON_THROW_ON_ERROR);
            $this->travelLogId = data_get($decoded, 'input_payload.dsScheduleData.Travel_Detail_Request.id');

            if ($this->travelLogId) {
                Cache::put("travel-analysis:{$this->travelLogId}", [
                    'status' => 'pending',
                ], now()->addMinutes(10));

                $this->isWaiting = true;
                $this->waitingStartedAt = now()->toIso8601String();

                Log::info('Travel analysis dispatched', ['travelLogId' => $this->travelLogId]);
            } else {
                Log::warning('Could not determine travelLogId from PSO services API response', ['response' => $this->response]);
            }
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
