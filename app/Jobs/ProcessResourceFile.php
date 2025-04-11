<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessResourceFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $path, // relative to 'local' disk
        public string $regionIds
    ) {}

    public function handle()
    {
        try {
            Log::info("Processing job [{$this->jobId}] started");

            cache()->put("resource-job:{$this->jobId}:status", 'processing');
            cache()->put("resource-job:{$this->jobId}:progress", 0);

            $regionList = collect(explode(',', $this->regionIds))
                ->map(fn($id) => trim($id))
                ->filter();

            $json = json_decode(Storage::disk('local')->get($this->path), true);

            if (!$json) {
                Log::error("Invalid JSON or file not found: {$this->path}");
                cache()->put("resource-job:{$this->jobId}:status", 'failed');
                return;
            }

            $scheduleData = $json['dsScheduleData'] ?? [];

            $filteredResources = collect($scheduleData['Resource'] ?? [])
                ->filter(fn ($r) => $regionList->contains($r['resource_region_id']))
                ->values();

            $resourceIds = $filteredResources->pluck('id');

            $progressSteps = ['Shift', 'Shift_Break', 'Resource_Skill'];
            $filteredData = [
                'dsScheduleData' => ['Resource' => $filteredResources->all()],
            ];

            foreach ($progressSteps as $index => $key) {
                $filteredData['dsScheduleData'][$key] = collect($scheduleData[$key] ?? [])
                    ->filter(fn($item) => $resourceIds->contains($item['resource_id'] ?? null))
                    ->values()
                    ->all();

                $percent = (int)((($index + 1) / count($progressSteps)) * 100);
                cache()->put("resource-job:{$this->jobId}:progress", $percent);
                Log::info("Job [{$this->jobId}] progress updated: {$percent}%");

                usleep(300000); // 300ms delay
            }

            $filteredData['dsScheduleData'] += collect($scheduleData)
                ->except(['Resource', 'Shift', 'Shift_Break', 'Resource_Skill'])
                ->toArray();

            $filename = 'filtered_' . Str::random(8) . '.json';
            Storage::disk('public')->put($filename, json_encode($filteredData, JSON_PRETTY_PRINT));

            cache()->put("resource-job:{$this->jobId}:status", 'complete');
            cache()->put("resource-job:{$this->jobId}:file", $filename);

            Log::info("Job [{$this->jobId}] completed. File: {$filename}");

        } catch (Throwable $e) {
            Log::error("Job [{$this->jobId}] failed: " . $e->getMessage());
            cache()->put("resource-job:{$this->jobId}:status", 'failed');
        }
    }
}
