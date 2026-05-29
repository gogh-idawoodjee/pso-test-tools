<?php

namespace App\Jobs;

use App\Services\ResourceActivityFilterService;
use App\Support\HasScopedCache;
use App\Support\PreviewSummaryFormatter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Log;
use Throwable;
use ZipArchive;

/**
 * Queued job that processes a PSO load file (JSON) uploaded via the FilterLoadFile page.
 *
 * Responsibilities:
 *  1. Download the uploaded JSON from R2 storage and decode it.
 *  2. Pass the data through ResourceActivityFilterService to apply region/resource/activity/date filters.
 *  3. Cache the available IDs (for dropdown population) and a preview summary.
 *  4. In filter mode (non-dry-run), write the filtered JSON to local storage,
 *     optionally compress it to ZIP if it exceeds 8 MB, and cache the download URL.
 *  5. Schedule cleanup of temporary files after one hour.
 *
 * Progress is reported to cache so the Filament page can poll and display a progress bar.
 */
class ProcessResourceFile extends HasScopedCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    protected const int|float LARGE_FILE_THRESHOLD = 8 * 1024 * 1024;

    public function __construct(
        public ?string $jobId,
        public string $path,
        public array $regionIds,
        public bool $dryRun = false,
        public ?string $overrideDatetime = null,
        public array $resourceIds = [],
        public array $activityIds = [],
        public ?Carbon $startDate = null,
        public ?Carbon $endDate = null,
        public array $activityTypeIds = [],
    ) {}

    /**
     * Execute the job: load → filter → cache IDs → preview → output → cleanup.
     * Each stage updates progress in cache so the frontend can track it.
     */
    public function handle(): void
    {
        Log::info("[ProcessResourceFile][{$this->jobId}] 🚀 Job started", [
            'path' => $this->path,
            'regions' => $this->regionIds,
            'dryRun' => $this->dryRun,
            'startDate' => $this->startDate?->toDateString(),
            'endDate' => $this->endDate?->toDateString(),
        ]); // ← logging

        try {
            $this->updateStatus('processing');
            $this->updateProgress(5);

            // --- LOAD INPUT ---
            $data = $this->loadInputData();
            Log::info("[ProcessResourceFile][{$this->jobId}] 📂 Input loaded", [
                'Region' => count($data['Region'] ?? []),
                'Activity' => count($data['Activity'] ?? []),
                'Resources' => count($data['Resources'] ?? []),
                'Shift' => count($data['Shift'] ?? []),
                'Shift_Break' => count($data['Shift_Break'] ?? []),
            ]); // ← logging
            $this->updateProgress(10);

            // --- PROCESS (FILTER) ---
            $result = $this->processData($data);
            Log::info("[ProcessResourceFile][{$this->jobId}] 🔍 Filtering done", [
                'resources_kept' => $result['summary']['resources']['kept'] ?? null,
                'resources_skipped' => $result['summary']['resources']['skipped'] ?? null,
                'shifts_kept' => $result['summary']['shifts']['kept'] ?? null,
                'shifts_skipped' => $result['summary']['shifts']['skipped'] ?? null,
            ]); // ← logging

            // --- CACHE IDS ---
            $this->cacheAvailableIds($data);
            $this->updateProgress(85);

            // --- PREVIEW ---
            $formatted = PreviewSummaryFormatter::format($result['summary']);
            Log::debug("[ProcessResourceFile][{$this->jobId}] 👀 Preview summary", $formatted); // ← logging
            $this->updateCache('preview', $formatted);
            $this->updateProgress(90);

            if ($this->dryRun) {
                Log::info("[ProcessResourceFile][{$this->jobId}] 🛑 Dry run – skipping output"); // ← logging
                $this->updateStatus('complete');
                $this->updateProgress(100);

                return;
            }

            // --- OUTPUT FILE ---
            $this->createOutputFile($result['filtered']);
            Log::info("[ProcessResourceFile][{$this->jobId}] 📤 Output created"); // ← logging
            $this->updateProgress(95);

            // --- CLEANUP ---
            $this->scheduleCleanup();
            $this->updateStatus('complete');
            $this->updateProgress(100);

            Log::info("[ProcessResourceFile][{$this->jobId}] 🎉 Job finished"); // ← logging

        } catch (Throwable $e) {
            Log::error("[ProcessResourceFile][{$this->jobId}] ❌ Failed", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]); // ← logging
            $this->updateStatus('failed');
        }
    }

    /**
     * Download and decode the JSON file from R2 storage.
     * Returns the 'dsScheduleData' object which contains all PSO entities
     * (Resources, Activities, Shifts, Locations, etc.).
     *
     * @throws JsonException
     */
    protected function loadInputData(): array
    {
        ini_set('memory_limit', '512M');

        Log::debug("[ProcessResourceFile][{$this->jobId}] ⏳ Reading file from storage");
        $raw = Storage::disk('r2')->get($this->path);
        Log::debug("[ProcessResourceFile][{$this->jobId}] 🔢 Read bytes", ['bytes' => strlen($raw)]);

        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        unset($raw); // Free the raw string immediately

        return $json['dsScheduleData'] ?? [];
    }

    /**
     * Instantiate the filter service and run it against the parsed data.
     * Progress range 10–80 is delegated to the service for granular tracking.
     */
    protected function processData(array $data): array
    {
        Log::info("[ProcessResourceFile][{$this->jobId}] ⚙️ Invoking filter service"); // ← logging
        $service = new ResourceActivityFilterService(
            $data,
            $this->regionIds,
            $this->jobId,
            $this->overrideDatetime,
            $this->resourceIds,
            $this->activityIds,
            $this->dryRun,
            $this->startDate,
            $this->endDate,
            10,
            80,
            $this->activityTypeIds,
        );

        $result = $service->filter();
        Log::info("[ProcessResourceFile][{$this->jobId}] ✅ Filter service returned"); // ← logging

        return $result;
    }

    /**
     * Build formatted label maps (id => "id - description") for each entity type
     * and store them in cache so the Filament page can populate its filter dropdowns.
     */
    protected function cacheAvailableIds(array $data): void
    {
        Log::info('📦 Region entries found: '.count($data['Region'] ?? []));
        Log::debug('📦 Sample Region:', [($data['Region'][0] ?? [])]);

        $regionList = collect($data['Region'] ?? [])
            ->mapWithKeys(static fn ($region) => [
                $region['id'] => isset($region['description'])
                    ? "{$region['id']} - {$region['description']}"
                    : $region['id'],
            ]);

        $activityTypeList = collect($data['Activity_Type'] ?? [])
            ->mapWithKeys(static fn ($type) => [
                $type['id'] => "{$type['id']} - {$type['description']}",
            ]);

        $resourceList = collect($data['Resources'] ?? [])
            ->mapWithKeys(static fn ($r) => [
                $r['id'] => "{$r['id']} - ".trim(($r['first_name'] ?? '').' '.($r['surname'] ?? '')),
            ]);

        $activityList = collect($data['Activity'] ?? [])
            ->mapWithKeys(static fn ($a) => [
                $a['id'] => isset($a['activity_type_id'])
                    ? "{$a['id']} - {$a['activity_type_id']}"
                    : $a['id'],
            ]);

        Log::info('🧠 Job regionIds:', $this->regionIds);
        Log::info('🧠 Filtered counts:', [
            'resources' => count($data['Resources'] ?? []),
            'activities' => count($data['Activity'] ?? []),
        ]);

        $activityTypeCounts = collect($data['Activity'] ?? [])
            ->groupBy('activity_type_id')
            ->map(fn ($items) => $items->count());

        $this->updateCache('available_ids', [
            'regions' => $regionList->all(),
            'resources' => $resourceList->all(),
            'activities' => $activityList->all(),
            'activity_types' => $activityTypeList->all(), // 👈 include this
            'activity_type_counts' => $activityTypeCounts->toArray(),
        ]);
    }

    /**
     * Write the filtered dataset as JSON to local storage, compress to ZIP if over 8 MB,
     * and cache the download URL for the frontend.
     *
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
    }

    /**
     * If the output JSON exceeds 8 MB, compress it into a ZIP archive
     * and delete the original JSON. Returns the final filename.
     */
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

        return config('app.url').'/download/'.urlencode($filename);
    }

    /**
     * Schedule a delayed job to delete both the uploaded input file (on R2)
     * and the filtered output file (on local storage) after one hour.
     */
    protected function scheduleCleanup(): void
    {
        $outputFilename = Storage::disk('public')->exists("filtered_{$this->jobId}.json")
            ? "filtered_{$this->jobId}.json"
            : "filtered_{$this->jobId}.zip";

        DeleteTemporaryFiles::dispatch($this->path, $outputFilename)
            ->delay(now()->addHour());
    }
}
