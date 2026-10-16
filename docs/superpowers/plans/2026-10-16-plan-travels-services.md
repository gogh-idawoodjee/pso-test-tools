# Plan travels: pso-services Google Routes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One Google Routes implementation in pso-services with a shared database cache: a `POST /api/v2/routes/compute` endpoint that pso-test-tools' Plan travels tool calls in chunks of up to 200 legs, a one-off seed command that loads the PowerShell script's cache CSV, and the Travel Analyzer moved off the Distance Matrix API onto the same client and cache (#27, #43).

**Architecture:** `GoogleRoutesClient` (app/Classes/V2) makes exactly one `computeRoutes` call and returns a `RouteResult` DTO. `GoogleRoutesService` (app/Services/V2) looks each leg up in the `google_routes` table first (key: six coordinates rounded to 6 dp as strings, plus mode and preference), calls the client on a miss with a configurable pause between Google calls, and stores successes with a duration above 0. The new `RouteController::compute` validates with `RouteComputeRequest`, answers 503 when no server key is set, and returns per-leg results and counts. `TravelService` swaps `getDistanceMatrix` for `getGoogleRoute`, which goes through `GoogleRoutesService` with `DRIVE` / `TRAFFIC_UNAWARE` and rebuilds the Distance Matrix element shape (`distance.text/value`, `duration.text/value`, `status`) so `PSOTravelLog`, `getTravelResults` and the pso-test-tools page are unchanged. The artisan command `google-routes:seed <csv>` bulk-inserts the CSV with `insertOrIgnore`.

