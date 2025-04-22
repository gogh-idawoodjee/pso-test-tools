<?php

namespace App\Jobs;

use App\Services\ResourceActivityFilterService;
use App\Support\PreviewSummaryFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Log;
use Throwable;
use ZipArchive;

class ProcessResourceFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const int|float LARGE_FILE_THRESHOLD = 8 * 1024 * 1024; // 8MB
    protected const string CACHE_KEY_PREFIX = 'resource-job:';

    public function __construct(
        public string  $jobId,
        public string  $path,
        public array   $regionIds,
        public bool    $dryRun = false,
        public ?string $overrideDatetime = null,
        public array   $resourceIds = [],
        public array   $activityIds = [],
    )
    {
    }

    public function handle(): void
    {
        Log::info("🤖 Processing resource file with regionIds: ", $this->regionIds);

        try {
            $this->updateJobStatus('processing', 0);

            // Load and process data
            $data = $this->loadInputData();
            $result = $this->processData($data);
            $this->cacheAvailableIds($data);

            // Format and cache preview
            $formatted = PreviewSummaryFormatter::format($result['summary']);
            $this->updateCache('preview', $formatted);
            $this->updateJobProgress(75);

            // Skip file creation for dry runs
            if ($this->dryRun) {
                $this->updateJobStatus('complete', 100);
                return;
            }

            // Create and store output file
            $this->createOutputFile($result['filtered']);

            // Schedule cleanup
            $this->scheduleCleanup();

        } catch (Throwable $e) {
            Log::error("Job [{$this->jobId}] failed: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            $this->updateJobStatus('failed');
        }
    }

    /**
     * @throws JsonException
     */
    protected function loadInputData(): array
    {
        $raw = Storage::disk('local')->get($this->path);
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $json['dsScheduleData'] ?? [];
    }

    protected function processData(array $data): array
    {
        $service = new ResourceActivityFilterService(
            $data,
            $this->regionIds,
            $this->jobId,
            $this->overrideDatetime,
            $this->resourceIds,
            $this->activityIds,
            $this->dryRun
        );

        return $service->filter();
    }

    protected function cacheAvailableIds(array $data): void
    {
        Log::info('📦 Region entries found: ' . count($data['Region'] ?? []));
        Log::debug('📦 Sample Region:', [($data['Region'][0] ?? [])]);

        $regionList = collect($data['Region'] ?? [])
            ->mapWithKeys(static fn($region) => [
                $region['id'] => isset($region['description'])
                    ? "{$region['id']} - {$region['description']}"
                    : $region['id']
            ]);

        $activityTypeList = collect($data['Activity_Type'] ?? [])
            ->mapWithKeys(static fn($type) => [
                $type['id'] => "{$type['id']} - {$type['description']}"
            ]);

        $resourceList = collect($data['Resources'] ?? [])
            ->mapWithKeys(static fn($r) => [
                $r['id'] => "{$r['id']} - " . trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''))
            ]);

        $activityList = collect($data['Activity'] ?? [])
            ->mapWithKeys(static fn($a) => [
                $a['id'] => isset($a['activity_type_id'])
                    ? "{$a['id']} - {$a['activity_type_id']}"
                    : $a['id']
            ]);

        Log::info('🧠 Job regionIds:', $this->regionIds);
        Log::info('🧠 Filtered counts:', [
            'resources' => count($data['Resources'] ?? []),
            'activities' => count($data['Activity'] ?? []),
        ]);

        $this->updateCache('available_ids', [
            'regions' => $regionList->all(),
            'resources' => $resourceList->all(),
            'activities' => $activityList->all(),
            'activity_types' => $activityTypeList->all(), // 👈 include this
        ]);
    }

    /**
     * @throws JsonException
     */
    protected function createOutputFile(array $filteredData): void
    {
        $filename = "filtered_{$this->jobId}.json";
        $jsonOut = json_encode(['dsScheduleData' => $filteredData], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        Storage::disk('public')->put($filename, $jsonOut);

        $finalFilename = $this->compressFileIfNeeded($filename);
        $downloadUrl = $this->generateDownloadUrl($finalFilename);

        $this->updateCache('download', $downloadUrl);
        $this->updateJobStatus('complete', 100);
    }

    protected function compressFileIfNeeded(string $filename): string
    {
        $filePath = Storage::disk('public')->path($filename);

        if (filesize($filePath) <= self::LARGE_FILE_THRESHOLD) {
            return $filename;
        }

        $zipName = str_replace('.json', '.zip', $filename);
        $zipPath = Storage::disk('public')->path($zipName);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            $zip->addFile($filePath, $filename);
            $zip->close();
        }

        Storage::disk('public')->delete($filename);
        return $zipName;
    }

    protected function generateDownloadUrl(string $filename): string
    {
        if (str_ends_with($filename, '.zip')) {
            return route('download.filtered', compact('filename'));
        }

        return config('app.url') . '/download/' . urlencode($filename);
    }

    protected function scheduleCleanup(): void
    {
        $outputFilename = Storage::disk('public')->exists("filtered_{$this->jobId}.json")
            ? "filtered_{$this->jobId}.json"
            : "filtered_{$this->jobId}.zip";

        DeleteTemporaryFiles::dispatch($this->path, $outputFilename)
            ->delay(now()->addHour());
    }

    protected function updateJobStatus(string $status, ?int $progress = null): void
    {
        $this->updateCache('status', $status);

        if ($progress !== null) {
            $this->updateJobProgress($progress);
        }
    }

    protected function updateJobProgress(int $progress): void
    {
        $this->updateCache('progress', $progress);
    }

    protected function updateCache(string $key, $value): void
    {
        Cache::put(self::CACHE_KEY_PREFIX . "{$this->jobId}:{$key}", $value);
    }
}
