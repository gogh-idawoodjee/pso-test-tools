<?php

namespace App\Traits;

use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use Spatie\Geocoder\Geocoder;

trait GeocCodeTrait
{

    public function performGeocode($address): array
    {
        $client = new Client();

        $geocoder = new Geocoder($client);
        $geocoder->setApiKey(config('geocoder.key'));
        return $geocoder->getCoordinatesForAddress($address);


    }

    public function geocodeFormAddress(Get $get, Set $set, $lat_path = 'latitude', $long_path = 'longitude', $address_path = 'address'): void
    {
        $address = $get($address_path);

        if (!$address) {
            $this->sendGeocodeNotification('noaddress', 'Please enter an address', 'warning');
            return;
        }

        $coords = $this->performGeocode($address);

        if ($coords['lat'] && $coords['lng']) {
            $set($lat_path, $coords['lat']);
            $set($long_path, $coords['lng']);
            $this->sendGeocodeNotification('passedgeo', 'Successful Geocode', 'success');
        } else {
            $this->sendGeocodeNotification('failedgeo', 'Failed Geocode', 'danger');
        }
    }

    /**
     * Send a geocode-related notification.
     */
    private function sendGeocodeNotification(string $key, string $message, string $type): void
    {
        Notification::make($key)
            ->title($message)
            ->icon('heroicon-s-map')
            ->{$type}()  // Dynamically call the appropriate notification type (success, danger, warning)
            ->send();
    }


}
