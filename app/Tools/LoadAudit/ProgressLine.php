<?php

namespace App\Tools\LoadAudit;

/** One stderr line from audit_load.py run with PSO_PROGRESS=lines, turned into words for the run page. */
class ProgressLine
{
    private const LABELS = [
        'load' => 'Reading the LOAD',
        'plan' => 'Reading the plan',
        'sys' => 'Reading the SYS file',
        'checks' => 'Running the checks',
    ];

    /** @return array{label: string, percent: ?int, seconds_left: ?int, stage: string}|null null for anything that is not a progress line we know */
    public static function parse(string $line): ?array
    {
        if (! str_starts_with($line, 'PROGRESS ')) {
            return null;
        }

        $record = json_decode(substr($line, strlen('PROGRESS ')), true);
        if (! is_array($record) || ! isset(self::LABELS[$record['stage'] ?? null])) {
            return null;
        }

        $percent = isset($record['percent']) && is_numeric($record['percent']) ? max(0, min(100, (int) $record['percent'])) : null;
        $left = isset($record['seconds_left']) && is_numeric($record['seconds_left']) ? max(0, (int) $record['seconds_left']) : null;

        $label = self::LABELS[$record['stage']];
        if ($percent !== null) {
            $label .= ": {$percent}%";
            if ($left !== null) {
                $label .= ", about {$left} s left";
            }
        }

        return ['label' => $label, 'percent' => $percent, 'seconds_left' => $left, 'stage' => $record['stage']];
    }
}
