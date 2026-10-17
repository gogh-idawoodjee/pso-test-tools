<?php

namespace App\Support\TrimExport;

use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
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
        private XmlTableReader $xmlReader,
        private JsonTableReader $jsonReader,
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

    public function choices(ExportFile $file): FilterChoices
    {
        return $this->remember($file, 'choices', fn (string $path, string $format): FilterChoices => $this->scanChoices($path, $format));
    }

    /** The choices a scan already cached for this file; null before the first scan. */
    public function cachedChoices(ExportFile $file): ?FilterChoices
    {
        $cached = Cache::get($this->cacheKey($file, 'choices'));
        if (! is_string($cached)) {
            return null;
        }

        try {
            $choices = Crypt::decrypt($cached);
        } catch (DecryptException) {
            return null;
        }

        return $choices instanceof FilterChoices ? $choices : null;
    }

    /** One streamed pass over the tables the filters offer, as Filter Load File listed them. */
    private function scanChoices(string $path, string $format): FilterChoices
    {
        $reader = $format === 'json' ? $this->jsonReader : $this->xmlReader;
        $fields = [
            'Region' => ['id', 'description'],
            'Resources' => ['id', 'first_name', 'surname'],
            'Activity_Type' => ['id', 'description'],
            'Activity' => ['id', 'activity_type_id'],
            'Resource_Region' => ['resource_id', 'region_id'],
        ];
        $regions = [];
        $resources = [];
        $typeNames = [];
        $activities = [];
        $activityTypeOf = [];
        $typeCounts = [];
        $resourceRegions = [];

        foreach ($reader->segments($path) as $segment) {
            if ($segment->type !== Segment::ROW || ! isset($fields[$segment->table])) {
                continue;
            }

            $row = $reader->fieldsOf($segment->body, $fields[$segment->table]);
            $id = $row['id'] ?? null;

            switch ($segment->table) {
                case 'Region':
                    if ($id !== null) {
                        $regions[$id] = isset($row['description']) ? "{$id} - {$row['description']}" : $id;
                    }
                    break;
                case 'Resources':
                    if ($id !== null) {
                        $name = trim(($row['first_name'] ?? '').' '.($row['surname'] ?? ''));
                        $resources[$id] = $name === '' ? $id : "{$id} - {$name}";
                    }
                    break;
                case 'Activity_Type':
                    if ($id !== null) {
                        $typeNames[$id] = isset($row['description']) ? "{$id} - {$row['description']}" : $id;
                    }
                    break;
                case 'Activity':
                    if ($id !== null) {
                        // A blank type is the Default type (PSO's rule): offer it under a key the plan maps back to blank.
                        $type = ($row['activity_type_id'] ?? '') === '' ? TrimPlan::DEFAULT_ACTIVITY_TYPE : $row['activity_type_id'];
                        $activities[$id] = $type === TrimPlan::DEFAULT_ACTIVITY_TYPE ? "{$id} - Default" : "{$id} - {$type}";
                        $activityTypeOf[$id] = $type;
                        $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
                    }
                    break;
                default:
                    if (isset($row['resource_id'], $row['region_id'])) {
                        $resourceRegions[$row['resource_id']][] = $row['region_id'];
                    }
            }
        }

        // Only types some activity has, with how many; a type the file uses but does not define still shows.
        $activityTypes = [];
        foreach ($typeCounts as $type => $count) {
            $name = $type === TrimPlan::DEFAULT_ACTIVITY_TYPE ? 'Default' : ($typeNames[$type] ?? (string) $type);
            $activityTypes[(string) $type] = $name.' ('.number_format($count).')';
        }
        ksort($activityTypes, SORT_NATURAL);
        if (isset($activityTypes[TrimPlan::DEFAULT_ACTIVITY_TYPE])) {
            $activityTypes = [TrimPlan::DEFAULT_ACTIVITY_TYPE => $activityTypes[TrimPlan::DEFAULT_ACTIVITY_TYPE]] + $activityTypes;
        }

        return new FilterChoices($regions, $resources, $activityTypes, $activities, $resourceRegions, $activityTypeOf);
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
