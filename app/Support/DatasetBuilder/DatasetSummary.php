<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use App\Support\DatasetBuilder\Spec\RegionSpec;
use App\Support\DatasetBuilder\Spec\ResourceSpec;
use App\Support\DatasetBuilder\Spec\ResourceTypeSpec;
use DateTimeImmutable;

/**
 * What the Dataset Builder page shows after a run: counts and timestamps only.
 * The shift start bands list, per region and type, each run of days with the
 * same UTC start time; a DST change shows as a second band, so a time zone
 * mistake is visible without opening the file.
 */
class DatasetSummary
{
    /** @var array<string, array<string, int>> region => type => resources */
    private array $resourcesByRegion = [];

    /** @var array<string, int> resource id => shifts */
    private array $shiftsByResource = [];

    /** @var array<string, array{first: DateTimeImmutable, last: DateTimeImmutable, shifts: int}> */
    private array $regions = [];

    /** @var array<string, array<string, array{utc: string, local: string, date: string, shifts: int}>> "region type" => Y-m-d => start */
    private array $starts = [];

    private int $shifts = 0;

    private int $mondayShifts = 0;

    private int $mondaysAtZero = 0;

    private int $otherShiftsAtOne = 0;

    /** @var list<string> */
    private array $extraRegions = [];

    /** @var array<string, array<string, array<string, int>>> region => local date => type => activities */
    private array $activityDays = [];

    /** @var array<string, array{type: string, activities: int, shortest: float, longest: float}> type => priority 1 window lengths, hours */
    private array $inSla = [];

    public function __construct(private readonly DatasetSpec $spec, private readonly DateTimeImmutable $now) {}

    public function resource(ResourceSpec $resource, ResourceTypeSpec $type): void
    {
        $this->resourcesByRegion[$resource->region][$type->id] = ($this->resourcesByRegion[$resource->region][$type->id] ?? 0) + 1;
        $this->shiftsByResource[$resource->id] = 0;
    }

    public function extraRegion(ResourceSpec $resource, string $regionId): void
    {
        $this->extraRegions[] = "{$resource->firstName} {$resource->surname} ({$resource->id}): {$resource->region} + {$regionId}";
    }

    /** @param array{region: string, type: string, date: string, in_start: DateTimeImmutable, in_end: DateTimeImmutable} $activity */
    public function activity(array $activity): void
    {
        $type = $activity['type'];
        $this->activityDays[$activity['region']][$activity['date']][$type] = ($this->activityDays[$activity['region']][$activity['date']][$type] ?? 0) + 1;

        $hours = ($activity['in_end']->getTimestamp() - $activity['in_start']->getTimestamp()) / 3600;
        $known = $this->inSla[$type] ?? ['type' => $type, 'activities' => 0, 'shortest' => INF, 'longest' => 0.0];
        $this->inSla[$type] = [
            'type' => $type,
            'activities' => $known['activities'] + 1,
            'shortest' => min($known['shortest'], $hours),
            'longest' => max($known['longest'], $hours),
        ];
    }

    /** @param array{shift: array<string, string>, local_start: DateTimeImmutable} $shift */
    public function shift(ResourceSpec $resource, ResourceTypeSpec $type, RegionSpec $region, array $shift): void
    {
        $this->shifts++;
        $this->shiftsByResource[$resource->id]++;

        $localStart = $shift['local_start'];
        $monday = $localStart->format('N') === '1';

        if ($monday) {
            $this->mondayShifts++;
            $this->mondaysAtZero += $shift['shift']['split_priority'] === '0' ? 1 : 0;
        } else {
            $this->otherShiftsAtOne += $shift['shift']['split_priority'] === '1' ? 1 : 0;
        }

        $known = $this->regions[$region->id] ?? null;
        $this->regions[$region->id] = [
            'first' => $known === null || $localStart < $known['first'] ? $localStart : $known['first'],
            'last' => $known === null || $localStart > $known['last'] ? $localStart : $known['last'],
            'shifts' => ($known['shifts'] ?? 0) + 1,
        ];

        $key = "{$region->id} {$type->id}";
        $day = $localStart->format('Y-m-d');
        $this->starts[$key][$day] ??= [
            'utc' => substr($shift['shift']['start_datetime'], 11, 5).'Z',
            'local' => $localStart->format('H:i'),
            'date' => $localStart->format('D Y-m-d'),
            'shifts' => 0,
        ];
        $this->starts[$key][$day]['shifts']++;
    }

