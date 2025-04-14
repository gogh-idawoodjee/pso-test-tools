<?php

namespace App\Services;

class DryRunSummaryFormatter
{
    public static function format(array $summary): array
    {
        $result = [];

        foreach ($summary as $section => $counts) {
            if (!is_array($counts)) {
                $result[$section] = $counts;
                continue;
            }

            $kept = $counts['kept'] ?? 0;
            $total = $counts['total'] ?? 0;
            $skipped = $counts['skipped'] ?? ($total - $kept);

            $formatted = "{$kept} kept / {$total} total";

            if ($skipped > 0) {
                $formatted .= " ({$skipped} skipped)";
            }

            $result[$section] = $formatted;
        }

        return $result;
    }
}
