<?php

namespace App\Services;

use App\Support\HasScopedCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Filters a PSO load file dataset down to a subset of resources, activities,
 * shifts, locations, and their related entities.
 *
 * Filtering pipeline (applied in order):
 *  1. Region filter — keep only resources belonging to selected regions.
 *  2. Resource ID filter — optionally narrow to specific resource IDs.
 *  3. Shift filter — keep shifts for valid resources, optionally within a date range.
 *  4. Resource relations — trim Resource_Region, Resource_Skill, and availability joins.
 *  5. Activity filter — keep activities matching region locations, activity types,
 *     specific IDs, and/or date range.
 *  6. Assemble — build the final dataset, filtering locations to only those
 *     referenced by the remaining resources and activities.
 *
 * In dry-run mode, no filters are applied — the original data is returned with a summary.
 */
class ResourceActivityFilterService extends HasScopedCache
{
    protected array $filteredAvailabilityIds = [];

    protected array $validResourceIds = [];

    protected array $validShiftIds = [];

    protected array $validLocationIds = [];

    protected array $validActivityIds = [];

    /** @var array<int, string> Tables the generic pass cascaded, for the summary. */
    protected array $cascadedTables = [];

    /**
     * Tables assembleFilteredData() rebuilds explicitly. Everything else goes
     * through the generic cascade pass.
     */
    protected const REBUILT_TABLES = [
        'Resources', 'Location', 'Shift', 'Shift_Break', 'Resource_Region', 'Resource_Skill',
        'Resource_Region_Availability', 'Availability', 'Activity', 'Activity_SLA',
        'Activity_Status', 'Activity_Group',
    ];

    /**
     * Foreign keys the cascade and the final referential check understand,
     * confirmed against load-audit/audit_load.py REFS in pso-tools. Each maps
     * to the table and column that hold the target ids.
     */
    protected const REFERENCE_KEYS = [
        'activity_id' => ['Activity', 'id'],
        'resource_id' => ['Resources', 'id'],
        'availability_id' => ['Availability', 'id'],
        'location_id' => ['Location', 'id'],
        'location_id_start' => ['Location', 'id'],
        'location_id_end' => ['Location', 'id'],
        'shift_id' => ['Shift', 'id'],
    ];

    /** @var array<string, true> Tables that arrived as one plain row object (older dsScheduleData shape). */
    protected array $singleRowTables = [];

    /** @var array<string, int> Row counts of the input, taken before any trimming. */
    protected array $originalCounts = [];

    /** Summary rows always shown, even when the export has no such table. */
    protected const ALWAYS_SHOWN_SUMMARY = [
        'resources', 'locations', 'shifts', 'shift_breaks', 'resource_skills', 'resource_regions',
        'resource_region_availability', 'availability', 'activities', 'activity_slas',
        'activity_statuses', 'activity_groups',
    ];

    protected int $progressStart = 0;

    protected int $progressEnd = 100;

    public function __construct(
        protected array $data,
        protected array $regionIds,
        protected ?string $jobId = null,
        protected ?string $overrideDatetime = null,
        protected array $resourceIds = [],
        protected array $activityIds = [],
        protected bool $isDryRun = false,
        protected ?Carbon $startDate = null,
        protected ?Carbon $endDate = null,
        int $progressStart = 0,
        int $progressEnd = 100,
        protected array $activityTypeIds = [],
    ) {
        $this->progressStart = $progressStart;
        $this->progressEnd = $progressEnd;
        $this->updateProgress($this->progressStart);

        activity()->event('Filter Service')->log('Filter service initialized');
    }

