<?php

namespace App\Http\Controllers\SysCompare;

use App\Http\Controllers\Controller;
use App\Support\SysCompare\Exceptions\InvalidSysFile;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysFileReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Receives one sys file (or a ParamDefinitions.csv) at a time and stores it on
 * the private local disk. This deliberately does not use Livewire's file upload,
 * whose temporary disk is the shared R2 bucket: sys files hold customer data.
 */
class SysCompareUploadController extends Controller
{
    public function __invoke(Request $request, SysCompareStorage $storage, SysFileReader $reader): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $kind = $request->input('kind') === 'definitions' ? 'definitions' : 'sys';
        $file = $request->file('file');
        $maxMegabytes = round((int) config('sys-compare.max_file_kilobytes') / 1024);

        if (! $file instanceof UploadedFile) {
            return $this->rejected('No file was received.');
        }

        if (! $file->isValid()) {
            return $this->rejected("{$file->getClientOriginalName()}: the file could not be uploaded. It may be larger than the server allows ({$maxMegabytes} MB is the limit of this tool).");
        }

        if ($file->getSize() > (int) config('sys-compare.max_file_kilobytes') * 1024) {
            return $this->rejected("{$file->getClientOriginalName()}: the file is larger than {$maxMegabytes} MB.");
        }

        $extension = $kind === 'definitions' ? 'csv' : 'xml';

        if (strtolower($file->getClientOriginalExtension()) !== $extension) {
            return $this->rejected("{$file->getClientOriginalName()}: please choose a .{$extension} file.");
        }

        if ($storage->pendingUploadCount($userId) >= (int) config('sys-compare.max_pending_uploads')) {
            return $this->rejected('Too many files are waiting to be compared. Remove some, or wait a few minutes, and try again.', 429);
        }

        $stored = $storage->storeUpload($userId, $file, $extension);

        if ($kind === 'sys') {
            try {
                $reader->assertSysFile((string) $storage->uploadPath($userId, $stored->id), $stored->originalName);
            } catch (InvalidSysFile $exception) {
                $storage->deleteUpload($userId, $stored->id);

                return $this->rejected($exception->getMessage());
            }
        }

        return response()->json([
            'id' => $stored->id,
            'name' => $stored->originalName,
            'size' => $stored->sizeBytes,
        ], 201);
    }

    private function rejected(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
