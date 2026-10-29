<?php

use App\Tools\PlanTravels\TripKeys;

function tripLeg(mixed $routeKey): array
{
    return ['resource_id' => 'R1', 'route_key' => $routeKey];
}

it('copies route keys verbatim and splits them for the request', function () {
    $trips = TripKeys::trips([tripLeg('52.123457,-106.500000|52.200000,-106.600000')]);

    expect(array_keys($trips))->toBe(['52.123457,-106.500000|52.200000,-106.600000'])
        ->and($trips['52.123457,-106.500000|52.200000,-106.600000'])->toBe(['origin' => ['lat' => 52.123457, 'lng' => -106.5], 'destination' => ['lat' => 52.2, 'lng' => -106.6]])
        ->and(TripKeys::split('-0.000000,180.000000|1.000000,-180.000000'))->toBe(['origin' => ['lat' => -0.0, 'lng' => 180.0], 'destination' => ['lat' => 1.0, 'lng' => -180.0]]);
});

it('keeps one trip per distinct route key in first seen order', function () {
    $trips = TripKeys::trips([
        tripLeg('52.123457,-106.500000|52.200000,-106.600000'),
        tripLeg('50.001000,-106.000000|51.001000,-106.100000'),
        tripLeg('52.123457,-106.500000|52.200000,-106.600000'),
    ]);

    expect(array_keys($trips))->toBe(['52.123457,-106.500000|52.200000,-106.600000', '50.001000,-106.000000|51.001000,-106.100000']);
});

it('leaves out legs whose route key is null, not text, malformed or out of range', function () {
    $trips = TripKeys::trips([
        tripLeg(null),
        tripLeg(52.1),
        tripLeg(''),
        tripLeg('52.100000,-106.500000'),
        tripLeg('52.100000,-106.500000|52.200000'),
        tripLeg('52.1,-106.5|52.2,-106.6|1,1'),
        tripLeg('north,-106.500000|52.200000,-106.600000'),
        tripLeg('91.000000,-106.500000|52.200000,-106.600000'),
        tripLeg('52.100000,-181.000000|52.200000,-106.600000'),
        ['resource_id' => 'R9'],
        'not a leg',
        tripLeg('52.100000,-106.500000|52.200000,-106.600000'),
    ]);

    expect(array_keys($trips))->toBe(['52.100000,-106.500000|52.200000,-106.600000']);
});

it('drops trips whose origin equals the destination', function () {
    $trips = TripKeys::trips([
        tripLeg('52.100000,-106.600000|52.100000,-106.600000'),
        tripLeg('52.100000,-106.600000|52.200000,-106.600000'),
    ]);

    expect(array_keys($trips))->toBe(['52.100000,-106.600000|52.200000,-106.600000']);
});

it('counts the legs, the trips to check and the legs that are not sent', function () {
    $counts = TripKeys::legCounts([
        tripLeg('52.100000,-106.500000|52.200000,-106.600000'),
        tripLeg('52.100000,-106.500000|52.200000,-106.600000'),
        tripLeg('52.300000,-106.500000|52.400000,-106.600000'),
        tripLeg(null),
        tripLeg('north,-106.500000|52.200000,-106.600000'),
        tripLeg('52.100000,-106.600000|52.100000,-106.600000'),
        'not a leg',
    ]);

    expect($counts)->toBe(['legs' => 6, 'trips' => 2, 'no_coordinates' => 2, 'same_place' => 1]);
});
