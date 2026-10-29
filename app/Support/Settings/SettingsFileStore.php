<?php

namespace App\Support\Settings;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where the settings page keeps its uploaded files (on the exports disk). Only
 * the fixed definitions key and uuid-named message texts keys are accepted, so
 * nothing user-supplied can reach a path.
 */
class SettingsFileStore
{
    public const PARAM_DEFINITIONS = 'settings/param-definitions.csv';

    /** The release-notes watchlist built from an uploaded IFS Public Release Notes PDF (the Load audit's --watchlist). */
    public const RELEASE_NOTES = 'settings/release-notes-watchlist.md';

    /** The parameter catalog built from an uploaded IFS docs Parameters page (Load audit --params, Sys compare defaults). */
    public const PARAM_REFERENCE = 'settings/pso-parameters-reference.csv';

    public const MESSAGE_TEXTS_PREFIX = 'settings/message-texts/';

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('exports.disk', 'r2'));
    }

    public static function messageTextsKey(string $extension): string
    {
        return self::MESSAGE_TEXTS_PREFIX.Str::uuid().'.'.$extension;
    }

    public function putFromLocal(string $key, string $localPath): void
    {
        $this->assertKey($key);

        $stream = @fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The file to store could not be opened.');
        }

        try {
            if ($this->disk()->writeStream($key, $stream) === false) {
                throw new RuntimeException('The file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function readToScratch(string $key, string $scratchDirectory): string
    {
        $this->assertKey($key);

        $target = rtrim($scratchDirectory, '/').'/'.basename($key);
        $source = $this->disk()->readStream($key);
        if ($source === null) {
            throw new RuntimeException('The file is no longer in storage.');
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

    public function delete(string $key): void
    {
        $this->assertKey($key);

        $this->disk()->delete($key);
    }

    public function exists(string $key): bool
    {
        $this->assertKey($key);

        return $this->disk()->exists($key);
    }

    private function assertKey(string $key): void
    {
        if (in_array($key, [self::PARAM_DEFINITIONS, self::RELEASE_NOTES, self::PARAM_REFERENCE], true)) {
            return;
        }

        if (preg_match('#^settings/message-texts/[0-9a-f-]{36}\.(xml|zip)$#', $key) !== 1) {
            throw new InvalidArgumentException('That is not a settings file key.');
        }
    }
}
