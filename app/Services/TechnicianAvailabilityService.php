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


    public function getTechnicianShifts(): array
    {
        if (!$this->technicianId) {
            return [];
        }
        $this->setStatus('found resource');
        Log::info("✅ about to start collecting shifts for technician {$this->technicianId}");

        $shifts = collect($this->data['Shift'] ?? [])
            ->filter(fn($shift) => $shift['resource_id'] === $this->technicianId)
            ->sortBy('start_time')
            ->take(30)
            ->map(function ($shift) {
                return [
                    'id' => $shift['id'],
                    'start' => $shift['start_datetime'],
                    'end' => $shift['end_datetime'],
                    'label' => 'Shift',
                ];
            })
            ->values()
            ->all();
        Log:
        info('Shifts:' . json_encode($shifts));
        Log::info('Tech ID: ' . $this->technicianId);
        Log::info('Shifts Raw Count: ' . count($this->data['Shifts'] ?? []));
        Log::info('First shift example: ' . json_encode(($this->data['Shift'] ?? [])[0] ?? 'none'));
        Log::info("🏁 Shifts Collected");
        return $shifts;
    }


    public function filter(): array
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
