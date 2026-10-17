<?php

namespace App\Support\TrimExport;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One Trim export request, resolved from the run's options: which tables go (Remove ticked or Keep ticked,
 * Input_Reference locked unless allowed), the row filters (ids as strings, the appointment window as UTC
 * seconds), the Input_Reference datetime override and Count only.
 */
class TrimPlan
{
    public const string MODE_REMOVE = 'remove';

    public const string MODE_KEEP = 'keep';

    /**
     * @param  list<string>  $tables  the ticked tables
     * @param  list<string>  $regionIds
     * @param  list<string>  $resourceIds
     * @param  list<string>  $activityTypeIds
     * @param  list<string>  $activityIds
     */
    public function __construct(
        public string $mode = self::MODE_REMOVE,
        public array $tables = [],
        public bool $allowInputReferenceRemoval = false,
        public array $regionIds = [],
        public array $resourceIds = [],
        public array $activityTypeIds = [],
        public array $activityIds = [],
        public ?int $windowStart = null,
        public ?int $windowEnd = null,
        public ?string $inputReferenceDatetime = null,
        public bool $countOnly = false,
    ) {}

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options, string $timeZone): self
    {
        [$windowStart, $windowEnd] = self::window($options['window_start'] ?? null, $options['window_end'] ?? null, $timeZone);

        return new self(
            mode: ($options['table_mode'] ?? null) === self::MODE_KEEP ? self::MODE_KEEP : self::MODE_REMOVE,
            tables: self::strings($options['tables'] ?? []),
            allowInputReferenceRemoval: (bool) ($options['allow_input_reference_removal'] ?? false),
            regionIds: self::strings($options['region_ids'] ?? []),
            resourceIds: self::strings($options['resource_ids'] ?? []),
            activityTypeIds: self::strings($options['activity_type_ids'] ?? []),
            activityIds: self::strings($options['activity_ids'] ?? []),
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            inputReferenceDatetime: self::override($options['input_reference_datetime'] ?? null),
            countOnly: (bool) ($options['count_only'] ?? false),
        );
    }

    public function dropsTable(string $table): bool
    {
        if ($table === TableDependencies::INPUT_REFERENCE && ! $this->allowInputReferenceRemoval) {
            return false;
        }

        $ticked = in_array($table, $this->tables, true);

        return $this->mode === self::MODE_KEEP ? ! $ticked : $ticked;
    }

    /**
     * @param  list<string>  $presentTables
     * @return list<string>
     */
    public function droppedTables(array $presentTables): array
    {
        return array_values(array_filter($presentTables, fn (string $table): bool => $this->dropsTable($table)));
    }

    /** Leaving every filter empty means rows are untouched: the run is tables only. */
    public function filtersRows(): bool
    {
        return $this->regionIds !== [] || $this->resourceIds !== [] || $this->activityTypeIds !== []
            || $this->activityIds !== [] || $this->windowStart !== null;
    }

    /** @return list<string> unique, non-empty, as strings (a JSON export may carry number ids) */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $strings[] = trim((string) $value);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Both days or neither, as Filter Load File did: the start of the first day to the end of the last, in the run's zone.
     *
     * @return array{0: ?int, 1: ?int} UTC seconds
     */
    private static function window(mixed $start, mixed $end, string $timeZone): array
    {
        if (! is_string($start) || ! is_string($end) || trim($start) === '' || trim($end) === '') {
            return [null, null];
        }

        try {
            $zone = new DateTimeZone($timeZone);
            $from = new DateTimeImmutable(substr(trim($start), 0, 10).' 00:00:00', $zone);
            $to = new DateTimeImmutable(substr(trim($end), 0, 10).' 23:59:59', $zone);
        } catch (Throwable) {
            return [null, null];
        }

        return $from <= $to ? [$from->getTimestamp(), $to->getTimestamp()] : [null, null];
    }

    /** The picker's value is UTC (its label says so); PSO writes offsetless UTC times. */
    private static function override(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
