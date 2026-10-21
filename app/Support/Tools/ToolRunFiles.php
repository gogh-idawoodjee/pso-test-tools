<?php

namespace App\Support\Tools;

use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use RuntimeException;

/** Extra files a tool run keeps beside its result (too big or too raw for the result column), on the exports disk under tool-runs/{run id}/. */
class ToolRunFiles
{
    public static function put(ToolRun $run, string $name, string $localPath): void
    {
        $stream = @fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('A file from the run could not be read to keep it.');
        }

        try {
            if (app(ExportStorage::class)->disk()->put(self::key($run, $name), $stream) === false) {
                throw new RuntimeException('The inputs CSV could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public static function get(ToolRun $run, string $name): ?string
    {
        $disk = app(ExportStorage::class)->disk();
        $key = self::key($run, $name);

        return $disk->exists($key) ? $disk->get($key) : null;
    }

    public static function deleteAll(ToolRun $run): void
    {
        app(ExportStorage::class)->disk()->deleteDirectory("tool-runs/{$run->id}");
    }

    private static function key(ToolRun $run, string $name): string
    {
        return "tool-runs/{$run->id}/".basename($name);
    }
}
