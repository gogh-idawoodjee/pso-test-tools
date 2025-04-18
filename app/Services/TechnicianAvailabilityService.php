<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Log;

class TechnicianAvailabilityService
{
    public function __construct(
        protected array   $data,
        protected ?string $jobId = null,
    )
    {
    }

    public function filter(): array
    {
        Log::info('5 percent mark');
        $this->updateProgress(5); // after loading file
        Log::info('5 percent mark');
        $regionIds = collect($this->regionIds)->filter()->map(static fn($id) => trim($id))->toArray();
        $this->updateProgress(10); // after first block

        // — Step 1: Filter resource-related stuff
        $validResourceIds = collect($this->data['Resource_Region'] ?? [])
            ->filter(static fn($rr) => in_array($rr['region_id'], $regionIds))
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

        $this->updateProgress(25);
        Log::info('25 percent mark');

        // — Step 2: Filter activity-related stuff
        $validLocationIds = collect($this->data['Location_Region'] ?? [])
            ->filter(static fn($lr) => in_array($lr['region_id'], $regionIds))
            ->pluck('location_id')
            ->unique()
            ->toArray();

        $this->updateProgress(50);
        Log::info('50 percent mark');

        $allActivities = collect($this->data['Activity'] ?? []);
        $skipped = $allActivities->filter(static fn($a) => !isset($a['location_id']))->count();

        $filteredActivities = $allActivities
            ->filter(static fn($a) => isset($a['location_id']) && in_array($a['location_id'], $validLocationIds))
            ->values();

        $validActivityIds = $filteredActivities->pluck('id')->toArray();

        $activitySLAs = collect($this->data['Activity_SLA'] ?? []);
        $filteredActivitySLAs = $activitySLAs->whereIn('activity_id', $validActivityIds)->values();

        $activityStatuses = collect($this->data['Activity_Status'] ?? []);
        $filteredActivityStatuses = $activityStatuses->whereIn('activity_id', $validActivityIds)->values();

        $this->updateProgress(75);
        Log::info('75 percent mark');


        // — Step 3: Build filtered dataset
        $filtered = $this->data;
        $filtered['Resources'] = $filteredResources->all();
        $filtered['Shift'] = $filteredShifts->all();
        $filtered['Shift_Break'] = $filteredShiftBreaks->all();
        $filtered['Resource_Skill'] = $filteredResourceSkills->all();
        $filtered['Resource_Region'] = $filteredResourceRegions->all();
        $filtered['Activity'] = $filteredActivities->all();
        $filtered['Activity_SLA'] = $filteredActivitySLAs->all();
        $filtered['Activity_Status'] = $filteredActivityStatuses->all();

        $this->updateProgress(90);
        Log::info('90 percent mark');

        // — Step 4: Build summary
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
        $this->updateProgress(100);

        return compact('filtered', 'summary');

    }

    protected function updateProgress(int $percent): void
    {

//        Log::info("Progress updated to {$percent} for job {$this->jobId}");

        if ($this->jobId) {
            Log::info('percent complete:' . $percent);
            Cache::put("resource-job:{$this->jobId}:progress", $percent);
            usleep(500_000); // 0.5 sec
        }
    }
}
