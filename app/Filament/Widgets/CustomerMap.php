<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Widgets\Widget;

/** Every customer the user can see that has coordinates, as a pin on a Leaflet map (Customers list page). */
class CustomerMap extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.customer-map';

    /**
     * The pins, ordered by name. The Customer global scopes limit this to the current user's datasets.
     *
     * @return list<array{name: string, address: ?string, city: ?string, lat: float, long: float, url: string}>
     */
    /** @return array{url: string, subdomains: string, maxZoom: int, attribution: string} */
    public function tiles(): array
    {
        return [
            'url' => (string) config('psott.map_tiles.url'),
            'subdomains' => (string) config('psott.map_tiles.subdomains'),
            'maxZoom' => (int) config('psott.map_tiles.max_zoom'),
            'attribution' => (string) config('psott.map_tiles.attribution'),
        ];
    }

    public function pins(): array
    {
        return Customer::query()
            ->whereNotNull('lat')
            ->whereNotNull('long')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'address', 'city', 'lat', 'long'])
            ->map(static fn (Customer $customer): array => [
                'name' => $customer->name,
                'address' => $customer->address,
                'city' => $customer->city,
                'lat' => (float) $customer->lat,
                'long' => (float) $customer->long,
                'url' => CustomerResource::getUrl('view', ['record' => $customer]),
            ])
            ->values()
            ->all();
    }
}
