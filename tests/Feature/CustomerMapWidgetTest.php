<?php

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Widgets\CustomerMap;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

function mapCustomerFor(User $user, array $attributes): Customer
{
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create();

    return Customer::factory()->for($dataset)->create($attributes);
}

/** A URL as it appears inside the page's @js(...) data: JSON.parse('...') with each slash written as \\\/. */
function jsonUrl(string $url): string
{
    return str_replace('/', '\\\\\\/', $url);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'map-owner@mac.com']); // the panel only admits some domains
    $this->actingAs($this->user);
});

it('shows the map on the Customers page with a pin for each customer that has coordinates', function () {
    $pinned = mapCustomerFor($this->user, ['name' => 'Regina Depot', 'address' => '1 Main St', 'city' => 'Regina', 'lat' => 50.4452, 'long' => -104.6189]);
    $unpinned = mapCustomerFor($this->user, ['name' => 'Nowhere Farm', 'lat' => 52.1, 'long' => null]);

    $this->get(ListCustomers::getUrl())
        ->assertOk()
        ->assertSee('Customer map')
        ->assertSee('leaflet@1.9.4/dist/leaflet.js', false)
        ->assertSee('Regina Depot')
        ->assertSee(jsonUrl(CustomerResource::getUrl('view', ['record' => $pinned])), false)
        ->assertDontSee(jsonUrl(CustomerResource::getUrl('view', ['record' => $unpinned])), false);

    $pins = Livewire::test(CustomerMap::class)->instance()->pins();
    expect($pins)->toBe([[
        'name' => 'Regina Depot',
        'address' => '1 Main St',
        'city' => 'Regina',
        'lat' => 50.4452,
        'long' => -104.6189,
        'url' => CustomerResource::getUrl('view', ['record' => $pinned]),
    ]]);
});

it('orders the pins by name', function () {
    mapCustomerFor($this->user, ['name' => 'Zeta', 'lat' => 1, 'long' => 2]);
    mapCustomerFor($this->user, ['name' => 'Alpha', 'lat' => 3, 'long' => 4]);

    expect(array_column(Livewire::test(CustomerMap::class)->instance()->pins(), 'name'))->toBe(['Alpha', 'Zeta']);
});

it('does not show another user\'s customers', function () {
    mapCustomerFor($this->user, ['name' => 'Mine Ltd', 'lat' => 50.1, 'long' => -104.1]);
    mapCustomerFor(User::factory()->create(['email' => 'map-other@mac.com']), ['name' => 'Theirs Inc', 'lat' => 43.6, 'long' => -79.4]);

    $this->get(ListCustomers::getUrl())
        ->assertOk()
        ->assertSee('Mine Ltd')
        ->assertDontSee('Theirs Inc');

    expect(array_column(Livewire::test(CustomerMap::class)->instance()->pins(), 'name'))->toBe(['Mine Ltd']);
});

it('says so when no customer has coordinates', function () {
    mapCustomerFor($this->user, ['name' => 'Nowhere Farm', 'lat' => null, 'long' => null]);

    Livewire::test(CustomerMap::class)
        ->assertSee('No customers have coordinates yet');
});

it('uses the configured tile source, CARTO by default, never OpenStreetMap's own servers', function () {
    expect(config('psott.map_tiles.url'))->toContain('basemaps.cartocdn.com')
        ->and((new \App\Filament\Widgets\CustomerMap)->tiles()['url'])->not->toContain('tile.openstreetmap.org');

    config(['psott.map_tiles.url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key=abc']);
    expect((new \App\Filament\Widgets\CustomerMap)->tiles()['url'])->toBe('https://tiles.example.com/{z}/{x}/{y}.png?key=abc');
});
