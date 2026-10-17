<?php

namespace App\Support\TrimExport;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Jobs\SummariseExportFileJob;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolContext;
use Throwable;

/**
 * Files a trimmed export where its input came from (the dataset, or the user for Unfiled), of the kind its content
 * has (see kindFor()), uploaded by the user who started the run, then queues its summary so it gets table counts and can feed
 * another tool. It expires like any upload. A failed store leaves no row behind.
 */
class TrimmedExportStore
{
    public function __construct(private ExportStorage $storage) {}

    public function store(ToolContext $context, ExportFile $input, string $localPath, string $contentType, ?ExportKind $kind = null): ExportFile
    {
        $file = ExportFile::create([
            'scope_type' => $context->scope->getMorphClass(),
            'scope_id' => (string) $context->scope->getKey(),
            'kind' => $kind ?? $input->kind,
            'uploaded_by_user_id' => $context->run->started_by_user_id,
            'original_filename' => self::trimmedName((string) $input->original_filename, $contentType),
            'stored_path' => '',
            'file_size_bytes' => (int) filesize($localPath),
            'content_type' => $contentType,
            'status' => ExportFileStatus::Uploading,
        ]);

        try {
            $file->update(['stored_path' => $this->storage->objectKey($file)]);
            $this->storage->putFromLocal($file, $localPath);
        } catch (Throwable $exception) {
            $file->delete();
            try {
                $this->storage->delete($file);
            } catch (Throwable) {
                // Best effort: the failed upload is the error to report.
            }

            throw $exception;
        }

        $file->forceFill(['status' => ExportFileStatus::Summarising])->save();
        SummariseExportFileJob::dispatch($file->id);

        return $file;
    }

    /**
     * The new export's kind follows its content: a plan or End of day export with no Plan rows left is a LOAD
     * (the summariser refuses a plan export with no Plan block); otherwise it keeps the input's kind. Decided from
     * the trim's own per-table kept counts, never by reading the file again.
     */
    public static function kindFor(ExportKind $inputKind, TrimReport $report): ExportKind
    {
        if ($inputKind !== ExportKind::Plan && $inputKind !== ExportKind::Eod) {
            return $inputKind;
        }

        $planKept = in_array('Plan', $report->keptTables(), true) && ($report->tables['Plan']['kept'] ?? 0) > 0;

        return $planKept ? $inputKind : ExportKind::Load;
    }

    /** "north-load.xml" gives "north-load.trimmed.xml"; the extension is the stored format's, so a zip named .xml becomes .trimmed.zip. */
    public static function trimmedName(string $originalName, string $extension): string
    {
        $base = pathinfo(basename($originalName), PATHINFO_FILENAME);

        return ($base === '' ? 'export' : $base).'.trimmed.'.$extension;
    }
}
