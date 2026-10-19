<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;
use Throwable;

/** Turns an appointment request's offers into the agenda the booking page shows. */
final class AgendaPresenter
{
    public function __construct(private string $timeZone) {}

    /**
     * The agenda rows for the mode, grouped by windowDayEnglish then period of day. Call centre: the valid
     * offers. Detail: allOfferValues merged with validOffers by id, 'valid' false for any id not valid.
     *
     * @return array<string, array<string, list<array>>>
     */
    public function groups(array $offers, string $mode): array
    {
        $valid = [];
        foreach ((array) data_get($offers, 'validOffers', []) as $offer) {
            $valid[(string) data_get($offer, 'id')] = (array) $offer;
        }

        $rows = [];
        if ($mode === 'detail') {
            foreach ((array) data_get($offers, 'allOfferValues', []) as $offer) {
                $id = (string) data_get($offer, 'id');
                $rows[$id] = isset($valid[$id]) ? [...(array) $offer, ...$valid[$id], 'valid' => true] : [...(array) $offer, 'valid' => false];
            }
        }
        foreach ($valid as $id => $offer) {
            $rows[$id] ??= [...$offer, 'valid' => true];
        }

        // allOfferValues carries no English day or times: derive them so invalid offers group with the valid ones.
        $rows = array_map(fn (array $row): array => $this->withEnglishWindow($row), $rows);

        $bestId = data_get($offers, 'bestOffer.id');
        $periods = array_fill_keys(['Morning', 'Midday', 'Afternoon', 'Evening'], []);
        $groups = [];
        foreach ($rows as $row) {
            $row['best'] = (bool) data_get($row, 'isBestOffer') || ($row['valid'] && $bestId !== null && (string) $bestId === (string) data_get($row, 'id'));
            $day = (string) (data_get($row, 'windowDayEnglish') ?: 'Unknown day');
            $groups[$day] ??= $periods;
            $groups[$day][self::periodOfDay(data_get($row, 'windowStartTime'))][] = $row;
        }

        return array_map(static fn (array $byPeriod): array => array_filter($byPeriod), $groups);
    }

    /** Morning before 12, Midday 12 to 15, Afternoon 15 to 18, Evening after, from "8:00 AM" style times. */
    public static function periodOfDay(mixed $startTime): string
    {
        $time = is_string($startTime) ? date_parse($startTime) : false;
        $hour = is_array($time) && is_int($time['hour']) ? $time['hour'] : 0;

        return match (true) {
            $hour < 12 => 'Morning',
            $hour < 15 => 'Midday',
            $hour < 18 => 'Afternoon',
            default => 'Evening',
        };
    }

    /** The slot length of the open request, from its first offer's window, e.g. "4 hours". */
    public function slotLength(array $offers): ?string
    {
        $offer = data_get($offers, 'allOfferValues.0') ?? data_get($offers, 'validOffers.0');
        $start = self::utcMoment(data_get($offer, 'windowStartDatetime'));
        $end = self::utcMoment(data_get($offer, 'windowEndDatetime'));

        return $start === null || $end === null || $end->lte($start) ? null : self::formatDuration((int) $start->diffInMinutes($end));
    }

    /** Fill windowDayEnglish, windowStartTime and windowEndTime from the window datetimes when missing. */
    public function withEnglishWindow(array $row): array
    {
        $start = self::utcMoment(data_get($row, 'windowStartDatetime'))?->setTimezone($this->timeZone);
        $end = self::utcMoment(data_get($row, 'windowEndDatetime'))?->setTimezone($this->timeZone);

        if ($start !== null) {
            $row['windowDayEnglish'] = filled(data_get($row, 'windowDayEnglish')) ? $row['windowDayEnglish'] : $start->format('D, M j, Y');
            $row['windowStartTime'] = filled(data_get($row, 'windowStartTime')) ? $row['windowStartTime'] : $start->format('g:i A');
        }
        if ($end !== null) {
            $row['windowEndTime'] = filled(data_get($row, 'windowEndTime')) ? $row['windowEndTime'] : $end->format('g:i A');
        }

        return $row;
    }

    /** Format a duration in minutes to a human-readable string, e.g. "1 hour 30 minutes". */
    public static function formatDuration(int $durationInMinutes): string
    {
        $hours = floor($durationInMinutes / 60);
        $minutes = $durationInMinutes % 60;

        $formattedDuration = $hours.' hour'.($hours != 1 ? 's' : '');

        if ($minutes > 0) {
            $formattedDuration .= ' '.$minutes.' minute'.($minutes !== 1 ? 's' : '');
        }

        return $formattedDuration;
    }

    /** An offer time as UTC; times with an offset keep it, offsetless times are UTC. */
    public static function utcMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
