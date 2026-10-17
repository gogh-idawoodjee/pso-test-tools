<?php

namespace App\Support\TrimExport;

/** What the Rows step offers for one export, gathered by one streamed pass. Holds customer ids and names: cache it encrypted only. */
class FilterChoices
{
    /** How many matches a search offers; the full lists can run to thousands of rows. */
    public const int SEARCH_LIMIT = 50;

    /**
     * @param  array<string, string>  $regions  id => "V1 - Regina"
     * @param  array<string, string>  $resources  id => "R1 - Ada Lovelace"
     * @param  array<string, string>  $activityTypes  id => "T1 - Install (3)", only types some activity has; TrimPlan::DEFAULT_ACTIVITY_TYPE => "Default (2)" for a blank type
     * @param  array<string, string>  $activities  id => "A1 - T1"
     * @param  array<string, list<string>>  $resourceRegions  resource id => region ids (Resource_Region)
     * @param  array<string, string>  $activityTypeOf  activity id => type id (TrimPlan::DEFAULT_ACTIVITY_TYPE for a blank type)
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

    /**
     * The options whose label (which starts with the id) contains the search, ignoring case, at most SEARCH_LIMIT.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function matching(array $options, string $search, int $limit = self::SEARCH_LIMIT): array
    {
        $search = trim($search);
        $found = [];
        foreach ($options as $id => $label) {
            if ($search === '' || mb_stripos($label, $search) !== false) {
                $found[$id] = $label;
                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The labels of the given ids that are among the options; any other id is left out, so validation refuses it.
     *
     * @param  array<string, string>  $options
     * @param  array<mixed>  $ids
     * @return array<string, string>
     */
    public static function labelsOf(array $options, array $ids): array
    {
        $labels = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && isset($options[(string) $id])) {
                $labels[(string) $id] = $options[(string) $id];
            }
        }

        return $labels;
    }
}
