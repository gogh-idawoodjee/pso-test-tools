<?php

namespace App\Support\SysCompare\Storage;

use App\Support\SysCompare\SysCompareArtifact;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where sys files and generated results live: a private local disk, namespaced
 * by user id so one user can never reach another's files. Ids are validated
 * before they touch a path, so nothing user-supplied can traverse the disk.
 */
class SysCompareStorage
{
    public const string SUMMARY_FILE = 'summary.json';

    private const string UPLOADS = 'uploads';

    private const array UPLOAD_EXTENSIONS = ['xml', 'csv'];

    private const string RUNS = 'runs';

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('sys-compare.disk'));
    }

    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-z]{26}$/', $id) === 1;
    }

    public function storeUpload(int $userId, UploadedFile $file, string $extension = 'xml'): StoredUpload
    {
        $id = self::newId();

        $this->disk()->putFileAs($this->uploadDirectory($userId), $file, $id.'.'.$extension);

        return new StoredUpload($id, $file->getClientOriginalName(), (int) $file->getSize());
    }

    /**
     * Absolute path of an upload this user owns, or null.
     */
    public function uploadPath(int $userId, string $id): ?string
    {
        if (! self::isValidId($id)) {
            return null;
        }

        foreach (self::UPLOAD_EXTENSIONS as $extension) {
            $relative = $this->uploadDirectory($userId).'/'.$id.'.'.$extension;

            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }
        }

        return null;
    }

    public function deleteUpload(int $userId, string $id): void
    {
        if (! self::isValidId($id)) {
            return;
        }

        foreach (self::UPLOAD_EXTENSIONS as $extension) {
            $this->disk()->delete($this->uploadDirectory($userId).'/'.$id.'.'.$extension);
        }
    }

    public function pendingUploadCount(int $userId): int
    {
        return count($this->disk()->files($this->uploadDirectory($userId)));
    }

    /**
     * Writes a generated file (or moves a local temp file) into a run's folder.
     */
    public function putRunFile(int $userId, string $runId, string $fileName, string $contents): void
    {
        $this->disk()->put($this->runDirectory($userId, $runId).'/'.$fileName, $contents);
    }

    /**
     * The absolute path where a run file should be written (the folder is created).
     */
    public function runFilePathForWriting(int $userId, string $runId, string $fileName): string
    {
        $directory = $this->runDirectory($userId, $runId);
        $this->disk()->makeDirectory($directory);

        return $this->disk()->path($directory.'/'.$fileName);
    }

    public function runFile(int $userId, string $runId, SysCompareArtifact|string $artifact): ?string
    {
        if (! self::isValidId($runId)) {
            return null;
        }

        $fileName = $artifact instanceof SysCompareArtifact ? $artifact->fileName() : $artifact;
        $relative = $this->runDirectory($userId, $runId).'/'.$fileName;

        return $this->disk()->exists($relative) ? $this->disk()->path($relative) : null;
    }

    public function readRunFile(int $userId, string $runId, string $fileName): ?string
    {
        $path = $this->runFile($userId, $runId, $fileName);

        return $path !== null ? (string) file_get_contents($path) : null;
    }

    public function deleteRun(int $userId, string $runId): void
    {
        if (self::isValidId($runId)) {
            $this->disk()->deleteDirectory($this->runDirectory($userId, $runId));
        }
    }

    /**
     * Removes expired results and uploads that never made it into a run.
     *
     * @return int number of items removed
     */
    public function purgeExpired(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $removed = 0;

        $runCutoff = $now->copy()->subMinutes((int) config('sys-compare.run_ttl_minutes'))->getTimestamp();
        $uploadCutoff = $now->copy()->subMinutes((int) config('sys-compare.upload_ttl_minutes'))->getTimestamp();

        foreach ($this->disk()->directories(self::RUNS) as $userDirectory) {
            foreach ($this->disk()->directories($userDirectory) as $runDirectory) {
                if ($this->lastWriteIn($runDirectory) < $runCutoff) {
                    $this->disk()->deleteDirectory($runDirectory);
                    $removed++;
                }
            }
        }

        foreach ($this->disk()->directories(self::UPLOADS) as $userDirectory) {
            foreach ($this->disk()->files($userDirectory) as $file) {
                if ($this->disk()->lastModified($file) < $uploadCutoff) {
                    $this->disk()->delete($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * When a run's newest file was written. A run folder's own timestamp is
     * ignored: it changes whenever anything inside is added or removed.
     */
    private function lastWriteIn(string $directory): int
    {
        $files = $this->disk()->files($directory);

        if ($files === []) {
            return $this->disk()->lastModified($directory);
        }

        return max(array_map(fn (string $file): int => $this->disk()->lastModified($file), $files));
    }

    private function uploadDirectory(int $userId): string
    {
        return self::UPLOADS.'/'.$userId;
    }

    private function runDirectory(int $userId, string $runId): string
    {
        return self::RUNS.'/'.$userId.'/'.$runId;
    }
}
