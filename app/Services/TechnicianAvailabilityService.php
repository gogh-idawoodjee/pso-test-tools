<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

        $this->setStatus('found resource');
        Log::info("✅ about to start collecting shifts for technician {$this->technicianId}");

        // Get just the next X shifts
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
            ->sortBy(fn($s) => Carbon::parse($s['start_datetime']))
            ->take($limit)
            ->values();

        // Get related data
        $availabilityData = $this->data['Availability'] ?? [];
        $resourceRegionAvailData = $this->data['Resource_Region_Availability'] ?? [];
        $regions = collect($this->data['Region'] ?? []);

        $availabilityById = collect($availabilityData)->keyBy('id');
        $regionsById = $regions->keyBy('id');

        $regionAvailability = collect($resourceRegionAvailData)
            ->filter(fn($rra) => !empty($rra['availability_id']))
            ->groupBy('resource_id');

        $shifts = collect($shiftData)->map(function ($shift) use ($regionAvailability, $availabilityById, $regionsById) {
            $shiftStart = Carbon::parse($shift['start_datetime']);
            $shiftEnd = Carbon::parse($shift['end_datetime']);
            $resourceId = $shift['resource_id'];

            $overlappingAvailability = collect($regionAvailability->get($resourceId, []))
                ->map(function ($rra) use ($availabilityById, $shiftStart, $shiftEnd, $regionsById) {
                    $availability = $availabilityById->get($rra['availability_id'] ?? '');

                    if (!$availability) {
                        return null;
                    }

                    $availStart = Carbon::parse($availability['datetime_start']);
                    $availEnd = Carbon::parse($availability['datetime_end']);

                    // Skip if no overlap
                    if ($availEnd->lte($shiftStart) || $availStart->gte($shiftEnd)) {
                        return null;
                    }

                    $region = $regionsById->get($rra['region_id'] ?? '');
                    $regionDescription = $region['description'] ?? null;

                    return [
                        'id' => $availability['id'],
                        'start' => max($shiftStart, $availStart)->toIso8601String(),
                        'end' => min($shiftEnd, $availEnd)->toIso8601String(),
                        'full_coverage' => $availStart->lte($shiftStart) && $availEnd->gte($shiftEnd),
                        'region_description' => $regionDescription,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            $shift['region_availability'] = $overlappingAvailability;

            return $shift;
        });

        Log::info('Tech ID: ' . $this->technicianId);
        Log::info('Shifts Raw Count: ' . count($this->data['Shifts'] ?? []));
        Log::info("🏁 Shifts Collected");

        return $shifts->toArray();
    }


    public function getTechnicians(): array
    {
        $this->setStatus('filtering');
        Log::info('🧪 TechnicianAvailabilityService::filter() started');
        $this->updateProgress(35);

        $resources = collect($this->data['Resources'] ?? []);
        Log::info("📊 Found {$resources->count()} resources");

        $technicians = $resources->map(function ($r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''));
            return [
                'id' => $r['id'],
                'name' => $name !== '' ? $name : $r['id'],
            ];
        })->values()->all();

        $this->updateProgress(50);

        Log::info("✅ Built technician list: " . count($technicians) . " entries");
        Log::info("🏁 TechnicianAvailabilityService::filter() complete");

        $this->setStatus('filtered');

        return [
            'filtered' => [],  // Placeholder for future filtered data
            'summary' => [],  // Placeholder for future summary
            'technicians' => $technicians,
        ];
    }

    protected function updateProgress(int $percent): void
    {
        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:progress", $percent);
            Log::info("📶 Progress: {$percent}% for job {$this->jobId}");
            usleep(500_000); // Optional throttle
        }
    }

    protected function setStatus(string $status): void
    {
        if ($this->jobId) {
            Cache::put("resource-job:{$this->jobId}:status", $status);
        }
    }
}
