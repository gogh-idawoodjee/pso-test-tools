<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\RegionSpec;
use App\Support\DatasetBuilder\Spec\ResourceSpec;
use App\Support\DatasetBuilder\Spec\ResourceTypeSpec;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One Shift and its Shift_Break per working day, from day -7 to day
 * `horizon_days`. Each shift is built from the type's local HH:MM in the
 * region's own IANA zone and only then turned into UTC, so a DST change moves
 * the UTC times and never the local ones. Monday shifts (local) get
 * split_priority 0, which keeps cross-shift splits off Mondays; every other
 * shift gets 1.
 */
class ShiftFactory
{
    public const int DAYS_BEFORE = 7;

    /**
     * @return list<array{shift: array<string, string>, break: array<string, string>, local_start: DateTimeImmutable, local_end: DateTimeImmutable}>
     */
    public static function shifts(
        ResourceSpec $resource,
        ResourceTypeSpec $type,
        RegionSpec $region,
        DateTimeImmutable $now,
        int $horizonDays,
    ): array {
        $zone = new DateTimeZone($region->timeZone);
        $dayZero = self::dayZero($now, $zone);
        $shifts = [];

        for ($day = -self::DAYS_BEFORE; $day <= $horizonDays; $day++) {
            $date = $dayZero->modify(sprintf('%+d days', $day));
            $weekday = (int) $date->format('N');

            if (! in_array($weekday, $type->shiftDays, true)) {
                continue;
            }

            $localStart = self::localTime($date, $type->shiftStart, $zone);
            $localEnd = self::localTime($date, $type->shiftEnd, $zone);
            $id = sprintf('%03d', $resource->sequence).$date->format('Ymd');

            $shifts[] = [
                'shift' => [
                    'id' => $id,
                    'resource_id' => $resource->id,
                    'start_datetime' => self::utc($localStart),
                    'end_datetime' => self::utc($localEnd),
                    'actual' => 'true',
                    'split_allowed' => 'true',
                    'split_priority' => $weekday === 1 ? '0' : '1',
                ],
                'break' => [
                    'id' => $id.'1',
                    'shift_id' => $id,
                    'duration' => $type->breakDuration,
                    'earliest_start_offset' => $type->breakEarliestOffset,
                    'latest_start_offset' => $type->breakLatestOffset,
                ],
                'local_start' => $localStart,
                'local_end' => $localEnd,
            ];
        }

        return $shifts;
    }

    /**
     * The whole span shifts can fall in, in the zone: midnight of day -7 to
     * midnight after day `horizon_days` (an extra region's availability).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function window(DateTimeImmutable $now, DateTimeZone $zone, int $horizonDays): array
    {
        $dayZero = self::dayZero($now, $zone);

        return [$dayZero->modify(sprintf('-%d days', self::DAYS_BEFORE)), $dayZero->modify(sprintf('+%d days', $horizonDays + 1))];
    }

    /** Calendar days in window(): day -7 to day `horizon_days`, both whole. */
    public static function windowDays(int $horizonDays): int
    {
        return self::DAYS_BEFORE + $horizonDays + 1;
    }

    /** Midnight of `now`'s calendar date in the zone: an 11 pm Toronto run is still today in Los Angeles. */
    public static function dayZero(DateTimeImmutable $now, DateTimeZone $zone): DateTimeImmutable
    {
        return $now->setTimezone($zone)->setTime(0, 0);
    }

    public static function utc(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    private static function localTime(DateTimeImmutable $date, string $clock, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' '.$clock.':00', $zone);
    }
}