**Tech Stack:** Laravel 13 (PHP ^8.3; the Mac runs 8.5, the box mod_php 8.4, MariaDB), Pest 5 with `pest-plugin-laravel`, SQLite in-memory for tests (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`; `tests/Pest.php` applies `RefreshDatabase` to Feature and Unit), Scramble for OpenAPI, Pint.

**Spec:** pso-test-tools `docs/superpowers/specs/2026-10-16-plan-travels-design.md`, section 5 (sections 2, 3 and 7 for context). Behaviour reference for the Google request and cache key: `docs/superpowers/notes/2026-10-16-plan-travels-inventory.md` sections 7 and 8.

Repository: `/Users/idawoodjee/Herd/pso-services`, branch `feat/google-routes` from `main` (clean at `e77e1fd`). All tasks are in this repo.

## Global Constraints

- Conventions copied from the code, not invented: routes go in `routes/api_v2.php`, which `bootstrap/app.php` mounts under `api/v2` with `['api', 'throttle:api']` (300 per minute per IP, `config/api.php`). The general limiter counts one hit per HTTP request, so a 200-leg request is one hit; no per-leg limiter. No `DisallowProdUrl` (no PSO URL is involved). Controllers live in `app/Http/Controllers/Api/V2`, carry Scramble `#[Group(...)]` and `#[Response(...)]` attributes (as `SessionController`), and type-hint a FormRequest from `app/Http/Requests/Api/V2`. The new request extends `Illuminate\Foundation\Http\FormRequest` directly with `authorize(): true` (the `BaseFormRequest` / `BaseGetFormRequest` parents add PSO environment rules that do not apply). Validation failures are Laravel's default JSON 422 `{message, errors}` (no `failedValidation` override exists anywhere in `app/`; `shouldRenderJsonWhen` in `bootstrap/app.php` makes every `api/*` error JSON).
- The endpoint's request and response JSON are exactly the spec's (see Task 4 Interfaces). pso-test-tools consumes them; do not rename keys or wrap them in `data`.
- Google request exactly as inventory section 7: `POST https://routes.googleapis.com/directions/v2:computeRoutes`, headers `X-Goog-Api-Key` and `X-Goog-FieldMask: routes.duration,routes.distanceMeters` (plus JSON content type and accept), body `{origin:{location:{latLng:{latitude,longitude}}}, destination:{...}, travelMode, routingPreference, units:"METRIC"}`. Duration `"Ns"` parsed to int seconds; `distanceMeters / 1000` rounded to 2. Errors: `No route returned`, or `HTTP <status>: <Google error.message or body>`, or the connection exception message. Timeout 10 s, connect timeout 10 s, no retry.
- One deliberate deviation from the script, to confirm with Ish at review: `routingPreference` is sent only for `DRIVE` and `TWO_WHEELER`, because Google rejects the field for `WALK`, `BICYCLE` and `TRANSIT` (inference from the Routes API reference, not tested against Google). The cache key still stores the preference given. Test: Task 2 `omits routingPreference for modes Google refuses it on`.
- Delay `GOOGLE_ROUTES_DELAY_MS` (default 150) is applied between Google calls only: never before the first Google call of a request, never for a cache hit. Implemented with `Illuminate\Support\Sleep` so tests use `Sleep::fake()`.
- Cache: `google_routes` stores coordinates, mode, preference, duration, distance, fetched time and source. No activity, resource, customer or key data. Only results with `duration_sec > 0` are stored; errors never.
- Key columns are always written and queried as six-decimal strings (`number_format(round($v, 6), 6, '.', '')`), so SQLite (NUMERIC affinity) and MariaDB (DECIMAL) both compare exactly.
- The Google key never leaves pso-services and is never logged. The client takes it as a `#[SensitiveParameter]`.
- No em dashes in code, comments, messages, README or commit messages.
- Before every commit: `vendor/bin/pint --dirty`, then the task's tests. Commit messages follow the repo's `Area: what changed` style and end with the attribution lines the executing session is given.
- Test runner: `vendor/bin/pest <path>`; the whole suite with `vendor/bin/pest`.

## Review Focus

1. Coordinates that differ only past the sixth decimal must hit the same cache row, and key columns must be compared as six-decimal strings on both SQLite and MariaDB. (Task 1 test `formats key coordinates as six-decimal strings`; Task 4 test `rounds coordinates to six decimals for the cache lookup`.)
2. A Google error must come back on its own leg and never be cached; a 0-second route is returned but not stored. (Task 4 test `returns a Google error per leg and does not cache it`; Task 3 test `does not store a zero-second route`.)
3. The pause sits between Google calls only: not before the first call, not around cache hits, and not at all when the delay is 0. (Task 3 tests `sleeps only between Google calls` and `never sleeps when the delay is 0`.)
4. The Travel Analyzer must store the same Distance Matrix element shape and keep every result key the page renders, and must now route From to To (the old Distance Matrix call sent `origins` = To and `destinations` = From). (Task 6 tests `stores a Distance Matrix shaped google_response from Google Routes` and `keeps the result keys the Travel Analyzer page renders`.)
5. The seed must read the real cache CSV: a UTF-8 BOM on the header, quoted values, `FetchedUtc` holding `seeded:<file>` instead of a date, duplicate keys, and keys already in the table (kept, never overwritten). (Task 5 tests `seeds a three-row csv with one duplicate` and `keeps an existing row and strips the byte order mark`.)

---

## File structure

Create:
- `database/migrations/2026_10_16_000000_create_google_routes_table.php` the cache table.
- `app/Models/V2/GoogleRoute.php` model, key builder `keyColumns()`, `coord()`, `lookup()`.
- `app/DataTransferObjects/RouteResult.php` one leg's outcome (readonly, like `PsoContext`).
- `app/Classes/V2/GoogleRoutesClient.php` the single Google call (next to `PsoClient`).
- `app/Services/V2/GoogleRoutesService.php` cache first, then Google, with the delay.
- `app/Http/Requests/Api/V2/RouteComputeRequest.php` validation.
- `app/Http/Controllers/Api/V2/RouteController.php` `compute`.
- `app/Console/Commands/SeedGoogleRoutes.php` `google-routes:seed {csv}` (Laravel 13 auto-registers `app/Console/Commands`; the folder does not exist yet).
- `app/Helpers/DistanceMatrixText.php` the Distance Matrix style text forms for the Travel Analyzer.
- Tests: `tests/Unit/Models/GoogleRouteTest.php`, `tests/Unit/Classes/V2/GoogleRoutesClientTest.php`, `tests/Unit/Services/V2/GoogleRoutesServiceTest.php`, `tests/Feature/Api/V2/RouteControllerTest.php`, `tests/Feature/Console/SeedGoogleRoutesTest.php`, `tests/Unit/Helpers/DistanceMatrixTextTest.php`, `tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php`.

Modify:
- `config/pso-services.php` (`settings.google_routes_delay_ms` next to `google_key`, line 111).
- `.env.example` (`GOOGLE_ROUTES_DELAY_MS=150` under the Google block, line 28).
- `routes/api_v2.php` (one route).
- `app/Services/V2/TravelService.php` (constructor, lines 79 to 90, replace lines 270 to 333).
- `README.md` (a `POST /api/v2/routes/compute` section after the session section, and the seed command).

---

### Task 1: Branch, config, migration and the GoogleRoute model

**Files:**
- Create: `database/migrations/2026_10_16_000000_create_google_routes_table.php`, `app/Models/V2/GoogleRoute.php`, `tests/Unit/Models/GoogleRouteTest.php`
- Modify: `config/pso-services.php`, `.env.example`

**Interfaces (Produces):**
```php
// app/Models/V2/GoogleRoute.php
class GoogleRoute extends Model
{
    public const KEY_COLUMNS = ['origin_lat', 'origin_lng', 'dest_lat', 'dest_lng', 'travel_mode', 'routing_preference'];
    public static function coord(float $value): string;          // '52.100000'
    public static function keyColumns(float $oLat, float $oLng, float $dLat, float $dLng, string $mode, string $preference): array; // column => string
    public static function lookup(array $key): ?self;
    // casts: coordinates float, duration_sec integer, distance_km float, fetched_at datetime; no timestamps
}
// config('pso-services.settings.google_routes_delay_ms'): int, default 150
```

- [ ] **Step 1: Branch**

```bash
cd /Users/idawoodjee/Herd/pso-services
git checkout main && git pull --ff-only
git checkout -b feat/google-routes
```

- [ ] **Step 2: Failing model test**

`tests/Unit/Models/GoogleRouteTest.php`:
```php
<?php

use App\Models\V2\GoogleRoute;

function googleRouteRow(array $overrides = []): array
{
    return array_replace(GoogleRoute::keyColumns(52.851108, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE') + [
        'duration_sec' => 4374,
        'distance_km' => '117.04',
        'fetched_at' => '2026-10-16 08:00:00',
        'source' => 'google',
    ], $overrides);
}

it('formats key coordinates as six-decimal strings', function () {
    expect(GoogleRoute::coord(52.1000004))->toBe('52.100000')
        ->and(GoogleRoute::coord(-106.6))->toBe('-106.600000')
        ->and(GoogleRoute::coord(52.8511086))->toBe('52.851109')
        ->and(GoogleRoute::keyColumns(52.1, -106.6, 52.2, -106.7, 'DRIVE', 'TRAFFIC_UNAWARE'))->toBe([
            'origin_lat' => '52.100000',
            'origin_lng' => '-106.600000',
            'dest_lat' => '52.200000',
            'dest_lng' => '-106.700000',
            'travel_mode' => 'DRIVE',
            'routing_preference' => 'TRAFFIC_UNAWARE',
        ]);
});

it('allows one row per key through the unique index', function () {
    expect(GoogleRoute::query()->insertOrIgnore([googleRouteRow()]))->toBe(1)
        ->and(GoogleRoute::query()->insertOrIgnore([googleRouteRow(['duration_sec' => 9999])]))->toBe(0)
        ->and(GoogleRoute::query()->count())->toBe(1)
        ->and(GoogleRoute::query()->first()->duration_sec)->toBe(4374);
});

it('treats a different mode or preference as a different key', function () {
    GoogleRoute::query()->insertOrIgnore([
        googleRouteRow(),
        googleRouteRow(['travel_mode' => 'WALK']),
        googleRouteRow(['routing_preference' => 'TRAFFIC_AWARE']),
    ]);

    expect(GoogleRoute::query()->count())->toBe(3);
});

it('looks a row up by its key and casts the values', function () {
    GoogleRoute::query()->insertOrIgnore([googleRouteRow()]);

    $found = GoogleRoute::lookup(GoogleRoute::keyColumns(52.8511084, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE'));

    expect($found)->not->toBeNull()
        ->and($found->duration_sec)->toBe(4374)
        ->and($found->distance_km)->toBe(117.04)
        ->and($found->origin_lat)->toBe(52.851108)
        ->and($found->source)->toBe('google')
        ->and(GoogleRoute::lookup(GoogleRoute::keyColumns(52.851108, -102.391453, 52.84367, -104.05325, 'WALK', 'TRAFFIC_UNAWARE')))->toBeNull();
});
```

- [ ] **Step 3: Run to fail**

`vendor/bin/pest tests/Unit/Models/GoogleRouteTest.php`. Expected: fails with `Class "App\Models\V2\GoogleRoute" not found`.

- [ ] **Step 4: Migration**

`database/migrations/2026_10_16_000000_create_google_routes_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Shared Google Routes cache: coordinates and times only, no activity, resource or customer data.
        Schema::create('google_routes', function (Blueprint $table) {
            $table->id();
            $table->decimal('origin_lat', 9, 6);
            $table->decimal('origin_lng', 9, 6);
            $table->decimal('dest_lat', 9, 6);
            $table->decimal('dest_lng', 9, 6);
            $table->string('travel_mode', 20);
            $table->string('routing_preference', 30);
            $table->unsignedInteger('duration_sec');
            $table->decimal('distance_km', 8, 2);
            $table->timestamp('fetched_at')->nullable();
            $table->string('source');
            $table->unique(
                ['origin_lat', 'origin_lng', 'dest_lat', 'dest_lng', 'travel_mode', 'routing_preference'],
                'google_routes_key_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_routes');
    }
};
```
`fetched_at` is nullable because seeded rows carry `seeded:<file>` in `FetchedUtc`, not a time.

- [ ] **Step 5: Model**

`app/Models/V2/GoogleRoute.php`:
```php
<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One cached Google Routes answer. The key is the four coordinates rounded to
 * six decimals (the PowerShell script's F6 key) plus travel mode and routing
 * preference. Key values are always bound as six-decimal strings so SQLite and
 * MariaDB compare them exactly.
 *
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class GoogleRoute extends Model
{
    public const KEY_COLUMNS = ['origin_lat', 'origin_lng', 'dest_lat', 'dest_lng', 'travel_mode', 'routing_preference'];

    public $timestamps = false;

    protected $table = 'google_routes';

    protected $fillable = [
        'origin_lat',
        'origin_lng',
        'dest_lat',
        'dest_lng',
        'travel_mode',
        'routing_preference',
        'duration_sec',
        'distance_km',
        'fetched_at',
        'source',
    ];

    protected $casts = [
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'dest_lat' => 'float',
        'dest_lng' => 'float',
        'duration_sec' => 'integer',
        'distance_km' => 'float',
        'fetched_at' => 'datetime',
    ];

    public static function coord(float $value): string
    {
        return number_format(round($value, 6), 6, '.', '');
    }

    /**
     * @return array<string, string>
     */
    public static function keyColumns(float $oLat, float $oLng, float $dLat, float $dLng, string $mode, string $preference): array
    {
        return [
            'origin_lat' => self::coord($oLat),
            'origin_lng' => self::coord($oLng),
            'dest_lat' => self::coord($dLat),
            'dest_lng' => self::coord($dLng),
            'travel_mode' => $mode,
            'routing_preference' => $preference,
        ];
    }

    /**
     * @param  array<string, string>  $key  from keyColumns()
     */
    public static function lookup(array $key): ?self
    {
        return static::query()->where($key)->first();
    }
}
```

- [ ] **Step 6: Config and .env.example**

In `config/pso-services.php`, after `'google_api_passthrough' => env('GOOGLE_API_PASSTHROUGH'),` add:
```php

        // Pause between Google Routes calls within one request, in milliseconds.
        // Cache hits never wait. 0 turns the pause off.
        'google_routes_delay_ms' => (int) env('GOOGLE_ROUTES_DELAY_MS', 150),
```
In `.env.example`, after `GOOGLE_API_PASSTHROUGH=` add `GOOGLE_ROUTES_DELAY_MS=150`.

- [ ] **Step 7: Run to pass**

`vendor/bin/pest tests/Unit/Models/GoogleRouteTest.php`. Expected: 4 passed (`RefreshDatabase` has run the migration on SQLite). Then `php artisan migrate --pretend` against the local dev database: expected a `create table "google_routes"` statement and the `google_routes_key_unique` index.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Unit/Models/GoogleRouteTest.php
git add database/migrations/2026_10_16_000000_create_google_routes_table.php app/Models/V2/GoogleRoute.php tests/Unit/Models/GoogleRouteTest.php config/pso-services.php .env.example
git commit -m "Google Routes: google_routes cache table and model keyed on six-decimal coordinates, mode and preference"
```

---

### Task 2: RouteResult and GoogleRoutesClient

**Files:**
- Create: `app/DataTransferObjects/RouteResult.php`, `app/Classes/V2/GoogleRoutesClient.php`, `tests/Unit/Classes/V2/GoogleRoutesClientTest.php`

**Interfaces (Consumes):** nothing from Task 1.

**Interfaces (Produces):**
```php
// app/DataTransferObjects/RouteResult.php
readonly class RouteResult
{
    public function __construct(
        public ?int $durationSec = null, public ?float $distanceKm = null, public ?string $source = null, // 'cache' | 'google'
        public ?string $error = null, public ?int $httpStatus = null, public ?string $googleStatus = null,
    ) {}
    public static function ok(int $durationSec, float $distanceKm, string $source): self;
    public static function failed(string $error, ?int $httpStatus = null, ?string $googleStatus = null): self;
    public function succeeded(): bool;
    public function toArray(string $id): array; // {id, durationSec, distanceKm, source} or {id, error}
}

// app/Classes/V2/GoogleRoutesClient.php
class GoogleRoutesClient
{
    public const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    public const FIELD_MASK = 'routes.duration,routes.distanceMeters';
    public const TIMEOUT_SECONDS = 10;
    public const NO_ROUTE = 'No route returned';
    public const MODES = ['DRIVE', 'WALK', 'BICYCLE', 'TWO_WHEELER', 'TRANSIT'];
    public const PREFERENCES = ['TRAFFIC_UNAWARE', 'TRAFFIC_AWARE', 'TRAFFIC_AWARE_OPTIMAL'];
    public function route(float $oLat, float $oLng, float $dLat, float $dLng, string $mode, string $preference, string $apiKey): RouteResult; // source 'google' on success
}
```

- [ ] **Step 1: Failing client tests**

`tests/Unit/Classes/V2/GoogleRoutesClientTest.php`:
```php
<?php

use App\Classes\V2\GoogleRoutesClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function callRoute(string $mode = 'DRIVE', string $preference = 'TRAFFIC_UNAWARE')
{
    return app(GoogleRoutesClient::class)->route(52.851108, -102.391453, 52.84367, -104.05325, $mode, $preference, 'test-key');
}

it('sends the exact computeRoutes request', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);

    callRoute();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
        && $request->hasHeader('X-Goog-Api-Key', 'test-key')
        && $request->hasHeader('X-Goog-FieldMask', 'routes.duration,routes.distanceMeters')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() == [
            'origin' => ['location' => ['latLng' => ['latitude' => 52.851108, 'longitude' => -102.391453]]],
            'destination' => ['location' => ['latLng' => ['latitude' => 52.84367, 'longitude' => -104.05325]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'units' => 'METRIC',
        ]);
});

it('parses the duration and distance', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '432s', 'distanceMeters' => 3352]]])]);

    $result = callRoute();

    expect($result->succeeded())->toBeTrue()
        ->and($result->durationSec)->toBe(432)
        ->and($result->distanceKm)->toBe(3.35)
        ->and($result->source)->toBe('google')
        ->and($result->toArray('L1'))->toBe(['id' => 'L1', 'durationSec' => 432, 'distanceKm' => 3.35, 'source' => 'google']);
});

