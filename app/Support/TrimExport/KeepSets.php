<?php

namespace App\Support\TrimExport;

/**
 * The ids pass one decided to keep, and the rule pass two applies to every row of a kept table: the table's own
 * id for Resources, Shift, Activity and Location; for Availability, the cascade plus being referenced or owned
 * by a kept activity (never a date); for every other table, the generic cascade of #26.
 */
class KeepSets
{
    /** A row whose field points at a removed row of these sets goes with it. */
    public const array CASCADE = [
        'activity_id' => 'activities',
        'activity_id1' => 'activities',
        'activity_id2' => 'activities',
        'resource_id' => 'resources',
        'location_id' => 'locations',
        'shift_id' => 'shifts',
    ];

    /**
     * Tables that only follow their activity, as Filter Load File rebuilt them: a status fixed to a resource
     * outside the filter stays with its kept activity and is reported as dangling, not dropped.
     */
    public const array CASCADE_ONLY = [
        'Activity_Status' => ['activity_id'],
        'Activity_SLA' => ['activity_id'],
    ];

    /** Every field keepsRow() reads. */
    public const array FIELDS = ['id', 'activity_id', 'activity_id1', 'activity_id2', 'resource_id', 'location_id', 'shift_id'];

    /**
     * @param  array<string|int, true>  $resources
     * @param  array<string|int, true>  $shifts
     * @param  array<string|int, true>  $activities
     * @param  array<string|int, true>  $locations
     * @param  array<string|int, true>  $availability
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $resources,
        public array $shifts,
        public array $activities,
        public array $locations,
        public array $availability,
        public array $notes = [],
    ) {}

    /** @param array<string, string> $fields */
    public function keepsRow(string $table, array $fields): bool
    {
        $id = $fields['id'] ?? null;

        return match ($table) {
            'Resources' => $id !== null && isset($this->resources[$id]),
            'Shift' => $id !== null && isset($this->shifts[$id]),
            'Activity' => $id !== null && isset($this->activities[$id]),
            'Location' => $id !== null && isset($this->locations[$id]),
            'Availability' => $this->cascades($table, $fields)
                && (($id !== null && isset($this->availability[$id])) || (isset($fields['activity_id']) && isset($this->activities[$fields['activity_id']]))),
            default => $this->cascades($table, $fields),
        };
    }

    /** @return list<string> */
    public function ids(string $set): array
    {
        return array_map('strval', array_keys($this->{$set}));
    }

    /** @param array<string, string> $fields */
    private function cascades(string $table, array $fields): bool
    {
        foreach (self::CASCADE_ONLY[$table] ?? array_keys(self::CASCADE) as $field) {
            $value = $fields[$field] ?? null;
            if ($value !== null && ! isset($this->{self::CASCADE[$field]}[$value])) {
                return false;
            }
        }

        return true;
    }
}
