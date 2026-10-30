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
 * both files carry, every home and depot location and (stage 2) the
 * activities. The random draws all come from one seeded engine: homes first in
 * spec order, then activities, so adding activities never moves a home. The
 * LOAD and the modelling data are both written from this, so neither
 * re-derives anything.
 */
readonly class DatasetModel
{
    /**
     * @param  list<array<string, string>>  $locations  Location fields: depots in region order, then homes in resource order
     * @param  array<string, string>  $startLocations  resource id => its start and end location id
     * @param  list<array<string, mixed>>  $activities  ActivityFactory::activities()
     */
    private function __construct(
        public DatasetSpec $spec,
        public DateTimeImmutable $now,
        public array $locations,
        public array $startLocations,
        public array $activities,
    ) {}

    public static function build(DatasetSpec $spec, DateTimeImmutable $now): self
    {
        $random = new Randomizer(new Mt19937($spec->seed));
        $factory = new LocationFactory($random);
        $now = $now->setTimezone(new DateTimeZone('UTC'));
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

        return new self($spec, $now, $locations, $startLocations, ActivityFactory::activities($spec, $now, $random, $factory));
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

    /**
     * One appointment template per region and kind the region lists, id
     * {REGION}-{KIND}, in the region's own time zone. number_of_slots is the
     * working days in the day pattern times the slots in a day.
     *
     * @return list<array{id: string, description: string, day_pattern: string, number_of_slots: string, appointment_window_length: string, time_zone: string, items: list<array{start: string, end: string}>}>
     */
    public function appointmentTemplates(): array
    {
        $templates = [];

        foreach ($this->spec->regions as $region) {
            foreach ($region->appointmentTemplates as $kind) {
                $template = $this->spec->appointmentTemplateKinds[$kind];

                $templates[] = [
                    'id' => "{$region->id}-{$kind}",
                    'description' => "{$region->description} {$template['description']}",
                    'day_pattern' => $template['day_pattern'],
                    'number_of_slots' => (string) (substr_count($template['day_pattern'], 'Y') * count($template['items'])),
                    'appointment_window_length' => $template['appointment_window_length'],
                    'time_zone' => $region->timeZone,
                    'items' => $template['items'],
                ];
            }
        }

        return $templates;
    }
}
