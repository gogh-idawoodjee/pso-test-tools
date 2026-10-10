<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ExportFileStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SummariseExportFileJob;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Http\JsonResponse;

/**
 * Called by the browser after its PUT to R2. Checks the object really arrived
 * with the declared size before anything is queued.
 */
class ExportCompleteController extends Controller
{
    public function __invoke(ExportFile $exportFile, ExportStorage $storage): JsonResponse
    {
        if ($exportFile->status !== ExportFileStatus::Uploading) {
            return response()->json(['message' => 'This upload has already been completed.'], 409);
        }

        $size = $storage->size($exportFile);

        if ($size === null) {
            return $this->failed($exportFile, 'The file never arrived in storage. Try the upload again.');
        }

        if ($size !== $exportFile->file_size_bytes) {
            return $this->failed($exportFile, "The uploaded size does not match the declared size ({$size} bytes against {$exportFile->file_size_bytes}). Try the upload again.");
        }

        $exportFile->update(['status' => ExportFileStatus::Summarising, 'error_message' => null]);
        SummariseExportFileJob::dispatch($exportFile->id);

        return response()->json(['id' => $exportFile->id, 'status' => ExportFileStatus::Summarising->value], 202);
    }

    private function failed(ExportFile $exportFile, string $message): JsonResponse
    {
        $exportFile->update(['status' => ExportFileStatus::Failed, 'error_message' => $message]);

        return response()->json(['message' => $message], 422);
    }
}