    /**
     * Run the full filtering pipeline and return the filtered dataset with a summary.
     *
     * @return array{filtered: array, summary: array} 'filtered' is the dataset ready for output,
     *                                                'summary' has per-entity kept/skipped counts.
     */
    public function filter(): array
    {
        // Log parameters
        $params = [
            'regionIds' => $this->regionIds,
            'resourceIds' => $this->resourceIds,
            'activityIds' => $this->activityIds,
            'startDate' => $this->startDate?->toIso8601String() ?: null,
            'endDate' => $this->endDate?->toIso8601String() ?: null,
            'isDryRun' => $this->isDryRun,
            'jobId' => $this->jobId,
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

        $this->normaliseSingleRowTables();

        if ($this->isDryRun) {
            Log::info('🧪 Dry run mode — skipping filters');

            return $this->handleDryRun();
        }

        // 1) Region filter (~15%)
        $this->reportStep(0.0);
        if (! empty($this->regionIds)) {
            $this->filterResourcesByRegion();
        } else {
            $this->validResourceIds = collect($this->data['Resources'] ?? [])->pluck('id')->all();
            $this->validLocationIds = collect($this->data['Location'] ?? [])->pluck('id')->all();
        }

        // 2) Specific resource IDs (~20%)
        $this->reportStep(0.15);
        if (! empty($this->resourceIds)) {
            $this->filterResourcesByIds();
        }

        // 3) Shift filtering (~40%)
        $this->reportStep(0.20);
        $this->applyShiftFilters();

        // 4) Resource relations & availability (~55%)
        $this->reportStep(0.40);
        $this->filterResourceRelations();
        $this->reportStep(0.50);
        $this->filterAvailability();

        // 5) Activity filtering (~75%)
        $this->reportStep(0.55);
        $this->filterActivitiesAndRelatedData();

        // 6) Assemble final dataset
        $this->reportStep(0.75);
        $filtered = $this->assembleFilteredData();
        $this->reportStep(0.90);

        Log::info('📊 Filter summary:', [
            'resourcesBefore' => count($this->data['Resources'] ?? []),
            'resourcesAfter' => count($filtered['Resources']),
            'shiftsBefore' => count($this->data['Shift'] ?? []),
            'shiftsAfter' => count($filtered['Shift']),
        ]);

        $summary = $this->generateSummary($filtered);
        $summary['dangling'] = $this->findDanglingReferences($filtered);

        if ($summary['dangling'] !== []) {
            Log::warning('Filter Load File: dangling references in the filtered file', [
                'tables' => count(array_unique(array_column($summary['dangling'], 'table'))),
                'findings' => count($summary['dangling']),
                'rows' => array_sum(array_column($summary['dangling'], 'count')),
            ]);
        }

        return [
            'filtered' => $this->restoreSingleRowTables($filtered),
            'summary' => $summary,
        ];
    }

    /**
     * Dry run — skip all filters, return the original data with a summary
     * showing total = kept for every entity (nothing filtered).
     */
    protected function handleDryRun(): array
    {
        return [
            'filtered' => $this->restoreSingleRowTables($this->data),
            'summary' => $this->generateSummary($this->data) + ['dangling' => []],
        ];
    }

    /**
     * Older dsScheduleData JSON wraps a table in a list only when it has more
     * than one row; a one-row table is a plain object. Turn those into one-item
     * lists so every step sees rows, and remember them so the output keeps its
     * original shape. Also records the input row counts for the summary totals.
     */
    protected function normaliseSingleRowTables(): void
    {
        $this->singleRowTables = [];
        foreach ($this->data as $table => $rows) {
            if (! is_array($rows) || $rows === [] || array_is_list($rows)) {
                continue;
            }
            foreach ($rows as $value) {
                if (is_array($value)) {
                    continue 2;
                }
            }
            $this->data[$table] = [$rows];
            $this->singleRowTables[$table] = true;
        }

        $this->originalCounts = [];
        foreach ($this->data as $table => $rows) {
            if (is_array($rows)) {
                $this->originalCounts[$table] = count($rows);
            }
        }
    }

    /**
     * Write tables that came in as one plain row object back in that shape when
     * exactly one row is left; with no rows they stay an empty list.
     */
    protected function restoreSingleRowTables(array $data): array
    {
        foreach (array_keys($this->singleRowTables) as $table) {
            if (isset($data[$table]) && is_array($data[$table]) && count($data[$table]) === 1) {
                $data[$table] = $data[$table][0];
            }
        }

        return $data;
    }

    /**
     * Build the final filtered dataset by selecting only valid entities from each section.
     * Starts with the original data (preserving unfiltered keys like Region, Activity_Type, etc.)
     * and overlays the filtered arrays for Resources, Activities, Shifts, Locations, and joins.
     */
    protected function assembleFilteredData(): array
    {
        $filteredResources = $this->getFilteredData('Resources', 'id', $this->validResourceIds);
        $filteredActivities = $this->getFilteredData('Activity', 'id', $this->validActivityIds);

        $filteredShifts = $this->getFilteredData('Shift', 'id', $this->validShiftIds);

        $this->cascadedTables = [];

        $assembled = array_merge(
            $this->data,
            [
                'Resources' => $filteredResources,
                'Shift' => $filteredShifts,
                'Shift_Break' => $this->getFilteredData('Shift_Break', 'shift_id', $this->validShiftIds),
                'Resource_Region' => $this->getFilteredData('Resource_Region', 'resource_id', $this->validResourceIds),
                'Resource_Skill' => $this->getFilteredData('Resource_Skill', 'resource_id', $this->validResourceIds),
                'Resource_Region_Availability' => $this->getFilteredData('Resource_Region_Availability', 'resource_id', $this->validResourceIds),
                'Activity' => $filteredActivities,
                'Activity_SLA' => $this->getFilteredData('Activity_SLA', 'activity_id', $this->validActivityIds),
                'Activity_Status' => $this->getFilteredData('Activity_Status', 'activity_id', $this->validActivityIds),
                'Activity_Group' => $this->getFilteredActivityGroupData($this->validActivityIds),
            ]
        );

        // Generic pass: every table not rebuilt above (Activity_Skill,
        // Additional_Attribute, custom URLs, Resource_Skill_Availability, plan
        // output, anything unknown) loses rows whose parent was removed.
        $parentSets = [
            'activity_id' => array_flip($this->validActivityIds),
            'resource_id' => array_flip($this->validResourceIds),
            'shift_id' => array_flip($this->validShiftIds),
        ];
        foreach (array_keys($assembled) as $table) {
            if (in_array($table, self::REBUILT_TABLES, true)) {
                continue;
            }
            $before = is_array($assembled[$table]) ? count($assembled[$table]) : 0;
            $assembled[$table] = $this->cascadeTable($assembled[$table], $parentSets);
            if ($before > 0 && is_array($assembled[$table])) {
                $this->cascadedTables[] = $table;
            }
        }

        // Locations: kept when a kept resource, activity, shift or any other
        // kept row points at them (location_id, location_id_start/_end).
        $referencedLocationIds = $this->collectReferencedLocationIds($assembled);
        $assembled['Location'] = $this->getFilteredData('Location', 'id', $referencedLocationIds);
        $locationSets = ['location_id' => array_flip($referencedLocationIds)];
        foreach (array_keys($assembled) as $table) {
            if (! in_array($table, self::REBUILT_TABLES, true)) {
                $assembled[$table] = $this->cascadeTable($assembled[$table], $locationSets);
            }
        }

        // Availability: kept when ANY kept table references it (never by date),
        // or when it belongs to a kept activity.
        $assembled['Availability'] = $this->filterAvailabilityRows($assembled);
        $this->filteredAvailabilityIds = array_column($assembled['Availability'], 'id');

        return $assembled;
    }

    /**
     * Drop rows that carry a parent key (activity_id, resource_id, location_id,
     * shift_id) whose value is not in the kept set. A null, blank or missing key
     * is not a reference. A table with no such key, or that is not a list of
     * rows, is returned as it came in. availability_id is not cascaded here:
     * Availability is kept by being referenced, so a row pointing at a missing
     * Availability is reported as dangling instead of silently dropped.
     *
     * @param  array<string, array<int|string, int>>  $parentSets  key => flipped kept ids
     */
    protected function cascadeTable(mixed $rows, array $parentSets): mixed
    {
        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            return $rows;
        }

        $dropIndexes = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($parentSets as $key => $keptSet) {
                $value = $row[$key] ?? null;
                if (is_scalar($value) && $value !== '' && ! isset($keptSet[$value])) {
                    $dropIndexes[] = $index;
                    break;
                }
            }
        }

