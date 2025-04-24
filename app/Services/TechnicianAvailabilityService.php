<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TechnicianAvailabilityService
{
    public function __construct(
        protected array   $data,
        protected ?string $jobId = null,
        protected ?string $technicianId = null,
    )
    {

    }

    public function getTechnicianShifts(int $limit = 5, bool $onlyUpcoming = false): array
    {
        if (!$this->technicianId) {
            return [];
        }

        Log::info("✅ Collecting shifts for technician {$this->technicianId}");

        $shiftData = collect($this->data['Shift'] ?? [])
            ->filter(function ($s) use ($onlyUpcoming) {
                if (!isset($s['resource_id'], $s['start_datetime'])) {
                    return false;
                }
                if ($s['resource_id'] !== $this->technicianId) {
                    return false;
                }
                if ($onlyUpcoming && Carbon::parse($s['start_datetime'])->lt(now())) {
                    return false;
                }
                return true;
            })
            ->sortBy(static fn($s) => Carbon::parse($s['start_datetime']))
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

        $patternAvailabilities = $this->expandPatternBasedAvailability($shiftData->all());

        $directAvailabilities = collect($resourceRegionAvailData)
            ->filter(static fn($rra) => !empty($rra['availability_id']));

        $regionAvailability = $directAvailabilities
            ->merge($patternAvailabilities)
            ->groupBy('resource_id');

        $shifts = collect($shiftData)->map(function ($shift) use ($regionAvailability, $availabilityById, $regionsById, $getTopParentId, $getTopParentDescription, $shiftBreaks) {
            $shiftId = $shift['id'];
            $shiftStart = Carbon::parse($shift['start_datetime']);
            $shiftEnd = Carbon::parse($shift['end_datetime']);
            $resourceId = $shift['resource_id'];

            $overlappingAvailability = collect($regionAvailability->get($resourceId, []))
                ->map(function ($rra) use ($availabilityById, $shiftStart, $shiftEnd, $regionsById, $getTopParentId, $getTopParentDescription) {
                    if (!isset($rra['availability_id'])) {
                        return null;
                    }
                    $availability = $availabilityById->get($rra['availability_id'] ?? '');
                    if (!$availability) {
                        return null;
                    }

                    $availStart = Carbon::parse($availability['datetime_start']);
                    $availEnd = Carbon::parse($availability['datetime_end']);

                    if ($availEnd->lte($shiftStart) || $availStart->gte($shiftEnd)) {
                        return null;
                    }

                    $regionId = $rra['region_id'] ?? null;
                    $regionDescription = 'Unknown region';

                    if ($regionId && $regionsById->has($regionId)) {
                        $regionDescription = $regionsById[$regionId]['description'] ?? $regionId;
                    }

                    $topParentRegionId = $regionId ? $getTopParentId($regionId) : null;
                    $topParentRegionDescription = $regionId ? $getTopParentDescription($regionId) : 'Unknown';

                    return [
                        'id' => $availability['id'],
                        'region_id' => $regionId,
                        'region_description' => $regionDescription,
                        'region_group_id' => $topParentRegionId,
                        'region_group_description' => $topParentRegionDescription,
                        'start' => max($shiftStart, $availStart)->toIso8601String(),
                        'end' => min($shiftEnd, $availEnd)->toIso8601String(),
                        'region_active' => !isset($rra['within_region_multiplier']) || (float)$rra['within_region_multiplier'] !== 0.0,
                        'full_coverage' => $availStart->lte($shiftStart) && $availEnd->gte($shiftEnd),
                        'source' => 'availability',
                        'source_id' => $rra['id'] ?? null,
                    ];

                })
                ->filter()
                ->values()
                ->all();

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
        });

        return $shifts->toArray();
    }

    public function getTechnicians(): array
    {
        Log::info('🧪 TechnicianAvailabilityService::filter() started');

        $resources = collect($this->data['Resources'] ?? []);
        Log::info("📊 Found {$resources->count()} resources");

        $technicians = $resources->map(static function ($r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''));
            return [
                'id' => $r['id'],
                'name' => $name !== '' ? $name : $r['id'],
            ];
        })->values()->all();

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

        // Get all shift days
        $shiftDays = collect($shiftData)
            ->filter(fn($s) => isset($s['start_datetime']))
            ->map(fn($s) => Carbon::parse($s['start_datetime'])->tz(config('app.timezone'))->startOfDay()->toDateString())
            ->unique();

        return $regionAvailability
            ->filter(fn($rra) => !empty($rra['availability_pattern_id']))
            ->flatMap(function ($rra) use ($patterns, $shiftDays) {
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

                $regionId = $rra['region_id'] ?? 'unknown';
                $resourceId = $rra['resource_id'] ?? null;
                $multiplier = (float)($rra['within_region_multiplier'] ?? 1.0);
                $active = $multiplier !== 0.0;

                return $shiftDays->flatMap(function ($dateString) use ($timezone, $patternStart, $patternEnd, $dayPattern, $open, $close, $pattern, $resourceId, $regionId, $active) {
                    $loopDate = Carbon::parse($dateString, $timezone)->startOfDay();

                    if (!$loopDate->betweenIncluded($patternStart, $patternEnd)) {
                        return [];
                    }

                    $dayIndex = $loopDate->dayOfWeekIso - 1; // Monday = 0
                    if (!isset($dayPattern[$dayIndex]) || $dayPattern[$dayIndex] !== 'Y') {
                        return [];
                    }

                    $start = $loopDate->copy()->add($open);
                    $end = $loopDate->copy()->add($close);

                    return [[
                        'id' => "pattern:{$pattern['id']}:{$loopDate->toDateString()}",
                        'resource_id' => $resourceId,
                        'region_id' => $regionId,
                        'availability_pattern_id' => $pattern['id'],
                        'start' => $start->copy()->tz('UTC')->toIso8601String(),
                        'end' => $end->copy()->tz('UTC')->toIso8601String(),
                        'region_active' => $active,
                        'full_coverage' => false, // handled later
                        'source' => 'pattern',
                        'source_id' => $rra['id'] ?? null,
                    ]];

                });
            })
            ->values();
    }

}
