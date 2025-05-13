<?php

namespace App\Services;

use App\Support\HasScopedCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service for filtering resources, shifts, and activities based on region constraints
 */
class ResourceActivityFilterService extends HasScopedCache
{
    protected array $filteredAvailabilityIds = [];
    protected array $validResourceIds = [];
    protected array $validShiftIds = [];
    protected array $validLocationIds = [];
    protected array $validActivityIds = [];

    protected int $currentProgress = 0;
    protected array $itemCounts = [];
    protected array $processedItems = [];
    protected int $progressStart = 0;
    protected int $progressEnd = 100;

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
    ) {
        $this->progressStart = $progressStart;
        $this->progressEnd = $progressEnd;
        $this->currentProgress = $progressStart;
        $this->updateProgress($this->progressStart);

        // Initial counts
        $this->itemCounts = [
            'resources'  => count($data['Resources'] ?? []),
            'shifts'     => count($data['Shift'] ?? []),
            'activities' => count($data['Activity'] ?? []),
            'locations'  => count($data['Location'] ?? []),
        ];
        $this->processedItems = array_fill_keys(array_keys($this->itemCounts), 0);

        activity()->event('Filter Service')->log('Filter service initialized');
        Log::info('🔢 Filter service initialized with progress range ' .
            "{$this->progressStart}%-{$this->progressEnd}%",
            ['itemCounts' => $this->itemCounts]
        );
    }

    public function filter(): array
    {
        // Log parameters
        $params = [
            'regionIds'   => $this->regionIds,
            'resourceIds' => $this->resourceIds,
            'activityIds' => $this->activityIds,
            'startDate'   => $this->startDate?->toIso8601String() ?: null,
            'endDate'     => $this->endDate?->toIso8601String() ?: null,
            'isDryRun'    => $this->isDryRun,
            'jobId'       => $this->jobId,
        ];
        Log::info('🔍 Filter called with parameters:', $params);

        if ($this->startDate) {
            $this->startDate = $this->startDate->startOfDay();
            Log::info('🕒 Start date:', ['startDate' => $this->startDate->toIso8601String()]);
        } else {
            Log::warning('⚠️ No start date provided');
        }
        if ($this->endDate) {
            $this->endDate = $this->endDate->endOfDay();
            Log::info('🕒 End date:', ['endDate' => $this->endDate->toIso8601String()]);
        } else {
            Log::warning('⚠️ No end date provided');
        }

        if ($this->isDryRun) {
            Log::info('🧪 Dry run mode — skipping filters');
            return $this->handleDryRun();
        }

        // 1) Region filter
        if (!empty($this->regionIds)) {
            $this->filterResourcesByRegion();
        } else {
            $this->validResourceIds = collect($this->data['Resources'] ?? [])->pluck('id')->all();
            $this->validLocationIds = collect($this->data['Location'] ?? [])->pluck('id')->all();
            $this->updateProcessedItems('resources', count($this->validResourceIds));
            $this->updateProcessedItems('locations', count($this->validLocationIds));
        }

        // 2) Specific resource IDs
        if (!empty($this->resourceIds)) {
            $this->filterResourcesByIds();
            $this->validShiftIds = collect($this->data['Shift'] ?? [])
                ->whereIn('resource_id', $this->validResourceIds)
                ->pluck('id')
                ->all();
            $this->filterShiftBreaks();
        }

        // 3) Shift filtering (always after resource filters)
        $this->applyShiftFilters();

        // 4) Resource relations & availability
        $this->filterResourceRelations();
        $this->filterAvailability();

        // 5) Activity filtering
        $this->filterActivitiesAndRelatedData();

        // 6) Assemble final dataset
        $filtered = $this->assembleFilteredData();
        Log::info('📊 Filter summary:', [
            'resourcesBefore' => count($this->data['Resources'] ?? []),
            'resourcesAfter'  => count($filtered['Resources']),
            'shiftsBefore'    => count($this->data['Shift'] ?? []),
            'shiftsAfter'     => count($filtered['Shift']),
        ]);

        return [
            'filtered' => $filtered,
            'summary'  => $this->generateSummary($filtered),
        ];
    }

    protected function handleDryRun(): array
    {
        return ['filtered' => $this->data, 'summary' => $this->generateSummary($this->data)];
    }

    protected function assembleFilteredData(): array
    {
        return array_merge(
            $this->data,
            [
                'Resources'                    => $this->getFilteredData('Resources', 'id', $this->validResourceIds),
                'Shift'                        => $this->getFilteredData('Shift', 'id', $this->validShiftIds),
                'Shift_Break'                  => $this->getFilteredData('Shift_Break', 'shift_id', $this->validShiftIds),
                'Resource_Region'              => $this->getFilteredData('Resource_Region', 'resource_id', $this->validResourceIds),
                'Resource_Skill'               => $this->getFilteredData('Resource_Skill', 'resource_id', $this->validResourceIds),
                'Resource_Region_Availability' => $this->getFilteredData('Resource_Region_Availability', 'resource_id', $this->validResourceIds),
                'Availability'                 => $this->getFilteredData('Availability', 'id', $this->filteredAvailabilityIds),
                'Activity'                     => $this->getFilteredData('Activity', 'id', $this->validActivityIds),
                'Activity_SLA'                 => $this->getFilteredData('Activity_SLA', 'activity_id', $this->validActivityIds),
                'Activity_Status'              => $this->getFilteredData('Activity_Status', 'activity_id', $this->validActivityIds),
                'Activity_Group'               => $this->getFilteredActivityGroupData($this->validActivityIds),
            ]
        );
    }

    protected function applyShiftFilters(): void
    {
        $shifts = collect($this->data['Shift'] ?? [])
            ->whereIn('resource_id', $this->validResourceIds);

        if ($this->startDate && $this->endDate) {
            $shifts = $shifts->filter(fn($s) =>
                Carbon::parse($s['start_datetime'])->lte($this->endDate) &&
                Carbon::parse($s['end_datetime'])->gte($this->startDate)
            );
        }

        $this->validShiftIds = $shifts->pluck('id')->all();
        $this->filterShiftBreaks();
    }

    protected function filterShiftBreaks(): void
    {
        $this->data['Shift_Break'] = collect($this->data['Shift_Break'] ?? [])
            ->filter(fn($break) => in_array(data_get($break, 'shift_id'), $this->validShiftIds))
            ->values()
            ->all();
    }

    protected function filterResourcesByRegion(): void
    {
        $valid = collect($this->data['Resource_Region'] ?? [])
            ->filter(fn($rr) => in_array($rr['region_id'], $this->regionIds))
            ->pluck('resource_id')
            ->unique()
            ->all();
        $this->validResourceIds = $valid;
    }

    protected function filterResourcesByIds(): void
    {
        $this->validResourceIds = array_values(
            array_intersect($this->validResourceIds, $this->resourceIds)
        );
    }

    protected function filterResourceRelations(): void
    {
        foreach (['Resource_Region', 'Resource_Skill', 'Resource_Region_Availability'] as $section) {
            $this->data[$section] = collect($this->data[$section] ?? [])
                ->filter(fn($i) => in_array(data_get($i, 'resource_id'), $this->validResourceIds))
                ->values()
                ->all();
        }
    }

    protected function filterAvailability(): void
    {
        $ids = collect($this->data['Resource_Region_Availability'] ?? [])
            ->pluck('availability_id')
            ->filter()
            ->unique()
            ->all();
        $this->filteredAvailabilityIds = $ids;
    }

    protected function filterActivitiesAndRelatedData(): void
    {
        $acts = collect($this->data['Activity'] ?? []);
        if (!empty($this->regionIds)) {
            $acts = $acts->whereIn('location_id', $this->validLocationIds);
        }
        if (!empty($this->activityIds)) {
            $acts = $acts->whereIn('id', $this->activityIds);
        }
        if ($this->startDate && $this->endDate) {
            $validIds = $this->getActivityIdsWithinDateRange();
            $acts = $acts->whereIn('id', $validIds);
        }
        $this->validActivityIds = $acts->pluck('id')->all();
    }

    protected function generateSummary(array $data): array
    {
        $sections = [
            'resources' => 'Resources',
            'shifts'    => 'Shift',
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
            $summary[$label] = compact('total', 'kept') + ['skipped' => $total - $kept];
        }
        return $summary;
    }

    protected function getActivityIdsWithinDateRange(): array
    {
        return collect($this->data['Activity_SLA'] ?? [])
            ->filter(fn($sla) => data_get($sla, 'sla_type_id') === 'APPOINTMENT')
            ->filter(fn($sla) =>
                Carbon::parse(data_get($sla, 'datetime_start'))->lte($this->endDate) &&
                Carbon::parse(data_get($sla, 'datetime_end'))->gte($this->startDate)
            )
            ->pluck('activity_id')
            ->unique()
            ->all();
    }

    protected function getFilteredData(string $section, string $key, array $ids): array
    {
        return array_values(array_filter(
            $this->data[$section] ?? [],
            static fn($item) => in_array($item[$key], $ids)
        ));
    }

    protected function getFilteredActivityGroupData(array $validIds): array
    {
        return array_values(array_filter(
            $this->data['Activity_Group'] ?? [],
            static fn($g) => in_array($g['activity_id1'], $validIds) && in_array($g['activity_id2'], $validIds)
        ));
    }

    protected function updateProcessedItems(string $type, int $processed): void
    {
        if (!isset($this->processedItems[$type])) {
            return;
        }
        $this->processedItems[$type] = $processed;
        $pct = $this->calculateProgress();
        if ($pct > $this->currentProgress) {
            $this->currentProgress = $pct;
            $this->updateProgress($pct);
        }
    }

    protected function calculateProgress(): int
    {
        $total = array_sum($this->itemCounts) ?: 1;
        $weights = ['resources' => 0.2, 'shifts' => 0.3, 'activities' => 0.4, 'locations' => 0.1];
        $ratio = 0;
        foreach ($weights as $key => $weight) {
            $ratio += ($this->processedItems[$key] / max(1, $this->itemCounts[$key])) * $weight;
        }
        return min(
            $this->progressEnd,
            max(
                $this->progressStart,
                (int)($this->progressStart + $ratio * ($this->progressEnd - $this->progressStart))
            )
        );
    }
}