it('omits routingPreference for modes Google refuses it on', function (string $mode, bool $sent) {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '60s', 'distanceMeters' => 100]]])]);

    callRoute($mode);

    Http::assertSent(fn (Request $request) => array_key_exists('routingPreference', $request->data()) === $sent
        && $request['travelMode'] === $mode);
})->with([
    'DRIVE' => ['DRIVE', true],
    'TWO_WHEELER' => ['TWO_WHEELER', true],
    'WALK' => ['WALK', false],
    'BICYCLE' => ['BICYCLE', false],
    'TRANSIT' => ['TRANSIT', false],
]);

it('reports No route returned for an empty answer', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response([])]);

    $result = callRoute();

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toBe('No route returned')
        ->and($result->toArray('L1'))->toBe(['id' => 'L1', 'error' => 'No route returned']);
});

it('reports the HTTP status and Google message on a failure, without retrying', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => [
        'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT',
    ]], 400)]);

    $result = callRoute();

    expect($result->error)->toBe('HTTP 400: API key not valid. Please pass a valid API key.')
        ->and($result->httpStatus)->toBe(400)
        ->and($result->googleStatus)->toBe('INVALID_ARGUMENT');
    Http::assertSentCount(1);
});

it('reports the HTTP status and body when the body is not Google JSON', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response('upstream down', 502)]);

    expect(callRoute()->error)->toBe('HTTP 502: upstream down');
    Http::assertSentCount(1);
});

it('reports a connection failure as its message', function () {
    Http::fake(['routes.googleapis.com/*' => static function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    }]);

    $result = callRoute();

    expect($result->error)->toBe('cURL error 28: Operation timed out')
        ->and($result->httpStatus)->toBeNull();
});
```

- [ ] **Step 2: Run to fail**

`vendor/bin/pest tests/Unit/Classes/V2/GoogleRoutesClientTest.php`. Expected: fails with `Class "App\Classes\V2\GoogleRoutesClient" not found`.

- [ ] **Step 3: RouteResult**

`app/DataTransferObjects/RouteResult.php`:
```php
<?php

namespace App\DataTransferObjects;

/**
 * One leg's Google Routes outcome: a duration and distance from the cache or
 * Google, or an error string.
 */
readonly class RouteResult
{
    public function __construct(
        public ?int $durationSec = null,
        public ?float $distanceKm = null,
        public ?string $source = null,
        public ?string $error = null,
        public ?int $httpStatus = null,
        public ?string $googleStatus = null,
    ) {}

    public static function ok(int $durationSec, float $distanceKm, string $source): self
    {
        return new self(durationSec: $durationSec, distanceKm: $distanceKm, source: $source);
    }

    public static function failed(string $error, ?int $httpStatus = null, ?string $googleStatus = null): self
    {
        return new self(error: $error, httpStatus: $httpStatus, googleStatus: $googleStatus);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    /**
     * The endpoint's per-leg shape.
     */
    public function toArray(string $id): array
    {
        if (! $this->succeeded()) {
            return ['id' => $id, 'error' => $this->error];
        }

        return [
            'id' => $id,
            'durationSec' => $this->durationSec,
            'distanceKm' => $this->distanceKm,
            'source' => $this->source,
        ];
    }
}
```

- [ ] **Step 4: GoogleRoutesClient**

`app/Classes/V2/GoogleRoutesClient.php`:
```php
<?php

namespace App\Classes\V2;

use App\DataTransferObjects\RouteResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * One Google Routes computeRoutes call, the same request the PowerShell
 * Build-PlanTravelsReport.ps1 makes. No cache and no retry here: see
 * GoogleRoutesService.
 */
class GoogleRoutesClient
{
    public const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public const FIELD_MASK = 'routes.duration,routes.distanceMeters';

    public const TIMEOUT_SECONDS = 10;

    public const NO_ROUTE = 'No route returned';

    public const MODES = ['DRIVE', 'WALK', 'BICYCLE', 'TWO_WHEELER', 'TRANSIT'];

    public const PREFERENCES = ['TRAFFIC_UNAWARE', 'TRAFFIC_AWARE', 'TRAFFIC_AWARE_OPTIMAL'];

    // Google rejects routingPreference for the other modes.
    private const MODES_WITH_PREFERENCE = ['DRIVE', 'TWO_WHEELER'];

    public function route(
        float $oLat,
        float $oLng,
        float $dLat,
        float $dLng,
        string $mode,
        string $preference,
        #[SensitiveParameter] string $apiKey,
    ): RouteResult {
        $body = [
            'origin' => ['location' => ['latLng' => ['latitude' => $oLat, 'longitude' => $oLng]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $dLat, 'longitude' => $dLng]]],
            'travelMode' => $mode,
        ];
        if (in_array($mode, self::MODES_WITH_PREFERENCE, true)) {
            $body['routingPreference'] = $preference;
        }
        $body['units'] = 'METRIC';

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            Log::warning('Google Routes connection failed', ['message' => $e->getMessage()]);

            return RouteResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = $response->json('error.message');
            $message = is_string($message) ? $message : Str::limit(trim($response->body()), 300);
            Log::warning('Google Routes request failed', ['status' => $status, 'message' => $message]);

            return RouteResult::failed(
                $message === '' ? "HTTP {$status}" : "HTTP {$status}: {$message}",
                $status,
                is_string($response->json('error.status')) ? $response->json('error.status') : null,
            );
        }

        $route = $response->json('routes.0');
        if (! is_array($route) || ! isset($route['duration'])) {
            return RouteResult::failed(self::NO_ROUTE);
        }

        $seconds = (int) round((float) rtrim((string) $route['duration'], 's'));
        $km = round(((float) ($route['distanceMeters'] ?? 0)) / 1000, 2);

        return RouteResult::ok($seconds, $km, 'google');
    }
}
```

- [ ] **Step 5: Run to pass**

`vendor/bin/pest tests/Unit/Classes/V2/GoogleRoutesClientTest.php`. Expected: 11 passed (the dataset counts five).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Unit/Classes/V2/GoogleRoutesClientTest.php
git add app/DataTransferObjects/RouteResult.php app/Classes/V2/GoogleRoutesClient.php tests/Unit/Classes/V2/GoogleRoutesClientTest.php
git commit -m "Google Routes: client making the script's computeRoutes request with a 10 s timeout and no retry"
```

---

### Task 3: GoogleRoutesService (cache first, then Google, with the delay)

**Files:**
- Create: `app/Services/V2/GoogleRoutesService.php`, `tests/Unit/Services/V2/GoogleRoutesServiceTest.php`

**Interfaces (Consumes):** `GoogleRoute::keyColumns()`, `GoogleRoute::lookup()` (Task 1); `GoogleRoutesClient::route()`, `RouteResult` (Task 2); `config('pso-services.settings.google_routes_delay_ms')`.

**Interfaces (Produces):**
```php
class GoogleRoutesService
{
    public function __construct(GoogleRoutesClient $client);
    /** @param list<array{id: string, origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}> $legs
     *  @return array{results: list<array>, counts: array{cache: int, google: int, error: int}} */
    public function compute(array $legs, string $mode, string $preference, string $apiKey): array;
    public function route(float $oLat, float $oLng, float $dLat, float $dLng, string $mode, string $preference, string $apiKey): RouteResult;
}
```
`route()` is what the Travel Analyzer uses (Task 6). The "has called Google" flag resets at the start of `compute()`; a single `route()` call never sleeps before its only Google call.

- [ ] **Step 1: Failing service tests**

`tests/Unit/Services/V2/GoogleRoutesServiceTest.php`:
```php
<?php

use App\Models\V2\GoogleRoute;
use App\Services\V2\GoogleRoutesService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function serviceLeg(string $id, float $oLat, float $oLng = -106.6, float $dLat = 52.2, float $dLng = -106.7): array
{
    return ['id' => $id, 'origin' => ['lat' => $oLat, 'lng' => $oLng], 'destination' => ['lat' => $dLat, 'lng' => $dLng]];
}

function cacheRoute(float $oLat, float $oLng = -106.6, float $dLat = 52.2, float $dLng = -106.7, int $seconds = 600, string $km = '10.00'): void
{
    GoogleRoute::query()->insertOrIgnore([GoogleRoute::keyColumns($oLat, $oLng, $dLat, $dLng, 'DRIVE', 'TRAFFIC_UNAWARE') + [
        'duration_sec' => $seconds, 'distance_km' => $km, 'fetched_at' => null, 'source' => 'seeded:test.csv',
    ]]);
}

beforeEach(function () {
    config(['pso-services.settings.google_routes_delay_ms' => 150]);
    Sleep::fake();
});

it('sleeps only between Google calls', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '300s', 'distanceMeters' => 5000]]])]);
    cacheRoute(52.11);

    $out = app(GoogleRoutesService::class)->compute([
        serviceLeg('A', 52.10),   // Google, no pause before the first call
        serviceLeg('B', 52.11),   // cache, no pause
        serviceLeg('C', 52.12),   // Google, pause
        serviceLeg('D', 52.13),   // Google, pause
    ], 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    expect($out['counts'])->toBe(['cache' => 1, 'google' => 3, 'error' => 0]);
    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(150)->milliseconds(),
        Sleep::for(150)->milliseconds(),
    ]);
});

it('never sleeps when the delay is 0', function () {
    config(['pso-services.settings.google_routes_delay_ms' => 0]);
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '300s', 'distanceMeters' => 5000]]])]);

    app(GoogleRoutesService::class)->compute([serviceLeg('A', 52.10), serviceLeg('B', 52.12)], 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    Sleep::assertNeverSlept();
});

it('fetches a repeated trip once within a request', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '300s', 'distanceMeters' => 5000]]])]);

    $out = app(GoogleRoutesService::class)->compute([serviceLeg('A', 52.10), serviceLeg('B', 52.10)], 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    expect($out['results'][1])->toBe(['id' => 'B', 'durationSec' => 300, 'distanceKm' => 5.0, 'source' => 'cache'])
        ->and($out['counts'])->toBe(['cache' => 1, 'google' => 1, 'error' => 0]);
    Http::assertSentCount(1);
});

it('stores a successful Google answer with its source and time', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);

    app(GoogleRoutesService::class)->route(52.851108, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    $row = GoogleRoute::query()->sole();
    expect($row->duration_sec)->toBe(4374)
        ->and($row->distance_km)->toBe(117.04)
        ->and($row->source)->toBe('google')
        ->and($row->fetched_at)->not->toBeNull();
    Sleep::assertNeverSlept();
});

it('does not store a zero-second route', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '0s', 'distanceMeters' => 0]]])]);

    $result = app(GoogleRoutesService::class)->route(52.1, -106.6, 52.1, -106.6, 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    expect($result->succeeded())->toBeTrue()
        ->and($result->durationSec)->toBe(0)
        ->and(GoogleRoute::query()->count())->toBe(0);
});

it('counts errors and caches nothing for them', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response([], 200)]);

    $out = app(GoogleRoutesService::class)->compute([serviceLeg('A', 52.10)], 'DRIVE', 'TRAFFIC_UNAWARE', 'k');

    expect($out)->toBe([
        'results' => [['id' => 'A', 'error' => 'No route returned']],
        'counts' => ['cache' => 0, 'google' => 0, 'error' => 1],
    ])->and(GoogleRoute::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to fail**

`vendor/bin/pest tests/Unit/Services/V2/GoogleRoutesServiceTest.php`. Expected: fails with `Class "App\Services\V2\GoogleRoutesService" not found`.

- [ ] **Step 3: Implement**

`app/Services/V2/GoogleRoutesService.php`:
```php
<?php