    /**
     * @param  array{path: string, bytes: int, counts: array<string, int>}|null  $modelling  the .arp ModellingData wrote, if any
     * @return array<string, mixed>
     */
    public function toArray(string $path, int $bytes, ?array $modelling = null): array
    {
        $types = [];

        foreach ($this->spec->resources as $resource) {
            $count = $this->shiftsByResource[$resource->id];
            $type = $this->spec->resourceTypes[$resource->resourceType];
            $row = $types[$type->id] ?? ['type' => $type->id, 'days' => self::dayNames($type->shiftDays), 'resources' => 0, 'shifts' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
            $row['resources']++;
            $row['shifts'] += $count;
            $row['min'] = min($row['min'], $count);
            $row['max'] = max($row['max'], $count);
            $types[$type->id] = $row;
        }

        $regions = [];

        foreach ($this->spec->regions as $id => $region) {
            $times = $this->regions[$id] ?? null;
            $regions[] = [
                'region' => $id,
                'time_zone' => $region->timeZone,
                'resources' => $this->resourcesByRegion[$id] ?? [],
                'shifts' => $times['shifts'] ?? 0,
                'first_local' => $times ? $times['first']->format('D Y-m-d H:i T') : null,
                'first_utc' => $times ? ShiftFactory::utc($times['first']) : null,
                'last_local' => $times ? $times['last']->format('D Y-m-d H:i T') : null,
                'last_utc' => $times ? ShiftFactory::utc($times['last']) : null,
            ];
        }

        $bands = [];

        foreach ($this->starts as $key => $days) {
            [$region, $type] = explode(' ', $key);
            ksort($days);
            $band = null;

            foreach ($days as $start) {
                if ($band !== null && $band['utc'] === $start['utc'] && $band['local'] === $start['local']) {
                    $band['to'] = $start['date'];
                    $band['shifts'] += $start['shifts'];

                    continue;
                }

                if ($band !== null) {
                    $bands[] = $band;
                }

                $band = ['region' => $region, 'type' => $type, 'utc' => $start['utc'], 'local' => $start['local'], 'from' => $start['date'], 'to' => $start['date'], 'shifts' => $start['shifts']];
            }

            if ($band !== null) {
                $bands[] = $band;
            }
        }

        return [
            'dataset_id' => $this->spec->datasetId,
            'seed' => $this->spec->seed,
            'now' => $this->now->format('Y-m-d\TH:i:sP'),
            'horizon_days' => $this->spec->horizonDays,
            'file' => basename($path),
            'bytes' => $bytes,
            'resources' => count($this->spec->resources),
            'resource_types' => array_keys($this->spec->resourceTypes),
            'regions' => $regions,
            'types' => array_values($types),
            'shifts' => $this->shifts,
            'monday_shifts' => $this->mondayShifts,
            'monday_shifts_at_zero' => $this->mondaysAtZero,
            'other_shifts' => $this->shifts - $this->mondayShifts,
            'other_shifts_at_one' => $this->otherShiftsAtOne,
            'extra_regions' => $this->extraRegions,
            'bands' => $bands,
            'activities' => array_sum(array_map(static fn (array $days): int => array_sum(array_map('array_sum', $days)), $this->activityDays)),
            'activity_types' => array_keys($this->spec->stage2?->mix ?? []),
            'activity_days' => $this->activityDays,
            'in_sla' => array_values($this->inSla),
            'modelling' => $modelling === null ? null : [
                'file' => basename($modelling['path']),
                'bytes' => $modelling['bytes'],
                'counts' => $modelling['counts'],
            ],
        ];
    }

    /** @param list<int> $days */
    private static function dayNames(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return implode(' ', array_map(static fn (int $day): string => $names[$day], $days));
    }
}
