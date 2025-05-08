<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class HasScopedCache
{
    protected ?string $jobId = null;

    /**
     * Override this in subclasses to set a different cache prefix.
     */
    protected string $cachePrefixType = 'resource-job';

    protected function cachePrefix(): string
    {
        return "{$this->cachePrefixType}:{$this->jobId}:";
    }

    protected function updateCache(string $key, $value): void
    {
        if ($this->jobId) {
            Cache::put($this->cachePrefix() . $key, $value);
        }
    }

    protected function updateProgress(int $percent): void
    {
        $this->updateCache('progress', $percent);
        Log::info('Progress at ' . $percent . '% - via ResourceActivityFilterService');

        // Add a tiny delay for visual smoothness on fast operations
        if ($this->jobId && !app()->environment('testing')) {
            usleep(50000); // 50ms delay
        }
    }
    protected function updateStatus(string $status): void
    {
        $this->updateCache('status', $status);
    }
}
