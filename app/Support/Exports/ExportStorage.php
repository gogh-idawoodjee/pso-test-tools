<?php

namespace App\Support\Exports;

use App\Models\ExportFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where raw exports live (R2) and how jobs get at them (streamed to a scratch
 * folder). Object keys are built from uuids only, so nothing user-supplied can
 * reach a path.
 */
class ExportStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('exports.disk', 'r2'));
    }

    public function objectKey(ExportFile $file): string
    {
        $this->assertUuid((string) $file->id);
        $this->assertUuid((string) $file->scope_id);
        $scope = preg_replace('/[^a-z]/', '', strtolower(class_basename((string) $file->scope_type))) ?: 'scope';
        $extension = in_array($file->content_type, ['xml', 'json', 'zip', 'xlsx', 'csv'], true) ? $file->content_type : 'bin';

        return "exports/{$scope}/{$file->scope_id}/{$file->id}.{$extension}";
    }

    /** @return array{url: string, headers: array<string, string>} */
    public function presignedPutUrl(ExportFile $file): array
    {
        return $this->disk()->temporaryUploadUrl(
            $this->objectKey($file),
            now()->addMinutes((int) config('exports.slot_minutes', 15)),
            ['ContentType' => self::mimeFor($file->content_type)],
        );
    }

    public static function mimeFor(string $contentType): string
    {
        return match ($contentType) {
            'json' => 'application/json',
            'zip' => 'application/zip',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => 'application/xml',
        };
    }

    public function exists(ExportFile $file): bool
    {
        return $this->disk()->exists($this->objectKey($file));
    }

    public function size(ExportFile $file): ?int
    {
        return $this->exists($file) ? (int) $this->disk()->size($this->objectKey($file)) : null;
    }

    public function readToScratch(ExportFile $file, string $scratchDirectory): string
    {
        $target = rtrim($scratchDirectory, '/').'/'.$file->id.'.'.$file->content_type;
        $source = $this->disk()->readStream($this->objectKey($file));
        if ($source === null) {
            throw new RuntimeException('The export is no longer in storage.');
        }
        $destination = fopen($target, 'wb');
        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($destination);
            fclose($source);
        }

        return $target;
    }

    public function delete(ExportFile $file): void
    {
        $this->disk()->delete($this->objectKey($file));
    }

    public function scratchDirectoryFor(string $id): string
    {
        $this->assertUuid($id);
        $directory = rtrim((string) config('exports.scratch_path'), '/').'/'.$id;
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        return $directory;
    }

    public function removeScratch(string $directory): void
    {
        $root = rtrim((string) config('exports.scratch_path'), '/');
        if (! str_starts_with($directory, $root.'/') || ! is_dir($directory)) {
            return;
        }
        File::deleteDirectory($directory);
    }

    private function assertUuid(string $value): void
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('Export ids must be uuids.');
        }
    }
}
