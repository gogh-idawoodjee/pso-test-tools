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
        $this->jobKey = 'Technician-Shift-Job';          // ✅ Matches TechnicianAvail constant
        $this->cachePrefixType = 'Technician-Shift-Job'; // ✅ Same here
        $this->jobId = $jobId;


        Cache::put($this->getJobCacheKey('status'), 'constructed');
        Log::info("📦 Technician Shift Job constructed for jobId={$this->jobId}");
    }


    public function handle(): void
    {
        $this->updateStatus('processing');
        $this->updateProgress(5);

        if ($this->technicianId === null) {
            Log::error("❌ Missing Technician");
            $this->updateStatus('failed');
            return;
        }

        try {
            $data = $this->loadDataFromPath();

            Log::info('handle process in technician shift job');
            $this->updateProgress(30);

            Log::info('about to call get shifts');
            $shifts = $this->getShifts($data);
            Log::info('expected progress 75. shifts should be collected');
            Log::info('shifts: ' . json_encode($shifts, JSON_THROW_ON_ERROR));
            $this->updateProgress(75);

            Cache::put($this->getJobCacheKey('shifts'), $shifts);
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

    protected function loadDataFromPath(): array
    {
        try {
            $this->updateProgress(10);
            Log::info("📖 Reading JSON file for jobId={$this->jobId}");

            $raw = Storage::disk('local')->get($this->path);
            $this->updateProgress(20);
            Log::info("🧠 Decoding JSON");

            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return $json['dsScheduleData'] ?? [];
        } catch (JsonException $e) {
            Log::error("❌ JSON decode failed: " . $e->getMessage());
            $this->notifyDanger('JSON Error', 'Could not decode the input file.');
            $this->updateStatus('error');
            throw $e;
        }
    }

}
