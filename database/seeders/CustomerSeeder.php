<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Customer;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // Predefined list of 10 legitimate Southern Ontario addresses


        $customers = [
            // 10 new Metro Toronto area businesses
            [
                'name' => 'Kensington Organic Market',
                'address' => '38 Kensington Ave',
                'city' => 'Toronto',
                'postcode' => 'M5T 2J9',
                'lat' => 43.6546,
                'long' => -79.4003,
            ],
            [
                'name' => 'Liberty Village Brewing',
                'address' => '124 Atlantic Ave',
                'city' => 'Toronto',
                'postcode' => 'M6K 1X9',
                'lat' => 43.6372,
                'long' => -79.4239,
            ],
            [
                'name' => 'Distillery District Chocolatier',
                'address' => '32 Tank House Lane',
                'city' => 'Toronto',
                'postcode' => 'M5A 3C4',
                'lat' => 43.6505,
                'long' => -79.3595,
            ],
            [
                'name' => 'Danforth Electronics',
                'address' => '1482 Danforth Ave',
                'city' => 'Toronto',
                'postcode' => 'M4J 1N4',
                'lat' => 43.6841,
                'long' => -79.3308,
            ],
            [
                'name' => 'Yorkville Luxury Boutique',
                'address' => '87 Yorkville Ave',
                'city' => 'Toronto',
                'postcode' => 'M5R 1B9',
                'lat' => 43.6709,
                'long' => -79.3932,
            ],
            [
                'name' => 'Roncesvalles Polish Deli',
                'address' => '195 Roncesvalles Ave',
                'city' => 'Toronto',
                'postcode' => 'M6R 2L5',
                'lat' => 43.6479,
                'long' => -79.4492,
            ],
            [
                'name' => 'Leslieville Home Furnishings',
                'address' => '1184 Queen St E',
                'city' => 'Toronto',
                'postcode' => 'M4M 1L4',
                'lat' => 43.6628,
                'long' => -79.3292,
            ],
            [
                'name' => 'St. Clair West Bakery',
                'address' => '651 St Clair Ave W',
                'city' => 'Toronto',
                'postcode' => 'M6C 1A7',
                'lat' => 43.6809,
                'long' => -79.4282,
            ],
            [
                'name' => 'Harbourfront Marine Supplies',
                'address' => '245 Queens Quay W',
                'city' => 'Toronto',
                'postcode' => 'M5J 2N5',
                'lat' => 43.6386,
                'long' => -79.3855,
            ],
            [
                'name' => 'Bloor West Village Pharmacy',
                'address' => '2387 Bloor St W',
                'city' => 'Toronto',
                'postcode' => 'M6S 1P6',
                'lat' => 43.6509,
                'long' => -79.4801,
            ],
            [
                'name' => 'Maple Leaf Bakery',
                'address' => '123 Queen St W',
                'city' => 'Toronto',
                'postcode' => 'M5H 2M9',
                'lat' => 43.6526,
                'long' => -79.3802,
            ],
            [
                'name' => 'Garden City Florist',
                'address' => '200 King St E',
                'city' => 'Hamilton',
                'postcode' => 'L8N 1G2',
                'lat' => 43.2557,
                'long' => -79.8711,
            ],
            [
                'name' => 'Niagara Furniture',
                'address' => '50 Drummond Rd',
                'city' => 'Brampton',
                'postcode' => 'L6W 3E4',
                'lat' => 43.7315,
                'long' => -79.7624,
            ],
            [
                'name' => 'Lakeside Optics',
                'address' => '101 Lakeshore Rd',
                'city' => 'Mississauga',
                'postcode' => 'L5G 1E2',
                'lat' => 43.5910,
                'long' => -79.6456,
            ],
            [
                'name' => 'Oakville Antiques',
                'address' => '300 Trafalgar Rd',
                'city' => 'Oakville',
                'postcode' => 'L6H 5S1',
                'lat' => 43.4675,
                'long' => -79.6877,
            ],
            [
                'name' => 'Kingston Books',
                'address' => '250 Johnson St',
                'city' => 'Kingston',
                'postcode' => 'K7L 1J9',
                'lat' => 44.2312,
                'long' => -76.4836,
            ],
            [
                'name' => 'London Electronics',
                'address' => '75 Dundas St',
                'city' => 'London',
                'postcode' => 'N6A 1B3',
                'lat' => 42.9849,
                'long' => -81.2453,
            ],
            [
                'name' => 'Kitchener Bakery',
                'address' => '10 King St W',
                'city' => 'Kitchener',
                'postcode' => 'N2G 1A1',
                'lat' => 43.4516,
                'long' => -80.4925,
            ],
            [
                'name' => 'Guelph Gardens',
                'address' => '55 Woolwich St',
                'city' => 'Guelph',
                'postcode' => 'N1H 3V1',
                'lat' => 43.5448,
                'long' => -80.2482,
            ],
            [
                'name' => 'Barrie Music',
                'address' => '120 Dunlop St W',
                'city' => 'Barrie',
                'postcode' => 'L4N 1C6',
                'lat' => 44.3894,
                'long' => -79.6903,
            ],
            [
                'name' => 'Niagara Falls Hardware',
                'address' => '4875 Victoria Ave',
                'city' => 'Niagara Falls',
                'postcode' => 'L2E 4E2',
                'lat' => 43.0896,
                'long' => -79.0849,
            ],
            [
                'name' => 'Cambridge Technology',
                'address' => '45 Hespeler Rd',
                'city' => 'Cambridge',
                'postcode' => 'N1R 3H2',
                'lat' => 43.3974,
                'long' => -80.3133,
            ],
            [
                'name' => 'St. Catharines Vineyard',
                'address' => '87 St. Paul St',
                'city' => 'St. Catharines',
                'postcode' => 'L2R 3M3',
                'lat' => 43.1594,
                'long' => -79.2469,
            ],
            [
                'name' => 'Windsor Auto Parts',
                'address' => '325 Ouellette Ave',
                'city' => 'Windsor',
                'postcode' => 'N9A 4J1',
                'lat' => 42.3149,
                'long' => -83.0364,
            ],
            [
                'name' => 'Oshawa Manufacturing',
                'address' => '50 Simcoe St N',
                'city' => 'Oshawa',
                'postcode' => 'L1G 4S1',
                'lat' => 43.8971,
                'long' => -78.8658,
            ],
            [
                'name' => 'Burlington Appliances',
                'address' => '468 Brant St',
                'city' => 'Burlington',
                'postcode' => 'L7R 2G4',
                'lat' => 43.3255,
                'long' => -79.7982,
            ],
            [
                'name' => 'Waterloo Tech Hub',
                'address' => '151 Charles St W',
                'city' => 'Waterloo',
                'postcode' => 'N2G 1H6',
                'lat' => 43.4643,
                'long' => -80.5204,
            ],
            [
                'name' => 'Brantford Textiles',
                'address' => '73 Dalhousie St',
                'city' => 'Brantford',
                'postcode' => 'N3T 2J6',
                'lat' => 43.1393,
                'long' => -80.2634,
            ],
            [
                'name' => 'Peterborough Outdoors',
                'address' => '340 George St N',
                'city' => 'Peterborough',
                'postcode' => 'K9H 3R2',
                'lat' => 44.3006,
                'long' => -78.3196,
            ],
            [
                'name' => 'Belleville Farmers Market',
                'address' => '256 Pinnacle St',
                'city' => 'Belleville',
                'postcode' => 'K8N 3B4',
                'lat' => 44.1627,
                'long' => -77.3832,
            ]
        ];

//        $regionIds = Region::pluck('id')->toArray();

        // Define the user IDs you want to assign customers to
        $userIds = [1, 2, 3];

        foreach ($userIds as $userId) {
            // Pick 5 random customers for this user
            $selectedCustomers = Arr::random($customers, 5);

            foreach ($selectedCustomers as $data) {
                Customer::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $userId,
                    'name' => $data['name'],
                    'address' => $data['address'],
                    'city' => $data['city'],
                    'country' => 'Canada',
                    'postcode' => $data['postcode'],
                    'lat' => $data['lat'],
                    'long' => $data['long'],
                    'region_id' => null, // no region or optional random
                    'status' => Status::ACTIVE,
                ]);
            }
        }
    }
}
