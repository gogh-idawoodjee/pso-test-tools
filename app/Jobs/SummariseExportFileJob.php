<?php

namespace App\Jobs;

use App\Enums\ExportFileStatus;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Streams an uploaded export to scratch, summarises it and marks it ready (or
 * failed, with a plain message). The scratch folder never outlives the job.
 */
class SummariseExportFileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $exportFileId) {}

    public function handle(ExportStorage $storage, ExportSummariser $summariser): void
    {
        $file = ExportFile::withoutGlobalScopes()->find($this->exportFileId);
        if ($file === null || $file->status !== ExportFileStatus::Summarising) {
            return;
        }

        $scratch = $storage->scratchDirectoryFor($file->id);

        try {
            $path = $storage->readToScratch($file, $scratch);
            $summary = $summariser->summarise($file, $path, $this->timeZoneFor($file));

            $file->forceFill([
                'summary' => $summary->toArray(),
                'status' => ExportFileStatus::Ready,
                'error_message' => null,
            ])->save();
        } catch (InvalidExportException|RuntimeException $exception) {
            $this->markFailed($file, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Export summary failed', ['export_file_id' => $file->id, 'error' => $exception->getMessage()]);
            $this->markFailed($file, 'The export could not be read: '.$exception->getMessage());
        } finally {
            $storage->removeScratch($scratch);
        }
    }

    public function failed(Throwable $exception): void
    {
        $file = ExportFile::withoutGlobalScopes()->find($this->exportFileId);
        if ($file !== null && $file->status === ExportFileStatus::Summarising) {
            $this->markFailed($file, 'The export could not be read: '.$exception->getMessage());
        }
    }

    private function markFailed(ExportFile $file, string $message): void
    {
        $file->forceFill(['status' => ExportFileStatus::Failed, 'error_message' => $message])->save();
    }

    private function timeZoneFor(ExportFile $file): string
    {
        if ($file->scope_type === (new Dataset)->getMorphClass()) {
            $dataset = Dataset::query()->withoutGlobalScopes()->find($file->scope_id);
            if ($dataset !== null) {
                return $dataset->timeZone();
            }
        }

        return (string) config('app.timezone', 'UTC');
    }
}
