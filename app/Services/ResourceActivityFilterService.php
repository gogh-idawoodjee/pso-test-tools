<?php

namespace App\Services;

use App\Support\HasScopedCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;


/**
 * Service for filtering resources, shifts, and activities based on region constraints
 *
 * This service processes large datasets by applying region, resource, and activity filters,
 * while tracking progress for long-running operations.
 */
class ResourceActivityFilterService extends HasScopedCache
{

    protected array $filteredAvailabilityIds = [];
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
        protected ?Carbon $startDate = null,
        protected ?Carbon $endDate = null,
    )
    {
        // Initialize progress tracking at 0%
        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", 5);
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
        // Log initial filter parameters
        Log::info('🔍 Filter method called with parameters:', [
            'regionIds' => $this->regionIds,
            'resourceIds' => $this->resourceIds,
            'activityIds' => $this->activityIds,
            'startDate' => $this->startDate ? $this->startDate->toIso8601String() : 'null',
            'endDate' => $this->endDate ? $this->endDate->toIso8601String() : 'null',
            'isDryRun' => $this->isDryRun,
            'jobId' => $this->jobId
        ]);

        // Ensure dates are properly formatted
        if ($this->startDate) {
            $this->startDate = $this->startDate->startOfDay();
            Log::info('🕒 Start date set to: ' . $this->startDate->toIso8601String());
        } else {
            Log::warning('⚠️ No start date provided for filtering');
        }

        if ($this->endDate) {
            $this->endDate = $this->endDate->endOfDay();
            Log::info('🕒 End date set to: ' . $this->endDate->toIso8601String());
        } else {
            Log::warning('⚠️ No end date provided for filtering');
        }

        // Fast path for dry run mode - return original data with summary
        if ($this->isDryRun) {
            Log::info('🧪 Dry run detected — skipping all filtering logic');
            return $this->handleDryRun();
        }

        Log::info('🧪 Region filter activated?', ['regionIds' => $this->regionIds]);
        Log::info('📦 Activity count BEFORE filter:', ['count' => count($this->data['Activity'] ?? [])]);
        Log::info('📦 Sample Activity:', $this->data['Activity'][0] ?? []);
        Log::info('📦 Sample Location:', $this->data['Location'][0] ?? []);
        Log::info('📦 Sample Location_Region:', $this->data['Location_Region'][0] ?? []);

        // Process input reference and override datetime if needed
        $inputReference = $this->getInputReference();

        // Apply all filters and generate the resulting dataset
        $filtered = $this->applyFilters($inputReference);
        $this->updateProgress(100); // Mark as fully complete

        // Log summary of filtering results
        Log::info('📊 Filter results summary:', [
            'resourcesBefore' => count($this->data['Resources'] ?? []),
            'resourcesAfter' => count($filtered['Resources'] ?? []),
            'shiftsBefore' => count($this->data['Shift'] ?? []),
            'shiftsAfter' => count($filtered['Shift'] ?? []),
            'activitiesBefore' => count($this->data['Activity'] ?? []),
            'activitiesAfter' => count($filtered['Activity'] ?? [])
        ]);

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
        // Mapping: logical summary keys → actual data sections
        $sections = [
            'resources' => 'Resources',
            'shifts' => 'Shift',
            'shift_breaks' => 'Shift_Break',
            'resource_skills' => 'Resource_Skill',
            'resource_regions' => 'Resource_Region',
            'resource_region_availability' => 'Resource_Region_Availability',
            'availability' => 'Availability',
            'activities' => 'Activity',
            'activity_slas' => 'Activity_SLA',
            'activity_statuses' => 'Activity_Status',
            'activity_groups' => 'Activity_Group',
        ];

        $summary = [];

        foreach ($sections as $label => $section) {
            $total = count($this->data[$section] ?? []);
            $kept = count($data[$section] ?? []);
            $skipped = $total - $kept;

            $summary[$label] = compact('total', 'kept', 'skipped');
        }

        // Add counts for activity type grouping (used in dropdowns)
        if (!empty($data['Activity_Type_Counts'])) {
            $summary['activity_type_counts'] = $data['Activity_Type_Counts'];
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
        $this->filterResourcesByShiftDate(); // ⏰ at this point, validResourceIds is finalized

        Log::info('🔗 Filtering Resource_* using final validResourceIds count: ' . count($this->validResourceIds));

        $this->filterResourceRelations();     // 👈 only now should this be called
        $this->filterAvailability();         // 👈 filters avails based on updated Resource_Region_Avail
        $this->updateProgressStep();

        // Step 5: Filter activities and related data for valid locations
        $this->filterActivitiesAndRelatedData();
        $this->updateProgressStep();

        // Assemble final dataset
        $filtered['Resources'] = $this->getFilteredData('Resources', 'id', $this->validResourceIds);
        $filtered['Shift'] = $this->getFilteredData('Shift', 'id', $this->validShiftIds);

        // ✅ Already filtered inside filterResourcesByShiftDate()
        $filtered['Shift_Break'] = $this->data['Shift_Break'] ?? [];
        $filtered['Resource_Region'] = $this->getFilteredData('Resource_Region', 'resource_id', $this->validResourceIds);
        $filtered['Resource_Skill'] = $this->getFilteredData('Resource_Skill', 'resource_id', $this->validResourceIds);
        $filtered['Resource_Region_Availability'] = $this->getFilteredData('Resource_Region_Availability', 'resource_id', $this->validResourceIds);
        $filtered['Availability'] = $this->getFilteredData('Availability', 'id', $this->filteredAvailabilityIds);


        // 🔁 Activity filtering handled separately
        $filtered['Activity'] = $this->getFilteredData('Activity', 'id', $this->validActivityIds);
        $filtered['Activity_SLA'] = $this->getFilteredData('Activity_SLA', 'activity_id', $this->validActivityIds);
        $filtered['Activity_Status'] = $this->getFilteredData('Activity_Status', 'activity_id', $this->validActivityIds);
        $filtered['Activity_Group'] = $this->getFilteredActivityGroupData($this->validActivityIds);

        // 👇 Preserved Input_Reference (with override)
        $filtered['Input_Reference'] = $inputReference;

        // 🔢 Activity type counts for summary/UX
        $filtered['Activity_Type_Counts'] = collect($filtered['Activity'] ?? [])
            ->groupBy('activity_type_id')
            ->map(static fn($group) => $group->count())
            ->filter(static fn($count) => $count > 0)
            ->toArray();

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

    protected function filterResourcesByShiftDate(): void
    {
        if (!$this->startDate || !$this->endDate) {
            return;
        }

        $this->filterShiftsInDateRange();
        $this->filterResourcesWithValidShifts();
        $this->filterShiftBreaks();
//        $this->filterResourceRelations();
        $this->filterAvailability();
    }

    protected function filterShiftsInDateRange(): void
    {
        $this->validShiftIds = collect($this->data['Shift'] ?? [])
            ->filter(function ($shift) {
                $start = Carbon::parse(data_get($shift, 'start_datetime'));
                $end = Carbon::parse(data_get($shift, 'end_datetime'));

                return $start->lte($this->endDate) && $end->gte($this->startDate);
            })
            ->pluck('id')
            ->unique()
            ->values()
            ->toArray();
    }

    protected function filterResourcesWithValidShifts(): void
    {
        $resourceIds = collect($this->data['Shift'] ?? [])
            ->whereIn('id', $this->validShiftIds)
            ->pluck('resource_id')
            ->unique()
            ->values()
            ->toArray();

        $this->validResourceIds = array_values(array_intersect($this->validResourceIds, $resourceIds));
    }

    protected function filterShiftBreaks(): void
    {
        $this->data['Shift_Break'] = collect($this->data['Shift_Break'] ?? [])
            ->filter(fn($break) => in_array(data_get($break, 'shift_id'), $this->validShiftIds))
            ->values()
            ->toArray();
    }

    protected function filterResourceRelations(): void
    {
        foreach (['Resource_Region', 'Resource_Skill', 'Resource_Region_Availability'] as $section) {
            $this->data[$section] = collect($this->data[$section] ?? [])
                ->filter(fn($item) => in_array(data_get($item, 'resource_id'), $this->validResourceIds))
                ->values()
                ->toArray();
        }
    }

    protected function filterAvailability(): void
    {
        $availabilityIds = collect($this->data['Resource_Region_Availability'] ?? [])
            ->pluck('availability_id')
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        $this->filteredAvailabilityIds = $availabilityIds;

        $this->data['Availability'] = collect($this->data['Availability'] ?? [])
            ->filter(fn($item) => in_array(data_get($item, 'id'), $availabilityIds))
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
        // First: collect IDs from Location that appear in Location_Region
        $regionLocationIds = collect($this->data['Location_Region'] ?? [])
            ->filter(fn($lr) => in_array($lr['region_id'], $this->regionIds))
            ->pluck('location_id')
            ->unique()
            ->values()
            ->toArray();

        // Second: find Locations whose *own* ID matches that ID
        $this->validLocationIds = collect($this->data['Location'] ?? [])
            ->filter(static fn($loc) => in_array($loc['id'], $regionLocationIds))
            ->pluck('id')
            ->toArray();

        Log::info('🧩 Matched Location IDs via Location_Region + Location:', $this->validLocationIds);
    }


    /**
     * Filters activities based on valid locations and optional activity IDs
     *
     * Identifies activities that are at valid locations and optionally match specified IDs
     */
    protected function filterActivitiesAndRelatedData(): void
    {
        $activities = collect($this->data['Activity'] ?? []);

        // 🔍 Region filter
        if (!empty($this->regionIds)) {
            $activities = $activities->filter(fn($a) => isset($a['location_id']) && in_array($a['location_id'], $this->validLocationIds)
            );
        }

        // 🔍 Activity ID filter
        if (!empty($this->activityIds)) {
            $activities = $activities->filter(fn($a) => in_array($a['id'], $this->activityIds)
            );
        }

        // 📅 SLA date filter
        if ($this->startDate && $this->endDate) {
            $matchedActivityIds = $this->getActivityIdsWithinDateRange();

            Log::info('📌 Matched activity IDs from SLA filter:', $matchedActivityIds);

            $activities = $activities->filter(static fn($a) => in_array(data_get($a, 'id'), $matchedActivityIds)
            );
        }

        // ✅ Set final list AFTER all filters
        $this->validActivityIds = $activities->pluck('id')->values()->toArray();

        Log::info('🎯 Final valid activity IDs:', $this->validActivityIds);
    }


    /**
     * Get activity IDs from Activity_SLA that overlap the selected date range.
     *
     * Only considers APPOINTMENT-type SLAs.
     *
     * @return array
     */
    protected function getActivityIdsWithinDateRange(): array
    {
        if (!$this->startDate || !$this->endDate) {
            Log::warning('❌ Start or End date missing in SLA filter');
            return [];
        }

        Log::info('📆 SLA Filter Date Window', [
            'startDate' => $this->startDate?->toIso8601String(),
            'endDate' => $this->endDate?->toIso8601String(),
        ]);

        Log::info('🎯 Start/End window', [
            'start' => $this->startDate?->toIso8601String(),
            'end' => $this->endDate?->toIso8601String(),
        ]);


        return collect($this->data['Activity_SLA'] ?? [])
            ->filter(static fn($sla) => data_get($sla, 'sla_type_id') === 'APPOINTMENT')
            ->filter(function ($sla) {
                $slaStart = Carbon::parse(data_get($sla, 'datetime_start'));
                $slaEnd = Carbon::parse(data_get($sla, 'datetime_end'));

                return $slaStart->lte($this->endDate) && $slaEnd->gte($this->startDate);
            })
            ->pluck('activity_id')
            ->unique()
            ->values()
            ->toArray();
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

    protected function getFilteredActivityGroupData(array $validActivityIds): array
    {
        return array_values(array_filter(
            $this->data['Activity_Group'] ?? [],
            static function ($group) use ($validActivityIds) {
                return in_array($group['activity_id1'], $validActivityIds)
                    && in_array($group['activity_id2'], $validActivityIds);
            }
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
            Log::info('ℹ️Filtering Progresss: ' . $percent . '%');
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
