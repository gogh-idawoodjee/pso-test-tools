<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use App\Support\DatasetBuilder\Spec\ResourceSpec;
use DateTimeImmutable;
use DateTimeZone;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Everything one generate run decides, made once: `now` in UTC, the update id
 * both files carry, and every location (the only random draws, all from one
 * seeded engine in spec order). The LOAD and the modelling data are both
 * written from this, so neither re-derives anything.
 */
readonly class DatasetModel
{
    /**
     * @param  list<array<string, string>>  $locations  Location fields: depots in region order, then homes in resource order
     * @param  array<string, string>  $startLocations  resource id => its start and end location id
     */
    private function __construct(
        public DatasetSpec $spec,
        public DateTimeImmutable $now,
        public array $locations,
        public array $startLocations,
    ) {}

    public static function build(DatasetSpec $spec, DateTimeImmutable $now): self
    {
        $factory = new LocationFactory(new Randomizer(new Mt19937($spec->seed)));
        $locations = [];
        $startLocations = [];

        foreach ($spec->regions as $region) {
            if ($region->depot !== null) {
                $locations[] = LocationFactory::depot($region);
            }
        }

        foreach ($spec->resources as $resource) {
            if ($spec->resourceTypes[$resource->resourceType]->startsAtDepot()) {
                $startLocations[$resource->id] = "D-{$resource->region}";

                continue;
            }

            $home = $factory->home($resource, $spec->regions[$resource->region]);
            $locations[] = $home;
            $startLocations[$resource->id] = $home['id'];
        }

        return new self($spec, $now->setTimezone(new DateTimeZone('UTC')), $locations, $startLocations);
    }

    /** Input_Reference.id and RAM_Update.id: the run's unix timestamp. */
    public function updateId(): string
    {
        return (string) $this->now->getTimestamp();
    }

    public function startLocation(ResourceSpec $resource): string
    {
        return $this->startLocations[$resource->id];
    }
}
