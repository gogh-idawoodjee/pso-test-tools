<?php

use App\Filament\Pages\Settings;
use App\Filament\Resources\EnvironmentResource\Pages\ListEnvironments;
use App\Filament\Widgets\GoogleRoutesCache;
use App\Models\User;
use App\Support\PsoServices\PsoServicesRoutesClient;
use App\Support\PsoServices\PsoServicesRoutesException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function routesStatsBody(array $override = []): array
{
    return array_replace([
        'rows' => 5558,
        'by_source' => ['seeded' => 5546, 'google' => 12],
        'by_mode' => ['DRIVE/TRAFFIC_UNAWARE' => 5558],
        'fetched' => ['oldest' => '2026-10-03T00:57:57Z', 'newest' => '2026-10-14T23:59:59Z', 'undated' => 5546],
        'added_last_7_days' => 12,
        'generated_at' => '2026-10-16T13:40:00Z',
    ], $override);
}

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2', 'psott.routes.token' => null]);
    Cache::forget(GoogleRoutesCache::CACHE_KEY);
    $this->actingAs(User::factory()->create(['email' => 'tester@mac.com']));
});

it('shows the card on the Settings page with the numbers from pso-services', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/stats' => Http::response(routesStatsBody())]);

    $this->get(ListEnvironments::getUrl())->assertOk()->assertDontSee('Google Routes cache');

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('Google Routes cache')
        ->assertSee('5,558')
        ->assertSee('5,546 seeded from the script&#039;s cache, 12 fetched from Google', false)
        ->assertSee('12 added in the last 7 days')
        ->assertSee('Newest answer 2026-10-14')
        ->assertDontSee('DRIVE/TRAFFIC_UNAWARE');
});

it('says there are no live answers yet and lists modes when there are several', function () {
    Http::fake(['*' => Http::response(routesStatsBody([
        'by_source' => ['seeded' => 5558, 'google' => 0],
        'by_mode' => ['DRIVE/TRAFFIC_UNAWARE' => 5000, 'DRIVE/TRAFFIC_AWARE' => 558],
        'fetched' => ['oldest' => null, 'newest' => null, 'undated' => 5558],
        'added_last_7_days' => 0,
    ]))]);

    Livewire::test(GoogleRoutesCache::class)
        ->assertSee('No live Google answers yet')
        ->assertSee('DRIVE/TRAFFIC_AWARE: 558');
});

it('shows the not reachable sentence on a 500 and caches nothing', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    Livewire::test(GoogleRoutesCache::class)
        ->assertSee('pso-services could not be reached. The cache is still used by Plan travels and the Travel Analyzer.');
    expect(Cache::has(GoogleRoutesCache::CACHE_KEY))->toBeFalse();
});

it('shows the not reachable sentence when the connection fails', function () {
    Http::fake(fn () => throw new ConnectionException('x'));

    Livewire::test(GoogleRoutesCache::class)
        ->assertSee('pso-services could not be reached.');
    expect(Cache::has(GoogleRoutesCache::CACHE_KEY))->toBeFalse();
});

it('reads pso-services once per five minutes', function () {
    Http::fake(['*' => Http::response(routesStatsBody())]);

    Livewire::test(GoogleRoutesCache::class);
    Livewire::test(GoogleRoutesCache::class);

    Http::assertSentCount(1);
});

it('reads pso-services again after Refresh', function () {
    Http::fake(['*' => Http::response(routesStatsBody())]);

    Livewire::test(GoogleRoutesCache::class)->call('refresh');

    Http::assertSentCount(2);
});

it('sends the routes token when configured', function () {
    config(['psott.routes.token' => 'secret-token']);
    Http::fake(['*' => Http::response(routesStatsBody())]);

    app(PsoServicesRoutesClient::class)->stats();

    Http::assertSent(static fn ($request) => $request->hasHeader('X-PSO-Routes-Token', 'secret-token'));
});

it('gets the stats URL without a token header when none is set', function () {
    Http::fake(['*' => Http::response(routesStatsBody())]);

    expect(app(PsoServicesRoutesClient::class)->stats()['rows'])->toBe(5558);

    Http::assertSent(static fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://pso-services.test/api/v2/routes/stats'
        && ! $request->hasHeader('X-PSO-Routes-Token'));
});

it('throws when the body has no rows', function () {
    Http::fake(['*' => Http::response(['message' => 'ok'])]);

    app(PsoServicesRoutesClient::class)->stats();
})->throws(PsoServicesRoutesException::class);
