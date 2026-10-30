<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use App\Support\DatasetBuilder\Spec\RegionSpec;
use App\Support\DatasetBuilder\Spec\Stage2Spec;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Random\Randomizer;

/**
 * Stage 2: unallocated SLA work. Per region, in its own zone, the next
 * `working_days` weekdays whose FIELD_TECH shift has not ended yet; on them
 * round(jobs per resource per day x home-region resources) jobs a day. The
 * region's total is split by the type mix with largest-remainder rounding;
 * EMERGENCY jobs all land on the first working day and every other type is
 * spread evenly over the days.
 *
 * Each activity gets its own Location (drawn like a home), a Location_Region,
 * at most one skill held by at least two of the region's own resources, two
 * touching start-based SLA rows and a status 0 row. Every draw comes from the
 * seeded Randomizer, in region, day and type order.
 *
 * Field names are the stage 2 cheat sheet's (docs/dataset-builder/stage2-entity-cheatsheet.md).
 */
class ActivityFactory
{
    public const string EMERGENCY = 'EMERGENCY';

    /** EMERGENCY's base value is this many times a normal job's of the same length. */
    private const int EMERGENCY_VALUE_FACTOR = 4;

    private const int CREATED_UP_TO_HOURS = 72;

    private const int DURATION_STEP_MINUTES = 15;

    private const string SLA_START = '08:00';

    private const string SLA_END = '17:00';

    /**
     * @return list<array{region: string, type: string, date: string, location: array<string, string>, location_region: array<string, string>, activity: array<string, string>, skill: array<string, string>|null, slas: list<array<string, string>>, status: array<string, string>, in_start: DateTimeImmutable, in_end: DateTimeImmutable}>
     */
    public static function activities(DatasetSpec $spec, DateTimeImmutable $now, Randomizer $random, LocationFactory $locations): array
    {
        $stage2 = $spec->stage2;

        if ($stage2 === null) {
            return [];
        }

        $activities = [];

        foreach ($spec->regions as $region) {
            $zone = new DateTimeZone($region->timeZone);
            $days = self::workingDays($spec, $region, $now);
            $residents = array_values(array_filter($spec->resources, static fn ($resource): bool => $resource->region === $region->id));
            $perDay = (int) round($stage2->jobsPerResourcePerDay * count($residents));
            $byType = self::largestRemainder($perDay * count($days), $stage2->mix);
            $skills = self::sharedSkills($residents);
            $sequence = 0;

            foreach ($days as $dayIndex => $day) {
                foreach ($byType as $type => $count) {
                    $onDay = $type === self::EMERGENCY
                        ? ($dayIndex === 0 ? $count : 0)
                        : self::largestRemainder($count, array_fill(0, count($days), 1))[$dayIndex];

                    for ($i = 0; $i < $onDay; $i++) {
                        $activities[] = self::activity($spec, $stage2, $region, $zone, $now, $day, $type, ++$sequence, $skills, $random, $locations);
                    }
                }
            }
        }

        return $activities;
    }

    /**
     * The region's working days: local midnights of the next weekdays whose
     * FIELD_TECH shift end (local) is still after now.
     *
     * @return list<DateTimeImmutable>
     */
    public static function workingDays(DatasetSpec $spec, RegionSpec $region, DateTimeImmutable $now): array
    {
        $zone = new DateTimeZone($region->timeZone);
        $shiftEnd = $spec->resourceTypes[Stage2Spec::WORKING_DAY_TYPE]->shiftEnd;
        $date = ShiftFactory::dayZero($now, $zone);
        $days = [];

        while (count($days) < $spec->stage2->workingDays) {
            if ((int) $date->format('N') <= 5 && self::at($date, $shiftEnd) > $now) {
                $days[] = $date;
            }

            $date = $date->modify('+1 day');
        }

        return $days;
    }

