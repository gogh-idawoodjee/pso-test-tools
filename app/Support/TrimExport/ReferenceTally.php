<?php

namespace App\Support\TrimExport;

/**
 * The writer's own tally of what it kept: the ids of the tables rows point at, and every reference field of
 * every kept row. Anything still pointing at an id that was not kept is dangling. A target table the input
 * never had is not checked (there was nothing to keep). Memory: distinct ids only.
 */
class ReferenceTally
{
    /** Reference field => the table holding the ids it points at (load-audit REFS in pso-tools). */
    public const array TARGETS = [
        'activity_id' => 'Activity',
        'resource_id' => 'Resources',
        'availability_id' => 'Availability',
        'location_id' => 'Location',
        'location_id_start' => 'Location',
        'location_id_end' => 'Location',
        'shift_id' => 'Shift',
    ];

    public const array FIELDS = ['id', 'activity_id', 'resource_id', 'availability_id', 'location_id', 'location_id_start', 'location_id_end', 'shift_id'];

    /** @var array<string, array<string|int, true>> target table => kept ids */
    private array $ids = [];

    /** @var array<string, array<string, array<string|int, int>>> table => field => value => rows */
    private array $references = [];

    /** @param array<string, string> $fields */
    public function record(string $table, array $fields): void
    {
        if (isset($fields['id']) && in_array($table, self::TARGETS, true)) {
            $this->ids[$table][$fields['id']] = true;
        }

        foreach (self::TARGETS as $field => $target) {
            if (isset($fields[$field])) {
                $value = $fields[$field];
                $this->references[$table][$field][$value] = ($this->references[$table][$field][$value] ?? 0) + 1;
            }
        }
    }

    /**
     * @param  list<string>  $presentTables  tables the input had
     * @return list<array{table: string, field: string, count: int, examples: list<string>}>
     */
    public function dangling(array $presentTables): array
    {
        $present = array_flip($presentTables);
        $found = [];

        foreach ($this->references as $table => $fields) {
            foreach ($fields as $field => $values) {
                $target = self::TARGETS[$field];
                if (! isset($present[$target])) {
                    continue;
                }

                $count = 0;
                $examples = [];
                foreach ($values as $value => $rows) {
                    if (isset($this->ids[$target][$value])) {
                        continue;
                    }
                    $count += $rows;
                    if (count($examples) < 3) {
                        $examples[] = (string) $value;
                    }
                }

                if ($count > 0) {
                    $found[] = ['table' => (string) $table, 'field' => $field, 'count' => $count, 'examples' => $examples];
                }
            }
        }

        usort($found, static fn (array $first, array $second): int => [$first['table'], $first['field']] <=> [$second['table'], $second['field']]);

        return $found;
    }
}
