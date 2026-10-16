<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ExportFileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportSlotRequest;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Http\JsonResponse;

/**
 * Hands the browser a presigned PUT url so the file goes straight to R2, filed on a dataset, an environment or the user (Unfiled). No
 * route in this app ever receives a file body.
 */
class ExportSlotController extends Controller
{
    public function __invoke(ExportSlotRequest $request, ExportStorage $storage): JsonResponse
    {
        $scopeId = (string) $request->validated('scope_id');
        $scope = match ($request->validated('scope_type')) {
            'dataset' => Dataset::query()->ownedByCurrentUser()->findOrFail($scopeId),
            'environment' => Environment::query()->findOrFail($scopeId),
            'unfiled' => $request->user(),
        };

        $kind = $request->exportKind();
        $fileName = basename((string) $request->validated('file_name'));

        $file = ExportFile::create([
            'scope_type' => $scope->getMorphClass(),
            'scope_id' => $scope->getKey(),
            'kind' => $kind,
            'uploaded_by_user_id' => $request->user()->getAuthIdentifier(),
            'original_filename' => $fileName,
            'stored_path' => '',
            'file_size_bytes' => (int) $request->validated('file_size'),
            'content_type' => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'status' => ExportFileStatus::Uploading,
        ]);
        $file->update(['stored_path' => $storage->objectKey($file)]);

        return response()->json([
            'id' => $file->id,
            'upload' => $storage->presignedPutUrl($file),
            'expires_at' => now()->addMinutes((int) config('exports.slot_minutes', 15))->toIso8601String(),
        ], 201);
    }
}
