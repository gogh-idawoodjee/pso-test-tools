<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use DateInterval;
use DatePeriod;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TechnicianAvailabilityService
{
    public function __construct(
        protected array   $data,
        protected ?string $jobId = null,
        protected ?string $technicianId = null,
        protected ?string $startDate = null,   // ← New: filter shifts from this date
    )
    {
    }

    public function getTechnicianShifts(
        int       $limit = 7,
        bool|null $onlyUpcoming = null,
    ): array
    {
        $onlyUpcoming ??= false;
        if (!$this->technicianId) {
            return [];
        }

        $timezone = config('app.timezone');            // e.g. 'America/Toronto'
        $cutoff = Carbon::parse($this->startDate, $timezone)
            ->startOfDay();

        Log::info("✅ Collecting shifts for technician {$this->technicianId} starting from {$this->startDate}");

        $shiftData = collect($this->data['Shift'] ?? [])
            ->filter(fn($s) => isset($s['resource_id'], $s['start_datetime'])
                && $s['resource_id'] === $this->technicianId
                && (!$onlyUpcoming || Carbon::parse($s['start_datetime'])
                        ->tz($timezone)
                        ->gte(now()->tz($timezone)))
            )
            ->when($this->startDate, fn($c) => $c->filter(fn($s) => Carbon::parse($s['start_datetime'])
                ->tz($timezone)            // convert from UTC into your local zone
                ->startOfDay()             // drop time portion
                ->greaterThanOrEqualTo($cutoff)  // full “>=” date check
            )
            )
            ->sortBy(fn($s) => Carbon::parse($s['start_datetime'])->tz($timezone))
            ->take($limit)
            ->values();

        $availabilityData = $this->data['Availability'] ?? [];
        $resourceRegionAvailData = $this->data['Resource_Region_Availability'] ?? [];
        $regions = collect($this->data['Region'] ?? []);
        $regionsById = $regions->keyBy('id');
        $availabilityById = collect($availabilityData)->keyBy('id');
        $shiftBreaks = collect($this->data['Shift_Break'] ?? []);

        // Recursively resolve top-most parent region ID
        $getTopParentId = static function (string $regionId) use (&$regionsById, &$getTopParentId): string {
            $region = $regionsById->get($regionId);
            if (!$region || empty($region['region_id'])) {
                return $regionId;
            }
            return $getTopParentId($region['region_id']);
        };

        $getTopParentDescription = static function (string $regionId) use ($getTopParentId, &$regionsById): string {
            $topId = $getTopParentId($regionId);
            return $regionsById[$topId]['description'] ?? 'Unknown';
        };

        // Expand pattern-based availabilities only for shift range
        $patternAvailabilities = $this->expandPatternBasedAvailability($shiftData->all());

        // Direct availabilities (those with availability_id)
        $directAvailabilities = collect($resourceRegionAvailData)
            ->filter(static fn($rra) => !empty($rra['availability_id']));

        // Merge both sources grouped by technician resource_id
        $regionAvailability = $directAvailabilities
            ->merge($patternAvailabilities)
            ->groupBy('resource_id');

        return collect($shiftData)
            ->map(function ($shift) use (
                $regionAvailability, $availabilityById, $regionsById,
                $getTopParentId, $getTopParentDescription, $shiftBreaks
            ) {
                $shiftId = $shift['id'];
                $shiftStart = Carbon::parse($shift['start_datetime']);
                $shiftEnd = Carbon::parse($shift['end_datetime']);
                $resourceId = $shift['resource_id'];

                // Build region_availability entries
                $overlappingAvailability = collect($regionAvailability->get($resourceId, []))
                    ->map(function ($rra) use (
                        $availabilityById, $shiftStart, $shiftEnd,
                        $regionsById, $getTopParentId, $getTopParentDescription
                    ) {
                        // Case A: direct availability
                        if (isset($rra['availability_id'])) {
                            $availability = $availabilityById->get($rra['availability_id']);
                            if (!$availability) {
                                return null;
                            }
                            $availStart = Carbon::parse($availability['datetime_start']);
                            $availEnd = Carbon::parse($availability['datetime_end']);
                            $entryId = $availability['id'];
                        } // Case B: pattern-based availability
                        elseif (isset($rra['availability_pattern_id'])) {
                            $availStart = Carbon::parse($rra['start']);
                            $availEnd = Carbon::parse($rra['end']);
                            $entryId = "pattern:{$rra['availability_pattern_id']}:{$availStart->toDateString()}";
                        } else {
                            return null;
                        }

                        // Skip non-overlapping
                        if ($availEnd->lte($shiftStart) || $availStart->gte($shiftEnd)) {
                            return null;
                        }

                        // Region metadata
                        $regionId = $rra['region_id'] ?? null;
                        $description = $regionsById[$regionId]['description'] ?? 'Unknown';
                        $groupId = $regionId ? $getTopParentId($regionId) : null;
                        $groupDesc = $groupId ? $getTopParentDescription($regionId) : 'Unknown';

                        // Multiplier → active flag
                        $multiplier = (float)($rra['within_region_multiplier'] ?? 1.0);
                        $active = $multiplier !== 0.0;

                        return [
                            'id' => $entryId,
                            'region_id' => $regionId,
                            'region_description' => $description,
                            'region_group_id' => $groupId,
                            'region_group_description' => $groupDesc,
                            'start' => max($shiftStart, $availStart)->toIso8601String(),
                            'end' => min($shiftEnd, $availEnd)->toIso8601String(),
                            'region_active' => $active,
                            'full_coverage' => $availStart->lte($shiftStart)
                                && $availEnd->gte($shiftEnd),
                            'source' => $rra['source'] ?? 'availability',
                            'source_id' => $rra['source_id'] ?? $rra['id'],
                            'override_priority' => (int)($rra['override_priority'] ?? 0),
                            'within_region_multiplier' => $multiplier,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                // Build breaks
                $breaks = $shiftBreaks
                    ->where('shift_id', $shiftId)
                    ->map(function ($b) use ($shiftStart) {
                        try {
                            $earliestStart = CarbonInterval::make($b['earliest_start_offset']);
                            $duration = CarbonInterval::make($b['duration']);
                            $start = $shiftStart->copy()->add($earliestStart);
                            $end = $start->copy()->add($duration);
                            return [
                                'start' => $start->toIso8601String(),
                                'end' => $end->toIso8601String(),
                            ];
                        } catch (Exception) {
                            return null;
                        }
                    })
                    ->filter()
                    ->values()
                    ->all();

                $shift['region_availability'] = $overlappingAvailability;
                $shift['breaks'] = $breaks;
                return $shift;
            })
            ->toArray();
    }

    public function getTechnicians(): array
    {
        Log::info('🧪 TechnicianAvailabilityService::filter() started');
        $resources = collect($this->data['Resources'] ?? []);
        Log::info("📊 Found {$resources->count()} resources");

        $technicians = $resources
            ->map(static function ($r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''));
                return [
                    'id' => $r['id'],
                    'name' => $name !== '' ? $name : $r['id'],
                ];
            })
            ->values()
            ->all();

        Log::info("✅ Built technician list: " . count($technicians) . " entries");
        Log::info("🏁 TechnicianAvailabilityService::filter() complete");

        return [
            'filtered' => [],
            'summary' => [],
            'technicians' => $technicians,
        ];
    }

    private function expandPatternBasedAvailability(array $shiftData): Collection
    {
        Log::info('Collecting the availability pattern based availability');

        $regionAvailability = collect($this->data['Resource_Region_Availability'] ?? []);
        $patterns = collect($this->data['Availability_Pattern'] ?? [])->keyBy('id');

        // Determine the overall date range from the shifts
        $shiftStartDates = collect($shiftData)
            ->pluck('start_datetime')
            ->map(static fn($dt) => Carbon::parse($dt));
        $shiftEndDates = collect($shiftData)
            ->pluck('end_datetime')
            ->map(static fn($dt) => Carbon::parse($dt));
        $overallStart = $shiftStartDates->min()->startOfDay();
        $overallEnd = $shiftEndDates->max()->endOfDay();

        // Build a daily period
        $dateRange = new DatePeriod(
            $overallStart,
            new DateInterval('P1D'),
            $overallEnd->addDay() // include last day
        );

        return $regionAvailability
            ->filter(static fn($rra) => !empty($rra['availability_pattern_id']))
            ->flatMap(function ($rra) use ($patterns, $dateRange) {
                $pattern = $patterns->get($rra['availability_pattern_id']);
                if (!$pattern) {
                    Log::warning("No pattern found for {$rra['availability_pattern_id']}");
                    return [];
                }

                $timezone = $pattern['time_zone'] ?? config('app.timezone');
                $patternStart = Carbon::parse($pattern['period_start_datetime'], $timezone);
                $patternEnd = Carbon::parse($pattern['period_end_datetime'], $timezone);
                $dayPattern = str_split($pattern['day_pattern']);
                $open = CarbonInterval::make($pattern['open_time']);
                $close = CarbonInterval::make($pattern['close_time']);

                $multiplier = (float)($rra['within_region_multiplier'] ?? 1.0);
                $active = $multiplier !== 0.0;
                $regionId = $rra['region_id'] ?? 'unknown';
                $resourceId = $rra['resource_id'] ?? null;

                $availabilities = [];

                foreach ($dateRange as $date) {
                    $loopDate = Carbon::parse($date, $timezone)->startOfDay();

                    if (!$loopDate->betweenIncluded($patternStart, $patternEnd)) {
                        continue;
                    }
                    $dayIndex = $loopDate->dayOfWeekIso - 1; // Monday=0
                    if (!isset($dayPattern[$dayIndex]) || $dayPattern[$dayIndex] !== 'Y') {
                        continue;
                    }

                    $start = $loopDate->copy()->add($open);
                    $end = $loopDate->copy()->add($close);

                    $availabilities[] = [
                        'id' => "pattern:{$pattern['id']}:{$loopDate->toDateString()}",
                        'resource_id' => $resourceId,
                        'region_id' => $regionId,
                        'availability_pattern_id' => $pattern['id'],
                        'start' => $start->copy()->tz('UTC')->toIso8601String(),
                        'end' => $end->copy()->tz('UTC')->toIso8601String(),
                        'region_active' => $active,
                        'full_coverage' => false,
                        'source' => 'pattern',
                        'source_id' => $rra['id'] ?? null,
                        'override_priority' => (int)($rra['override_priority'] ?? 0),
                        'within_region_multiplier' => $multiplier,
                    ];
                }

                return $availabilities;
            })
            ->values();
    }
}
