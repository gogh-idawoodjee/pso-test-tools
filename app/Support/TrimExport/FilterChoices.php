<?php

namespace App\Support\TrimExport;

/** What the Rows step offers for one export, gathered by one streamed pass. Holds customer ids and names: cache it encrypted only. */
class FilterChoices
{
    /**
     * @param  array<string, string>  $regions  id => "V1 - Regina"
     * @param  array<string, string>  $resources  id => "R1 - Ada Lovelace"
     * @param  array<string, string>  $activityTypes  id => "T1 - Install (3)", only types some activity has
     * @param  array<string, string>  $activities  id => "A1 - T1"
     * @param  array<string, list<string>>  $resourceRegions  resource id => region ids (Resource_Region)
     * @param  array<string, string>  $activityTypeOf  activity id => type id
     */
    public function __construct(
        public array $regions = [],
        public array $resources = [],
        public array $activityTypes = [],
        public array $activities = [],
        public array $resourceRegions = [],
        public array $activityTypeOf = [],
    ) {}

    /**
     * @param  list<string>  $regionIds
     * @return array<string, string>
     */
    public function resourcesIn(array $regionIds): array
    {
        if ($regionIds === []) {
            return $this->resources;
        }

        $wanted = array_flip($regionIds);
        $kept = [];
        foreach ($this->resources as $resourceId => $label) {
            foreach ($this->resourceRegions[$resourceId] ?? [] as $regionId) {
                if (isset($wanted[$regionId])) {
                    $kept[$resourceId] = $label;

                    break;
                }
            }
        }

        return $kept;
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, string>
     */
    public function activitiesOf(array $typeIds): array
    {
        if ($typeIds === []) {
            return $this->activities;
        }

        $wanted = array_flip($typeIds);

        return array_filter($this->activities, fn (string|int $activityId): bool => isset($wanted[$this->activityTypeOf[$activityId] ?? '']), ARRAY_FILTER_USE_KEY);
    }
}
