<?php

namespace App\Support\TrimExport;

use App\Enums\ExportFileStatus;
use App\Jobs\SummariseExportFileJob;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolContext;
use Throwable;

/**
 * Files a trimmed export where its input came from (the dataset, or the user for Unfiled), of the input's kind,
 * uploaded by the user who started the run, then queues its summary so it gets table counts and can feed
 * another tool. It expires like any upload. A failed store leaves no row behind.
 */
class TrimmedExportStore
{
    public function __construct(private ExportStorage $storage) {}

    public function store(ToolContext $context, ExportFile $input, string $localPath, string $contentType): ExportFile
    {
        $file = ExportFile::create([
            'scope_type' => $context->scope->getMorphClass(),
            'scope_id' => (string) $context->scope->getKey(),
            'kind' => $input->kind,
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

            throw $exception;
        }

        $file->forceFill(['status' => ExportFileStatus::Summarising])->save();
        SummariseExportFileJob::dispatch($file->id);

        return $file;
    }

    /** "north-load.xml" gives "north-load.trimmed.xml"; the extension is the stored format's, so a zip named .xml becomes .trimmed.zip. */
    public static function trimmedName(string $originalName, string $extension): string
    {
        $base = pathinfo(basename($originalName), PATHINFO_FILENAME);

        return ($base === '' ? 'export' : $base).'.trimmed.'.$extension;
    }
}
