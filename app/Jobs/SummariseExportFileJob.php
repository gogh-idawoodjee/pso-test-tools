<?php

namespace App\Jobs;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;
use App\Support\Exports\MessageTextsImporter;
use Closure;
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

    /** A day of audits is 25,000 messages and a few minutes of reading; under the queue's 960s retry window. */
    public int $timeout = 900;

    public int $tries = 1;

    private const PROGRESS_EVERY = 250;

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
            $summary = $summariser->summarise($file, $path, $this->timeZoneFor($file), $this->progressWriter($file));

            if ($file->kind === ExportKind::MessageTexts) {
                app(MessageTextsImporter::class)->import($path);
            }

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

    /**
     * Keeps "Read N of M messages" on the row for the drop zone to show: the first message
     * (the read has begun), then every few hundred, so a long read is visibly alive.
     *
     * @return Closure(int, int): void
     */
    private function progressWriter(ExportFile $file): Closure
    {
        return static function (int $read, int $of) use ($file): void {
            if ($read !== 1 && $read % self::PROGRESS_EVERY !== 0 && $read !== $of) {
                return;
            }
            $file->forceFill(['summary' => ['progress' => 'Read '.number_format($read).' of '.number_format($of).' messages']])->save();
        };
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
                // IFS event log times are written in the IFS zone, which may differ from the dataset zone.
                return $file->kind === ExportKind::EventLog ? $dataset->ifsTimeZone() : $dataset->timeZone();
            }
        }

        return (string) config('app.timezone', 'UTC');
    }
}
