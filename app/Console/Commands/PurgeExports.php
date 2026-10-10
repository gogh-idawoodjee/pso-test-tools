<?php

namespace App\Console\Commands;

use App\Enums\ExportFileStatus;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurgeExports extends Command
{
    protected $signature = 'exports:purge';

    protected $description = 'Delete raw exports from R2 once they expire and sweep scratch folders a dead job left behind';

    public function handle(ExportStorage $storage): int
    {
        $purged = 0;

        ExportFile::withoutGlobalScopes()
            ->where('expires_at', '<', now())
            ->where('status', '!=', ExportFileStatus::Purged)
            ->chunkById(100, function ($files) use ($storage, &$purged): void {
                foreach ($files as $file) {
                    try {
                        $storage->delete($file);
                    } catch (Throwable $exception) {
                        Log::warning('Could not delete an expired export', ['export_file_id' => $file->id, 'error' => $exception->getMessage()]);

                        continue;
                    }

                    $file->forceFill(['status' => ExportFileStatus::Purged])->saveQuietly();
                    $purged++;
                }
            });

        $swept = $this->sweepScratch($storage);

        $this->info("Purged {$purged} export(s), swept {$swept} scratch folder(s).");

        return self::SUCCESS;
    }

    private function sweepScratch(ExportStorage $storage): int
    {
        $root = rtrim((string) config('exports.scratch_path'), '/');
        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = now()->subHours((int) config('exports.scratch_sweep_hours', 2))->getTimestamp();
        $swept = 0;

        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            $directory = $root.'/'.$entry;
            if (is_dir($directory) && filemtime($directory) < $cutoff) {
                $storage->removeScratch($directory);
                $swept++;
            }
        }

        return $swept;
    }
}
