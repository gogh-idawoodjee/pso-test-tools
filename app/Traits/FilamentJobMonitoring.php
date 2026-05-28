<?php

namespace App\Traits;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait FilamentJobMonitoring
{
    public ?string $jobId = null;

    public ?string $jobKey = null;

    public ?string $status = 'idle';

    public ?string $data = null;

    public bool $isPolling = false;

    public int $progress = 0;

    protected const int JOB_TIMEOUT = 60;

    public string $cachePrefixType = 'resource-job';

    protected function startJob(string $jobType): void
    {
        $this->jobKey = $jobType;
        $this->jobId = (string) Str::uuid();
        $this->status = 'starting up';
        $this->progress = 0;
        $this->cachePrefixType = $jobType;

        Cache::put($this->getJobCacheKey('status'), 'starting up');
        Log::info("Starting {$jobType} job with ID: {$this->jobId}");
    }

    protected function resetJobState(): void
    {
        $this->jobId = null;
        $this->status = 'idle';
    }

    protected function isJobTimedOut(): bool
    {
        $createdAt = Cache::get($this->getJobCacheKey('created_at'), 0);

        return $this->status === 'pending'
            && (time() - $createdAt) > self::JOB_TIMEOUT;
    }

    protected function handleJobTimeout(): void
    {
        $this->progress = 100;
        $this->notify('Job timed out', 'The processing job took too long to complete.', 'warning');
        $this->resetJobState();
    }

    protected function getJobCacheKey(string $suffix = ''): string
    {
        if (! $this->jobKey || ! $this->jobId) {
            Log::warning('getJobCacheKey called with missing jobKey or jobId', [
                'jobKey' => $this->jobKey,
                'jobId' => $this->jobId,
                'suffix' => $suffix,
            ]);

            return 'invalid:cache:key';
        }

        return "{$this->cachePrefixType}:{$this->jobId}".($suffix ? ":{$suffix}" : '');
    }

    protected function getFromJobCache(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->getJobCacheKey($key), $default);
    }

    protected function getJobProgress(): int
    {
        return (int) $this->getFromJobCache('progress', 0);
    }

    protected function getJobStatus(): string
    {
        return $this->getFromJobCache('status', 'unknown');
    }

    protected function getJobData(): mixed
    {
        return $this->getFromJobCache('data');
    }

    protected function incrementPollingCount(): void
    {
        $pollingCount = $this->getFromJobCache('polling-count', 0) + 1;
        Cache::put($this->getJobCacheKey('polling-count'), $pollingCount);
        Log::info("Polling count: {$pollingCount} for jobId: {$this->jobId}");
    }

    protected function notify(string $title, string $body, string $type = 'success'): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->{$type}()
            ->send();
    }

    protected function notifySuccess(string $title, string $body): void
    {
        $this->notify($title, $body, 'success');
    }

    protected function notifyWarning(string $title, string $body): void
    {
        $this->notify($title, $body, 'warning');
    }

    protected function notifyDanger(string $title, string $body): void
    {
        $this->notify($title, $body, 'danger');
    }

    protected function updateProgress(int $percent): void
    {
        $this->progress = $percent;
        if ($this->jobId && $this->cachePrefixType) {
            $key = $this->getJobCacheKey('progress');
            Cache::put($key, $percent);
            Log::info("updateProgress({$percent}) to {$key}");
        }
    }

    protected function updateStatus(string $status): void
    {
        $this->status = $status;
        if ($this->jobId && $this->jobKey) {
            Cache::put($this->getJobCacheKey('status'), $status);
        }
    }
}
