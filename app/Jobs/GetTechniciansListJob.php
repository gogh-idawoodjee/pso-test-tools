<?php

namespace App\Jobs;

use App\Services\TechnicianAvailabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class GetTechniciansListJob extends BaseJob
{

    public ?string $jobName = 'resource-job';

    #[Override] public function handle(): void
    {

        parent::handle();

        try {
            $data = $this->loadDataFromPath();

            $this->updateProgress(30);

            $technicians = $this->filterTechnicians($data);
            Cache::put($this->cacheKey(self::TECHNICIANS_KEY), $technicians);

            $this->updateProgress(75);

            Cache::put($this->cacheKey(self::RAW_DATA_KEY), $data ? 'has data' : 'no data');
            $this->updateStatus('complete');
            $this->updateProgress(100);

            Log::info("✅ Technician list job {$this->jobId} completed with " . count($technicians) . " technicians.");
        } catch (Throwable $e) {
            Log::error("❌ Job {$this->jobId} failed: " . $e->getMessage());
            $this->updateStatus('failed');
        }
    }


    protected function filterTechnicians(array $data): array
    {
        $service = new TechnicianAvailabilityService($data, $this->jobId);
        return $service->filter()['technicians'] ?? [];
    }


}
