<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait JobCacheHelper
{
    public function updateJobCache(string $jobId, string $key, $value): void
    {
        Cache::put("resource-job:{$jobId}:{$key}", $value);
    }

    public function updateJobProgress(string $jobId, int $percent): void
    {
        $this->updateJobCache($jobId, 'progress', $percent);
    }

    public function updateJobStatus(string $jobId, string $status): void
    {
        $this->updateJobCache($jobId, 'status', $status);
    }
}
