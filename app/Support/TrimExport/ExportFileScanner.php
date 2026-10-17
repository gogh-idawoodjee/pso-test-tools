<?php

namespace App\Support\TrimExport;

use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * What the Trim export wizard shows about one export: its tables (from the stored summary, else a streamed
 * scan) and its filter choices for the Rows step. A scan is cached per export file until the file expires,
 * encrypted, because it can hold customer ids and names.
 */
class ExportFileScanner
{
    public function __construct(
        private ExportStorage $storage,
        private ExportSource $source,
        private TableCounter $counter,
    ) {}

    /** @return array<string, int> table => rows */
    public function tableCounts(ExportFile $file): array
    {
        $stored = $file->summary['table_counts'] ?? null;
        if (is_array($stored) && $stored !== []) {
            return array_map('intval', $stored);
        }

        return $this->remember($file, 'tables', fn (string $path, string $format): array => $this->counter->count($path, $format));
    }

    private function cacheKey(ExportFile $file, string $what): string
    {
        return "trim-export:{$what}:{$file->id}";
    }

    private function remember(ExportFile $file, string $what, Closure $scan): mixed
    {
        $key = $this->cacheKey($file, $what);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decrypt($cached);
            } catch (DecryptException) {
                Cache::forget($key);
            }
        }

        $value = $this->withLocalCopy($file, $scan);
        Cache::put($key, Crypt::encrypt($value), $file->expires_at ?? now()->addHours((int) config('exports.ttl_hours', 12)));

        return $value;
    }

    /** Streams the stored export to its own scratch folder, opens it (a zip's member) and scans it; the folder never outlives the call. */
    private function withLocalCopy(ExportFile $file, Closure $scan): mixed
    {
        $scratch = $this->storage->scratchDirectoryFor((string) Str::uuid());

        try {
            $source = $this->source->open($this->storage->readToScratch($file, $scratch));

            return $scan($source->path, $source->format);
        } finally {
            $this->storage->removeScratch($scratch);
        }
    }
}
