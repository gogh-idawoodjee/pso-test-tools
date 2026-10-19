<?php

namespace App\Support\Booking;

use App\Enums\Status;

/**
 * Made-up customers with real street locations in New York, Toronto, London
 * and Los Angeles, for trying the appointment booking page. Names are invented, coordinates are
 * approximate points on the named street.
 */
class SampleCustomers
{
    /** Slugs of seeded customers are "sample-<dataset id>-<n>"; --fresh finds them by this prefix. */
    public static function slugPrefix(string $datasetId): string
    {
        return 'sample-'.$datasetId.'-';
    }

    /** @var list<string> */
    private const NAMES = [
        'Harpreet Gill', 'Marie Tremblay', 'Daniel Okafor', 'Sarah Whitebear', 'Liam Kowalchuk', 'Priya Nair',
        'Ethan Bouchard', 'Aisha Rahman', 'Noah Peterson', 'Chantelle Dumont', 'Mateo Alvarez', 'Hannah Olsen',
        'Tyler Sutherland', 'Mei Lin Chen', 'Jacob Friesen', 'Amara Mensah', 'Connor McBride', 'Sophie Lavallee',
        'Omar Haddad', 'Brenda Yaremko', 'Kyle Anderson', 'Fatima Sheikh', 'Gabriel Roy', 'Ingrid Johansson',
    ];

    /** @var list<array{string, string, string, string, float, float}> street address, city, country, postcode, lat, long */
    private const LOCATIONS = [
        ['350 5th Avenue', 'New York', 'United States', '10118', 40.7484, -73.9857],
        ['89 East 42nd Street', 'New York', 'United States', '10017', 40.7527, -73.9772],
        ['30 Rockefeller Plaza', 'New York', 'United States', '10112', 40.7587, -73.9787],
        ['200 Eastern Parkway', 'Brooklyn', 'United States', '11238', 40.6712, -73.9636],
        ['4 Pennsylvania Plaza', 'New York', 'United States', '10001', 40.7505, -73.9934],
        ['1000 Richmond Terrace', 'Staten Island', 'United States', '10301', 40.6436, -74.1012],
        ['290 Bremner Boulevard', 'Toronto', 'Canada', 'M5V 3L9', 43.6426, -79.3871],
        ['100 Queen Street West', 'Toronto', 'Canada', 'M5H 2N2', 43.6534, -79.3839],
        ['1 Yonge Street', 'Toronto', 'Canada', 'M5E 1E5', 43.6426, -79.3729],
        ['2300 Yonge Street', 'Toronto', 'Canada', 'M4P 1E4', 43.7068, -79.3984],
        ['25 Carlton Street', 'Toronto', 'Canada', 'M5B 1L2', 43.6615, -79.3806],
        ['3401 Dufferin Street', 'Toronto', 'Canada', 'M6A 2T9', 43.7250, -79.4525],
        ['10 Downing Street', 'London', 'United Kingdom', 'SW1A 2AA', 51.5034, -0.1276],
        ['221B Baker Street', 'London', 'United Kingdom', 'NW1 6XE', 51.5238, -0.1586],
        ['1 Canada Square', 'London', 'United Kingdom', 'E14 5AB', 51.5050, -0.0195],
        ['Kings Road 150', 'London', 'United Kingdom', 'SW3 4UT', 51.4875, -0.1687],
        ['Brick Lane 91', 'London', 'United Kingdom', 'E1 6QL', 51.5203, -0.0718],
        ['Portobello Road 200', 'London', 'United Kingdom', 'W11 1LA', 51.5176, -0.2054],
        ['6925 Hollywood Boulevard', 'Los Angeles', 'United States', '90028', 34.1022, -118.3406],
        ['1 World Way', 'Los Angeles', 'United States', '90045', 33.9425, -118.4081],
        ['111 South Grand Avenue', 'Los Angeles', 'United States', '90012', 34.0553, -118.2498],
        ['4800 Oak Grove Drive', 'Pasadena', 'United States', '91109', 34.2013, -118.1713],
        ['3911 South Figueroa Street', 'Los Angeles', 'United States', '90037', 34.0141, -118.2879],
        ['1750 Ocean Avenue', 'Santa Monica', 'United States', '90401', 34.0094, -118.4963],
    ];

    /**
     * @return array{name: string, slug: string, address: string, city: string, country: string, postcode: string, lat: float, long: float, status: Status}
     */
    public static function at(int $index, string $datasetId): array
    {
        [$address, $city, $country, $postcode, $lat, $long] = self::LOCATIONS[$index % count(self::LOCATIONS)];
        $name = self::NAMES[$index % count(self::NAMES)];
        $round = intdiv($index, count(self::LOCATIONS));
        $suffix = $round > 0 ? ' '.($round + 1) : '';

        return [
            'name' => $name.$suffix,
            'slug' => self::slugPrefix($datasetId).($index + 1),
            'address' => $address,
            'city' => $city,
            'country' => $country,
            'postcode' => $postcode,
            'lat' => $lat,
            'long' => $long,
            'status' => Status::ACTIVE,
        ];
    }
}
