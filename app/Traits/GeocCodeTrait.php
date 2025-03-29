<?php

namespace App\Traits;

use GuzzleHttp\Client;
use Spatie\Geocoder\Geocoder;

trait GeocCodeTrait
{

    public function geocodeAddress($address): array
    {
        $client = new Client();

        $geocoder = new Geocoder($client);
        $geocoder->setApiKey(config('geocoder.key'));
        return $geocoder->getCoordinatesForAddress($address);


    }
}
