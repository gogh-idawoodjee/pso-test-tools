<?php

namespace App\Support;

use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use Spatie\Geocoder\Geocoder;

class GeocodeHelper
{
    public static function makeAddressFromParts(Get $get): string
    {
        return collect([
            $get('address'),
            $get('city'),
            $get('country'),
            $get('postcode'),
        ])->filter()->implode(', ');
    }

    public static function geocodeFormAddress(
        Get $get,
        Set $set,
        string $latPath = 'latitude',
        string $longPath = 'longitude',
        string $addressPath = 'address',
        bool $usesFullAddressAsPath = false
    ): void {
        $address = $usesFullAddressAsPath ? $addressPath : $get($addressPath);

        if (!$address) {
            self::sendGeocodeNotification('noaddress', 'Please enter an address', 'warning');
            return;
        }

        $coords = self::performGeocode($address);

        if ($coords['lat'] && $coords['lng']) {
            $set($latPath, $coords['lat']);
            $set($longPath, $coords['lng']);
            self::sendGeocodeNotification('passedgeo', 'Successful Geocode', 'success');
        } else {
            self::sendGeocodeNotification('failedgeo', 'Failed Geocode', 'danger');
        }
    }

    public static function performGeocode(string $address): array
    {
        $client = new Client();

        $geocoder = new Geocoder($client);
        $geocoder->setApiKey(config('geocoder.key'));

        return $geocoder->getCoordinatesForAddress($address);
    }

    private static function sendGeocodeNotification(string $key, string $message, string $type): void
    {
        Notification::make($key)
            ->title($message)
            ->icon('heroicon-s-map')
            ->{$type}()
            ->send();
    }
}
