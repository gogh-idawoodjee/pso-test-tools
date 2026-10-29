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
            $ends = explode('|', $key);
            if ($trip !== null && $ends[0] !== $ends[1]) {
                $trips[$key] = $trip;
            }
        }

        return $trips;
    }

    /**
     * What the progress line says before the Google check: legs, distinct trips to check, and the legs never
     * sent (no usable coordinates, or the same place at both ends).
     *
     * @param  array<int, mixed>  $legs
     * @return array{legs: int, trips: int, no_coordinates: int, same_place: int}
     */
    public static function legCounts(array $legs): array
    {
        $counts = ['legs' => 0, 'trips' => 0, 'no_coordinates' => 0, 'same_place' => 0];
        foreach ($legs as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            $counts['legs']++;
            $key = $leg['route_key'] ?? null;
            if (! is_string($key) || self::split($key) === null) {
                $counts['no_coordinates']++;
            } elseif (explode('|', $key)[0] === explode('|', $key)[1]) {
                $counts['same_place']++;
            }
        }
        $counts['trips'] = count(self::trips($legs));

        return $counts;
    }
}
