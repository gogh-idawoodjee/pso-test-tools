<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\TableReader;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pass one: reads the key fields of the tables the row filters need, once, and resolves which resources,
 * shifts, activities, locations and availability rows stay. Memory holds ids only.
 */
class KeyCollector
{
    /** The fields read per table; every other table is skipped unread. */
    private const array FIELDS = [
        'Resources' => ['id', 'location_id_start', 'location_id_end'],
        'Resource_Region' => ['resource_id', 'region_id'],
        'Location' => ['id', 'region_id'],
        'Location_Region' => ['location_id', 'region_id'],
        'Activity' => ['id', 'location_id', 'activity_type_id', 'availability_id'],
        'Activity_SLA' => ['activity_id', 'sla_type_id', 'datetime_start', 'datetime_end'],
        'Shift' => ['id', 'resource_id', 'start_datetime', 'end_datetime', 'location_id_start', 'location_id_end'],
        'Resource_Type' => ['location_id_start', 'location_id_end'],
        'Resource_Region_Availability' => ['resource_id', 'availability_id'],
        'Resource_Skill_Availability' => ['resource_id', 'availability_id'],
    ];

    /** @param Closure(int): void|null $progress percent of the file read */
    public function collect(TableReader $reader, string $path, TrimPlan $plan, ?Closure $progress = null): KeepSets
    {
        $regions = array_fill_keys($plan->regionIds, true);
        $byRegion = $regions !== [];
        $windowed = $plan->windowStart !== null && $plan->windowEnd !== null;

        $resources = []; // id => [start and end location ids]
        $regionResources = [];
        $regionLocations = [];
        $activities = []; // id => [location id, type id, availability id]
        $windowActivities = [];
        $shifts = []; // id => [resource id, [start and end location ids]]
        $typeLocations = [];
        $availabilityPairs = []; // [resource id, availability id]

        $size = max(1, (int) @filesize($path));
        $rows = 0;
        $lastPercent = -1;

        foreach ($reader->segments($path) as $segment) {
            if ($segment->type !== Segment::ROW || ! isset(self::FIELDS[$segment->table])) {
                continue;
            }

            if ($progress !== null && ++$rows % 5000 === 0) {
                $percent = min(100, intdiv($segment->offset * 100, $size));
                if ($percent !== $lastPercent) {
                    $progress($percent);
                    $lastPercent = $percent;
                }
            }

            $table = $segment->table;
            $fields = $reader->fieldsOf($segment->body, self::FIELDS[$table]);
            $id = $fields['id'] ?? null;

            switch ($table) {
                case 'Resources':
                    if ($id !== null) {
                        $resources[$id] = self::locationsOf($fields);
                    }
                    break;
                case 'Resource_Region':
                    if ($byRegion && isset($fields['resource_id'], $fields['region_id'], $regions[$fields['region_id']])) {
                        $regionResources[$fields['resource_id']] = true;
                    }
                    break;
                case 'Location':
                    if ($byRegion && $id !== null && isset($fields['region_id'], $regions[$fields['region_id']])) {
                        $regionLocations[$id] = true;
                    }
                    break;
                case 'Location_Region':
                    if ($byRegion && isset($fields['location_id'], $fields['region_id'], $regions[$fields['region_id']])) {
                        $regionLocations[$fields['location_id']] = true;
                    }
                    break;
                case 'Activity':
                    if ($id !== null) {
                        $activities[$id] = [$fields['location_id'] ?? null, $fields['activity_type_id'] ?? '', $fields['availability_id'] ?? null];
                    }
                    break;
                case 'Activity_SLA':
                    if ($windowed && ($fields['sla_type_id'] ?? null) === 'APPOINTMENT' && isset($fields['activity_id'])
                        && self::overlaps($fields['datetime_start'] ?? null, $fields['datetime_end'] ?? null, $plan)) {
                        $windowActivities[$fields['activity_id']] = true;
                    }
                    break;
                case 'Shift':
                    if ($id !== null && isset($fields['resource_id'])
                        && (! $windowed || self::overlaps($fields['start_datetime'] ?? null, $fields['end_datetime'] ?? null, $plan))) {
                        $shifts[$id] = [$fields['resource_id'], self::locationsOf($fields)];
                    }
                    break;
                case 'Resource_Type':
                    if (! $plan->dropsTable($table)) {
                        foreach (self::locationsOf($fields) as $location) {
                            $typeLocations[$location] = true;
                        }
                    }
                    break;
                default: // Resource_Region_Availability, Resource_Skill_Availability
                    if (! $plan->dropsTable($table) && isset($fields['resource_id'], $fields['availability_id'])) {
                        $availabilityPairs[] = [$fields['resource_id'], $fields['availability_id']];
                    }
            }
        }

        $keptResources = array_fill_keys(array_keys($resources), true);
        if ($byRegion) {
            $keptResources = array_intersect_key($keptResources, $regionResources);
        }
        if ($plan->resourceIds !== []) {
            $keptResources = array_intersect_key($keptResources, array_fill_keys($plan->resourceIds, true));
        }

        $keptShifts = [];
        foreach ($shifts as $shiftId => [$resourceId]) {
            if (isset($keptResources[$resourceId])) {
                $keptShifts[$shiftId] = true;
            }
        }

        $types = array_fill_keys($plan->activityTypeIds, true);
        $chosen = array_fill_keys($plan->activityIds, true);
        $keptActivities = [];
        foreach ($activities as $activityId => [$locationId, $typeId]) {
            if (($byRegion && ($locationId === null || ! isset($regionLocations[$locationId])))
                || ($types !== [] && ! isset($types[$typeId]))
                || ($chosen !== [] && ! isset($chosen[$activityId]))
                || ($windowed && ! isset($windowActivities[$activityId]))) {
                continue;
            }
            $keptActivities[$activityId] = true;
        }

        $keptAvailability = [];
        foreach ($availabilityPairs as [$resourceId, $availabilityId]) {
            if (isset($keptResources[$resourceId])) {
                $keptAvailability[$availabilityId] = true;
            }
        }

        $keptLocations = $typeLocations;
        if (! $plan->dropsTable('Activity')) {
            foreach (array_keys($keptActivities) as $activityId) {
                [$locationId, , $availabilityId] = $activities[$activityId];
                if ($locationId !== null) {
                    $keptLocations[$locationId] = true;
                }
                if ($availabilityId !== null) {
                    $keptAvailability[$availabilityId] = true;
                }
            }
        }
        if (! $plan->dropsTable('Resources')) {
            foreach (array_keys($keptResources) as $resourceId) {
                foreach ($resources[$resourceId] as $location) {
                    $keptLocations[$location] = true;
                }
            }
        }
        if (! $plan->dropsTable('Shift')) {
            foreach (array_keys($keptShifts) as $shiftId) {
                foreach ($shifts[$shiftId][1] as $location) {
                    $keptLocations[$location] = true;
                }
            }
        }

        $notes = [];
        if ($resources !== [] && $keptResources === []) {
            $notes[] = 'No resources match the row filters, so the file keeps none.';
        }
        if ($activities !== [] && $keptActivities === []) {
            $notes[] = 'No activities match the row filters, so the file keeps none.';
        }

        return new KeepSets($keptResources, $keptShifts, $keptActivities, $keptLocations, $keptAvailability, $notes);
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private static function locationsOf(array $fields): array
    {
        return array_values(array_filter([$fields['location_id_start'] ?? null, $fields['location_id_end'] ?? null], static fn (?string $location): bool => $location !== null));
    }

    private static function overlaps(?string $start, ?string $end, TrimPlan $plan): bool
    {
        $from = self::timestamp($start);
        $to = self::timestamp($end);

        return $from !== null && $to !== null && $from <= $plan->windowEnd && $to >= $plan->windowStart;
    }

    /** PSO writes exported times in UTC; a time without an offset is UTC. */
    private static function timestamp(?string $value): ?int
    {
        static $utc = null;
        $utc ??= new DateTimeZone('UTC');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, $utc))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
