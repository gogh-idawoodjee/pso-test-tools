<?php

namespace App\Support;

use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Spatie\Geocoder\Exceptions\CouldNotGeocode;
use Spatie\Geocoder\Geocoder;

class GeocodeHelper
{
    public static function makeAddressFromParts(callable $get): string
    {
        return collect([
            $get('address'),
            $get('city'),
            $get('country'),
            $get('postcode'),
        ])->filter()->implode(', ');
    }

    public static function geocodeFormAddress(
        callable $get,
        callable $set,
        ?string $latPath = null,
        ?string $longPath = null,
        ?string $addressPath = null,
        bool $usesFullAddressAsPath = false
    ): void {
        $addressPath ??= 'address';
        $longPath ??= 'longitude';
        $latPath ??= 'latitude';
        $address = $usesFullAddressAsPath ? $addressPath : $get($addressPath);

        if (! $address) {
            self::sendGeocodeNotification('noaddress', 'Please enter an address', 'warning');

            return;
        }

        $coords = self::performGeocode($address);

        if ($coords['lat'] && $coords['lng']) {
            $set($latPath, $coords['lat']);
            $set($longPath, $coords['lng']);
            self::sendGeocodeNotification('passedgeo', 'Successful Geocode', 'success');
        } else {
            self::sendGeocodeNotification('failedgeo', 'Failed Geocode', 'danger', $coords['error'] ?? null);
        }
    }

    public static function performGeocode(string $address): array
    {
        $client = new Client;

        $geocoder = new Geocoder($client);
        $geocoder->setApiKey(config('geocoder.key'));

        try {
            return $geocoder->getCoordinatesForAddress($address);
        } catch (CouldNotGeocode $e) {
            Log::error('Geocoding failed', ['address' => $address, 'message' => $e->getMessage()]);

            return ['lat' => null, 'lng' => null, 'error' => $e->getMessage()];
        }
    }

    private static function sendGeocodeNotification(string $key, string $message, string $type, ?string $body = null): void
    {
        Notification::make($key)
            ->title($message)
            ->body($body)
            ->icon('heroicon-s-map')
            ->{$type}()
            ->send();
    }
}
