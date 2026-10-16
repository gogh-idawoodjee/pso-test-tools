<?php

use App\Filament\Pages\TravelAnalyzer;
use Livewire\Livewire;

it('lives at the path the plan travels page links to', function () {
    expect(parse_url(TravelAnalyzer::getUrl(), PHP_URL_PATH))->toBe('/travel-analyzer');
});

it('fills the four coordinates from a Check in PSO link', function () {
    Livewire::withQueryParams(['lat_from' => '52.123457', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6'])
        ->test(TravelAnalyzer::class)
        ->assertSet('data.lat_from', 52.123457)
        ->assertSet('data.long_from', -106.5)
        ->assertSet('data.lat_to', 52.2)
        ->assertSet('data.long_to', -106.6);
});

it('ignores the query when a value is missing, out of range or not a number', function (array $query) {
    expect(TravelAnalyzer::prefillFromQuery($query))->toBe([]);

    Livewire::withQueryParams($query)->test(TravelAnalyzer::class)->assertSet('data.lat_from', null);
})->with([
    'three of four' => [['lat_from' => '52.1', 'long_from' => '-106.5', 'lat_to' => '52.2']],
    'latitude over 90' => [['lat_from' => '91', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'longitude under -180' => [['lat_from' => '52.1', 'long_from' => '-180.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'text' => [['lat_from' => 'north', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'an array' => [['lat_from' => ['52.1'], 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
]);

it('opens empty without a query, as before', function () {
    Livewire::test(TravelAnalyzer::class)->assertOk()->assertSet('data.lat_from', null);
});
