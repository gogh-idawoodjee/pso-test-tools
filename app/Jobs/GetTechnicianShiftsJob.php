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
use JsonException;
use Throwable;

class GetTechnicianShiftsJob implements ShouldQueue
{
    use Dispatchable, FilamentJobMonitoring, InteractsWithQueue, Queueable, SerializesModels;

    public string $startDate;

    public function __construct(
        string $jobId,
        public string $path,
        public ?string $technicianId,
        string $startDate

    ) {
        $this->jobKey = 'Technician-Shift-Job';          // ✅ Matches TechnicianAvail constant
        $this->cachePrefixType = 'Technician-Shift-Job'; // ✅ Same here
        $this->jobId = $jobId;
        $this->startDate = $startDate;

        Cache::put($this->getJobCacheKey('status'), 'constructed');
        Log::info("📦 Technician Shift Job constructed for jobId={$this->jobId}");
    }

    public function handle(): void
    {
        $this->updateStatus('processing');
        $this->updateProgress(5);

        if ($this->technicianId === null) {
            Log::error('❌ Missing Technician');
            $this->updateStatus('failed');

            return;
        }
        $this->updateProgress(30);
        try {
            $data = $this->loadDataFromPath();

            $this->updateProgress(75);

            $shifts = $this->getShifts($data);
            $this->updateProgress(90);

            Cache::put($this->getJobCacheKey('shifts'), $shifts);
            $this->updateStatus('complete');
            $this->updateProgress(100);

            Log::info("✅ Technician Shift Job {$this->jobId} completed");
        } catch (Throwable $e) {
            Log::error("❌ Technician Shift Job {$this->jobId} failed: ".$e->getMessage());
            $this->updateStatus('failed');
        }
    }

    protected function getShifts(array $data): array
    {
        $service = new TechnicianAvailabilityService($data, $this->jobId, $this->technicianId, $this->startDate);

        return $service->getTechnicianShifts() ?? [];
    }

    /**
     * @throws JsonException
     */
    protected function loadDataFromPath(): array
    {
        $this->updateProgress(10);
        Log::info("📖 Reading JSON file for jobId={$this->jobId}");

        $raw = Storage::disk('r2')->get($this->path);
        $this->updateProgress(20);
        Log::info('🧠 Decoding JSON');

        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $json['dsScheduleData'] ?? [];
    }
}
