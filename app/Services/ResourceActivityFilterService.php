<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;


/**
 * Service for filtering resources, shifts, and activities based on region constraints
 *
 * This service processes large datasets by applying region, resource, and activity filters,
 * while tracking progress for long-running operations.
 */
class ResourceActivityFilterService
{
    /**
     * Cache of valid resource IDs after filtering
     */
    protected array $validResourceIds = [];

    /**
     * Cache of valid shift IDs after filtering
     */
    protected array $validShiftIds = [];

    /**
     * Cache of valid location IDs after filtering
     */
    protected array $validLocationIds = [];

    /**
     * Cache of valid activity IDs after filtering
     */
    protected array $validActivityIds = [];

    /**
     * Tracks the current progress step for percentage calculations
     */
    protected int $progressStep = 0;

    /**
     * Total number of major processing steps for progress calculation
     */
    protected int $totalSteps = 5;

    /**
     * Constructor for the filter service
     *
     * @param array $data The dataset to filter (contains Resources, Shifts, Activities, etc.)
     * @param array $regionIds Region IDs to filter by
     * @param string|null $jobId Optional job ID for progress tracking
     * @param string|null $overrideDatetime Optional datetime to override the input reference
     * @param array $resourceIds Optional specific resource IDs to filter by
     * @param array $activityIds Optional specific activity IDs to filter by
     * @param bool $isDryRun When true, skips filtering and returns original data
     */
    public function __construct(
        protected array   $data,
        protected array   $regionIds,
        protected ?string $jobId = null,
        protected ?string $overrideDatetime = null,
        protected array   $resourceIds = [],
        protected array   $activityIds = [],
        protected bool    $isDryRun = false,
    )
    {
        // Initialize progress tracking at 0%
        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", 0);
        }
    }

    /**
     * Main entry point to filter the dataset
     *
     * Applies region, resource, and activity filters to the input data
     * and returns the filtered data with summary statistics
     *
     * @return array Contains 'filtered' data and 'summary' statistics
     */
    public function filter(): array
    {
        // Fast path for dry run mode - return original data with summary
        if ($this->isDryRun) {
            return $this->handleDryRun();
        }

        // Bail early if no regions are specified
        // actually not bailing
//        if (empty($this->regionIds)) {
//            return ['filtered' => [], 'summary' => $this->generateSummary([])];
//        }

        // Process input reference and override datetime if needed
        $inputReference = $this->getInputReference();

        // Apply all filters and generate the resulting dataset
        $filtered = $this->applyFilters($inputReference);
        $this->updateProgress(100); // Mark as fully complete

        return [
            'filtered' => $filtered,
            'summary' => $this->generateSummary($filtered),
        ];
    }

    /**
     * Handles dry run mode by skipping filtering and returning original data
     *
     * @return array Contains original data and summary statistics
     */
    protected function handleDryRun(): array
    {
        Log::info('🧪 Dry run detected — skipping all filtering logic');

        return [
            'filtered' => $this->data,
            'summary' => $this->generateSummary($this->data),
        ];
    }

    /**
     * Generates summary statistics for the filtered dataset
     *
     * Compares original data size with filtered data size for each section
     *
     * @param array $data The filtered dataset
     * @return array Summary statistics
     */
    protected function generateSummary(array $data): array
    {
        // Map of friendly section names to data keys
        $sections = [
            'resources' => 'Resources',
            'shifts' => 'Shift',
            'shift_breaks' => 'Shift_Break',
            'resource_skills' => 'Resource_Skill',
            'resource_regions' => 'Resource_Region',
            'activities' => 'Activity',
            'activity_slas' => 'Activity_SLA',
            'activity_statuses' => 'Activity_Status',
        ];

        $summary = [];
        foreach ($sections as $key => $section) {
            $total = count($this->data[$section] ?? []);
            $kept = count($data[$section] ?? []);
            $summary[$key] = compact('total', 'kept');

            // Add skipped count only for activities
            if ($key === 'activities') {
                $summary[$key]['skipped'] = $total - $kept;
            }
        }

        return $summary;
    }

    /**
     * Main filtering method that orchestrates the entire filtering process
     *
     * @param array $inputReference The input reference data
     * @return array The filtered dataset
     */
    protected function applyFilters(array $inputReference): array
    {
        $filtered = $this->data;

        // Step 1 and 4 only apply if regionIds were provided
        if (!empty($this->regionIds)) {
            // Step 1: Filter resources by region
            $this->filterResourcesByRegion();
            $this->updateProgressStep();

            // Step 4: Filter locations by region
            $this->filterLocationsByRegion();
            $this->updateProgressStep();
        } else {
            // No region filtering – use all available IDs
            $this->validResourceIds = collect($this->data['Resources'] ?? [])->pluck('id')->toArray();
            $this->validLocationIds = collect($this->data['Location'] ?? [])->pluck('id')->toArray();
        }

        // Step 2: Filter resources by specific resource IDs (optional)
        if (!empty($this->resourceIds)) {
            $this->filterResourcesByIds();
        }

        // Step 3: Filter shifts and related data for valid resources
        $this->filterShiftsAndRelatedData();
        $this->updateProgressStep();

        // Step 5: Filter activities and related data for valid locations
        $this->filterActivitiesAndRelatedData();
        $this->updateProgressStep();

        // Assemble final dataset
        $filtered['Resources'] = $this->getFilteredData('Resources', 'id', $this->validResourceIds);
        $filtered['Shift'] = $this->getFilteredData('Shift', 'id', $this->validShiftIds);
        $filtered['Shift_Break'] = $this->getFilteredData('Shift_Break', 'shift_id', $this->validShiftIds);
        $filtered['Resource_Skill'] = $this->getFilteredData('Resource_Skill', 'resource_id', $this->validResourceIds);
        $filtered['Resource_Region'] = $this->getFilteredData('Resource_Region', 'resource_id', $this->validResourceIds);
        $filtered['Activity'] = $this->getFilteredData('Activity', 'id', $this->validActivityIds);
        $filtered['Activity_SLA'] = $this->getFilteredData('Activity_SLA', 'activity_id', $this->validActivityIds);
        $filtered['Activity_Status'] = $this->getFilteredData('Activity_Status', 'activity_id', $this->validActivityIds);
        $filtered['Input_Reference'] = $inputReference;

        return $filtered;
    }


    /**
     * Filters resources based on region constraints
     *
     * Identifies resources that are assigned to any of the specified regions
     */
    protected function filterResourcesByRegion(): void
    {
        // Find all resources that are associated with any of the target regions
        $this->validResourceIds = collect($this->data['Resource_Region'] ?? [])
            ->filter(fn($rr) => in_array($rr['region_id'], $this->regionIds))
            ->pluck('resource_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Further filters resources by specified resource IDs
     *
     * Only keeps resources that match both region constraints and specified IDs
     */
    protected function filterResourcesByIds(): void
    {
        // If no specific resource IDs were provided, keep all valid ones
        if (empty($this->resourceIds)) {
            return;
        }

        // Retain only resources that are in both the region-filtered list and the specified IDs
        $this->validResourceIds = array_intersect($this->validResourceIds, $this->resourceIds);
    }


    /**
     * Filters shifts based on valid resources
     *
     * Identifies shifts assigned to valid resources after region filtering
     */
    protected function filterShiftsAndRelatedData(): void
    {
        // Get all shifts belonging to the valid resources
        $this->validShiftIds = collect($this->data['Shift'] ?? [])
            ->filter(fn($shift) => in_array($shift['resource_id'], $this->validResourceIds))
            ->pluck('id')
            ->values()
            ->toArray();
    }

    /**
     * Filters locations based on region constraints
     *
     * Identifies locations that belong to any of the specified regions
     */
    protected function filterLocationsByRegion(): void
    {
        // Find all locations that are in any of the target regions
        $this->validLocationIds = collect($this->data['Location_Region'] ?? [])
            ->filter(fn($lr) => in_array($lr['region_id'], $this->regionIds))
            ->pluck('location_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Filters activities based on valid locations and optional activity IDs
     *
     * Identifies activities that are at valid locations and optionally match specified IDs
     */
    protected function filterActivitiesAndRelatedData(): void
    {
        $activities = collect($this->data['Activity'] ?? []);

        // Apply region-based filtering if applicable
        if (!empty($this->regionIds)) {
            $activities = $activities->filter(fn($a) => isset($a['location_id']) && in_array($a['location_id'], $this->validLocationIds)
            );
        }

        // Apply activity ID filtering if applicable
        if (!empty($this->activityIds)) {
            $activities = $activities->filter(fn($a) => in_array($a['id'], $this->activityIds));
        }

        // If no filters were applied at all, keep all activity IDs
        $this->validActivityIds = $activities->pluck('id')->values()->toArray();
    }


    /**
     * Generic method to filter a data section by valid IDs
     *
     * @param string $section The data section name (e.g., 'Resources', 'Shift')
     * @param string $keyField The field name to match against valid IDs
     * @param array $validIds The list of valid IDs to filter by
     * @return array Filtered data section
     */
    protected function getFilteredData(string $section, string $keyField, array $validIds): array
    {
        return array_values(array_filter(
            $this->data[$section] ?? [],
            static fn($item) => in_array($item[$keyField], $validIds)
        ));
    }

    /**
     * Updates progress based on completed steps
     *
     * Increments the progress step counter and calculates percentage
     */
    protected function updateProgressStep(): void
    {
        if ($this->jobId) {
            $this->progressStep++;

            // Calculate percentage based on completed steps (capped at 95%)
            // The final 5% is added when completely done
            $percent = min(95, (int)(($this->progressStep / $this->totalSteps) * 100));

            Cache::put("resource-job:{$this->jobId}:progress", $percent);
        }
    }

    /**
     * Updates progress with a specific percentage value
     *
     * @param int $percent The percentage value to set (0-100)
     */
    protected function updateProgress(int $percent): void
    {
        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", $percent);
        }
    }

    protected function getInputReference(): array
    {
        $ref = $this->data['Input_Reference'] ?? [];
        if ($this->overrideDatetime) {
            $ref['datetime'] = Carbon::parse($this->overrideDatetime)->toIso8601String();
        }
        return $ref;
    }
}
