<?php

namespace App\Jobs;

use App\Services\TechnicianAvailabilityService;
use App\Traits\FilamentJobMonitoring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GetTechniciansListJob implements ShouldQueue
{
    use Dispatchable, FilamentJobMonitoring, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        string $jobId,
        public string $path,
    ) {
        $this->jobId = $jobId;
        $this->jobKey = 'resource-job';
        $this->cachePrefixType = 'resource-job';

        Log::info("📦 resource-job Job constructed for jobId={$this->jobId}");
    }

    public function handle(): void
    {
        $this->updateStatus('processing');
        $this->updateProgress(5);

        try {
            $data = $this->loadDataFromPath();
            $this->updateProgress(30);

            $technicians = $this->filterTechnicians($data);
            $this->updateProgress(50);

            Cache::put($this->getJobCacheKey('technicians'), $technicians);
            $this->updateStatus('complete');
            $this->updateProgress(100);

            Log::info("✅ Technician list job {$this->jobId} completed with ".count($technicians).' technicians.');
        } catch (Throwable $e) {
            Log::error("❌ Job {$this->jobId} failed: ".$e->getMessage());
            $this->updateStatus('failed');
        }
    }

    protected function loadDataFromPath(): array
    {
        $this->updateProgress(10);
        Log::info("📖 Reading JSON file for jobId={$this->jobId}");

        $raw = Storage::disk('r2')->get($this->path);

        $this->updateProgress(20);
        Log::info('🧠 Decoding JSON');

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['dsScheduleData'] ?? [];
    }

    protected function filterTechnicians(array $data): array
    {
        $service = new TechnicianAvailabilityService($data, $this->jobId);

        return $service->getTechnicians();
    }
}
