<?php

namespace App\Jobs;

use App\Services\TechnicianAvailabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class GetTechnicianShiftsJob extends BaseJob
{

    public ?string $jobName = 'Technician-Shift-Job';

    #[Override] public function handle(): void
    {

        parent::handle();

        if ($this->technicianId === null) {
            Log::error("❌ Missing Technician");
        }

        try {
            $data = $this->loadDataFromPath();

            Log::info('handle process in technician shift job');
            $this->updateProgress(30);

            Log::info('about to call get shifts');
            $shifts = $this->getShifts($data);
            Log::info('expected progress 75. shifts should be collected');
//            Log::info('shifts: ' . json_encode($shifts));
            $this->updateProgress(75);
            Cache::put($this->cacheKey(self::SHIFTS_KEY), $shifts);
            $this->updateStatus('complete');
            $this->updateProgress(100);

            Log::info("✅ Technician Shift Job {$this->jobId} completed");
        } catch (Throwable $e) {
            Log::error("❌ Technician Shift Job {$this->jobId} failed: " . $e->getMessage());
            $this->updateStatus('failed');
        }
    }

    protected function getShifts(array $data): array
    {
        Log::info('called get shifts with technician id: ' . $this->technicianId);
        $service = new TechnicianAvailabilityService($data, $this->jobId, $this->technicianId);
        return $service->getTechnicianShifts() ?? [];
    }


}
