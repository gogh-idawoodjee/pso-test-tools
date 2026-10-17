<?php

namespace App\Support\TrimExport;

/**
 * The Tables step's list: tables grouped by what they hold (Filament's CheckboxList is flat, so the group is a
 * prefix and the order), each with its row count, and the footer that sums both sides of the selection.
 */
class TableChoices
{
    /** Group headings, in the order the list shows them. */
    public const array GROUPS = ['Plan output', 'Appointments and events', 'Activities', 'Resources and shifts', 'Locations and regions', 'Other'];

    public static function groupOf(string $table): string
    {
        return match (true) {
            str_starts_with($table, 'Plan') || str_starts_with($table, 'Allocation') => 'Plan output',
            str_starts_with($table, 'Appointment_') || str_starts_with($table, 'Schedule_')
                || str_starts_with($table, 'Explanation_') || str_starts_with($table, 'Broadcast') => 'Appointments and events',
            str_starts_with($table, 'Activity') => 'Activities',
            $table === 'Resources' || str_starts_with($table, 'Resource_') || str_starts_with($table, 'Shift') || $table === 'Availability' => 'Resources and shifts',
            str_starts_with($table, 'Location') || str_starts_with($table, 'Region') => 'Locations and regions',
            default => 'Other',
        };
    }

    /**
     * @param  array<string, int>  $counts  table => rows
     * @return array<string, string> table => "Plan output: Plan_Travel, 4,812 rows", in GROUPS order, by name within a group
     */
    public static function options(array $counts): array
    {
        $tables = array_map('strval', array_keys($counts));
        usort($tables, static fn (string $first, string $second): int => [array_search(self::groupOf($first), self::GROUPS, true), $first]
            <=> [array_search(self::groupOf($second), self::GROUPS, true), $second]);

        $options = [];
        foreach ($tables as $table) {
            $options[$table] = self::groupOf($table).': '.$table.', '.TrimReport::plural((int) $counts[$table], 'row')
                .($table === TableDependencies::INPUT_REFERENCE ? ' (always kept unless allowed below)' : '');
        }

        return $options;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $dropped
     */
    public static function footer(array $counts, array $dropped): string
    {
        if ($counts === []) {
            return 'The tables are read from the file when you leave the Inputs step.';
        }

        $droppedSet = array_flip($dropped);
        $removedTables = 0;
        $removedRows = 0;
        $keptTables = 0;
        $keptRows = 0;
        foreach ($counts as $table => $rows) {
            if (isset($droppedSet[$table])) {
                $removedTables++;
                $removedRows += (int) $rows;
            } else {
                $keptTables++;
                $keptRows += (int) $rows;
            }
        }

        return 'Removing '.TrimReport::plural($removedTables, 'table').', '.TrimReport::plural($removedRows, 'row').'. '
            .'Keeping '.TrimReport::plural($keptTables, 'table').', '.TrimReport::plural($keptRows, 'row').'.';
    }
}
