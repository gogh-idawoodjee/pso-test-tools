<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Travel setup: the travel parameters plus an inventory of the travel-time
 * profile, weighting and polygon tables. Polygons are summarised by count and
 * weighting range rather than compared row by row.
 */
class TravelArea implements Area
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const array TRAVEL_PARAMETERS = [
        ['DEFAULT', 'TravelCalculationOption'], ['CommittedActivitiesProfile', 'TravelCalculationOption'],
        ['STRAIGHTLINE', 'TravelCalculationOption'], ['DEFAULT', 'RealTimeTravelProvider'],
        ['CommittedActivitiesProfile', 'RealTimeTravelProvider'], ['DEFAULT', 'TravelTimeProfileId'],
        ['CommittedActivitiesProfile', 'TravelTimeProfileId'], ['STRAIGHTLINE', 'TravelTimeProfileId'],
        ['DEFAULT', 'AllowSplitTravel'], ['DEFAULT', 'HierarchicalDatabaseMatrixId'],
    ];

    public function build(ComparisonContext $context): array
    {
        $inventories = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $inventories[] = $this->inventoryFor($context, $environmentIndex);
        }

        $tab = new ComparisonTab('Travel', '07_Travel.csv', ['Item']);

        foreach (array_keys($inventories[0]) as $item) {
            $tab->addRow([$item], array_map(static fn (array $inventory): string => $inventory[$item], $inventories));
        }

        return [$tab];
    }

    /**
     * @return array<string, string>
     */
    private function inventoryFor(ComparisonContext $context, int $environmentIndex): array
    {
        $parameterValues = [];

        foreach ($context->rows($environmentIndex, 'Profile_Parameter') as $row) {
            $parameterValues[($row['profile_id'] ?? '').Cells::KEY_SEPARATOR.($row['parameter_id'] ?? '')] = $row['parameter_value'] ?? null;
        }

        $inventory = [];

        foreach (self::TRAVEL_PARAMETERS as [$profile, $parameter]) {
            $key = $profile.Cells::KEY_SEPARATOR.$parameter;

            $inventory["Param: {$profile} / {$parameter}"] = array_key_exists($key, $parameterValues)
                ? Cells::format($parameterValues[$key])
                : Cells::ABSENT;
        }

        $profiles = $context->rows($environmentIndex, 'Travel_Time_Profile');
        $weightings = $context->rows($environmentIndex, 'Travel_Time_Weighting');
        $polygonLinks = $context->rows($environmentIndex, 'Travel_Time_Polygon');
        $polygons = $context->rows($environmentIndex, 'Polygon');

        $inventory['Travel time profile IDs'] = $profiles === []
            ? Cells::NONE
            : $this->joinSorted(array_column($profiles, 'id'), ', ');
        $inventory['Weighting rows'] = (string) count($weightings);

        $zones = [];

        foreach ($weightings as $weighting) {
            $zone = $weighting['time_zone'] ?? '';
            $zones[$zone === '' ? Cells::NONE : $zone] = true;
        }

        $inventory['Weighting time zone(s)'] = $zones === []
            ? Cells::NONE
            : $this->joinSorted(array_map('strval', array_keys($zones)), ', ');

        $inventory['Polygons defined'] = (string) count($polygons);
        $inventory['Polygon-weighting links'] = (string) count($polygonLinks);
        $inventory['Polygon weighting range'] = $this->weightingRange($polygonLinks);
        $inventory['Polygon IDs (sample)'] = $this->polygonSample($polygons);

        return $inventory;
    }

    /**
     * @param  list<array<string, string>>  $polygonLinks
     */
    private function weightingRange(array $polygonLinks): string
    {
        $weights = [];

        foreach ($polygonLinks as $link) {
            $weight = trim($link['weighting'] ?? '');

            if ($weight !== '' && is_numeric($weight)) {
                $weights[] = (float) $weight;
            }
        }

        return $weights === [] ? Cells::NONE : min($weights).' - '.max($weights);
    }

    /**
     * @param  list<array<string, string>>  $polygons
     */
    private function polygonSample(array $polygons): string
    {
        if ($polygons === []) {
            return Cells::NONE;
        }

        $ids = array_map('strval', array_column($polygons, 'id'));
        usort($ids, Cells::compareText(...));

        $sample = implode(', ', array_slice($ids, 0, 2));

        return count($polygons) > 2 ? $sample.' ...' : $sample;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function joinSorted(array $values, string $separator): string
    {
        $values = array_values($values);
        usort($values, Cells::compareText(...));

        return implode($separator, $values);
    }
}
