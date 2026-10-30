<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\RegionSpec;
use App\Support\DatasetBuilder\Spec\ResourceSpec;
use Random\Randomizer;

/**
 * Home and depot Locations. Homes in a metro region scatter around a centroid
 * picked by weight (normal, sigma = spread_km / 2 on each axis); homes in a
 * rural region sit at a random point on the line between two consecutive
 * centroids, jittered up to 8 km. A home that lands inside one of the
 * region's exclude polygons (a lake) is drawn again. Addresses are synthetic
 * but readable.
 * Every draw comes from the one seeded Randomizer, so the same seed gives the
 * same homes.
 */
class LocationFactory
{
    private const float KM_PER_DEGREE = 111.0;

    private const float RURAL_JITTER_KM = 8.0;

    /** Draws before giving up on a home that keeps landing in an exclude polygon. */
    private const int MAX_DRAWS = 100;

    private const array STREET_SUFFIXES = ['Ave', 'St', 'Blvd', 'Rd'];

    /** Letters Canada Post uses in postal codes. */
    private const string POSTAL_LETTERS = 'ABCEGHJKLMNPRSTVWXYZ';

    public function __construct(private readonly Randomizer $random) {}

    /** @return array<string, string> Location fields, in the cheat sheet's order */
    public function home(ResourceSpec $resource, RegionSpec $region): array
    {
        $draws = 0;

        do {
            if (++$draws > self::MAX_DRAWS) {
                throw new InvalidDatasetSpec("Region {$region->id}: no home for {$resource->id} outside the exclude polygons in ".self::MAX_DRAWS.' draws. Check the polygons against the centroids.');
            }

            [$lat, $lon] = $region->kind === 'rural' ? $this->ruralPoint($region) : $this->metroPoint($region);
        } while ($region->excludes($lat, $lon));

        $street = $region->streetNames[$this->random->getInt(0, count($region->streetNames) - 1)];
        $suffix = self::STREET_SUFFIXES[$this->random->getInt(0, count(self::STREET_SUFFIXES) - 1)];
        $addressLine1 = $this->random->getInt(100, 9999)." {$street} {$suffix}";
        $city = $this->nearestLabel($region, $lat, $lon);

        return [
            'id' => "H-{$resource->id}",
            'name' => "{$addressLine1}, {$city}",
            'post_code_zip' => $this->postcode($region->postcodePrefix),
            'address_line1' => $addressLine1,
            'city' => $city,
            'state' => $region->state,
            'latitude' => self::coordinate($lat),
            'longitude' => self::coordinate($lon),
        ];
    }

    /** @return array<string, string> Location fields for the region's depot (the region must have one) */
    public static function depot(RegionSpec $region): array
    {
        $depot = $region->depot;

        return [
            'id' => "D-{$region->id}",
            'name' => "{$depot['address_line1']}, {$depot['city']}",
            'post_code_zip' => $depot['postcode'],
            'address_line1' => $depot['address_line1'],
            'city' => $depot['city'],
            'state' => $region->state,
            'latitude' => self::coordinate($depot['lat']),
            'longitude' => self::coordinate($depot['lon']),
        ];
    }

    /** @return array{0: float, 1: float} */
    private function metroPoint(RegionSpec $region): array
    {
        $total = array_sum(array_column($region->centroids, 'weight'));
        $pick = $this->random->getInt(1, $total);
        $centroid = $region->centroids[0];

        foreach ($region->centroids as $candidate) {
            $pick -= $candidate['weight'];

            if ($pick <= 0) {
                $centroid = $candidate;
                break;
            }
        }

        $sigma = $region->spreadKm / 2;

        return $this->offset($centroid['lat'], $centroid['lon'], $this->normal() * $sigma, $this->normal() * $sigma);
    }

    /** @return array{0: float, 1: float} */
    private function ruralPoint(RegionSpec $region): array
    {
        $index = $this->random->getInt(0, count($region->centroids) - 2);
        $from = $region->centroids[$index];
        $to = $region->centroids[$index + 1];
        $along = $this->random->nextFloat();

        $lat = $from['lat'] + ($to['lat'] - $from['lat']) * $along;
        $lon = $from['lon'] + ($to['lon'] - $from['lon']) * $along;

        return $this->offset(
            $lat,
            $lon,
            ($this->random->nextFloat() * 2 - 1) * self::RURAL_JITTER_KM,
            ($this->random->nextFloat() * 2 - 1) * self::RURAL_JITTER_KM,
        );
    }

    /** @return array{0: float, 1: float} the point $northKm north and $eastKm east of the given one */
    private function offset(float $lat, float $lon, float $northKm, float $eastKm): array
    {
        return [
            $lat + $northKm / self::KM_PER_DEGREE,
            $lon + $eastKm / (self::KM_PER_DEGREE * cos(deg2rad($lat))),
        ];
    }

    /** A standard normal draw (Box-Muller). */
    private function normal(): float
    {
        $u1 = 1.0 - $this->random->nextFloat(); // (0, 1], so the log is finite
        $u2 = $this->random->nextFloat();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    private function nearestLabel(RegionSpec $region, float $lat, float $lon): string
    {
        $best = null;
        $bestDistance = INF;

        foreach ($region->centroids as $centroid) {
            $north = $centroid['lat'] - $lat;
            $east = ($centroid['lon'] - $lon) * cos(deg2rad($lat));
            $distance = $north * $north + $east * $east;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $centroid['label'];
            }
        }

        return (string) $best;
    }

    /**
     * A plausible postcode from the region's prefix: a US prefix (digits) is
     * padded to a five-digit ZIP; a Canadian one (letter first) becomes
     * "A1B 2C3" with its forward sortation area starting with the prefix.
     */
    private function postcode(string $prefix): string
    {
        if (ctype_digit($prefix)) {
            $zip = $prefix;

            while (strlen($zip) < 5) {
                $zip .= $this->random->getInt(0, 9);
            }

            return $zip;
        }

        $area = $prefix;

        while (strlen($area) < 3) {
            $area .= ctype_digit($area[strlen($area) - 1]) ? $this->letter() : (string) $this->random->getInt(0, 9);
        }

        return $area.' '.$this->random->getInt(0, 9).$this->letter().$this->random->getInt(0, 9);
    }

    private function letter(): string
    {
        return self::POSTAL_LETTERS[$this->random->getInt(0, strlen(self::POSTAL_LETTERS) - 1)];
    }

    private static function coordinate(float $value): string
    {
        return number_format($value, 5, '.', '');
    }
}
