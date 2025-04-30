<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Customer;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // Predefined list of 10 legitimate Southern Ontario addresses
        $customers = [
            [
                'name'     => 'Maple Leaf Bakery',
                'address'  => '123 Queen St W',
                'city'     => 'Toronto',
                'postcode' => 'M5H 2M9',
                'lat'      => 43.6526,
                'long'     => -79.3802,
            ],
            [
                'name'     => 'Garden City Florist',
                'address'  => '200 King St E',
                'city'     => 'Hamilton',
                'postcode' => 'L8N 1G2',
                'lat'      => 43.2557,
                'long'     => -79.8711,
            ],
            [
                'name'     => 'Niagara Furniture',
                'address'  => '50 Drummond Rd',
                'city'     => 'Brampton',
                'postcode' => 'L6W 3E4',
                'lat'      => 43.7315,
                'long'     => -79.7624,
            ],
            [
                'name'     => 'Lakeside Optics',
                'address'  => '101 Lakeshore Rd',
                'city'     => 'Mississauga',
                'postcode' => 'L5G 1E2',
                'lat'      => 43.5910,
                'long'     => -79.6456,
            ],
            [
                'name'     => 'Oakville Antiques',
                'address'  => '300 Trafalgar Rd',
                'city'     => 'Oakville',
                'postcode' => 'L6H 5S1',
                'lat'      => 43.4675,
                'long'     => -79.6877,
            ],
            [
                'name'     => 'Kingston Books',
                'address'  => '250 Johnson St',
                'city'     => 'Kingston',
                'postcode' => 'K7L 1J9',
                'lat'      => 44.2312,
                'long'     => -76.4836,
            ],
            [
                'name'     => 'London Electronics',
                'address'  => '75 Dundas St',
                'city'     => 'London',
                'postcode' => 'N6A 1B3',
                'lat'      => 42.9849,
                'long'     => -81.2453,
            ],
            [
                'name'     => 'Kitchener Bakery',
                'address'  => '10 King St W',
                'city'     => 'Kitchener',
                'postcode' => 'N2G 1A1',
                'lat'      => 43.4516,
                'long'     => -80.4925,
            ],
            [
                'name'     => 'Guelph Gardens',
                'address'  => '55 Woolwich St',
                'city'     => 'Guelph',
                'postcode' => 'N1H 3V1',
                'lat'      => 43.5448,
                'long'     => -80.2482,
            ],
            [
                'name'     => 'Barrie Music',
                'address'  => '120 Dunlop St W',
                'city'     => 'Barrie',
                'postcode' => 'L4N 1C6',
                'lat'      => 44.3894,
                'long'     => -79.6903,
            ],
        ];

        $regionIds = Region::pluck('id')->toArray();

        foreach ($customers as $data) {
            Customer::create([
                'id'         => Str::uuid()->toString(),
                'name'       => $data['name'],
                'address'    => $data['address'],
                'city'       => $data['city'],
                'country'    => 'Canada',
                'postcode'   => $data['postcode'],
                'lat'        => $data['lat'],
                'long'       => $data['long'],
                'region_id'  => $faker = null, // no region or optional random
                'status'     => collect(Status::cases())->random()->value,
            ]);
        }
    }
}
