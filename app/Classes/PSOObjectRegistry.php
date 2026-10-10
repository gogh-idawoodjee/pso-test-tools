<?php

namespace App\Classes;

class PSOObjectRegistry
{
    public static function all(): array
    {
        return [
            'activity_sla' => [
                'label' => 'Activity SLA',
                'entity' => 'Activity_SLA',
                'attributes' => [
                    ['name' => 'activity_id', 'type' => 'string'],
                    ['name' => 'priority', 'type' => 'int'],
                    ['name' => 'sla_type_id', 'type' => 'string'],
                    ['name' => 'start_based', 'type' => 'boolean'],
                ],
            ],
            'activity_skill' => [
                'label' => 'Activity Skill',
                'entity' => 'Activity_Skill',
                'attributes' => [
                    ['name' => 'activity_id', 'type' => 'string'],
                    ['name' => 'skill_id', 'type' => 'string'],
                ],
            ],
            'shift' => [
                'label' => 'Shift',
                'entity' => 'Shift',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
            'activity' => [
                'label' => 'Activity',
                'entity' => 'Activity',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
            'resource' => [
                'label' => 'Resource',
                'entity' => 'Resource',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string']
                ],
            ],
            'location' => [
                'label' => 'Location',
                'entity' => 'Location',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
            'unavailability' => [
                'label' => 'Unavilability',
                'entity' => 'Unavailability',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
            'location_region' => [
                'label' => 'Location Region',
                'entity' => 'Location_Region',
                'attributes' => [
                    ['name' => 'location_id', 'type' => 'string'],
                    ['name' => 'region_id', 'type' => 'string'],
                ],
            ],
            'schedule_event' => [
                'label' => 'Schedule Event',
                'entity' => 'Schedule_Event',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
            'resource_region' => [
                'label' => 'Resource Region',
                'entity' => 'Resource_Region',
                'attributes' => [
                    ['name' => 'region_id', 'type' => 'string'],
                    ['name' => 'resource_id', 'type' => 'string'],
                ],
            ],
            'resource_region_availability' => [
                'label' => 'Resource Region Availability',
                'entity' => 'Resource_Region_Availability',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Identifier tables a request can reference (region, skill, activity
     * type, slot usage rule set, appointment template, SLA type). Kept
     * apart from all(): these are not deletable objects, and their
     * attributes are columns to read, not primary keys. Mirrors the
     * same method in pso-services.
     */
    public static function referenceValueEntries(): array
    {
        return [
            'region' => [
                'label' => 'Region',
                'entity' => 'Region',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                ],
            ],
            'skill' => [
                'label' => 'Skill',
                'entity' => 'Skill',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                ],
            ],
            'activity_type' => [
                'label' => 'Activity Type',
                'entity' => 'Activity_Type',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                    ['name' => 'priority', 'type' => 'int'],
                    ['name' => 'base_value', 'type' => 'float'],
                    // PSO sends a time span (PT1H30M); the parser stores whole minutes.
                    ['name' => 'duration', 'type' => 'minutes'],
                ],
            ],
            'slot_usage_rule' => [
                'label' => 'Slot Usage Rule Set',
                'entity' => 'Slot_Usage_Rule_Set',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                ],
            ],
            'appointment_template' => [
                'label' => 'Appointment Template',
                'entity' => 'Appointment_Template',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                ],
            ],
            'sla_type' => [
                'label' => 'SLA Type',
                'entity' => 'SLA_Type',
                'attributes' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'string'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? self::referenceValueEntries()[$key] ?? null;
    }

    public static function forSelect(): array
    {
        return collect(self::all())
            ->mapWithKeys(static fn($item, $key) => [$key => $item['label']])
            ->sort()
            ->toArray();
    }

    public static function findByEntities(array $entities): array
    {
        return collect(self::all())
            ->filter(static fn($object) => in_array($object['entity'], $entities, true))
            ->mapWithKeys(static fn($item, $key) => [$key => $item['label']])
            ->toArray();
    }
}
