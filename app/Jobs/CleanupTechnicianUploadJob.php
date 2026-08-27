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

/**
 * Deletes an uploaded technician-shifts JSON file from R2 once it's been idle
 * for an hour. Dispatched (with a 1 hour delay) on every activity against the
 * file by GetTechniciansListJob/GetTechnicianShiftsJob; if more activity has
 * happened since dispatch, this no-ops and lets the later activity's own
 * delayed check do the deleting instead.
 */
class CleanupTechnicianUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int IDLE_SECONDS = 3600;

    public function __construct(public string $path) {}

    public function handle(): void
    {
        $lastActivity = Cache::get(self::activityCacheKey($this->path));

        if ($lastActivity && now()->diffInSeconds($lastActivity) < self::IDLE_SECONDS) {
            return;
        }

        if (Storage::disk('r2')->exists($this->path)) {
            Storage::disk('r2')->delete($this->path);
            Log::info("🗑️ Deleted idle technician upload: {$this->path}");
        }

        Cache::forget(self::activityCacheKey($this->path));
    }

    /**
     * Records activity against $path and schedules an idle-check delete for
     * an hour from now. Call this from any job that just used $path.
     */
    public static function recordActivityAndScheduleCleanup(string $path): void
    {
        Cache::put(self::activityCacheKey($path), now(), now()->addDay());

        self::dispatch($path)->delay(now()->addHour());
    }

    public static function activityCacheKey(string $path): string
    {
        return 'technician-upload-last-activity:'.$path;
    }
}
