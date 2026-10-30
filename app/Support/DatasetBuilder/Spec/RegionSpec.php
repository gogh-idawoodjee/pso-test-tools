<?php

namespace App\Support\DatasetBuilder\Spec;

/**
 * One region of a seed spec. `kind` is metro (homes scattered around weighted
 * centroids) or rural (homes along the line through its centroids). Homes are
 * never drawn inside an exclude polygon (a lake beside a metro, say).
 * `appointmentTemplates` are the template kinds (AMPM, ALLDAY) made for it.
 */
readonly class RegionSpec
{
    /**
     * @param  list<array{label: string, lat: float, lon: float, weight: int}>  $centroids
     * @param  array{lat: float, lon: float, address_line1: string, city: string, postcode: string}|null  $depot
     * @param  list<string>  $streetNames
     * @param  list<list<array{0: float, 1: float}>>  $excludePolygons  each a ring of [lat, lon] points
     * @param  list<string>  $appointmentTemplates  keys of DatasetSpec::$appointmentTemplateKinds
     */
    public function __construct(
        public string $id,
        public string $description,
        public string $kind,
        public string $timeZone,
        public string $state,
        public string $postcodePrefix,
        public array $centroids,
        public float $spreadKm,
        public ?array $depot,
        public array $streetNames,
        public array $excludePolygons = [],
        public array $appointmentTemplates = [],
    ) {}

    /** Whether the point falls inside one of the exclude polygons (ray casting). */
    public function excludes(float $lat, float $lon): bool
    {
        foreach ($this->excludePolygons as $ring) {
            $inside = false;
            $count = count($ring);

            for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
                [$latI, $lonI] = $ring[$i];
                [$latJ, $lonJ] = $ring[$j];

                if (($latI > $lat) !== ($latJ > $lat) && $lon < ($lonJ - $lonI) * ($lat - $latI) / ($latJ - $latI) + $lonI) {
                    $inside = ! $inside;
                }
            }

            if ($inside) {
                return true;
            }
        }

        return false;
    }
}