    /**
     * $total split by $weights: each gets the floor of its share, and what is
     * left goes one each to the largest remainders (ties in order given).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, float|int>  $weights
     * @return array<TKey, int>
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $counts = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = $sum > 0 ? $total * $weight / $sum : 0;
            $counts[$key] = (int) floor($exact + 1e-9);
            $remainders[$key] = $exact - $counts[$key];
        }

        $left = $total - array_sum($counts);
        $order = array_keys($remainders);
        usort($order, static fn ($a, $b): int => $remainders[$b] <=> $remainders[$a] ?: array_search($a, array_keys($weights), true) <=> array_search($b, array_keys($weights), true));

        foreach (array_slice($order, 0, $left) as $key) {
            $counts[$key]++;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $skills  skills held by at least two of the region's own resources
     * @return array{region: string, type: string, date: string, location: array<string, string>, location_region: array<string, string>, activity: array<string, string>, skill: array<string, string>|null, slas: list<array<string, string>>, status: array<string, string>, in_start: DateTimeImmutable, in_end: DateTimeImmutable}
     */
    private static function activity(DatasetSpec $spec, Stage2Spec $stage2, RegionSpec $region, DateTimeZone $zone, DateTimeImmutable $now, DateTimeImmutable $day, string $type, int $sequence, array $skills, Randomizer $random, LocationFactory $locations): array
    {
        $id = sprintf('A-%s-%06d', $region->id, $sequence);
        $location = $locations->activity(sprintf('L-%s-%05d', $region->id, $sequence), $region);

        $range = $stage2->durations[$type];
        $minutes = (int) (round($random->getInt($range['min'], $range['max']) / self::DURATION_STEP_MINUTES) * self::DURATION_STEP_MINUTES);
        $minutes = max(self::DURATION_STEP_MINUTES, $minutes);
        $value = (int) round(1000 * $minutes / 60 * ($type === self::EMERGENCY ? self::EMERGENCY_VALUE_FACTOR : 1));

        // Created up to 72 hours before now, on the minute, but never before midnight of day -1 in the region.
        $created = $now->modify('-'.$random->getInt(0, self::CREATED_UP_TO_HOURS * 60).' minutes');
        $earliest = ShiftFactory::dayZero($now, $zone)->modify('-1 day');
        $created = max($created, $earliest);
        $createdUtc = ShiftFactory::utc($created);

        $pool = array_values(array_intersect($stage2->skills[$type]['pool'], $skills));
        $skill = null;

        if ($random->nextFloat() < $stage2->skills[$type]['p'] && $pool !== []) {
            $skill = ['skill_id' => $pool[$random->getInt(0, count($pool) - 1)], 'activity_id' => $id, 'share_with_corequisites' => 'false'];
        }

        // Priority 1 from 08:00 local on the target day (or now, if later); its end snapped to 17:00 local.
        $inStart = max($now, self::at($day, self::SLA_START))->setTimezone($zone);
        $window = $stage2->slaWindows[$type];
        $inEnd = $window['in'] === Stage2Spec::SAME_DAY
            ? self::at($day, self::SLA_END)
            : self::at($inStart->add(new DateInterval($window['in']))->setTime(0, 0), self::SLA_END);
        $outEnd = $inEnd->add(new DateInterval($window['out']));
        $primary = isset($spec->slaTypes[$type]) ? $type : 'IN_SLA';

        return [
            'region' => $region->id,
            'type' => $type,
            'date' => $day->format('Y-m-d'),
            'location' => $location,
            'location_region' => ['region_id' => $region->id, 'location_id' => $location['id']],
            'activity' => [
                'id' => $id,
                'activity_class_id' => 'CALL',
                'activity_type_id' => $type,
                'location_id' => $location['id'],
                'description' => "{$type} : {$location['address_line1']}",
                'duration' => self::isoMinutes($minutes),
                'base_value' => (string) $value,
                'split_allowed' => 'false',
                'appointed' => 'false',
                'do_on_site' => 'false',
                'auto_duration_enabled' => 'false',
                'remote_fulfillment' => 'false',
                'date_time_created' => $createdUtc,
                'date_time_open' => $createdUtc,
                'memo' => '.',
            ],
            'skill' => $skill,
            'slas' => [
                self::sla($primary, $id, 1, $inStart, $inEnd),
                self::sla('OUT_SLA', $id, 2, $inEnd, $outEnd),
            ],
            'status' => [
                'activity_id' => $id,
                'status_id' => '0',
                'visit_id' => '1',
                'fixed' => 'false',
                'date_time_status' => $createdUtc,
                'date_time_stamp' => $createdUtc,
            ],
            'in_start' => $inStart,
            'in_end' => $inEnd,
        ];
    }

    /** @return array<string, string> */
    private static function sla(string $type, string $activityId, int $priority, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'sla_type_id' => $type,
            'activity_id' => $activityId,
            'priority' => (string) $priority,
            'start_based' => 'true',
            'datetime_start' => ShiftFactory::utc($start),
            'datetime_end' => ShiftFactory::utc($end),
        ];
    }

    /**
     * @param  list<Spec\ResourceSpec>  $residents
     * @return list<string>
     */
    private static function sharedSkills(array $residents): array
    {
        $holders = [];

        foreach ($residents as $resource) {
            foreach ($resource->skills as $skill) {
                $holders[$skill] = ($holders[$skill] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($holders, static fn (int $count): bool => $count >= 2));
    }

    /** HH:MM local on the given date (a local midnight). */
    private static function at(DateTimeImmutable $date, string $clock): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' '.$clock.':00', $date->getTimezone());
    }

    private static function isoMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours > 0 ? "{$hours}H" : '').($rest > 0 ? "{$rest}M" : '');
    }
}
