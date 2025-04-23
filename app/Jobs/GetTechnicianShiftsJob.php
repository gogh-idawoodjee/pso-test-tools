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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, FilamentJobMonitoring;

    public function __construct(
        string         $jobId,
        public string  $path,
        public ?string $technicianId = null
    )
    {
        $this->jobKey = 'technician-shift-job';
        $this->jobId = $jobId;

        Cache::put($this->getJobCacheKey('status'), 'constructed');
        Log::info("📦 Technician Shift Job constructed for jobId={$this->jobId}");
    }


    public function handle(): void
    {
        $this->setStatus('processing');
        $this->setProgress(5);

        if ($this->technicianId === null) {
            Log::error("❌ Missing Technician");
            $this->setStatus('failed');
            return;
        }

        try {
            $data = $this->loadDataFromPath();

            Log::info('handle process in technician shift job');
            $this->setProgress(30);

            Log::info('about to call get shifts');
            $shifts = $this->getShifts($data);
            Log::info('expected progress 75. shifts should be collected');
            Log::info('shifts: ' . json_encode($shifts, JSON_THROW_ON_ERROR));
            $this->setProgress(75);

            Cache::put($this->getJobCacheKey('shifts'), $shifts);
            $this->setStatus('complete');
            $this->setProgress(100);

            Log::info("✅ Technician Shift Job {$this->jobId} completed");
        } catch (Throwable $e) {
            Log::error("❌ Technician Shift Job {$this->jobId} failed: " . $e->getMessage());
            $this->setStatus('failed');
        }
    }

    protected function getShifts(array $data): array
    {
        Log::info('called get shifts with technician id: ' . $this->technicianId);
        $service = new TechnicianAvailabilityService($data, $this->jobId, $this->technicianId);
        return $service->getTechnicianShifts() ?? [];
    }

    protected function loadDataFromPath(): array
    {
        try {
            $this->setProgress(10);
            Log::info("📖 Reading JSON file for jobId={$this->jobId}");

            $raw = Storage::disk('local')->get($this->path);
            $this->setProgress(20);
            Log::info("🧠 Decoding JSON");

            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return $json['dsScheduleData'] ?? [];
        } catch (JsonException $e) {
            Log::error("❌ JSON decode failed: " . $e->getMessage());
            $this->notifyDanger('JSON Error', 'Could not decode the input file.');
            $this->setStatus('error');
            throw $e;
        }
    }

    protected function setProgress(int $percent): void
    {
        Cache::put($this->getJobCacheKey('progress'), $percent);
    }

    protected function setStatus(string $status): void
    {
        Cache::put($this->getJobCacheKey('status'), $status);
    }
}
