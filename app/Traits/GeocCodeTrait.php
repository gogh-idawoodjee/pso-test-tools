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
        if ($get($address_path)) {
            $coords = $this->performGeocode($get($address_path));
            if ($coords['lat'] && $coords['lng']) {
                $set($lat_path, $coords['lat']);
                $set($long_path, $coords['lng']);
                Notification::make('passedgeo')
                    ->icon('heroicon-s-map')
                    ->title('Successful Geocode')
                    ->success()
                    ->send();
            } else {
                Notification::make('failedgeo')
                    ->title('Failed Geocode')
                    ->danger()
                    ->send();
            }
        } else {
            Notification::make('noaddress')
                ->title('Please enter an address')
                ->warning()
                ->send();
        }
    }

}
