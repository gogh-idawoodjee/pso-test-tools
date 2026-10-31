<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use App\Support\DatasetBuilder\Spec\RegionSpec;
use App\Support\DatasetBuilder\Spec\Stage2Spec;
use App\Support\DatasetBuilder\Spec\Stage3Spec;
use App\Support\DatasetBuilder\Spec\Stage4Spec;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Random\Randomizer;

/**
 * Stage 2: unallocated SLA work. Per region, in its own zone, the next
 * `working_days` weekdays whose FIELD_TECH shift has not ended yet; on them
 * round(jobs per resource per day x serving resources) jobs a day (home
 * resources, plus `extra_region_weight` for each one covering the region
 * through `extra_regions`). The
 * region's total is split by the type mix with largest-remainder rounding;
 * EMERGENCY jobs all land on the first working day and every other type is
 * spread evenly over the days.
 *
 * Each activity gets its own Location (drawn like a home), a Location_Region,
 * at most one skill held by at least two of the region's serving resources,
 * two touching start-based SLA rows (the first of the type's
 * `primary_sla_type`) and a status 0 row.
 *
 * Stage 3: booked INSTALL and SURVEY appointments in the region's appointment
 * template slots, over today (slots starting an hour or more after now) and
 * the next weekdays; each with one SLA row, an ACCEPTED_APPOINTMENT
 * Availability and a status row with date_time_earliest, all on the slot.
 *
 * Stage 4: splittable PROJECT work, a few a week per region whose serving
 * resources include two or more holders of the job's skills, starting on a
 * weighted weekday over the next weeks, with two end-based SLA rows.
 *
 * Every draw comes from the seeded Randomizer: all stage 2 work first, region
 * by region (regions with home resources in spec order, then those served only
 * through `extra_regions`, so their work never moves anyone else's), then all
 * stage 3 appointments, then all stage 4 projects. A later stage's draws
 * never move an earlier stage's.
 *
 * Field names are the stage 2 and 3 cheat sheets' (docs/dataset-builder/).
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
     * @return list<array<string, mixed>> region, type, date, kind (stage 3 template kind, else null), location,
     *                                    location_region, activity, skill, slas, availability (stage 3), status, in_start, in_end
     */
    public static function activities(DatasetSpec $spec, DateTimeImmutable $now, Randomizer $random, LocationFactory $locations): array
    {
        $stage2 = $spec->stage2;
        $activities = [];
        /** @var array<string, int> $sequences region => last activity number */
        $sequences = [];

        if ($stage2 !== null) {
            foreach (self::stage2RegionOrder($spec) as $region) {
                $zone = new DateTimeZone($region->timeZone);
                $days = self::workingDays($spec, $region, $now);
                $perDay = (int) round($stage2->jobsPerResourcePerDay * $spec->servingResources($region->id));
                $byType = self::largestRemainder($perDay * count($days), $stage2->mix);
                $skills = self::sharedSkills($spec->servingResourceList($region->id));
                $sequences[$region->id] ??= 0;

                foreach ($days as $dayIndex => $day) {
                    foreach ($byType as $type => $count) {
                        $onDay = $type === self::EMERGENCY
                            ? ($dayIndex === 0 ? $count : 0)
                            : self::largestRemainder($count, array_fill(0, count($days), 1))[$dayIndex];

                        for ($i = 0; $i < $onDay; $i++) {
                            $activities[] = self::activity($spec, $stage2, $region, $zone, $now, $day, $type, ++$sequences[$region->id], $skills, $random, $locations);
                        }
                    }
                }
            }
        }

        if ($spec->stage3 !== null) {
            foreach ($spec->regions as $region) {
                $sequences[$region->id] ??= 0;

                $appointments = self::appointments($spec, $region, $now, $sequences[$region->id], $random, $locations);
                array_push($activities, ...$appointments);
                $sequences[$region->id] += count($appointments);
            }
        }

        if ($spec->stage4 !== null) {
            foreach ($spec->regions as $region) {
                $sequences[$region->id] ??= 0;
                $projects = self::projects($spec, $region, $now, $sequences[$region->id], $random, $locations);
                array_push($activities, ...$projects);
                $sequences[$region->id] += count($projects);
            }
        }

        return $activities;
    }

    /**
     * Whether a region can take projects, and which skills a project there may
     * carry: the required ones need two or more serving holders, else the
     * region gets none; each optional skill is possible only if the full set
     * with it still has two or more.
     *
     * @return array{required: list<string>, optional: list<string>}|null null when the region gets no projects
     */
    public static function projectSkills(DatasetSpec $spec, RegionSpec $region): ?array
    {
        $stage4 = $spec->stage4;
        $serving = $spec->servingResourceList($region->id);
        $holders = static fn (array $skills): int => count(array_filter($serving, static fn ($resource): bool => array_diff($skills, $resource->skills) === []));

        if ($holders($stage4->requiredSkills) < 2) {
            return null;
        }

        $optional = array_values(array_filter(array_keys($stage4->optionalSkills), static fn (string $skill): bool => $holders([...$stage4->requiredSkills, $skill]) >= 2));

        return ['required' => $stage4->requiredSkills, 'optional' => $optional];
    }

    /** @return list<array<string, mixed>> the region's stage 4 projects, numbered on from $sequence */
    private static function projects(DatasetSpec $spec, RegionSpec $region, DateTimeImmutable $now, int $sequence, Randomizer $random, LocationFactory $locations): array
    {
        $stage4 = $spec->stage4;
        $skills = self::projectSkills($spec, $region);
        $count = $stage4->perRegionPerWeek[$region->kind] * $stage4->weeks;

        if ($skills === null || $count === 0) {
            return [];
        }

        // Start days: the next weeks x 5 weekdays after today, in the region.
        $zone = new DateTimeZone($region->timeZone);
        $days = [];

        for ($date = ShiftFactory::dayZero($now, $zone)->modify('+1 day'); count($days) < $stage4->weeks * 5; $date = $date->modify('+1 day')) {
            if ((int) $date->format('N') <= 5) {
                $days[] = $date;
            }
        }

        $weights = array_map(static fn (DateTimeImmutable $day): float => $stage4->startDayWeights[(int) $day->format('N')] ?? 0.0, $days);
        $projects = [];

        for ($i = 0; $i < $count; $i++) {
            $projects[] = self::project($spec, $stage4, $region, $zone, $now, $days[self::weightedIndex($weights, $random)], $skills, ++$sequence, $random, $locations);
        }

        return $projects;
    }

    /**
     * @param  array{required: list<string>, optional: list<string>}  $skills
     * @return array<string, mixed>
     */
    private static function project(DatasetSpec $spec, Stage4Spec $stage4, RegionSpec $region, DateTimeZone $zone, DateTimeImmutable $now, DateTimeImmutable $day, array $skills, int $sequence, Randomizer $random, LocationFactory $locations): array
    {
        $id = sprintf('A-%s-%06d', $region->id, $sequence);
        $location = $locations->activity(sprintf('L-%s-%05d', $region->id, $sequence), $region);
        $minutes = self::drawMinutes($stage4->duration, $random);

        // Created as stage 2 work is: up to 72 hours before now, on the minute, never before midnight of day -1.
        $created = max($now->modify('-'.$random->getInt(0, self::CREATED_UP_TO_HOURS * 60).' minutes'), ShiftFactory::dayZero($now, $zone)->modify('-1 day'));
        $createdUtc = ShiftFactory::utc($created);

        // Each optional skill drawn by its probability, kept only where the region can staff the full set.
        $jobSkills = $skills['required'];

        foreach ($stage4->optionalSkills as $skill => $p) {
            if ($random->nextFloat() < $p && in_array($skill, $skills['optional'], true)) {
                $jobSkills[] = $skill;
            }
        }

        $inStart = self::at($day, self::SLA_START);
        $inEnd = self::at($inStart->add(new DateInterval($stage4->sla['in']))->setTime(0, 0), self::SLA_END);
        $outEnd = $inEnd->add(new DateInterval($stage4->sla['out']));
        $startBased = $stage4->sla['start_based'] ? 'true' : 'false';
        $slas = [
            self::sla($spec->activityTypes[Stage4Spec::TYPE]['primary_sla_type'], $id, 1, $inStart, $inEnd),
            self::sla('OUT_SLA', $id, 2, $inEnd, $outEnd),
        ];

        return [
            'stage' => 4,
            'region' => $region->id,
            'type' => Stage4Spec::TYPE,
            'date' => $day->format('Y-m-d'),
            'kind' => null,
            'location' => $location,
            'location_region' => ['region_id' => $region->id, 'location_id' => $location['id']],
            'activity' => [
                'id' => $id,
                'activity_class_id' => 'CALL',
                'activity_type_id' => Stage4Spec::TYPE,
                'location_id' => $location['id'],
                'description' => Stage4Spec::TYPE." : {$location['address_line1']}",
                'duration' => self::isoMinutes($minutes),
                'base_value' => (string) (int) round(1000 * $minutes / 60),
                'split_allowed' => 'true',
                'split_minimum_priority' => (string) $stage4->splitMinimumPriority,
                'appointed' => 'false',
                'do_on_site' => 'false',
                'auto_duration_enabled' => 'false',
                'remote_fulfillment' => 'false',
                'date_time_created' => $createdUtc,
                'date_time_open' => $createdUtc,
                'memo' => '.',
            ],
            'skills' => array_map(static fn (string $skill): array => ['skill_id' => $skill, 'activity_id' => $id, 'share_with_corequisites' => 'false'], $jobSkills),
            'skill' => null,
            'slas' => array_map(static fn (array $sla): array => array_replace($sla, ['start_based' => $startBased]), $slas),
            'availability' => null,
            'status' => [
                'activity_id' => $id,
                'status_id' => '0',
                'visit_id' => '1',
                'fixed' => 'false',
                'date_time_status' => $createdUtc,
                'date_time_stamp' => $createdUtc,
            ],
            'minutes' => $minutes,
            'in_start' => $inStart,
            'in_end' => $inEnd,
        ];
    }

    /** @param list<float> $weights an index drawn in proportion to its weight */
    private static function weightedIndex(array $weights, Randomizer $random): int
    {
        $pick = $random->nextFloat() * array_sum($weights);

        foreach ($weights as $index => $weight) {
            if ($pick < $weight) {
                return $index;
            }

            $pick -= $weight;
        }

        return array_key_last(array_filter($weights, static fn (float $weight): bool => $weight > 0));
    }

    /**
     * Stage 2's region order: regions with home resources in spec order, then
     * those served only through `extra_regions`.
     *
     * @return list<RegionSpec>
     */
    private static function stage2RegionOrder(DatasetSpec $spec): array
    {
        $hasHomes = static fn (RegionSpec $region): bool => array_filter($spec->resources, static fn ($resource): bool => $resource->region === $region->id) !== [];

        return [...array_filter($spec->regions, $hasHomes), ...array_filter($spec->regions, static fn (RegionSpec $region): bool => ! $hasHomes($region))];
    }

    /**
     * The region's appointment days: today and the following weekdays, a day
     * counting only if one of the region's template slots is still open on it
     * (today: starting an hour or more after now), `working_days` of them.
     *
     * @return list<array{day: DateTimeImmutable, slots: array<string, list<array{start: DateTimeImmutable, end: DateTimeImmutable}>>}> slots open per template kind
     */
    public static function appointmentDays(DatasetSpec $spec, RegionSpec $region, DateTimeImmutable $now): array
    {
        $stage3 = $spec->stage3;
        $date = ShiftFactory::dayZero($now, new DateTimeZone($region->timeZone));
        $earliest = $now->modify('+'.Stage3Spec::TODAY_LEAD_MINUTES.' minutes');
        $days = [];

        for ($guard = 0; count($days) < $stage3->workingDays && $guard < 366; $guard++, $date = $date->modify('+1 day')) {
            $weekday = (int) $date->format('N');

            if ($weekday > 5) {
                continue;
            }

            $slots = [];

            foreach ($region->appointmentTemplates as $kind) {
                $template = $spec->appointmentTemplateKinds[$kind];

                if (($stage3->kindWeights[$kind] ?? 0) <= 0 || $template['day_pattern'][$weekday - 1] !== 'Y') {
                    continue;
                }

                foreach ($template['items'] as $item) {
                    $start = $date->add(new DateInterval($item['start']));

                    if ($start >= $earliest) {
                        $slots[$kind][] = ['start' => $start, 'end' => $date->add(new DateInterval($item['end']))];
                    }
                }
            }

            if ($slots !== []) {
                $days[] = ['day' => $date, 'slots' => $slots];
            }
        }

        return $days;
    }

    /** @return list<array<string, mixed>> the region's stage 3 appointments, numbered on from $sequence */
    private static function appointments(DatasetSpec $spec, RegionSpec $region, DateTimeImmutable $now, int $sequence, Randomizer $random, LocationFactory $locations): array
    {
        $stage3 = $spec->stage3;
        $perDay = (int) round($stage3->appointmentsPerResourcePerDay * $spec->servingResources($region->id));
        $byType = self::largestRemainder($perDay, $stage3->mix);
        $skills = self::sharedSkills($spec->servingResourceList($region->id));
        $appointments = [];

        foreach (self::appointmentDays($spec, $region, $now) as ['day' => $day, 'slots' => $slots]) {
            foreach ($byType as $type => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $appointments[] = self::appointment($spec, $stage3, $region, $now, $day, $slots, $type, ++$sequence, $skills, $random, $locations);
                }
            }
        }

        return $appointments;
    }

    /**
     * @param  array<string, list<array{start: DateTimeImmutable, end: DateTimeImmutable}>>  $slots
     * @param  list<string>  $skills
     * @return array<string, mixed>
     */
    private static function appointment(DatasetSpec $spec, Stage3Spec $stage3, RegionSpec $region, DateTimeImmutable $now, DateTimeImmutable $day, array $slots, string $type, int $sequence, array $skills, Randomizer $random, LocationFactory $locations): array
    {
        // The kind by its weight among the kinds still open that day, then a slot of it, uniformly.
        $kinds = array_intersect_key($stage3->kindWeights, $slots);
        $pick = $random->nextFloat() * array_sum($kinds);
        $kind = array_key_last($kinds);

        foreach ($kinds as $candidate => $weight) {
            if ($pick < $weight) {
                $kind = $candidate;
                break;
            }

            $pick -= $weight;
        }

        $slot = $slots[$kind][$random->getInt(0, count($slots[$kind]) - 1)];

        $id = sprintf('A-%s-%06d', $region->id, $sequence);
        $location = $locations->activity(sprintf('L-%s-%05d', $region->id, $sequence), $region);
        $minutes = self::drawMinutes($stage3->durations[$type], $random);

        // Booked U(booked_ago) before the slot starts, on the minute, but never after now.
        $created = min($now, $slot['start']->modify('-'.$random->getInt($stage3->bookedAgo['min'], $stage3->bookedAgo['max']).' minutes'));
        $createdUtc = ShiftFactory::utc($created);
        $skill = self::drawSkill($stage3->skills[$type], $skills, $id, $random);
        $start = ShiftFactory::utc($slot['start']);
        $end = ShiftFactory::utc($slot['end']);

        return [
            'stage' => 3,
            'region' => $region->id,
            'type' => $type,
            'date' => $day->format('Y-m-d'),
            'kind' => $kind,
            'location' => $location,
            'location_region' => ['region_id' => $region->id, 'location_id' => $location['id']],
            'activity' => [
                'id' => $id,
                'activity_class_id' => 'CALL',
                'activity_type_id' => $type,
                'location_id' => $location['id'],
                'description' => "{$type} : {$location['address_line1']}",
                'duration' => self::isoMinutes($minutes),
                'base_value' => (string) (int) round(1000 * $minutes / 60),
                'split_allowed' => 'false',
                'appointed' => 'true',
                'do_on_site' => 'false',
                'auto_duration_enabled' => 'false',
                'remote_fulfillment' => 'false',
                'date_time_created' => $createdUtc,
                'date_time_open' => $createdUtc,
                'memo' => '.',
            ],
            'skill' => $skill,
            'slas' => [self::sla($spec->activityTypes[$type]['primary_sla_type'], $id, 1, $slot['start'], $slot['end'])],
            'availability' => [
                'id' => "AP-{$id}-1",
                'datetime_start' => $start,
                'datetime_end' => $end,
                'activity_id' => $id,
                'start_based' => 'true',
                'availability_type' => 'ACCEPTED_APPOINTMENT',
            ],
            'status' => [
                'activity_id' => $id,
                'status_id' => '0',
                'visit_id' => '1',
                'fixed' => 'false',
                'date_time_status' => $createdUtc,
                'date_time_stamp' => $createdUtc,
                'date_time_earliest' => $start,
            ],
            'in_start' => $slot['start'],
            'in_end' => $slot['end'],
        ];
    }

    /** @param array{min: int, max: int} $range minutes; the draw rounded to the nearest 15 */
    private static function drawMinutes(array $range, Randomizer $random): int
    {
        $minutes = (int) (round($random->getInt($range['min'], $range['max']) / self::DURATION_STEP_MINUTES) * self::DURATION_STEP_MINUTES);

        return max(self::DURATION_STEP_MINUTES, $minutes);
    }

    /**
     * With probability p, one skill from the pool that at least two serving resources hold.
     *
     * @param  array{pool: list<string>, p: float}  $rule
     * @param  list<string>  $skills
     * @return array<string, string>|null
     */
    private static function drawSkill(array $rule, array $skills, string $activityId, Randomizer $random): ?array
    {
        $pool = array_values(array_intersect($rule['pool'], $skills));

        if ($random->nextFloat() < $rule['p'] && $pool !== []) {
            return ['skill_id' => $pool[$random->getInt(0, count($pool) - 1)], 'activity_id' => $activityId, 'share_with_corequisites' => 'false'];
        }

        return null;
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
     * @return array<string, mixed> one activity, as activities() lists them
     */
    private static function activity(DatasetSpec $spec, Stage2Spec $stage2, RegionSpec $region, DateTimeZone $zone, DateTimeImmutable $now, DateTimeImmutable $day, string $type, int $sequence, array $skills, Randomizer $random, LocationFactory $locations): array
    {
        $id = sprintf('A-%s-%06d', $region->id, $sequence);
        $location = $locations->activity(sprintf('L-%s-%05d', $region->id, $sequence), $region);

        $minutes = self::drawMinutes($stage2->durations[$type], $random);
        $value = (int) round(1000 * $minutes / 60 * ($type === self::EMERGENCY ? self::EMERGENCY_VALUE_FACTOR : 1));

        // Created up to 72 hours before now, on the minute, but never before midnight of day -1 in the region.
        $created = $now->modify('-'.$random->getInt(0, self::CREATED_UP_TO_HOURS * 60).' minutes');
        $earliest = ShiftFactory::dayZero($now, $zone)->modify('-1 day');
        $created = max($created, $earliest);
        $createdUtc = ShiftFactory::utc($created);

        $skill = self::drawSkill($stage2->skills[$type], $skills, $id, $random);

        // Priority 1 from 08:00 local on the target day (or now, if later); its end snapped to 17:00 local.
        $inStart = max($now, self::at($day, self::SLA_START))->setTimezone($zone);
        $window = $stage2->slaWindows[$type];
        $inEnd = $window['in'] === Stage2Spec::SAME_DAY
            ? self::at($day, self::SLA_END)
            : self::at($inStart->add(new DateInterval($window['in']))->setTime(0, 0), self::SLA_END);
        $outEnd = $inEnd->add(new DateInterval($window['out']));
        $primary = $spec->activityTypes[$type]['primary_sla_type'];

        return [
            'stage' => 2,
            'region' => $region->id,
            'type' => $type,
            'date' => $day->format('Y-m-d'),
            'kind' => null,
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
            'availability' => null,
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
     * @param  list<Spec\ResourceSpec>  $residents  the region's serving resources
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
