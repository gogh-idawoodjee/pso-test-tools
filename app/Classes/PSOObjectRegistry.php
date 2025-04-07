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
                'attributes' => ['activity_id', 'priority', 'sla_type_id', 'start_based'],
            ],
            'activity_skill' => [
                'label' => 'Activity Skill',
                'entity' => 'Activity_Skill',
                'attributes' => ['activity_id', 'skill_id'],
            ],
            'shift' => [
                'label' => 'Shift',
                'entity' => 'Shift',
                'attributes' => ['id'],
            ],
            'activity' => [
                'label' => 'Activity',
                'entity' => 'Activity',
                'attributes' => ['id'],
            ],
            'location' => [
                'label' => 'Location',
                'entity' => 'Location',
                'attributes' => ['id'],
            ],
            'location_region' => [
                'label' => 'Location_Region',
                'entity' => 'Location_Region',
                'attributes' => ['id'],
            ],
            'resource_region' => [
                'label' => 'Resource_Region',
                'entity' => 'Resource_Region',
                'attributes' => ['region_id', 'resource_id'],
            ],
            'resource_region_availability' => [
                'label' => 'Resource_Region_Availability',
                'entity' => 'Resource_Region_Availability',
                'attributes' => ['id'],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

}
