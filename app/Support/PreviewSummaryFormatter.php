<?php

namespace App\Support;

class PreviewSummaryFormatter
{
    public static function format(array $summary): array
    {
        $iconMap = [
            'activities' => 'heroicon-o-clipboard-document-list',
            'resources' => 'heroicon-o-users',
            'shifts' => 'heroicon-o-clock',
            'shift_breaks' => 'heroicon-o-pause',
            'resource_skills' => 'heroicon-o-academic-cap',
            'resource_regions' => 'heroicon-o-map',
            'activity_slas' => 'heroicon-o-adjustments-horizontal',
            'activity_statuses' => 'heroicon-o-information-circle',
        ];

        return collect($summary)->map(static function ($stats, $key) use ($iconMap) {
            return [
                'entity' => ucwords(str_replace('_', ' ', $key)),
                'icon' => $iconMap[$key] ?? 'heroicon-o-question-mark-circle',
                'total' => $stats['total'] ?? 0,
                'kept' => $stats['kept'] ?? 0,
                'skipped' => $stats['skipped'] ?? null,
            ];
        })->values()->all();
    }
}
