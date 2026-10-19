<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;
use Throwable;

/** Turns an appointment request's offers into the agenda the booking page shows. */
final class AgendaPresenter
{
    public function __construct(private string $timeZone) {}

    /**
     * The agenda as a day grid: one column per day (Y-m-d in $timeZone, ascending) with each offer as a block
     * positioned against a shared hour axis. Rows are allOfferValues merged with validOffers by id, 'valid' false
     * for any id not valid; call centre mode drops the invalid rows first. Rows without a window are left out.
     *
     * @return array{axis: array{startHour?: int, endHour?: int, labels?: list<string>}, columns: array<string, array{label: string, blocks: list<array>}>}
     */
    public function grid(array $offers, string $mode, string $timeZone): array
    {
        $valid = [];
        foreach ((array) data_get($offers, 'validOffers', []) as $offer) {
            $valid[(string) data_get($offer, 'id')] = (array) $offer;
        }

        $rows = [];
        foreach ((array) data_get($offers, 'allOfferValues', []) as $offer) {
            $id = (string) data_get($offer, 'id');
            $rows[$id] = isset($valid[$id]) ? [...(array) $offer, ...$valid[$id], 'valid' => true] : [...(array) $offer, 'valid' => false];
        }
        foreach ($valid as $id => $offer) {
            $rows[$id] ??= [...$offer, 'valid' => true];
        }
        if ($mode === 'callcentre') {
            $rows = array_filter($rows, static fn (array $row): bool => $row['valid']);
        }

        // Place each row: its day column and its start and end as minutes from that day's midnight.
        $bestId = data_get($offers, 'bestOffer.id');
        $placed = [];
        foreach ($rows as $row) {
            $start = self::utcMoment(data_get($row, 'windowStartDatetime'))?->setTimezone($timeZone);
            $end = self::utcMoment(data_get($row, 'windowEndDatetime'))?->setTimezone($timeZone);
            if ($start === null || $end === null) {
                continue;
            }
            $from = $start->hour * 60 + $start->minute;
            $placed[] = [
                'row' => $row,
                'day' => $start->format('Y-m-d'),
                'label' => $start->format('D M j'),
                'from' => $from,
                'to' => $from + max(0, (int) $start->diffInMinutes($end)),
                'times' => $start->format('g:i A').' to '.$end->format('g:i A'),
                'best' => (bool) data_get($row, 'isBestOffer') || ($row['valid'] && $bestId !== null && (string) $bestId === (string) data_get($row, 'id')),
            ];
        }
        if ($placed === []) {
            return ['axis' => [], 'columns' => []];
        }

        // Whole hours from the earliest start to the latest end, no later than midnight; blocks past it are clipped.
        $startHour = intdiv(min(array_column($placed, 'from')), 60);
        $endHour = min(24, max($startHour + 1, (int) ceil(max(array_column($placed, 'to')) / 60)));
        $span = ($endHour - $startHour) * 60;

        usort($placed, static fn (array $a, array $b): int => [$a['day'], $a['from'], $a['to']] <=> [$b['day'], $b['from'], $b['to']]);

        $columns = [];
        foreach ($placed as $block) {
            $columns[$block['day']]['label'] ??= $block['label'];
            $columns[$block['day']]['blocks'][] = $this->block($block, $startHour, $span, $timeZone);
        }
        foreach ($columns as $day => $column) {
            $columns[$day]['blocks'] = self::withLanes($column['blocks'], array_values(array_filter($placed, static fn (array $p): bool => $p['day'] === $day)));
        }

        return [
            'axis' => [
                'startHour' => $startHour,
                'endHour' => $endHour,
                'labels' => array_map(static fn (int $hour): string => (($hour % 12) ?: 12).($hour % 24 < 12 ? ' AM' : ' PM'), range($startHour, $endHour)),
            ],
            'columns' => $columns,
        ];
    }

    /** One placed row as the block the grid renders: text fields only, top and height as percent of the axis span. */
    private function block(array $placed, int $startHour, int $span, string $timeZone): array
    {
        $row = $placed['row'];
        $top = max(0.0, round(($placed['from'] - $startHour * 60) / $span * 100, 1));
        $value = data_get($row, 'offerValue');
        $allocated = self::utcMoment(data_get($row, 'prospectiveAllocationStart'))?->setTimezone($timeZone);
        $resource = data_get($row, 'prospectiveResourceId');

        return [
            'id' => data_get($row, 'id'),
            'valid' => $row['valid'],
            'best' => $placed['best'],
            'times' => $placed['times'],
            'top' => $top,
            'height' => min(round(($placed['to'] - $placed['from']) / $span * 100, 1), round(100 - $top, 1)),
            'lane' => 0,
            'lanes' => 1,
            'value' => is_numeric($value) ? (string) (int) round((float) $value) : null,
            'resource' => filled($resource) ? (string) $resource : 'no resource',
            'allocated' => $allocated === null ? null : $allocated->format($allocated->format('Y-m-d') === $placed['day'] ? 'g:i A' : 'M j g:i A'),
            'planId' => self::text(data_get($row, 'planId')),
            'reason' => self::text(data_get($row, 'reasonTypeId')),
            'offerSpan' => self::span(data_get($row, 'offerStartDatetime'), data_get($row, 'offerEndDatetime'), $timeZone),
            'window' => self::span(data_get($row, 'windowStartDatetime'), data_get($row, 'windowEndDatetime'), $timeZone, iso: true),
        ];
    }

    /**
     * Side by side placement within one column: blocks that overlap (directly or through a chain) form a cluster;
     * each block takes the lowest lane free at its start, and every block in a cluster shares its lane count.
     * $placed is in the same order as $blocks (by start, then end).
     */
    private static function withLanes(array $blocks, array $placed): array
    {
        $cluster = [];
        $clusterEnd = -1;
        $laneEnds = [];
        $flush = static function () use (&$blocks, &$cluster, &$laneEnds): void {
            foreach ($cluster as $index) {
                $blocks[$index]['lanes'] = count($laneEnds);
            }
            $cluster = [];
            $laneEnds = [];
        };

        foreach ($placed as $index => $p) {
            if ($cluster !== [] && $p['from'] >= $clusterEnd) {
                $flush();
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $p['from']) {
                $lane++;
            }
            $laneEnds[$lane] = $p['to'];
            $blocks[$index]['lane'] = $lane;
            $cluster[] = $index;
            $clusterEnd = max($clusterEnd, $p['to']);
        }
        $flush();

        return $blocks;
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

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** "8:00 AM to 12:00 PM" in the zone, or with iso the full datetimes with their offset; null when either end is missing. */
    private static function span(mixed $start, mixed $end, string $timeZone, bool $iso = false): ?string
    {
        $from = self::utcMoment($start)?->setTimezone($timeZone);
        $to = self::utcMoment($end)?->setTimezone($timeZone);
        if ($from === null || $to === null) {
            return null;
        }

        if (! $iso) {
            return $from->format('g:i A').' to '.$to->format('g:i A');
        }

        // The full window, readable: "Tue Oct 20, 8:00 AM to 12:00 PM America/Toronto EDT"; the end carries its day only when it differs.
        $sameDay = $from->toDateString() === $to->toDateString();
        $abbreviation = $from->format('T');
        $zone = $abbreviation === $timeZone ? $timeZone : $timeZone.' '.$abbreviation;

        return $from->format('D M j, g:i A').' to '.$to->format($sameDay ? 'g:i A' : 'D M j, g:i A').' '.$zone;
    }
}