namespace App\Services\V2;

use App\Classes\V2\GoogleRoutesClient;
use App\DataTransferObjects\RouteResult;
use App\Models\V2\GoogleRoute;
use Illuminate\Support\Sleep;
use SensitiveParameter;

/**
 * Google Routes through the shared google_routes cache: every leg is looked up
 * first, misses go to Google one at a time with a pause between Google calls,
 * and answers with a duration above 0 are stored. Errors are never stored.
 */
class GoogleRoutesService
{
    private bool $calledGoogle = false;

    public function __construct(private readonly GoogleRoutesClient $client) {}

    /**
     * @param  list<array{id: string, origin: array{lat: float|string, lng: float|string}, destination: array{lat: float|string, lng: float|string}}>  $legs
     * @return array{results: list<array<string, mixed>>, counts: array{cache: int, google: int, error: int}}
     */
    public function compute(array $legs, string $mode, string $preference, #[SensitiveParameter] string $apiKey): array
    {
        $this->calledGoogle = false;
        $results = [];
        $counts = ['cache' => 0, 'google' => 0, 'error' => 0];

        foreach ($legs as $leg) {
            $result = $this->route(
                (float) $leg['origin']['lat'],
                (float) $leg['origin']['lng'],
                (float) $leg['destination']['lat'],
                (float) $leg['destination']['lng'],
                $mode,
                $preference,
                $apiKey,
            );
            $results[] = $result->toArray((string) $leg['id']);
            $counts[$result->succeeded() ? $result->source : 'error']++;
        }

        return ['results' => $results, 'counts' => $counts];
    }

    public function route(
        float $oLat,
        float $oLng,
        float $dLat,
        float $dLng,
        string $mode,
        string $preference,
        #[SensitiveParameter] string $apiKey,
    ): RouteResult {
        $key = GoogleRoute::keyColumns($oLat, $oLng, $dLat, $dLng, $mode, $preference);

        $cached = GoogleRoute::lookup($key);
        if ($cached !== null) {
            return RouteResult::ok($cached->duration_sec, $cached->distance_km, 'cache');
        }

        $delay = (int) config('pso-services.settings.google_routes_delay_ms', 150);
        if ($this->calledGoogle && $delay > 0) {
            Sleep::for($delay)->milliseconds();
        }
        $this->calledGoogle = true;

        $result = $this->client->route(
            (float) $key['origin_lat'],
            (float) $key['origin_lng'],
            (float) $key['dest_lat'],
            (float) $key['dest_lng'],
            $mode,
            $preference,
            $apiKey,
        );

        if ($result->succeeded() && $result->durationSec > 0) {
            // insertOrIgnore: a parallel request may have stored the same key meanwhile.
            GoogleRoute::query()->insertOrIgnore([$key + [
                'duration_sec' => $result->durationSec,
                'distance_km' => number_format($result->distanceKm, 2, '.', ''),
                'fetched_at' => now(),
                'source' => 'google',
            ]]);
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run to pass**

`vendor/bin/pest tests/Unit/Services/V2/GoogleRoutesServiceTest.php`. Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Unit/Services/V2/GoogleRoutesServiceTest.php
git add app/Services/V2/GoogleRoutesService.php tests/Unit/Services/V2/GoogleRoutesServiceTest.php
git commit -m "Google Routes: service that answers from the cache first, pauses only between Google calls and stores successes"
```

---

### Task 4: `POST /api/v2/routes/compute`

**Files:**
- Create: `app/Http/Requests/Api/V2/RouteComputeRequest.php`, `app/Http/Controllers/Api/V2/RouteController.php`, `tests/Feature/Api/V2/RouteControllerTest.php`
- Modify: `routes/api_v2.php`

**Interfaces (Consumes):** `GoogleRoutesService::compute()` (Task 3); `GoogleRoutesClient::MODES`, `PREFERENCES` (Task 2); `GoogleRoute::keyColumns()` (Task 1); `config('pso-services.settings.google_key')`.

**Interfaces (Produces):** the endpoint pso-test-tools' `PsoServicesRoutesClient` calls, verbatim from spec 5.3.

Request (JSON, `Accept: application/json`):

```json
{ "travelMode": "DRIVE", "routingPreference": "TRAFFIC_UNAWARE",
  "legs": [ { "id": "any string", "origin": { "lat": 52.1, "lng": -106.6 }, "destination": { "lat": 52.2, "lng": -106.7 } } ] }
```

Validation (422 `{message, errors}`): 1 to 200 legs, ids unique, coordinates in range, mode one of DRIVE, WALK, BICYCLE, TWO_WHEELER, TRANSIT; preference one of TRAFFIC_UNAWARE, TRAFFIC_AWARE, TRAFFIC_AWARE_OPTIMAL.

Response 200:

```json
{ "results": [ { "id": "…", "durationSec": 4374, "distanceKm": 117.04, "source": "cache" } ,
               { "id": "…", "error": "No route returned" } ],
  "counts": { "cache": 1, "google": 0, "error": 1 } }
```

No key configured: 503 `{"message": "Google Routes is not configured on pso-services."}`. Results come back in request order, one per leg. Both `travelMode` and `routingPreference` are required. Route name `v2.routes.compute`; `throttle:api` from the group, one hit per request.

- [ ] **Step 1: Failing feature tests**

`tests/Feature/Api/V2/RouteControllerTest.php`:
```php
<?php

use App\Models\V2\GoogleRoute;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function routeLeg(string $id, float $oLat = 52.851108, float $oLng = -102.391453, float $dLat = 52.84367, float $dLng = -104.05325): array
{
    return ['id' => $id, 'origin' => ['lat' => $oLat, 'lng' => $oLng], 'destination' => ['lat' => $dLat, 'lng' => $dLng]];
}

function routesPayload(?array $legs = null): array
{
    return ['travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'legs' => $legs ?? [routeLeg('A')]];
}

function seedRouteRow(): void
{
    GoogleRoute::query()->insertOrIgnore([GoogleRoute::keyColumns(52.851108, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE') + [
        'duration_sec' => 4374, 'distance_km' => '117.04', 'fetched_at' => null, 'source' => 'seeded:google_routes_cache.csv',
    ]]);
}

beforeEach(function () {
    config(['pso-services.settings.google_key' => 'server-key']);
    Sleep::fake();
});

it('rejects an invalid request with a 422', function (Closure $mutate, string $field) {
    Http::fake();

    $this->postJson('/api/v2/routes/compute', $mutate(routesPayload()))
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors([$field]);

    Http::assertNothingSent();
})->with([
    'no legs' => [function (array $p) { $p['legs'] = []; return $p; }, 'legs'],
    '201 legs' => [function (array $p) { $p['legs'] = array_map(fn ($i) => routeLeg("L{$i}"), range(1, 201)); return $p; }, 'legs'],
    'duplicate ids' => [function (array $p) { $p['legs'] = [routeLeg('A'), routeLeg('A')]; return $p; }, 'legs.1.id'],
    'missing id' => [function (array $p) { unset($p['legs'][0]['id']); return $p; }, 'legs.0.id'],
    'latitude out of range' => [function (array $p) { $p['legs'][0]['origin']['lat'] = 91; return $p; }, 'legs.0.origin.lat'],
    'longitude out of range' => [function (array $p) { $p['legs'][0]['destination']['lng'] = -181; return $p; }, 'legs.0.destination.lng'],
    'missing destination' => [function (array $p) { unset($p['legs'][0]['destination']); return $p; }, 'legs.0.destination.lat'],
    'unknown mode' => [function (array $p) { $p['travelMode'] = 'FLY'; return $p; }, 'travelMode'],
    'unknown preference' => [function (array $p) { $p['routingPreference'] = 'FASTEST'; return $p; }, 'routingPreference'],
    'missing mode' => [function (array $p) { unset($p['travelMode']); return $p; }, 'travelMode'],
]);

it('accepts exactly 200 legs', function () {
    seedRouteRow();
    Http::fake();
    $legs = array_map(fn ($i) => routeLeg("L{$i}"), range(1, 200));

    $this->postJson('/api/v2/routes/compute', routesPayload($legs))
        ->assertOk()
        ->assertJsonPath('counts', ['cache' => 200, 'google' => 0, 'error' => 0]);
});

it('returns a 503 without a server key before any work', function () {
    config(['pso-services.settings.google_key' => null]);
    seedRouteRow();
    Http::fake();

    $this->postJson('/api/v2/routes/compute', routesPayload())
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Google Routes is not configured on pso-services.']);

    Http::assertNothingSent();
});

it('returns a cached leg without calling Google', function () {
    seedRouteRow();
    Http::fake();

    $this->postJson('/api/v2/routes/compute', routesPayload())
        ->assertOk()
        ->assertExactJson([
            'results' => [['id' => 'A', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'cache']],
            'counts' => ['cache' => 1, 'google' => 0, 'error' => 0],
        ]);

    Http::assertNothingSent();
});

it('rounds coordinates to six decimals for the cache lookup', function () {
    seedRouteRow();
    Http::fake();

    $this->postJson('/api/v2/routes/compute', routesPayload([routeLeg('A', 52.8511084, -102.3914526, 52.8436704, -104.0532496)]))
        ->assertOk()
        ->assertJsonPath('results.0.source', 'cache');

    Http::assertNothingSent();
});

it('calls Google on a miss with the exact request, stores the row and serves the next call from the cache', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);

    $this->postJson('/api/v2/routes/compute', routesPayload())
        ->assertOk()
        ->assertExactJson([
            'results' => [['id' => 'A', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'google']],
            'counts' => ['cache' => 0, 'google' => 1, 'error' => 0],
        ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
        && $request->hasHeader('X-Goog-Api-Key', 'server-key')
        && $request->hasHeader('X-Goog-FieldMask', 'routes.duration,routes.distanceMeters')
        && $request->data() == [
            'origin' => ['location' => ['latLng' => ['latitude' => 52.851108, 'longitude' => -102.391453]]],
            'destination' => ['location' => ['latLng' => ['latitude' => 52.84367, 'longitude' => -104.05325]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'units' => 'METRIC',
        ]);

    $row = GoogleRoute::query()->sole();
    expect($row->origin_lat)->toBe(52.851108)
        ->and($row->dest_lng)->toBe(-104.05325)
        ->and($row->travel_mode)->toBe('DRIVE')
        ->and($row->routing_preference)->toBe('TRAFFIC_UNAWARE')
        ->and($row->duration_sec)->toBe(4374)
        ->and($row->source)->toBe('google');

    $this->postJson('/api/v2/routes/compute', routesPayload())
        ->assertOk()
        ->assertJsonPath('results.0.source', 'cache');

    Http::assertSentCount(1);
});

it('returns a Google error per leg and does not cache it', function () {
    Http::fake(['routes.googleapis.com/*' => Http::sequence()
        ->push(['error' => ['code' => 403, 'message' => 'Routes API has not been used in project 1.', 'status' => 'PERMISSION_DENIED']], 403)
        ->push([], 200)
        ->push(['routes' => [['duration' => '60s', 'distanceMeters' => 900]]], 200)]);

    $this->postJson('/api/v2/routes/compute', routesPayload([
        routeLeg('A'),
        routeLeg('B', 50.1),
        routeLeg('C', 50.2),
    ]))
        ->assertOk()
        ->assertExactJson([
            'results' => [
                ['id' => 'A', 'error' => 'HTTP 403: Routes API has not been used in project 1.'],
                ['id' => 'B', 'error' => 'No route returned'],
                ['id' => 'C', 'durationSec' => 60, 'distanceKm' => 0.9, 'source' => 'google'],
            ],
            'counts' => ['cache' => 0, 'google' => 1, 'error' => 2],
        ]);

    expect(GoogleRoute::query()->count())->toBe(1)
        ->and(GoogleRoute::query()->sole()->origin_lat)->toBe(50.2);
});

it('counts one request against the rate limit whatever the number of legs', function () {
    config(['api.rate_limit_per_minute' => 2]);
    seedRouteRow();
    Http::fake();
    $legs = [routeLeg('A'), routeLeg('B'), routeLeg('C')];

    foreach (range(1, 2) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->postJson('/api/v2/routes/compute', routesPayload($legs))->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->postJson('/api/v2/routes/compute', routesPayload($legs))
        ->assertStatus(429);
});
```

- [ ] **Step 2: Run to fail**

`vendor/bin/pest tests/Feature/Api/V2/RouteControllerTest.php`. Expected: every test fails with 404 (no route yet).

- [ ] **Step 3: Request class**

`app/Http/Requests/Api/V2/RouteComputeRequest.php`:
```php
<?php

namespace App\Http\Requests\Api\V2;

use App\Classes\V2\GoogleRoutesClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RouteComputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Google travel mode.
             * @example "DRIVE"
             */
            'travelMode' => ['required', 'string', Rule::in(GoogleRoutesClient::MODES)],

            /**
             * Google routing preference (sent to Google for DRIVE and TWO_WHEELER only).
             * @example "TRAFFIC_UNAWARE"
             */
            'routingPreference' => ['required', 'string', Rule::in(GoogleRoutesClient::PREFERENCES)],

            /**
             * 1 to 200 legs. Results come back in the same order.
             */
            'legs' => ['required', 'array', 'min:1', 'max:200'],
            'legs.*' => ['array'],

            /**
             * The caller's id for the leg, echoed back. Unique within the request.
             * @example "leg-1"
             */
            'legs.*.id' => ['required', 'string', 'max:255', 'distinct'],

            /** @example 52.1 */
            'legs.*.origin.lat' => ['required', 'numeric', 'between:-90,90'],
            /** @example -106.6 */
            'legs.*.origin.lng' => ['required', 'numeric', 'between:-180,180'],
            /** @example 52.2 */
            'legs.*.destination.lat' => ['required', 'numeric', 'between:-90,90'],
            /** @example -106.7 */
            'legs.*.destination.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
```

- [ ] **Step 4: Controller**

`app/Http/Controllers/Api/V2/RouteController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RouteComputeRequest;
use App\Services\V2\GoogleRoutesService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Routes')]
class RouteController extends Controller
{
    /**
     * Compute Routes
     *
     * Road travel time and distance for up to 200 legs. Each leg is answered
     * from the shared Google Routes cache (coordinates rounded to six
     * decimals) or, on a miss, by one Google Routes call; successes are
     * cached. A leg Google cannot answer comes back with `error` instead of
     * a time. Uses the server's Google key; no PSO logon is involved.
     */
    #[Response(200, 'Per-leg results in request order, and counts by source', type: 'object')]
    #[Response(503, 'Google Routes is not configured on pso-services', type: 'object')]
    public function compute(RouteComputeRequest $request, GoogleRoutesService $routes): JsonResponse
    {
        $apiKey = config('pso-services.settings.google_key');
        if (empty($apiKey)) {
            return response()->json(['message' => 'Google Routes is not configured on pso-services.'], 503);
        }

        $validated = $request->validated();

        return response()->json($routes->compute(
            $validated['legs'],
            $validated['travelMode'],
            $validated['routingPreference'],
            $apiKey,
        ));
    }
}
```
The key check runs after validation (a malformed request is a 422 either way) and before any cache lookup or Google call, which is what the spec's "before any work" requires.

- [ ] **Step 5: Route**

In `routes/api_v2.php` add `use App\Http\Controllers\Api\V2\RouteController;` in the sorted `use` block (after `ResourceUnavailabilityController`), and after the session routes:
```php

// routes (Google Routes travel times through the shared cache)
Route::post('/routes/compute', [RouteController::class, 'compute'])->name('v2.routes.compute');
```

- [ ] **Step 6: Run to pass**

`vendor/bin/pest tests/Feature/Api/V2/RouteControllerTest.php`. Expected: 17 passed (ten dataset cases plus seven tests). Then `vendor/bin/pest tests/Feature/Api/V2/RateLimitTest.php` still passes.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Feature/Api/V2/RouteControllerTest.php tests/Feature/Api/V2/RateLimitTest.php
git add app/Http/Requests/Api/V2/RouteComputeRequest.php app/Http/Controllers/Api/V2/RouteController.php routes/api_v2.php tests/Feature/Api/V2/RouteControllerTest.php
git commit -m "Google Routes: POST /api/v2/routes/compute answers up to 200 legs from the cache or Google, 503 without a key"
```

---

### Task 5: `google-routes:seed <csv>`

**Files:**
- Create: `app/Console/Commands/SeedGoogleRoutes.php`, `tests/Feature/Console/SeedGoogleRoutesTest.php`

**Interfaces (Consumes):** `GoogleRoute::keyColumns()` (Task 1); `GoogleRoutesClient::MODES`, `PREFERENCES` (Task 2).

**Interfaces (Produces):** `php artisan google-routes:seed <csv>`. Input: the script's cache CSV (`"Key","DurationSec","DistanceKm","FetchedUtc"`, UTF-8 with BOM, all values quoted, key `oLat,oLng|dLat,dLng|MODE|PREF` with six decimals). Inserts missing keys only (`insertOrIgnore` in batches of 500; existing rows are never changed), `source` = `seeded:<csv file name>`, `fetched_at` = `FetchedUtc` when it parses as a date, else null. Rows that are not valid (bad key, unknown mode or preference, duration missing or not above 0, coordinates out of range) are skipped with a `Line N:` warning. Output last line `Inserted X, skipped Y.` (skipped = duplicates in the file, keys already in the table and invalid rows). Exit 0; exit 1 when the file is missing, empty or lacks `Key`, `DurationSec` or `DistanceKm`.

- [ ] **Step 1: Failing command tests**

`tests/Feature/Console/SeedGoogleRoutesTest.php`:
```php
<?php

use App\Models\V2\GoogleRoute;

function writeSeedCsv(array $lines, bool $bom = true): string
{
    $dir = sys_get_temp_dir().'/'.uniqid('seed_', true);
    mkdir($dir);
    $path = $dir.'/google_routes_cache.csv';
    file_put_contents($path, ($bom ? "\xEF\xBB\xBF" : '').implode("\r\n", $lines)."\r\n");

    return $path;
}

const SEED_HEADER = '"Key","DurationSec","DistanceKm","FetchedUtc"';

it('seeds a three-row csv with one duplicate', function () {
    $path = writeSeedCsv([
        SEED_HEADER,
        '"52.851108,-102.391453|52.843670,-104.053250|DRIVE|TRAFFIC_UNAWARE","4374","117.04","seeded:prod_4wk_oct5"',
        '"52.662068,-106.332258|52.131010,-106.596260|DRIVE|TRAFFIC_UNAWARE","2862","72.93","2026-10-08T14:03:11.1234567Z"',
        '"52.851108,-102.391453|52.843670,-104.053250|DRIVE|TRAFFIC_UNAWARE","9999","1.00","seeded:other"',
    ]);

    $this->artisan('google-routes:seed', ['csv' => $path])
        ->expectsOutput('Inserted 2, skipped 1.')
        ->assertExitCode(0);

    $first = GoogleRoute::lookup(GoogleRoute::keyColumns(52.851108, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE'));
    $second = GoogleRoute::lookup(GoogleRoute::keyColumns(52.662068, -106.332258, 52.13101, -106.59626, 'DRIVE', 'TRAFFIC_UNAWARE'));

    expect(GoogleRoute::query()->count())->toBe(2)
        ->and($first->duration_sec)->toBe(4374)
        ->and($first->distance_km)->toBe(117.04)
        ->and($first->source)->toBe('seeded:google_routes_cache.csv')
        ->and($first->fetched_at)->toBeNull()
        ->and($second->fetched_at->toIso8601ZuluString())->toBe('2026-10-08T14:03:11Z');
});

it('keeps an existing row and strips the byte order mark', function () {
    GoogleRoute::query()->insertOrIgnore([GoogleRoute::keyColumns(52.851108, -102.391453, 52.84367, -104.05325, 'DRIVE', 'TRAFFIC_UNAWARE') + [
        'duration_sec' => 4000, 'distance_km' => '110.00', 'fetched_at' => null, 'source' => 'google',
    ]]);
    $path = writeSeedCsv([
        SEED_HEADER,
        '"52.851108,-102.391453|52.843670,-104.053250|DRIVE|TRAFFIC_UNAWARE","4374","117.04","seeded:prod_4wk_oct5"',
    ]);

    $this->artisan('google-routes:seed', ['csv' => $path])
        ->expectsOutput('Inserted 0, skipped 1.')
        ->assertExitCode(0);

    expect(GoogleRoute::query()->sole()->duration_sec)->toBe(4000)
        ->and(GoogleRoute::query()->sole()->source)->toBe('google');
});

it('skips invalid rows with a line number', function () {
    $path = writeSeedCsv([
        SEED_HEADER,
        '"not a key","100","1.00",""',
        '"52.1,-106.6|52.2,-106.7|FLY|TRAFFIC_UNAWARE","100","1.00",""',
        '"52.1,-106.6|52.2,-106.7|DRIVE|TRAFFIC_UNAWARE","0","0.00",""',
        '"91.0,-106.6|52.2,-106.7|DRIVE|TRAFFIC_UNAWARE","100","1.00",""',
        '"52.1,-106.6|52.2,-106.7|WALK|TRAFFIC_UNAWARE","100","","2026-10-08T14:03:11Z"',
    ], bom: false);

    $this->artisan('google-routes:seed', ['csv' => $path])
        ->expectsOutputToContain('Line 2:')
        ->expectsOutputToContain('Line 5:')
        ->expectsOutput('Inserted 1, skipped 4.')
        ->assertExitCode(0);

    expect(GoogleRoute::query()->sole()->distance_km)->toBe(0.0);
});

it('fails on a missing file or a file without the cache columns', function () {
    $this->artisan('google-routes:seed', ['csv' => '/nonexistent/google_routes_cache.csv'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);

    $this->artisan('google-routes:seed', ['csv' => writeSeedCsv(['"Resource ID","Flag"', '"1","OK"'])])
        ->expectsOutputToContain('Missing column Key')
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run to fail**

`vendor/bin/pest tests/Feature/Console/SeedGoogleRoutesTest.php`. Expected: fails with `The command "google-routes:seed" does not exist.`

- [ ] **Step 3: Implement**

`app/Console/Commands/SeedGoogleRoutes.php`:
```php
<?php

namespace App\Console\Commands;

use App\Classes\V2\GoogleRoutesClient;
use App\Models\V2\GoogleRoute;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('google-routes:seed {csv : Path to the google_routes_cache.csv kept by Build-PlanTravelsReport.ps1}')]
#[Description('Load the PowerShell script\'s Google Routes cache CSV into google_routes. Existing keys are kept.')]
class SeedGoogleRoutes extends Command
{
    private const BATCH = 500;

    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false || $header === [null]) {
            fclose($handle);
            $this->error('The file is empty.');

            return self::FAILURE;
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $columns = array_flip(array_map(fn ($name) => trim((string) $name), $header));
        foreach (['Key', 'DurationSec', 'DistanceKm'] as $required) {
            if (! isset($columns[$required])) {
                fclose($handle);
                $this->error("Missing column {$required}. Expected the cache CSV: Key, DurationSec, DistanceKm, FetchedUtc.");

                return self::FAILURE;
            }
        }

        $source = 'seeded:'.basename($path);
        $batch = [];
        $total = 0;
        $inserted = 0;
        $line = 1;

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $total++;

            $row = $this->parse($cells, $columns, $source);
            if ($row === null) {
                $this->warn("Line {$line}: not a valid cache row, skipped.");

                continue;
            }

            $batch[] = $row;
            if (count($batch) === self::BATCH) {
                $inserted += GoogleRoute::query()->insertOrIgnore($batch);
                $batch = [];
            }
        }
        fclose($handle);

        if ($batch !== []) {
            $inserted += GoogleRoute::query()->insertOrIgnore($batch);
        }

        $skipped = $total - $inserted;
        $this->info("Inserted {$inserted}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $columns
     */
    private function parse(array $cells, array $columns, string $source): ?array
    {
        $parts = explode('|', trim((string) ($cells[$columns['Key']] ?? '')));
        if (count($parts) !== 4) {
            return null;
        }

        $origin = explode(',', $parts[0]);
        $destination = explode(',', $parts[1]);
        if (count($origin) !== 2 || count($destination) !== 2) {
            return null;
        }

        $coords = array_map('trim', [...$origin, ...$destination]);
        foreach ($coords as $coord) {
            if (! is_numeric($coord)) {
                return null;
            }
        }
        [$oLat, $oLng, $dLat, $dLng] = array_map('floatval', $coords);
        if (abs($oLat) > 90 || abs($dLat) > 90 || abs($oLng) > 180 || abs($dLng) > 180) {
            return null;
        }

        $mode = trim($parts[2]);
        $preference = trim($parts[3]);
        if (! in_array($mode, GoogleRoutesClient::MODES, true) || ! in_array($preference, GoogleRoutesClient::PREFERENCES, true)) {
            return null;
        }

        $duration = trim((string) ($cells[$columns['DurationSec']] ?? ''));
        if (! is_numeric($duration) || (int) $duration <= 0) {
            return null;
        }

        // The script defaults a missing distance to 0.
        $distance = trim((string) ($cells[$columns['DistanceKm']] ?? ''));
        $distance = is_numeric($distance) ? (float) $distance : 0.0;

        $fetched = isset($columns['FetchedUtc']) ? trim((string) ($cells[$columns['FetchedUtc']] ?? '')) : '';

        return GoogleRoute::keyColumns($oLat, $oLng, $dLat, $dLng, $mode, $preference) + [
            'duration_sec' => (int) $duration,
            'distance_km' => number_format($distance, 2, '.', ''),
            'fetched_at' => $this->fetchedAt($fetched),
            'source' => $source,
        ];
    }

    private function fetchedAt(string $value): ?string
    {
        if ($value === '' || str_starts_with($value, 'seeded:')) {
            return null;
        }

        // The script writes .NET round-trip times with seven fraction digits; keep six for the parser.
        $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
```
`fgetcsv` is called with an explicit empty escape argument: PHP 8.4 deprecates relying on the default.

- [ ] **Step 4: Run to pass**

`vendor/bin/pest tests/Feature/Console/SeedGoogleRoutesTest.php`. Expected: 4 passed.

- [ ] **Step 5: Smoke test on the real CSV (local dev database only)**

```bash
php artisan migrate
php artisan google-routes:seed "/Users/idawoodjee/Documents/Projects/PSO Tools/scripts/google_routes_cache.csv"
php artisan google-routes:seed "/Users/idawoodjee/Documents/Projects/PSO Tools/scripts/google_routes_cache.csv"
```
Expected: the first run prints `Inserted N, skipped M.` with N + M = 5,553 (the file's data rows) and few or no `Line` warnings; the second prints `Inserted 0, skipped 5553.` Record N in the commit message body. Do not commit the CSV.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Feature/Console/SeedGoogleRoutesTest.php
git add app/Console/Commands/SeedGoogleRoutes.php tests/Feature/Console/SeedGoogleRoutesTest.php
git commit -m "Google Routes: google-routes:seed loads the script's cache CSV, keeping existing keys"
```

---

### Task 6: Travel Analyzer on Google Routes (#27)

**Files:**
- Create: `app/Helpers/DistanceMatrixText.php`, `tests/Unit/Helpers/DistanceMatrixTextTest.php`, `tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php`
- Modify: `app/Services/V2/TravelService.php`

**Interfaces (Consumes):** `GoogleRoutesService::route()` (Task 3), `RouteResult` and `GoogleRoutesClient::NO_ROUTE` (Task 2).

**Interfaces (Produces):** unchanged externally. `PSOTravelLog.google_response` keeps the Distance Matrix element shape:
```php
['distance' => ['text' => '117 km', 'value' => 117040], 'duration' => ['text' => '1 hour 13 mins', 'value' => 4374], 'status' => 'OK']
```
`TravelService::getTravelResults()` keeps exactly these keys, which `GET /api/v2/travelanalyzer/{id}` and the callback job send and pso-test-tools reads (`resources/views/filament/pages/travel-analyzer.blade.php` renders `results.pso` and `results.google` by key, `Str::headline` of `time` and `distance`, and falls back to `Arr::dot` of the whole result): `travel_detail_request_id`, `start_address`, `end_address`, `pso.time`, `pso.distance`, `google.time` (from `duration.text`), `google.distance` (from `distance.text`), and `warnings` when present. The page file is not touched.

Behaviour changes, all deliberate: the call goes From to To (the Distance Matrix call had `origins` = To, `destinations` = From); the timeout is 10 s instead of 5 s; the answer may come from the shared cache. Text forms are built locally to look like the Distance Matrix's (inference: Google's own wording rules are not documented; under 1 km `N m`, under 100 km one decimal, else whole km; minutes rounded, at least `1 min`, then hours and days). Warnings keep their wording for the denied key, the query limit and no route; failures that used to say `Google Distance Matrix API` now say `Google Routes API`. `distance.value` is rebuilt from the km value, so it has 10 m resolution.

- [ ] **Step 1: Failing text tests**

`tests/Unit/Helpers/DistanceMatrixTextTest.php`:
```php
<?php

use App\Helpers\DistanceMatrixText;

it('formats durations like the Distance Matrix', function (int $seconds, string $text) {
    expect(DistanceMatrixText::duration($seconds))->toBe($text);
})->with([
    [0, '1 min'],
    [29, '1 min'],
    [60, '1 min'],
    [1320, '22 mins'],
    [3600, '1 hour'],
    [4374, '1 hour 13 mins'],
    [7200, '2 hours'],
    [90000, '1 day 1 hour'],
    [176400, '2 days 1 hour'],
]);

it('formats distances like the Distance Matrix', function (float $km, string $text) {
    expect(DistanceMatrixText::distance($km))->toBe($text);
})->with([
    [0.35, '350 m'],
    [3.36, '3.4 km'],
    [11.84, '11.8 km'],
    [117.04, '117 km'],
]);
```

- [ ] **Step 2: Failing analyzer tests**

`tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php`:
```php
<?php

use App\Enums\TravelLogStatus;
use App\Models\V2\GoogleRoute;
use App\Models\V2\PSOTravelLog;
use App\Services\V2\TravelService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function travelPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'environment' => ['sendToPso' => false, 'datasetId' => 'dataset_123'],
        'data' => [
            'latFrom' => 52.851108, 'longFrom' => -102.391453,
            'latTo' => 52.84367, 'longTo' => -104.05325,
            'googleApiKey' => 'use-server-key',
        ],
    ], $overrides);
}

beforeEach(function () {
    config([
        'pso-services.settings.google_key' => 'server-key',
        'pso-services.settings.google_api_passthrough' => 'use-server-key',
        'geocoder.key' => null,
    ]);
});

it('stores a Distance Matrix shaped google_response from Google Routes', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);

    $this->postJson('/api/v2/travelanalyzer', travelPayload())->assertStatus(202);

    expect(PSOTravelLog::query()->sole()->google_response)->toBe([
        'distance' => ['text' => '117 km', 'value' => 117040],
        'duration' => ['text' => '1 hour 13 mins', 'value' => 4374],
        'status' => 'OK',
    ]);

    // From is the origin, To the destination; DRIVE and TRAFFIC_UNAWARE; the passthrough word becomes the server key.
    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Goog-Api-Key', 'server-key')
        && $request['origin']['location']['latLng'] == ['latitude' => 52.851108, 'longitude' => -102.391453]
        && $request['destination']['location']['latLng'] == ['latitude' => 52.84367, 'longitude' => -104.05325]
        && $request['travelMode'] === 'DRIVE'
        && $request['routingPreference'] === 'TRAFFIC_UNAWARE');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'distancematrix'));

    expect(GoogleRoute::query()->count())->toBe(1);
});

it('keeps the result keys the Travel Analyzer page renders', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);
    $this->postJson('/api/v2/travelanalyzer', travelPayload())->assertStatus(202);

    $log = PSOTravelLog::query()->sole();
    $log->update([
        'status' => TravelLogStatus::COMPLETED,
        'pso_response' => ['travel_detail_request_id' => $log->id, 'time' => '01:10:00', 'distance' => 115000],
    ]);

    $results = app(TravelService::class)->getTravelResults($log->fresh());

    expect(array_keys($results))->toBe(['travel_detail_request_id', 'start_address', 'end_address', 'pso', 'google', 'warnings'])
        ->and($results['pso'])->toBe(['time' => '1 hour 10 minutes', 'distance' => '115.00 km'])
        ->and($results['google'])->toBe(['time' => '1 hour 13 mins', 'distance' => '117 km']);
});

it('answers a repeated trip from the cache', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '4374s', 'distanceMeters' => 117040]]])]);

    $this->postJson('/api/v2/travelanalyzer', travelPayload())->assertStatus(202);
    $this->postJson('/api/v2/travelanalyzer', travelPayload())->assertStatus(202);

    Http::assertSentCount(1);
    expect(PSOTravelLog::query()->latest('created_at')->first()->google_response['duration']['value'])->toBe(4374);
});

it('keeps the warning wording for a denied key and for no route', function (array $body, int $status, string $warning) {
    Http::fake(['routes.googleapis.com/*' => Http::response($body, $status)]);

    $this->postJson('/api/v2/travelanalyzer', travelPayload())->assertStatus(202);

    $log = PSOTravelLog::query()->sole();
    expect($log->google_response)->toBeNull()
        ->and($log->warnings)->toContain($warning)
        ->and(GoogleRoute::query()->count())->toBe(0);
})->with([
    'denied' => [['error' => ['code' => 403, 'message' => 'Permission denied.', 'status' => 'PERMISSION_DENIED']], 403,
        'Google API key is invalid or request was denied. Please check your API key and permissions.'],
    'invalid key' => [['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT']], 400,
        'Google API key is invalid or request was denied. Please check your API key and permissions.'],
    'quota' => [['error' => ['code' => 429, 'message' => 'Quota exceeded.', 'status' => 'RESOURCE_EXHAUSTED']], 429,
        'Google API query limit exceeded. Please check your quota.'],
    'no route' => [[], 200, 'No route found between the specified locations.'],
]);
```
The 202 status is the dry-run status `ActivityControllerTest` asserts for `sendToPso: false`; if the travel route answers a different 2xx in the dry run, correct the expectation after reading `TravelController::store`. The `pso` values come from the existing `PSOTravelLog` accessors (unchanged); asserting them proves the whole result is intact. If `pso_time_formatted` renders differently from `'1 hour 10 minutes'` on first run, read the accessor (`app/Models/V2/PSOTravelLog.php` line 70) and correct the expectation, not the accessor.

- [ ] **Step 3: Run to fail**

`vendor/bin/pest tests/Unit/Helpers/DistanceMatrixTextTest.php tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php`. Expected: the text tests fail on the missing class; the analyzer tests fail because the Distance Matrix URL is called (the fake answers it with an empty 200, so `google_response` is null).

- [ ] **Step 4: DistanceMatrixText**

`app/Helpers/DistanceMatrixText.php`:
```php
<?php

namespace App\Helpers;

/**
 * Text forms in the style of the Distance Matrix API's `text` fields, so the
 * Travel Analyzer shows the same kind of values after the move to Google
 * Routes (which returns numbers only).
 */
class DistanceMatrixText
{
    public static function duration(int $seconds): string
    {
        $minutes = max(1, (int) round($seconds / 60));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        if ($days > 0) {
            return trim(self::plural($days, 'day').' '.($hours > 0 ? self::plural($hours, 'hour') : ''));
        }
        if ($hours > 0) {
            return trim(self::plural($hours, 'hour').' '.($mins > 0 ? self::plural($mins, 'min') : ''));
        }

        return self::plural($mins, 'min');
    }

    public static function distance(float $km): string
    {
        if ($km < 1) {
            return ((int) round($km * 1000)).' m';
        }
        if ($km < 100) {
            return number_format($km, 1, '.', '').' km';
        }

        return number_format($km, 0, '.', '').' km';
    }

    private static function plural(int $count, string $unit): string
    {
        return $count.' '.$unit.($count === 1 ? '' : 's');
    }
}
```

- [ ] **Step 5: TravelService**

In `app/Services/V2/TravelService.php`:

1. Imports: add `use App\Classes\V2\GoogleRoutesClient;`, `use App\Classes\V2\PsoClient;`, `use App\DataTransferObjects\RouteResult;`, `use App\Helpers\DistanceMatrixText;`; remove `use Illuminate\Support\Facades\Http;` (only `getDistanceMatrix` used it; confirm with `grep -n "Http::" app/Services/V2/TravelService.php` after the edit).

2. After the three properties (line 29) add the constructor:
```php
    public function __construct(PsoClient $psoClient, protected GoogleRoutesService $routes)
    {
        parent::__construct($psoClient);
    }
```

3. Replace lines 79 to 90 (the `getDistanceMatrix` call, the fallback warning and its log line) with:
```php
        $googleResults = $this->getGoogleRoute(
            $context->data('latFrom'),
            $context->data('longFrom'),
            $context->data('latTo'),
            $context->data('longTo'),
            $context->data('googleApiKey'),
        );

        if ($googleResults === null && $this->hasGoogleKey && !$this->hasWarning('Google Routes') && !$this->hasWarning('Google API')) {
            $this->warnings[] = 'Google Routes API request failed. Distance/duration data unavailable.';
        }
        Log::info('Google route complete', ['result' => $googleResults]);
```

4. Replace the whole `getDistanceMatrix` method (lines 270 to 333) with:
```php
    /**
     * Google Routes (DRIVE, TRAFFIC_UNAWARE) through the shared cache, returned
     * in the Distance Matrix element shape PSOTravelLog and the page read.
     */
    protected function getGoogleRoute(float $latFrom, float $longFrom, float $latTo, float $longTo, string|null $apiKey = null): array|null
    {
        $passthrough = config('pso-services.settings.google_api_passthrough');
        if ($passthrough && $apiKey === $passthrough) {
            $apiKey = config('pso-services.settings.google_key');
        }

        $keyToUse = $apiKey ?? config('pso-services.settings.google_key');

        if (empty($keyToUse)) {
            return null;
        }

        $result = $this->routes->route($latFrom, $longFrom, $latTo, $longTo, 'DRIVE', 'TRAFFIC_UNAWARE', $keyToUse);

        if (!$result->succeeded()) {
            Log::warning('Google Routes returned no route for the travel analyzer', [
                'error' => $result->error,
                'status' => $result->httpStatus,
            ]);
            $this->warnings[] = $this->routeWarning($result);

            return null;
        }

        return [
            'distance' => [
                'text' => DistanceMatrixText::distance($result->distanceKm),
                'value' => (int) round($result->distanceKm * 1000),
            ],
            'duration' => [
                'text' => DistanceMatrixText::duration($result->durationSec),
                'value' => $result->durationSec,
            ],
            'status' => 'OK',
        ];
    }

    protected function routeWarning(RouteResult $result): string
    {
        $denied = $result->httpStatus === 403
            || $result->googleStatus === 'PERMISSION_DENIED'
            || ($result->httpStatus === 400 && str_contains((string) $result->error, 'API key'));

        return match (true) {
            $result->error === GoogleRoutesClient::NO_ROUTE => 'No route found between the specified locations.',
            $denied => 'Google API key is invalid or request was denied. Please check your API key and permissions.',
            $result->httpStatus === 429 || $result->googleStatus === 'RESOURCE_EXHAUSTED' => 'Google API query limit exceeded. Please check your quota.',
            $result->httpStatus === null => 'Exception calling Google API: '.$result->error,
            default => 'Google Routes API request failed with status '.$result->httpStatus.'. Check API key validity.',
        };
    }
```
`hasWarning`, `getTravelResults` and `PSOTravelLog` are unchanged. The key-missing warning at line 50 is unchanged (it is unreachable over HTTP because `data.googleApiKey` is required, as before).

- [ ] **Step 6: Run to pass**

`vendor/bin/pest tests/Unit/Helpers/DistanceMatrixTextTest.php tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php tests/Unit/Models/PSOTravelLogTest.php`. Expected: all pass (13 text cases, 7 analyzer cases, 1 model test).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
vendor/bin/pest tests/Unit/Helpers/DistanceMatrixTextTest.php tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php tests/Unit/Models/PSOTravelLogTest.php
git add app/Helpers/DistanceMatrixText.php app/Services/V2/TravelService.php tests/Unit/Helpers/DistanceMatrixTextTest.php tests/Feature/Api/V2/TravelAnalyzerGoogleRoutesTest.php
git commit -m "Travel Analyzer: Google Routes through the shared cache instead of the Distance Matrix, same result shape and warnings"
```

---

### Task 7: README, OpenAPI check, full suite, hand-off

**Files:**
- Modify: `README.md`

**Interfaces (Consumes):** everything above. **Produces:** documentation only.

- [ ] **Step 1: OpenAPI check**

Add `use Dedoc\Scramble\Generator;` to the imports at the top of `tests/Feature/Api/V2/RouteControllerTest.php` (as `SessionControllerTest` does) and this test at the end:
```php
it('documents the routes endpoint in OpenAPI', function () {
    $document = app(Generator::class)();

    expect($document['paths'])->toHaveKey('/routes/compute')
        ->and($document['paths']['/routes/compute'])->toHaveKey('post');
});
```
Run `vendor/bin/pest tests/Feature/Api/V2/RouteControllerTest.php --filter=documents`. Expected: passes already if Scramble picks the route up (it reads `routes/api_v2.php`); if it fails, compare the path key with what `SessionControllerTest` uses (`'/session'`) and fix the expectation to the generator's actual prefix handling, not the route.

- [ ] **Step 2: README section**

Insert after the session section in `README.md` (before `## Rate limits`):
```markdown
## POST /api/v2/routes/compute

Road travel time and distance from Google Routes for 1 to 200 legs per request, through a shared cache.
Body: `{travelMode, routingPreference, legs: [{id, origin: {lat, lng}, destination: {lat, lng}}]}`; modes
DRIVE, WALK, BICYCLE, TWO_WHEELER, TRANSIT; preferences TRAFFIC_UNAWARE, TRAFFIC_AWARE, TRAFFIC_AWARE_OPTIMAL
(sent to Google for DRIVE and TWO_WHEELER only); ids unique. Returns `{results, counts}`: each result is
`{id, durationSec, distanceKm, source}` with source `cache` or `google`, or `{id, error}`, in request order;
counts are `{cache, google, error}`. Invalid input is a 422 `{message, errors}`; without `GOOGLE_MAPS_API_KEY`
the endpoint answers 503 before any work. No PSO logon is involved and callers never send a Google key.

Each leg is looked up in the `google_routes` table first (coordinates rounded to six decimals, plus mode and
preference: the PowerShell script's cache key). A miss makes one `computeRoutes` call (10 s timeout, no retry),
with a pause of `GOOGLE_ROUTES_DELAY_MS` (default 150) between Google calls; answers with a duration above 0
are stored. Errors are returned per leg and never stored. The table holds coordinates and times only. A chunk
of 200 misses takes about a minute and a half at normal Google speed; the proxy and Apache timeouts (360 s on
the box) bound the worst case. The general rate limit counts one hit per request, not per leg. pso-test-tools'
Plan travels tool calls this in chunks of 200, and the Travel Analyzer uses the same client and cache
(DRIVE, TRAFFIC_UNAWARE).

Seed the cache once from the script's CSV (`pso-tools/scripts/google_routes_cache.csv`, copied to the box):
`php artisan google-routes:seed /path/to/google_routes_cache.csv`. It inserts keys that are missing, keeps
existing rows, and prints `Inserted X, skipped Y.`
```

- [ ] **Step 3: Full suite and lint**

```bash
vendor/bin/pint --dirty
vendor/bin/pest
git status
```
Expected: the whole suite passes (the RateLimitTest loop is the slowest part); `git status` shows only the files listed in this plan (no CSV, no `.env`).

- [ ] **Step 4: Commit**

```bash
git add README.md tests/Feature/Api/V2/RouteControllerTest.php
git commit -m "Google Routes: README section for routes/compute and the seed command, OpenAPI check"
```

- [ ] **Step 5: Hand-off**

Use superpowers:finishing-a-development-branch. Pushing `main` deploys to the box within about two minutes and the deploy runs `php artisan migrate --force`, so merging creates the table. Then Ish, on the box: copy `google_routes_cache.csv` from pso-tools and run `php artisan google-routes:seed <path>` once as `deploy`; confirm `GOOGLE_MAPS_API_KEY` is set in the box `.env` (otherwise the endpoint answers 503). Browser check for #27: run a Travel Analyzer request in pso-test-tools and confirm the PSO / Google table still shows Time and Distance on both sides. Comment on pso-services #43 and pso-test-tools #27 with the commit range; #27 closes after that browser check.

---

## Self-review

Coverage (spec requirement to task):

| Spec | Requirement | Task |
|---|---|---|
| 5.1 | One client class, computeRoutes endpoint | 2 |
| 5.1 | Headers `X-Goog-Api-Key`, `X-Goog-FieldMask: routes.duration,routes.distanceMeters` | 2 (test `sends the exact computeRoutes request`), 4 |
| 5.1 | Body origin/destination latLng, travelMode, routingPreference, units METRIC | 2, 4; preference omission deviation in Global Constraints, test in 2 |
| 5.1 | Duration `"Ns"` to int seconds; distance km rounded 2 | 2 (`parses the duration and distance`) |
| 5.1 | Errors `No route returned` or HTTP status plus message | 2 (three error tests) |
| 5.1 | Timeout 10 s, no retry | 2 (`TIMEOUT_SECONDS`, `assertSentCount(1)` after a failure) |
| 5.1 | `GOOGLE_ROUTES_DELAY_MS` default 150 between calls | 1 (config, `.env.example`), 3 (Sleep tests) |
| 5.1 | Server key `pso-services.settings.google_key`; `googleApiKey` passthrough kept for the Travel Analyzer | 4 (controller), 6 (`getGoogleRoute`, passthrough asserted) |
| 5.2 | Table columns, decimal(9,6), duration unsigned, distance decimal(8,2), fetched_at, source, unique index on six key columns | 1 |
| 5.2 | Only successes with duration above 0 stored; no identifiers | 3 (`does not store a zero-second route`, `counts errors and caches nothing`), 4 |
| 5.2 | Seed command, script CSV, inserts missing, reports inserted / skipped | 5 |
| 5.3 | Route, request and response JSON verbatim | 4 (Interfaces) |
| 5.3 | Validation list, 422 `{message, errors}` | 4 (ten dataset cases, `accepts exactly 200 legs`) |
| 5.3 | Cache first, rounded to 6 dp; misses in sequence with the delay; successes stored; counts | 3, 4 |
| 5.3 | 503 without key before any work | 4 |
| 5.3 | `throttle:api` per request, not per leg; no `DisallowProdUrl` | 4 (rate limit test; no rule on the request) |
| 5.3 | README section like the session one | 7 |
| 5.4 | `getDistanceMatrix` replaced by the client through the cache, DRIVE + TRAFFIC_UNAWARE | 6 |
| 5.4 | Result shape kept (`duration.value`, `distance.value`, text forms); page unchanged | 6 (both shape tests, key list from the blade) |
| 5.4 | Warnings keep their wording (denied, no route; key missing untouched) | 6 (warning dataset) |
| 5.5 | Validation cases | 4 |
| 5.5 | Cache hit sends nothing | 4 (`returns a cached leg without calling Google`) |
| 5.5 | Miss: exact headers, body, field mask; stored; second call a hit | 4 |
| 5.5 | Google error per leg, not cached | 4 |
| 5.5 | 503 without a key | 4 |
| 5.5 | Seed on a three-row CSV with one duplicate | 5 |
| 5.5 | Travel Analyzer result shape unchanged with the new client | 6 |
| 7 | Key never leaves pso-services; cache holds coordinates and times only | 2 (`SensitiveParameter`, key only in a header), 1 (schema) |
| Brief | `.env.example` | 1 |

Gaps found and fixed while writing: the spec's "key missing" warning cannot be triggered over HTTP (validation requires `data.googleApiKey`), so Task 6 leaves it untouched and tests the reachable warnings instead; the real CSV starts with a BOM and has `seeded:` values in `FetchedUtc`, so `fetched_at` is nullable and Task 5 tests both; the old Distance Matrix call had origin and destination swapped, now recorded in Task 6 and Review Focus 4.

Placeholders: none. Type consistency: `GoogleRoute::keyColumns()` returns string coordinates used by `lookup()`, the service insert and the seed command; `RouteResult::toArray($id)` is the endpoint's per-leg shape; `GoogleRoutesService::compute()` returns `{results, counts}` exactly as the controller sends it; `GoogleRoutesService::route()` is the Travel Analyzer's entry; `GoogleRoutesClient::MODES` / `PREFERENCES` / `NO_ROUTE` are shared by the request, the seed command and `routeWarning()`. Review Focus items each name their tests.
