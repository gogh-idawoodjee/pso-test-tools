<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

class ProcessResourceFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $path, // relative to 'local' disk
        public string $regionIds
    ) {}

    public function handle(): void
    {
        try {
            Cache::put("resource-job:{$this->jobId}:status", 'processing');
            Cache::put("resource-job:{$this->jobId}:progress", 0);

            $raw = Storage::disk('local')->get($this->path);
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $data = $json['dsScheduleData'] ?? [];

            $regionIds = collect(explode(',', $this->regionIds))->map(static fn($id) => trim($id))->filter();

            // ————— RESOURCE REGION FILTERING —————
            $validResourceIds = collect($data['Resource_Region'] ?? [])
                ->filter(fn($rr) => $regionIds->contains($rr['region_id']))
                ->pluck('resource_id')
                ->unique()
                ->toArray();

            $filteredResources = collect($data['Resources'] ?? [])
                ->filter(static fn($r) => in_array($r['id'], $validResourceIds))
                ->values();

            $filteredShifts = collect($data['Shift'] ?? [])
                ->filter(static fn($shift) => in_array($shift['resource_id'], $validResourceIds))
                ->values();

            $validShiftIds = $filteredShifts->pluck('id')->toArray();

            $filteredShiftBreaks = collect($data['Shift_Break'] ?? [])
                ->filter(static fn($sb) => in_array($sb['shift_id'], $validShiftIds))
                ->values();

            $filteredResourceSkills = collect($data['Resource_Skill'] ?? [])
                ->filter(static fn($rs) => in_array($rs['resource_id'], $validResourceIds))
                ->values();

            $filteredResourceRegions = collect($data['Resource_Region'] ?? [])
                ->filter(static fn($rr) => in_array($rr['resource_id'], $validResourceIds))
                ->values();

            Cache::put("resource-job:{$this->jobId}:progress", 25);

            // ————— ACTIVITY REGION FILTERING —————
            $validLocationIds = collect($data['Location_Region'] ?? [])
                ->filter(fn($lr) => $regionIds->contains($lr['region_id']))
                ->pluck('location_id')
                ->unique()
                ->toArray();

            $filteredActivities = collect($data['Activity'] ?? [])
                ->filter(static fn($a) => in_array($a['location_id'], $validLocationIds))
                ->values();

            $validActivityIds = $filteredActivities->pluck('id')->toArray();

            $filteredActivitySLAs = collect($data['Activity_SLA'] ?? [])
                ->filter(static fn($sla) => in_array($sla['activity_id'], $validActivityIds))
                ->values();

            $filteredActivityStatuses = collect($data['Activity_Status'] ?? [])
                ->filter(static fn($status) => in_array($status['activity_id'], $validActivityIds))
                ->values();

            Cache::put("resource-job:{$this->jobId}:progress", 60);

            // ————— DRY RUN? —————
            if ($this->dryRun ?? false) {
                Cache::put("resource-job:{$this->jobId}:status", 'complete');
                Cache::put("resource-job:{$this->jobId}:progress", 100);
                Cache::put("resource-job:{$this->jobId}:preview", [
                    'resources' => $filteredResources->count(),
                    'shifts' => $filteredShifts->count(),
                    'shift_breaks' => $filteredShiftBreaks->count(),
                    'resource_skills' => $filteredResourceSkills->count(),
                    'activities' => $filteredActivities->count(),
                    'activity_slas' => $filteredActivitySLAs->count(),
                    'activity_statuses' => $filteredActivityStatuses->count(),
                ]);
                return;
            }

            // ————— COMBINE FINAL FILTERED STRUCTURE —————
            $filtered = $data;
            $filtered['Resources'] = $filteredResources->toArray();
            $filtered['Shift'] = $filteredShifts->toArray();
            $filtered['Shift_Break'] = $filteredShiftBreaks->toArray();
            $filtered['Resource_Skill'] = $filteredResourceSkills->toArray();
            $filtered['Resource_Region'] = $filteredResourceRegions->toArray();

            $filtered['Activity'] = $filteredActivities->toArray();
            $filtered['Activity_SLA'] = $filteredActivitySLAs->toArray();
            $filtered['Activity_Status'] = $filteredActivityStatuses->toArray();

            $jsonOut = json_encode(['dsScheduleData' => $filtered], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $filename = "filtered_{$this->jobId}.json";

            Storage::disk('public')->put($filename, $jsonOut);

            $downloadUrl = route('download.filtered', compact('filename'));

            Cache::put("resource-job:{$this->jobId}:progress", 90);

            // ————— ZIP if > 8MB —————
            $filePath = Storage::disk('public')->path($filename);
            if (filesize($filePath) > 8 * 1024 * 1024) {
                $zipName = Str::replaceLast('.json', '.zip', $filename);
                $zipPath = Storage::disk('public')->path($zipName);

                $zip = new ZipArchive;
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    $zip->addFile($filePath, $filename);
                    $zip->close();
                }

                Storage::disk('public')->delete($filename);
                $downloadUrl = route('download.filtered', ['filename' => $zipName]);
            }

            Cache::put("resource-job:{$this->jobId}:download", $downloadUrl);
            Cache::put("resource-job:{$this->jobId}:status", 'complete');
            Cache::put("resource-job:{$this->jobId}:progress", 100);

        } catch (Throwable $e) {
            Log::error("Job [{$this->jobId}] failed: " . $e->getMessage());
            Cache::put("resource-job:{$this->jobId}:status", 'failed');
        }
    }
}
