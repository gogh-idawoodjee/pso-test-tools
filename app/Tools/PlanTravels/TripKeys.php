<?php

namespace App\Tools\PlanTravels;

/**
 * The distinct trips in a legs.json, by each leg's route_key ("<oLat>,<oLng>|<dLat>,<dLng>", six decimals,
 * made by the script). The key is copied verbatim into google.json, because merge-google matches on it;
 * this class only splits it into the pso-services request and never formats a coordinate itself.
 */
final class TripKeys
{
    private const LIMITS = [90.0, 180.0, 90.0, 180.0];

    /** @return array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}|null */
    public static function split(string $routeKey): ?array
    {
        $ends = explode('|', $routeKey);
        if (count($ends) !== 2) {
            return null;
        }
        $parts = [...explode(',', $ends[0]), ...explode(',', $ends[1])];
        if (count($parts) !== 4 || substr_count($ends[0], ',') !== 1) {
            return null;
        }

        $values = [];
        foreach ($parts as $i => $part) {
            if (! is_numeric($part) || abs((float) $part) > self::LIMITS[$i]) {
                return null;
            }
            $values[] = (float) $part;
        }
        [$oLat, $oLng, $dLat, $dLng] = $values;

        return ['origin' => ['lat' => $oLat, 'lng' => $oLng], 'destination' => ['lat' => $dLat, 'lng' => $dLng]];
    }

    /**
     * Trips whose origin equals the destination are left out: the script flags those legs OK "Same location"
     * itself, so asking Google would only waste a call.
     *
     * @param  array<int, mixed>  $legs
     * @return array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>
     */
    public static function trips(array $legs): array
    {
        $trips = [];
        foreach ($legs as $leg) {
            $key = is_array($leg) ? ($leg['route_key'] ?? null) : null;
            if (! is_string($key) || isset($trips[$key])) {
                continue;
            }
            $trip = self::split($key);
            if ($trip !== null && $trip['origin'] !== $trip['destination']) {
                $trips[$key] = $trip;
            }
        }

        return $trips;
    }
}
