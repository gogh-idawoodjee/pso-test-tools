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

class ProcessResourceFile extends HasScopedCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes

    public int $tries = 1; // Don't retry — large files shouldn't be re-queued

    protected const int|float LARGE_FILE_THRESHOLD = 8 * 1024 * 1024; // 8MB

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
                'Shifts' => count($data['Shifts'] ?? []),
                'Shift_Breaks' => count($data['Shift_Breaks'] ?? []),
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

    /** @throws JsonException */
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
        $this->updateStatus('complete');
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

        return config('app.url').'/download/'.urlencode($filename);
    }

    protected function scheduleCleanup(): void
    {
        $outputFilename = Storage::disk('public')->exists("filtered_{$this->jobId}.json")
            ? "filtered_{$this->jobId}.json"
            : "filtered_{$this->jobId}.zip";

        DeleteTemporaryFiles::dispatch($this->path, $outputFilename)
            ->delay(now()->addHour());
    }
}
