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

        $this->withSharedUmask(fn () => $this->disk()->putFileAs($this->uploadDirectory($userId), $file, $id.'.'.$extension));
        $this->alignGroup($this->disk()->path($this->uploadDirectory($userId).'/'.$id.'.'.$extension));

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

    public function uploadSize(int $userId, string $id): int
    {
        $path = $this->uploadPath($userId, $id);

        return $path !== null ? (int) filesize($path) : 0;
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
        $relative = $this->runDirectory($userId, $runId).'/'.$fileName;

        $this->withSharedUmask(fn () => $this->disk()->put($relative, $contents));
        $this->alignGroup($this->disk()->path($relative));
    }

    /**
     * The absolute path where a run file should be written (the folder is created).
     */
    public function runFilePathForWriting(int $userId, string $runId, string $fileName): string
    {
        $directory = $this->runDirectory($userId, $runId);

        // Only create it when it is missing: makeDirectory() on an existing folder re-applies the
        // permissions, and that chmod clears the setgid bit, so files written afterwards would get
        // the writer's own group instead of the shared one.
        if (! $this->disk()->exists($directory)) {
            $this->withSharedUmask(fn () => $this->disk()->makeDirectory($directory));
        }

        $this->alignGroup($this->disk()->path($directory));

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

    /**
     * Makes a file written outside the disk API (a zip or workbook written straight to a path)
     * readable and writable by the group, as the disk does for its own files.
     */
    public function shareWithGroup(string $path): void
    {
        $this->alignGroup($path);
    }

    /**
     * Gives a file or folder, and every folder above it up to the disk root, the same group as
     * the disk root, group read/write (folders also keep the setgid bit).
     *
     * The web process and the queue worker are different users that share a group. Relying on the
     * setgid bit alone is fragile: anything that re-applies a folder's permissions clears it, and
     * files created afterwards silently get the creating user's own group, which the other user
     * cannot read. So the group is set explicitly. A user may change a file's group to any group
     * they belong to, so this works without root.
     */
    public function alignGroup(string $path): void
    {
        $root = rtrim($this->disk()->path(''), '/');
        $group = @filegroup($root);

        if ($group === false || ! str_starts_with($path, $root.'/')) {
            return;
        }

        for ($current = $path; str_starts_with($current, $root.'/'); $current = dirname($current)) {
            clearstatcache(true, $current);

            if (! file_exists($current)) {
                continue;
            }

            if (@filegroup($current) !== $group) {
                @chgrp($current, $group);
            }

            $wanted = is_dir($current) ? 02770 : 0660;

            if ((@fileperms($current) & 07777) !== $wanted) {
                @chmod($current, $wanted);
            }
        }
    }

    /**
     * The web process and the queue worker can be different users that share a group, so
     * everything written here must be group-writable. A normal umask (022) would strip that
     * from new folders, and the worker could then read an upload but not delete it.
     */
    private function withSharedUmask(callable $callback): mixed
    {
        $previous = umask(0007);

        try {
            return $callback();
        } finally {
            umask($previous);
        }
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
