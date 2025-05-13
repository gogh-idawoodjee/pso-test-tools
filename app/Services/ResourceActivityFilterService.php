<?php

namespace App\Services;

use App\Support\HasScopedCache;

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
     * Tracks the current progress percentage within our allowed range
     */
    protected int $currentProgress = 0;

    /**
     * Tracks total items to process for weighted progress calculation
     */
    protected array $itemCounts = [];

    /**
     * Tracks processed items for weighted progress calculation
     */
    protected array $processedItems = [];

    /**
     * Start of progress range (percentage)
     */
    protected int $progressStart = 0;

    /**
     * End of progress range (percentage)
     */
    protected int $progressEnd = 100;

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
        int               $progressStart = 0,
        int               $progressEnd = 100
    )
    {
        $this->progressStart = $progressStart;
        $this->progressEnd = $progressEnd;
        $this->currentProgress = $progressStart;

        // Initialize progress tracking at starting value
        $this->updateProgress($this->progressStart);

        // Pre-count items for weighted progress calculation
        $this->itemCounts = [
            'resources' => count($this->data['Resources'] ?? []),
            'shifts' => count($this->data['Shift'] ?? []),
            'activities' => count($this->data['Activity'] ?? []),
            'locations' => count($this->data['Location'] ?? [])
        ];

        $this->processedItems = [
            'resources' => 0,
            'shifts' => 0,
            'activities' => 0,
            'locations' => 0
        ];

        activity()->event('Filter Service')->log('Filter service initialized');
        Log::info('🔢 Filter service initialized with progress range ' .
            "{$this->progressStart}%-{$this->progressEnd}%, item counts:",
            $this->itemCounts);
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
        activity()->event('Filter Service')->log('Filter method called with parameters:' . json_encode([
                'regionIds' => $this->regionIds,
                'resourceIds' => $this->resourceIds,
                'activityIds' => $this->activityIds,
                'startDate' => $this->startDate ? $this->startDate->toIso8601String() : 'null',
                'endDate' => $this->endDate ? $this->endDate->toIso8601String() : 'null',
                'isDryRun' => $this->isDryRun,
                'jobId' => $this->jobId
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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
        activity()->event('Filter Service')->log('🧪 Region filter activated?' . json_encode(['regionIds' => $this->regionIds], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        activity()->event('Filter Service')->log('🧪 Activity count BEFORE filter' . json_encode(['count' => count($this->data['Activity'] ?? [])], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        Log::info('📦 Activity count BEFORE filter:', ['count' => count($this->data['Activity'] ?? [])]);
        Log::info('📦 Sample Activity:', $this->data['Activity'][0] ?? []);
        Log::info('📦 Sample Location:', $this->data['Location'][0] ?? []);
        Log::info('📦 Sample Location_Region:', $this->data['Location_Region'][0] ?? []);

        // Process input reference and override datetime if needed
        $inputReference = $this->getInputReference();

        // Apply all filters and generate the resulting dataset
        $filtered = $this->applyFilters($inputReference);


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

        // Log the filtering process start
        Log::info('🔄 Starting filter application process');

        if (!empty($this->regionIds)) {
            Log::info('🌎 Applying region filters');

            // Step 1: Filter resources by region
            $this->filterResourcesByRegion();
            Log::info('👥 Resources filtered by region:', ['validResourceCount' => count($this->validResourceIds)]);

            // Step 4: Filter locations by region
            $this->filterLocationsByRegion();
            Log::info('📍 Locations filtered by region:', ['validLocationCount' => count($this->validLocationIds)]);
        } else {
            Log::info('🌎 No region IDs provided - skipping region filtering');
            // No region filtering – use all available IDs
            $this->validResourceIds = collect($this->data['Resources'] ?? [])->pluck('id')->toArray();
            $this->validLocationIds = collect($this->data['Location'] ?? [])->pluck('id')->toArray();

            // Still mark these as processed for progress tracking
            $this->updateProcessedItems('resources', count($this->data['Resources'] ?? []));
            $this->updateProcessedItems('locations', count($this->data['Location'] ?? []));
        }

        // Step 2: Filter resources by specific resource IDs (optional)
        if (!empty($this->resourceIds)) {
            Log::info('👤 Filtering by specific resource IDs');
            $this->filterResourcesByIds();
        }

        // Step 3: Filter shifts and related data for valid resources
        if (!$this->startDate || !$this->endDate) {
            $this->filterShiftsAndRelatedData();
        }

        // Date filtering for resources and shifts
        if ($this->startDate && $this->endDate) {
            Log::info('📅 Applying date filters to shifts');
            $this->filterResourcesByShiftDate();
        }

        $this->filterResourceRelations();     // 👈 only now should this be called
        $this->filterAvailability();         // 👈 filters avails based on updated Resource_Region_Avail

        // Step 5: Filter activities and related data for valid locations
        $this->filterActivitiesAndRelatedData();

        // Assemble final dataset
        Log::info('🔄 Assembling final filtered dataset');


        // Assemble final dataset
        $filtered['Resources'] = $this->getFilteredData('Resources', 'id', $this->validResourceIds);
        $filtered['Shift'] = $this->getFilteredData('Shift', 'id', $this->validShiftIds);

        // ✅ Already filtered inside filterResourcesByShiftDate()
//        $filtered['Shift_Break'] = $this->data['Shift_Break'] ?? [];
        $filtered['Shift_Break'] = $this->getFilteredData('Shift_Break', 'shift_id', $this->validShiftIds);
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
     */
    protected function filterResourcesByRegion(): void
    {
        $totalResources = count($this->data['Resource_Region'] ?? []);
        $processed = 0;
        $chunkSize = max(1, (int)($totalResources / 10)); // Update progress ~10 times during this operation

        // Find all resources that are associated with any of the target regions
        $validResourceIds = collect($this->data['Resource_Region'] ?? [])
            ->filter(function ($rr) use (&$processed, $chunkSize, $totalResources) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0 || $processed === $totalResources) {
                    $this->updateProcessedItems('resources', $processed);
                }

                return in_array($rr['region_id'], $this->regionIds);
            })
            ->pluck('resource_id')
            ->unique()
            ->values()
            ->toArray();

        $this->validResourceIds = $validResourceIds;

        // Mark resources as fully processed
        $this->updateProcessedItems('resources', $totalResources);
    }

    protected function filterResourcesByShiftDate(): void
    {
        if (!$this->startDate || !$this->endDate) {
            return;
        }

        $this->filterShiftsInDateRange();
        $this->filterResourcesWithValidShifts();
        $this->reFilterShiftsByValidResources(); // 👈 cleanup shifts now that resource set shrank again
        $this->filterShiftBreaks();              // 👈 must re-filter breaks using updated shift IDs
        $this->filterAvailability();             // still valid here
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


    protected function reFilterShiftsByValidResources(): void
    {
        $this->validShiftIds = collect($this->data['Shift'] ?? [])
            ->filter(fn($shift) => in_array(data_get($shift, 'resource_id'), $this->validResourceIds))
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
            ->filter(static fn($item) => in_array(data_get($item, 'id'), $availabilityIds))
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
        $totalShifts = count($this->data['Shift'] ?? []);
        $processed = 0;
        $chunkSize = max(1, (int)($totalShifts / 10)); // Update progress ~10 times

        // Get all shifts belonging to the valid resources
        $validShiftIds = collect($this->data['Shift'] ?? [])
            ->filter(function ($shift) use (&$processed, $chunkSize, $totalShifts) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0 || $processed === $totalShifts) {
                    $this->updateProcessedItems('shifts', $processed);
                }

                return in_array($shift['resource_id'], $this->validResourceIds);
            })
            ->pluck('id')
            ->values()
            ->toArray();

        $this->validShiftIds = $validShiftIds;

        // Mark shifts as fully processed
        $this->updateProcessedItems('shifts', $totalShifts);
    }

    /**
     * Filters locations based on region constraints
     *
     * Identifies locations that belong to any of the specified regions
     */
    protected function filterLocationsByRegion(): void
    {
        $totalLocations = count($this->data['Location_Region'] ?? []);
        $processed = 0;
        $chunkSize = max(1, (int)($totalLocations / 10));

        // First: collect IDs from Location that appear in Location_Region
        $regionLocationIds = collect($this->data['Location_Region'] ?? [])
            ->filter(function ($lr) use (&$processed, $chunkSize, $totalLocations) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0 || $processed === $totalLocations) {
                    $this->updateProcessedItems('locations', (int)($processed * 0.5)); // 50% of location filtering
                }

                return in_array($lr['region_id'], $this->regionIds);
            })
            ->pluck('location_id')
            ->unique()
            ->values()
            ->toArray();

        // Second: find Locations whose *own* ID matches that ID
        $this->validLocationIds = collect($this->data['Location'] ?? [])
            ->filter(static fn($loc) => in_array($loc['id'], $regionLocationIds))
            ->pluck('id')
            ->toArray();

        // Mark locations as fully processed
        $this->updateProcessedItems('locations', $totalLocations);

        Log::info('🧩 Matched this many Location IDs via Location_Region + Location:', count($this->validLocationIds));
    }

    /**
     * Filters activities based on valid locations and optional activity IDs
     *
     * Identifies activities that are at valid locations and optionally match specified IDs
     */
    protected function filterActivitiesAndRelatedData(): void
    {
        $totalActivities = count($this->data['Activity'] ?? []);
        $processed = 0;
        $chunkSize = max(1, (int)($totalActivities / 20)); // More updates for activities as they're a large part

        $activities = collect($this->data['Activity'] ?? []);

        // Apply filters with progress tracking
        if (!empty($this->regionIds)) {
            $activities = $activities->filter(function ($a) use (&$processed, $chunkSize, $totalActivities) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0 || $processed === $totalActivities) {
                    $this->updateProcessedItems('activities', (int)($processed * 0.33)); // 1/3 of activity filtering
                }

                return isset($a['location_id']) && in_array($a['location_id'], $this->validLocationIds);
            });
        }

        // 🔍 Activity ID filter
        $processed = 0;
        if (!empty($this->activityIds)) {
            $activities = $activities->filter(function ($a) use (&$processed, $chunkSize, $totalActivities) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0) {
                    $this->updateProcessedItems('activities', (int)($totalActivities * 0.33 + $processed * 0.33)); // 2/3 of activity filtering
                }

                return in_array($a['id'], $this->activityIds);
            });
        }

        // 📅 SLA date filter
        $processed = 0;
        if ($this->startDate && $this->endDate) {
            $matchedActivityIds = $this->getActivityIdsWithinDateRange();


            $activities = $activities->filter(function ($a) use (&$processed, $chunkSize, $totalActivities, $matchedActivityIds) {
                $processed++;

                // Update progress periodically
                if ($processed % $chunkSize === 0) {
                    // Cap at 100% of total activities
                    $progress = min($totalActivities, (int)($totalActivities * 0.67 + $processed * 0.33));
                    $this->updateProcessedItems('activities', $progress);
                }
                return in_array(data_get($a, 'id'), $matchedActivityIds);
            });
        }

        // ✅ Always set final valid activity IDs after all filters
        $this->validActivityIds = $activities->pluck('id')->values()->toArray();

        // Mark activities as fully processed
        $this->updateProcessedItems('activities', $totalActivities);

        Log::info('🎯 Final valid activity ID count:', count($this->validActivityIds));
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
            'startDate' => $this->startDate->toIso8601String(),
            'endDate' => $this->endDate->toIso8601String(),
        ]);

        Log::info('🎯 Start/End window', [
            'start' => $this->startDate->toIso8601String(),
            'end' => $this->endDate->toIso8601String(),
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


    protected function getInputReference(): array
    {
        $ref = $this->data['Input_Reference'] ?? [];
        if ($this->overrideDatetime) {
            $ref['datetime'] = Carbon::parse($this->overrideDatetime)->toIso8601String();
        }
        return $ref;
    }

    /**
     * Calculates weighted progress based on processed items
     *
     * @return int The calculated progress percentage (0-100)
     */
    protected function calculateProgress(): int
    {
        // If there are no items to process, return current progress
        $totalItems = array_sum($this->itemCounts);
        if ($totalItems === 0) {
            return $this->progressStart;
        }

        // Calculate weights for each category
        $weights = [
            'resources' => 0.2,  // 20% of progress weight
            'shifts' => 0.3,  // 30% of progress weight
            'activities' => 0.4, // 40% of progress weight
            'locations' => 0.1  // 10% of progress weight
        ];

        // Calculate weighted progress (0-1 scale)
        $progressRatio = 0;
        foreach ($weights as $key => $weight) {
            if ($this->itemCounts[$key] > 0) {
                $progressRatio += ($this->processedItems[$key] / $this->itemCounts[$key]) * $weight;
            }
        }

        // Map to the specified range
        $progressRange = $this->progressEnd - $this->progressStart;
        $progress = $this->progressStart + ($progressRatio * $progressRange);

        // Ensure progress stays within the specified range
        return min($this->progressEnd, max($this->progressStart, (int)$progress));
    }

    /**
     * Updates processed items count and recalculates progress
     *
     * @param string $type The type of items being processed (resources, shifts, activities, locations)
     * @param int $processed The number of items processed
     */
    protected function updateProcessedItems(string $type, int $processed): void
    {
        if (isset($this->processedItems[$type])) {
            $this->processedItems[$type] = $processed;
            $newProgress = $this->calculateProgress();

            // Only update if progress has increased
            if ($newProgress > $this->currentProgress) {
                $this->currentProgress = $newProgress;
                $this->updateProgress($newProgress);
            }
        }
    }

}
