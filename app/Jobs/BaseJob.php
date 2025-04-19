<?php

namespace App\Jobs;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;


class BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string|null $jobName = null;
    protected const string STATUS_KEY = '%s:%s:status';
    protected const string PROGRESS_KEY = '%s:%s:progress';
    protected const string TECHNICIANS_KEY = '%s:%s:technicians';
    protected const string SHIFTS_KEY = '%s:%s:shifts';
    protected const string RAW_DATA_KEY = '$s:%s:data';

    public function __construct(
        public string      $jobId,
        public string      $path,
        public string|null $technicianId = null,
    )
    {
        Log::info("📦 {$this->jobName} Job constructed for jobId={$this->jobId}");
    }

    public function handle(): void
    {
        $this->updateStatus('processing');
        $this->updateProgress(5);
    }

    /**
     * @throws JsonException
     */
    protected function loadDataFromPath(): array
    {
        $this->updateProgress(10);
        Log::info("📖 Reading JSON file for jobId={$this->jobId}");

        $raw = Storage::disk('local')->get($this->path);

        $this->updateProgress(20);
        Log::info("🧠 Decoding JSON");

        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $json['dsScheduleData'] ?? [];
    }


    protected function updateProgress(int $percent): void
    {
        if ($this->jobId) {
            Cache::put($this->cacheKey(self::PROGRESS_KEY), $percent);
        }
    }

    protected function updateStatus(string $status): void
    {
        if ($this->jobId) {
            Cache::put($this->cacheKey(self::STATUS_KEY), $status);
        }
    }

    protected function cacheKey(string $template): string
    {
        return sprintf($template, $this->jobName, $this->jobId);
    }
}
