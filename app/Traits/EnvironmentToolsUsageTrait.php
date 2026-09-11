<?php

namespace App\Traits;

use App\Enums\ScheduleDataUsageType;

trait EnvironmentToolsUsageTrait
{
    /**
     * Groups raw ScheduleDataUsages rows by usage type, keeping the most
     * recent value per type plus how many readings fell in the requested range.
     */
    public function groupUsageRows(array $rows): array
    {
        return collect($rows)
            ->groupBy('ScheduleDataUsageType')
            ->map(function ($rowsForType) {
                $sorted = collect($rowsForType)->sortByDesc('DatetimeStamp')->values();
                $latest = $sorted->first();

                return [
                    'type' => ScheduleDataUsageType::tryFrom((int) data_get($latest, 'ScheduleDataUsageType')),
                    'latestValue' => data_get($latest, 'Value'),
                    'latestDatetime' => data_get($latest, 'DatetimeStamp'),
                    'readingCount' => $sorted->count(),
                ];
            })
            ->sortBy(fn (array $group) => $group['type']?->value ?? PHP_INT_MAX)
            ->values()
            ->all();
    }
}
