<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;
use App\Support\SysCompare\EffectiveValue;

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
        $applicationByParameter = $this->applicationByParameter($context);
        $inventories = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $inventories[] = $this->inventoryFor($context, $environmentIndex, $applicationByParameter);
        }

        $tab = new ComparisonTab('Travel', '07_Travel.csv', ['Item']);

        foreach (array_keys($inventories[0]) as $item) {
            $tab->addRow(
                [$item],
                array_map(static fn (array $inventory): string => $inventory[$item]->display, $inventories),
                compareValues: array_map(static fn (array $inventory): string => $inventory[$item]->compare, $inventories),
            );
        }

        return [$tab];
    }

    /**
     * The application type each travel parameter is set under (the first one seen in any export),
     * so it can be matched to its catalog default.
     *
     * @return array<string, string>
     */
    private function applicationByParameter(ComparisonContext $context): array
    {
        $applications = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            foreach ($context->rows($environmentIndex, 'Profile_Parameter') as $row) {
                $applications[$row['parameter_id'] ?? ''] ??= $row['parameter_application_type_id'] ?? '';
            }
        }

        return $applications;
    }

    /**
     * @param  array<string, string>  $applicationByParameter
     * @return array<string, EffectiveValue>
     */
    private function inventoryFor(ComparisonContext $context, int $environmentIndex, array $applicationByParameter): array
    {
        $parameters = $context->parameters($environmentIndex);
        $inventory = [];

        foreach (self::TRAVEL_PARAMETERS as [$profile, $parameter]) {
            $inventory["Param: {$profile} / {$parameter}"] = $context->resolver->resolve(
                $parameters,
                $profile,
                $parameter,
                $applicationByParameter[$parameter] ?? '',
            );
        }

        $profiles = $context->rows($environmentIndex, 'Travel_Time_Profile');
        $weightings = $context->rows($environmentIndex, 'Travel_Time_Weighting');
        $polygonLinks = $context->rows($environmentIndex, 'Travel_Time_Polygon');
        $polygons = $context->rows($environmentIndex, 'Polygon');

        $zones = [];

        foreach ($weightings as $weighting) {
            $zone = $weighting['time_zone'] ?? '';
            $zones[$zone === '' ? Cells::NONE : $zone] = true;
        }

        $plain = [
            'Travel time profile IDs' => $profiles === [] ? Cells::NONE : $this->joinSorted(array_column($profiles, 'id'), ', '),
            'Weighting rows' => (string) count($weightings),
            'Weighting time zone(s)' => $zones === [] ? Cells::NONE : $this->joinSorted(array_map('strval', array_keys($zones)), ', '),
            'Polygons defined' => (string) count($polygons),
            'Polygon-weighting links' => (string) count($polygonLinks),
            'Polygon weighting range' => $this->weightingRange($polygonLinks),
            'Polygon IDs (sample)' => $this->polygonSample($polygons),
        ];

        foreach ($plain as $item => $value) {
            $inventory[$item] = new EffectiveValue($value, $value);
        }

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