        if ($dropIndexes === []) {
            return $rows;
        }

        foreach ($dropIndexes as $index) {
            unset($rows[$index]);
        }

        return array_values($rows);
    }

    /**
     * Keep Availability rows referenced by any kept row (Resource_Region_Availability,
     * Resource_Skill_Availability, or any other table with an availability_id),
     * plus rows that belong to a kept activity.
     */
    protected function filterAvailabilityRows(array $assembled): array
    {
        $referenced = [];
        foreach ($assembled as $table => $rows) {
            if ($table === 'Availability' || ! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $value = is_array($row) ? ($row['availability_id'] ?? null) : null;
                if (is_scalar($value) && $value !== '') {
                    $referenced[$value] = true;
                }
            }
        }
        $activitySet = array_flip($this->validActivityIds);

        return array_values(array_filter(
            $assembled['Availability'] ?? [],
            static fn ($row) => isset($referenced[$row['id'] ?? null])
                || (isset($row['activity_id']) && isset($activitySet[$row['activity_id']]))
        ));
    }

    /**
     * Final referential check: for every kept table, find rows whose
     * activity_id, resource_id, availability_id, location_id or shift_id points
     * at an id that is not in the kept target table. A target table that is not
     * in the data at all is not checked.
     *
     * @return list<array{table: string, key: string, count: int, examples: list<string>}>
     */
    protected function findDanglingReferences(array $filtered): array
    {
        $targetSets = [];
        foreach (self::REFERENCE_KEYS as $key => [$targetTable, $targetColumn]) {
            if (isset($filtered[$targetTable]) && is_array($filtered[$targetTable])) {
                $targetSets[$key] = array_flip(array_column($filtered[$targetTable], $targetColumn));
            }
        }

        $found = [];
        foreach ($filtered as $table => $rows) {
            if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($targetSets as $key => $keptSet) {
                    $value = $row[$key] ?? null;
                    if (! is_scalar($value) || $value === '' || isset($keptSet[$value])) {
                        continue;
                    }
                    $found[$table][$key]['count'] = ($found[$table][$key]['count'] ?? 0) + 1;
                    if (count($found[$table][$key]['examples'] ?? []) < 5) {
                        $found[$table][$key]['examples'][] = (string) $value;
                    }
                }
            }
        }

        $dangling = [];
        foreach ($found as $table => $keys) {
            foreach ($keys as $key => $info) {
                $dangling[] = ['table' => $table, 'key' => $key, 'count' => $info['count'], 'examples' => $info['examples']];
            }
        }

        return $dangling;
    }

    /**
     * Collect the location ids that kept rows genuinely use: Activity.location_id
     * and the location_id_start / location_id_end columns of Resources, Shift and
     * Resource_Type. Location itself and Location_Region (rows that only describe
     * a Location) are deliberately not walked, or every location would count as
     * referenced. Used to strip unreferenced locations from the output.
     */
    protected function collectReferencedLocationIds(array $assembled): array
    {
        $columnsByTable = [
            'Resources' => ['location_id_start', 'location_id_end'],
            'Shift' => ['location_id_start', 'location_id_end'],
            'Resource_Type' => ['location_id_start', 'location_id_end'],
            'Activity' => ['location_id'],
        ];
        $locationIds = [];

        foreach ($columnsByTable as $table => $columns) {
            $rows = $assembled[$table] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($columns as $key) {
                    $value = $row[$key] ?? null;
                    if (is_scalar($value) && $value !== '') {
                        $locationIds[$value] = true;
                    }
                }
            }
        }

        return array_keys($locationIds);
    }

    /**
     * Keep only shifts belonging to valid resources. If a date range is set,
     * further narrow to shifts that overlap the range.
     */
    protected function applyShiftFilters(): void
    {
        $shifts = collect($this->data['Shift'] ?? [])
            ->whereIn('resource_id', $this->validResourceIds);

        if ($this->startDate && $this->endDate) {
            $shifts = $shifts->filter(fn ($s) => Carbon::parse($s['start_datetime'])->lte($this->endDate) &&
                Carbon::parse($s['end_datetime'])->gte($this->startDate)
            );
        }

        $this->validShiftIds = $shifts->pluck('id')->all();
    }

    /**
     * Filter resources to only those assigned to selected regions (via Resource_Region join).
     * Also builds validLocationIds for locations in those regions, used later to filter activities.
     * A location is in a region when Location.region_id says so or a Location_Region row links
     * them: some customers (SaskTel) carry regions only through Location_Region.
     */
    protected function filterResourcesByRegion(): void
    {
        $regionIdSet = array_flip($this->regionIds);

        $this->validResourceIds = collect($this->data['Resource_Region'] ?? [])
            ->filter(static fn ($rr) => isset($regionIdSet[data_get($rr, 'region_id')]))
            ->pluck('resource_id')
            ->unique()
            ->all();

        $this->validLocationIds = collect($this->data['Location'] ?? [])
            ->filter(static fn ($loc) => isset($regionIdSet[data_get($loc, 'region_id')]))
            ->pluck('id')
            ->merge(
                collect($this->data['Location_Region'] ?? [])
                    ->filter(static fn ($lr) => isset($regionIdSet[data_get($lr, 'region_id')]))
                    ->pluck('location_id'),
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Narrow validResourceIds to only those explicitly selected by the user.
     * Intersects with the already region-filtered list so both constraints apply.
     */
    protected function filterResourcesByIds(): void
    {
        $this->validResourceIds = array_values(
            array_intersect($this->validResourceIds, $this->resourceIds)
        );
    }

    /**
     * Trim the resource join tables (Resource_Region, Resource_Skill,
     * Resource_Region_Availability) to only rows for valid resources.
     * Mutates $this->data in place for these sections.
     */
    protected function filterResourceRelations(): void
    {
        $resourceIdSet = array_flip($this->validResourceIds);

        foreach (['Resource_Region', 'Resource_Skill', 'Resource_Region_Availability'] as $section) {
            $this->data[$section] = collect($this->data[$section] ?? [])
                ->filter(static fn ($i) => isset($resourceIdSet[data_get($i, 'resource_id')]))
                ->values()
                ->all();
        }
    }

    /**
     * Collect availability IDs referenced by the filtered Resource_Region_Availability rows.
     * These are used later to filter the Availability section in assembleFilteredData().
     */
    protected function filterAvailability(): void
    {
        $ids = collect($this->data['Resource_Region_Availability'] ?? [])
            ->pluck('availability_id')
            ->filter()
            ->unique()
            ->all();
        $this->filteredAvailabilityIds = $ids;
    }

    /**
     * Apply all activity filters in sequence: region locations, activity types,
     * specific activity IDs, and date range. Each filter narrows the set further.
     */
    protected function filterActivitiesAndRelatedData(): void
    {
        $acts = collect($this->data['Activity'] ?? []);
        if (! empty($this->regionIds)) {
            $acts = $acts->whereIn('location_id', $this->validLocationIds);
        }
        if (! empty($this->activityTypeIds)) {
            $typeIdSet = array_flip($this->activityTypeIds);
            $acts = $acts->filter(static fn ($a) => isset($typeIdSet[$a['activity_type_id'] ?? '']));
        }
        if (! empty($this->activityIds)) {
            $acts = $acts->whereIn('id', $this->activityIds);
        }
        if ($this->startDate && $this->endDate) {
            $validIds = $this->getActivityIdsWithinDateRange();
            $acts = $acts->whereIn('id', $validIds);
        }
        $this->validActivityIds = $acts->pluck('id')->all();
    }

    /**
     * Compare the filtered dataset against the original to produce a per-entity
     * summary with total, kept, and skipped counts.
     */
    protected function generateSummary(array $data): array
    {
        $sections = [
            'resources' => 'Resources',
            'locations' => 'Location',
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
            'activity_skills' => 'Activity_Skill',
            'additional_attributes' => 'Additional_Attribute',
            'activity_custom_urls' => 'Activity_Custom_URL',
            'resource_skill_availability' => 'Resource_Skill_Availability',
            'resource_custom_urls' => 'Resource_Custom_URL',
        ];
        // The cascaded tables only appear when the export has them; any other
        // table the generic pass touched gets a row labelled by its own name.
        foreach ($this->cascadedTables as $table) {
            if (! in_array($table, $sections, true)) {
                $sections[strtolower($table)] = $table;
            }
        }
        $summary = [];
        foreach ($sections as $label => $section) {
            if (! in_array($label, self::ALWAYS_SHOWN_SUMMARY, true) && ! isset($this->data[$section])) {
                continue;
            }
            $total = $this->originalCounts[$section] ?? count($this->data[$section] ?? []);
            $kept = count($data[$section] ?? []);
            $summary[$label] = compact('total', 'kept') + ['skipped' => $total - $kept];
        }

        return $summary;
    }

    /**
     * Find activities that have an APPOINTMENT SLA overlapping the selected date range.
     * Returns the activity IDs that fall within range.
     */
    protected function getActivityIdsWithinDateRange(): array
    {
        return collect($this->data['Activity_SLA'] ?? [])
            ->filter(fn ($sla) => data_get($sla, 'sla_type_id') === 'APPOINTMENT')
            ->filter(fn ($sla) => Carbon::parse(data_get($sla, 'datetime_start'))->lte($this->endDate) &&
                Carbon::parse(data_get($sla, 'datetime_end'))->gte($this->startDate)
            )
            ->pluck('activity_id')
            ->unique()
            ->all();
    }

    /**
     * Generic filter helper: keep only items from a data section whose $key value
     * is in the provided $ids array. Uses array_flip for O(1) lookups.
     */
    protected function getFilteredData(string $section, string $key, array $ids): array
    {
        $idSet = array_flip($ids);

        return array_values(array_filter(
            $this->data[$section] ?? [],
            static fn ($item) => isset($idSet[$item[$key]])
        ));
    }

    /**
     * Filter Activity_Group — keep only groups where BOTH linked activities are valid.
     * Activity groups link two activities (activity_id1 and activity_id2).
     */
    protected function getFilteredActivityGroupData(array $validIds): array
    {
        $idSet = array_flip($validIds);

        return array_values(array_filter(
            $this->data['Activity_Group'] ?? [],
            static fn ($g) => isset($idSet[$g['activity_id1']]) && isset($idSet[$g['activity_id2']])
        ));
    }

    /**
     * Report progress as a fraction (0.0–1.0) within the assigned progress range.
     */
    protected function reportStep(float $fraction): void
    {
        $pct = (int) ($this->progressStart + $fraction * ($this->progressEnd - $this->progressStart));
        $this->updateProgress(min($pct, $this->progressEnd));
    }
}
