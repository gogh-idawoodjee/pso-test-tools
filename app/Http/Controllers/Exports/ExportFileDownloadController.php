<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a stored LOAD, plan, EOD, audits or event log export back to the user who owns it (the ownership scope finds nothing for anyone else). */
class ExportFileDownloadController extends Controller
{
    public function __invoke(string $exportFile, ExportStorage $storage): StreamedResponse
    {
        $file = ExportFile::query()->findOrFail($exportFile);
        // A SYS export holds live credentials (a TomTom key in plain text): it is never served back.
        abort_if($file->kind === ExportKind::Sys || $file->isExpired()
            || ! in_array($file->status, [ExportFileStatus::Ready, ExportFileStatus::Summarising], true), 404);

        $stream = $storage->disk()->readStream($storage->objectKey($file));
        abort_if($stream === null, 404);

        return response()->streamDownload(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, basename((string) $file->original_filename), ['Content-Type' => ExportStorage::mimeFor((string) $file->content_type)]);
    }
}
