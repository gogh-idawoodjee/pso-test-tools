<?php

namespace App\Jobs;

use App\Services\DryRunSummaryFormatter;
use App\Services\ResourceActivityFilterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Log;
use Throwable;
use ZipArchive;

class ProcessResourceFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string  $jobId,
        public string  $path,
        public string  $regionIds,
        public bool    $dryRun = false, // 👈 this is the missing one,
        public ?string $overrideDatetime = null
    )
    {
    }

    public function handle(): void
    {
        try {
            Cache::put("resource-job:{$this->jobId}:status", 'processing');
            Cache::put("resource-job:{$this->jobId}:progress", 0);

            // Load and decode uploaded file
            $raw = Storage::disk('local')->get($this->path);
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $data = $json['dsScheduleData'] ?? [];

            $regionIds = collect(explode(',', $this->regionIds))->map(static fn($id) => trim($id))->toArray();

            // Filter and summarize
            $service = new ResourceActivityFilterService($data, $regionIds, $this->jobId, $this->overrideDatetime);
            ['filtered' => $filteredData, 'summary' => $summary] = $service->filter();

            $formatted = DryRunSummaryFormatter::format($summary);
            Cache::put("resource-job:{$this->jobId}:progress", 75);

            // Dry run? No file output
            if ($this->dryRun ?? false) {
                Cache::put("resource-job:{$this->jobId}:status", 'complete');
                Cache::put("resource-job:{$this->jobId}:progress", 100);
                Cache::put("resource-job:{$this->jobId}:preview", $formatted);
                return;
            }

            // Write filtered JSON
            $filename = "filtered_{$this->jobId}.json";
            $jsonOut = json_encode(['dsScheduleData' => $filteredData], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            Storage::disk('public')->put($filename, $jsonOut);
            $downloadUrl = config('app.url') . '/download/' . urlencode($filename);
            $finalDownloadFilename = $filename;

            // Zip if > 8MB
            $filePath = Storage::disk('public')->path($filename);
            if (filesize($filePath) > 8 * 1024 * 1024) {
                $zipName = str_replace('.json', '.zip', $filename);
                $zipPath = Storage::disk('public')->path($zipName);

                $zip = new ZipArchive;
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    $zip->addFile($filePath, $filename);
                    $zip->close();
                }

                Storage::disk('public')->delete($filename);
                $downloadUrl = route('download.filtered', ['filename' => $zipName]);
                $finalDownloadFilename = $zipName;
            }

            // Finalize job
            Cache::put("resource-job:{$this->jobId}:preview", $formatted);
            Cache::put("resource-job:{$this->jobId}:download", $downloadUrl);
            Cache::put("resource-job:{$this->jobId}:status", 'complete');
            Cache::put("resource-job:{$this->jobId}:progress", 100);

            // 🔥 Schedule cleanup
            DeleteTemporaryFiles::dispatch($this->path, $finalDownloadFilename)
                ->delay(now()->addHour());

        } catch (Throwable $e) {
            Log::error("Job [{$this->jobId}] failed: " . $e->getMessage());
            Cache::put("resource-job:{$this->jobId}:status", 'failed');
        }
    }


}
