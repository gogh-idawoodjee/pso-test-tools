<?php

namespace App\Traits;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait FilamentJobMonitoring
{
    // Job monitoring properties
    public ?string $jobId = null;
    public ?string $jobKey = null;
    public ?string $status = 'idle';
    public ?string $data = null;
    public bool $isPolling = false;
    public int $progress = 1;

    // Job timeout in seconds
    protected const int JOB_TIMEOUT = 60;
    protected string $cachePrefixType = 'resource-job';

    /**
     * Initialize a new background job
     */
    protected function startJob(string $jobType): void
    {
        $this->jobKey = $jobType;
        $this->jobId = (string)Str::uuid();
        $this->status = 'starting up';
        $this->progress = 0;  // Reset progress when starting a new job



        Cache::put($this->getJobCacheKey('status'), 'starting up');
        Log::info("Starting {$jobType} job with ID: {$this->jobId}");
    }

    /**
     * Reset the job state
     */
    protected function resetJobState(): void
    {
        $this->jobId = null;
        $this->status = 'idle';
//        $this->progress = 0;
    }

    /**
     * Check if the job has timed out
     */
    protected function isJobTimedOut(): bool
    {
        $createdAt = Cache::get($this->getJobCacheKey('created_at'), 0);
        return $this->status === 'pending' &&
            (time() - $createdAt) > self::JOB_TIMEOUT;
    }

    /**
     * Handle job timeout
     */
    protected function handleJobTimeout(): void
    {
        $this->progress = 100;
        $this->notifyWarning('Job timed out', 'The processing job took too long to complete.');
        $this->resetJobState();
    }

    /**
     * Get job cache key with optional suffix
     */
    protected function getJobCacheKey(string $suffix = ''): string
    {

        if (!$this->jobKey || !$this->jobId) {
            Log::warning('⚠️ getJobCacheKey called with missing jobKey or jobId', [
                'jobKey' => $this->jobKey,
                'jobId' => $this->jobId,
                'suffix' => $suffix,
            ]);
            return 'invalid:cache:key';
        }
        return "{$this->cachePrefixType}:{$this->jobId}" . ($suffix ? ":{$suffix}" : '');
    }

    /**
     * Get value from job cache
     */
    protected function getFromJobCache(string $key, $default = null)
    {
        return Cache::get($this->getJobCacheKey($key), $default);
    }

    /**
     * Get job progress
     */
    protected function getJobProgress(): int
    {

        return (int)$this->getFromJobCache('progress', 1);
    }

    /**
     * Get job status
     */
    protected function getJobStatus(): string
    {
        return $this->getFromJobCache('status', 'unknown');
    }

    /**
     * Get job data
     */
    protected function getJobData()
    {
        return $this->getFromJobCache('data');
    }

    /**
     * Increment the polling count (for debugging)
     */
    protected function incrementPollingCount(): void
    {
        $pollingCount = $this->getFromJobCache('polling-count', 0);
        $pollingCount++;
        Cache::put($this->getJobCacheKey('polling-count'), $pollingCount);
        Log::info("Polling count: {$pollingCount} for jobId: {$this->jobId}");
    }

    /**
     * Toggle polling state
     */
    public function togglePolling($start = false): void
    {
        Log::info("togglePolling: " . ($start ? 'start' : 'stop'));
        $this->isPolling = $start;
    }

    // Notification helper methods
    protected function notifySuccess(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }

    protected function notifyWarning(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }

    protected function notifyDanger(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }
}
