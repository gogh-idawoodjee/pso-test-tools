<?php

namespace App\Support\Booking;

use App\Enums\Status;

/**
 * Made-up customers with real street locations in Saskatchewan cities, for
 * trying the appointment booking page. Names are invented, coordinates are
 * approximate points on the named street.
 */
class SampleCustomers
{
    /** Slugs of seeded customers start with this, which is how --fresh finds them. */
    public const SLUG_PREFIX = 'sample-';

    /** @var list<string> */
    private const NAMES = [
        'Harpreet Gill', 'Marie Tremblay', 'Daniel Okafor', 'Sarah Whitebear', 'Liam Kowalchuk', 'Priya Nair',
        'Ethan Bouchard', 'Aisha Rahman', 'Noah Peterson', 'Chantelle Dumont', 'Mateo Alvarez', 'Hannah Olsen',
        'Tyler Sutherland', 'Mei Lin Chen', 'Jacob Friesen', 'Amara Mensah', 'Connor McBride', 'Sophie Lavallee',
        'Omar Haddad', 'Brenda Yaremko', 'Kyle Anderson', 'Fatima Sheikh', 'Gabriel Roy', 'Ingrid Johansson',
    ];

    /** @var list<array{string, string, string, float, float}> street address, city, postcode, lat, long */
    private const LOCATIONS = [
        ['2155 Albert Street', 'Regina', 'S4P 2V1', 50.4452, -104.6089],
        ['3500 Dewdney Avenue', 'Regina', 'S4T 0A6', 50.4538, -104.6501],
        ['1801 Broad Street', 'Regina', 'S4P 1Y2', 50.4521, -104.6063],
        ['4100 Rochdale Boulevard', 'Regina', 'S4X 4J2', 50.5017, -104.6684],
        ['201 1st Avenue South', 'Saskatoon', 'S7K 1J5', 52.1294, -106.6697],
        ['2215 8th Street East', 'Saskatoon', 'S7H 0V4', 52.1177, -106.6195],
        ['3510 8th Street East', 'Saskatoon', 'S7H 0W6', 52.1167, -106.5912],
        ['310 Primrose Drive', 'Saskatoon', 'S7K 3N5', 52.1520, -106.6310],
        ['55 Main Street North', 'Moose Jaw', 'S6H 3J6', 50.3932, -105.5349],
        ['1000 Thatcher Drive West', 'Moose Jaw', 'S6J 1L8', 50.3963, -105.5707],
        ['1005 Main Street North', 'Moose Jaw', 'S6H 3K6', 50.4011, -105.5337],
        ['1501 Central Avenue', 'Prince Albert', 'S6V 4W7', 53.2034, -105.7531],
        ['2800 2nd Avenue West', 'Prince Albert', 'S6V 5E7', 53.1959, -105.7752],
        ['101 15th Street East', 'Prince Albert', 'S6V 1G1', 53.2008, -105.7392],
        ['1100 Central Avenue North', 'Swift Current', 'S9H 0L3', 50.2886, -107.7936],
        ['1 Chaplin Street East', 'Swift Current', 'S9H 1E6', 50.2872, -107.7885],
        ['400 Herbert Street East', 'Swift Current', 'S9H 3R8', 50.2915, -107.7850],
        ['140 Broadway Street East', 'Yorkton', 'S3N 0L1', 51.2140, -102.4610],
        ['345 Broadway Street West', 'Yorkton', 'S3N 0N8', 51.2142, -102.4770],
        ['1150 Circlebrooke Drive', 'Yorkton', 'S3N 2J6', 51.2275, -102.4540],
        ['1291 100th Street', 'North Battleford', 'S9A 0V9', 52.7640, -108.2860],
        ['1301 101st Street', 'North Battleford', 'S9A 0Z7', 52.7618, -108.2830],
        ['1202 4th Street', 'Estevan', 'S4A 0W2', 49.1390, -102.9890],
        ['1175 Kensington Avenue', 'Estevan', 'S4A 2A9', 49.1350, -102.9780],
    ];

    /**
     * @return array{name: string, slug: string, address: string, city: string, country: string, postcode: string, lat: float, long: float, status: Status}
     */
    public static function at(int $index, string $datasetId): array
    {
        [$address, $city, $postcode, $lat, $long] = self::LOCATIONS[$index % count(self::LOCATIONS)];
        $name = self::NAMES[$index % count(self::NAMES)];
        $round = intdiv($index, count(self::LOCATIONS));
        $suffix = $round > 0 ? ' '.($round + 1) : '';

        return [
            'name' => $name.$suffix,
            'slug' => self::SLUG_PREFIX.substr($datasetId, 0, 8).'-'.($index + 1),
            'address' => $address,
            'city' => $city,
            'country' => 'Canada',
            'postcode' => $postcode,
            'lat' => $lat,
            'long' => $long,
            'status' => Status::ACTIVE,
        ];
    }
}
