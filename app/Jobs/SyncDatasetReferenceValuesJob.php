<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Support\ReferenceValues\ReferenceValuesClient;
use App\Support\ReferenceValues\ReferenceValuesFetchException;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDatasetReferenceValuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    public function __construct(public string $datasetId) {}

    public static function lockKey(string $datasetId): string
    {
        return "dataset-reference-sync:{$datasetId}";
    }

    public function handle(ReferenceValuesClient $client, ReferenceValuesUpserter $upserter): void
    {
        // No user is logged in on the queue, and Environment carries the
        // UserOwnedModel global scope (user_id = auth()->id(), i.e. null here),
        // so the environment must be loaded without that scope.
        $dataset = Dataset::query()->find($this->datasetId);
        $environment = $dataset?->environment()->withoutGlobalScopes()->first();
        if ($dataset === null || $environment === null) {
            Cache::forget(self::lockKey($this->datasetId));

            return;
        }

        try {
            $now = CarbonImmutable::now();
            $tables = $client->fetch($environment, $dataset->name);
            $counts = $upserter->apply($dataset, $tables, $now);

            $dataset->forceFill([
                'last_synced_at' => $now,
                'last_sync_summary' => ['status' => 'succeeded', 'finished_at' => $now->toIso8601String(), 'error' => null, 'counts' => $counts],
            ])->save();
        } catch (ReferenceValuesFetchException $exception) {
            $this->recordFailure($dataset, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Reference values sync failed', ['dataset_id' => $dataset->id, 'message' => $exception->getMessage()]);
            $this->recordFailure($dataset, 'The sync failed unexpectedly: '.$exception->getMessage());
        } finally {
            Cache::forget(self::lockKey($this->datasetId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(self::lockKey($this->datasetId));
    }

    private function recordFailure(Dataset $dataset, string $message): void
    {
        $dataset->forceFill([
            'last_sync_summary' => [
                'status' => 'failed',
                'finished_at' => CarbonImmutable::now()->toIso8601String(),
                'error' => $message,
                'counts' => data_get($dataset->last_sync_summary, 'counts'),
            ],
        ])->save();
    }
}
