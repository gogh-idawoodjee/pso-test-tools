<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ResourceActivityFilterService
{
    public function __construct(
        protected array $data,
        protected array $regionIds,
        protected ?string $jobId = null,
    ) {}

    public function filter(): array
    {
        $regionIds = collect($this->regionIds)->filter()->map(fn($id) => trim($id))->toArray();

        // 1. Resource region filtering
        $validResourceIds = collect($this->data['Resource_Region'] ?? [])
            ->filter(fn($rr) => in_array($rr['region_id'], $regionIds))
            ->pluck('resource_id')
            ->unique()
            ->toArray();

        $resources = collect($this->data['Resources'] ?? []);
        $filteredResources = $resources->whereIn('id', $validResourceIds)->values();

        $shifts = collect($this->data['Shift'] ?? []);
        $filteredShifts = $shifts->whereIn('resource_id', $validResourceIds)->values();

        $validShiftIds = $filteredShifts->pluck('id')->toArray();

        $shiftBreaks = collect($this->data['Shift_Break'] ?? []);
        $filteredShiftBreaks = $shiftBreaks->whereIn('shift_id', $validShiftIds)->values();

        $resourceSkills = collect($this->data['Resource_Skill'] ?? []);
        $filteredResourceSkills = $resourceSkills->whereIn('resource_id', $validResourceIds)->values();

        $resourceRegions = collect($this->data['Resource_Region'] ?? []);
        $filteredResourceRegions = $resourceRegions->whereIn('resource_id', $validResourceIds)->values();

        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", 25);
            usleep(300_000);
        }

        // 2. Activity region filtering
        $validLocationIds = collect($this->data['Location_Region'] ?? [])
            ->filter(fn($lr) => in_array($lr['region_id'], $regionIds))
            ->pluck('location_id')
            ->unique()
            ->toArray();

        $allActivities = collect($this->data['Activity'] ?? []);
        $skipped = $allActivities->filter(fn($a) => !isset($a['location_id']))->count();
        $filteredActivities = $allActivities
            ->filter(fn($a) => isset($a['location_id']) && in_array($a['location_id'], $validLocationIds))
            ->values();

        $validActivityIds = $filteredActivities->pluck('id')->toArray();

        $activitySLAs = collect($this->data['Activity_SLA'] ?? []);
        $filteredActivitySLAs = $activitySLAs->whereIn('activity_id', $validActivityIds)->values();

        $activityStatuses = collect($this->data['Activity_Status'] ?? []);
        $filteredActivityStatuses = $activityStatuses->whereIn('activity_id', $validActivityIds)->values();

        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", 50);
            usleep(300_000);
        }

        // Prepare output
        $filtered = $this->data;
        $filtered['Resources'] = $filteredResources->all();
        $filtered['Shift'] = $filteredShifts->all();
        $filtered['Shift_Break'] = $filteredShiftBreaks->all();
        $filtered['Resource_Skill'] = $filteredResourceSkills->all();
        $filtered['Resource_Region'] = $filteredResourceRegions->all();
        $filtered['Activity'] = $filteredActivities->all();
        $filtered['Activity_SLA'] = $filteredActivitySLAs->all();
        $filtered['Activity_Status'] = $filteredActivityStatuses->all();

        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", 75);
            usleep(300_000);
        }

        // Prepare summary
        $summary = [
            'resources' => [
                'total' => $resources->count(),
                'kept' => $filteredResources->count(),
            ],
            'shifts' => [
                'total' => $shifts->count(),
                'kept' => $filteredShifts->count(),
            ],
            'shift_breaks' => [
                'total' => $shiftBreaks->count(),
                'kept' => $filteredShiftBreaks->count(),
            ],
            'resource_skills' => [
                'total' => $resourceSkills->count(),
                'kept' => $filteredResourceSkills->count(),
            ],
            'resource_regions' => [
                'total' => $resourceRegions->count(),
                'kept' => $filteredResourceRegions->count(),
            ],
            'activities' => [
                'total' => $allActivities->count(),
                'kept' => $filteredActivities->count(),
                'skipped' => $skipped,
            ],
            'activity_slas' => [
                'total' => $activitySLAs->count(),
                'kept' => $filteredActivitySLAs->count(),
            ],
            'activity_statuses' => [
                'total' => $activityStatuses->count(),
                'kept' => $filteredActivityStatuses->count(),
            ],
        ];

        return compact('filtered', 'summary');
    }
}
