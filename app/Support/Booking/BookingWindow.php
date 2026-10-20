<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The window and cursor maths of the booking page: which 21 days the month view shows, where Next and Previous
 * go in the month and the agenda, and how offer times and the picker's wall time become days and UTC instants.
 * Offsetless times from services are UTC.
 */
final class BookingWindow
{
    public const int DAYS = 21;

    private readonly CarbonImmutable $now;

    /**
     * @param  string  $timeZone  the zone the month and agenda are based in
     * @param  ?string  $windowStart  Y-m-d in $timeZone, null means today
     * @param  ?string  $surveyEnd  ISO 8601 UTC instant where the last survey ended
     * @param  ?string  $cursor  latest window end of the open agenda reply
     * @param  ?string  $cursorBack  earliest window start of the open agenda reply
     * @param  ?string  $requestedFromUtc  Y-m-d\TH:i:s the open reply was asked from
     * @param  ?CarbonImmutable  $now  the clock, injected for tests
     */
    public function __construct(
        private readonly string $timeZone,
        private readonly ?string $windowStart = null,
        private readonly ?string $surveyEnd = null,
        private readonly ?string $cursor = null,
        private readonly ?string $cursorBack = null,
        private readonly ?string $requestedFromUtc = null,
        ?CarbonImmutable $now = null,
    ) {
        $this->now = $now ?? CarbonImmutable::now();
    }

    /** The first day of the month view: the start of windowStart, or of today, in the booking zone. */
    public function firstDay(): CarbonImmutable
    {
        return filled($this->windowStart)
            ? CarbonImmutable::parse($this->windowStart, $this->timeZone)->startOfDay()
            : $this->now->setTimezone($this->timeZone)->startOfDay();
    }

    /**
     * The 21 calendar days of the month view, as Y-m-d.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $first = $this->firstDay();

        return array_map(static fn (int $i): string => $first->addDays($i)->toDateString(), range(0, self::DAYS - 1));
    }

    /** Month Next: where the last survey ended in the zone, or three weeks after the first day. */
    public function nextMonthStart(): string
    {
        $from = AgendaPresenter::utcMoment($this->surveyEnd)?->setTimezone($this->timeZone);

        return ($from ?? $this->firstDay()->addDays(self::DAYS))->toDateString();
    }

    /** Month Previous: three weeks back from the first day; null (today) when that is not after today. */
    public function previousMonthStart(): ?string
    {
        $today = $this->now->setTimezone($this->timeZone)->startOfDay();
        $from = $this->firstDay()->subDays(self::DAYS);

        return $from->gt($today) ? $from->toDateString() : null;
    }

    /** Month Previous is off when the window already starts today (or earlier). */
    public function canGoPreviousMonth(): bool
    {
        return filled($this->windowStart)
            && $this->firstDay()->gt($this->now->setTimezone($this->timeZone)->startOfDay());
    }

    /** Agenda Next picks up where the open reply ended, as a UTC instant; null without a reply. */
    public function nextAgendaFrom(): ?CarbonImmutable
    {
        return AgendaPresenter::utcMoment($this->cursor);
    }

    /**
     * Where agenda Previous would start: the reply's span (latest end minus earliest start) before its earliest
     * start, never before now, as a UTC instant to the second. Null without both ends of a reply.
     */
    public function previousAgendaFrom(): ?CarbonImmutable
    {
        $earliest = AgendaPresenter::utcMoment($this->cursorBack);
        $latest = AgendaPresenter::utcMoment($this->cursor);
        if ($earliest === null || $latest === null) {
            return null;
        }

        $from = $earliest->subSeconds((int) $earliest->diffInSeconds($latest, true))->utc()->startOfSecond();
        $now = $this->now->utc()->startOfSecond();

        return $from->lt($now) ? $now : $from;
    }

    /**
     * Agenda Previous is off when the reply had no offers, or when Previous would ask from now and the open request
     * already began at now or earlier: there is nothing earlier to show.
     */
    public function canGoPreviousAgenda(): bool
    {
        $from = $this->previousAgendaFrom();
        if ($from === null) {
            return false;
        }

        $now = $this->now->utc()->startOfSecond();
        $requestedFrom = AgendaPresenter::utcMoment($this->requestedFromUtc);

        return ! ($from->eq($now) && $requestedFrom !== null && $requestedFrom->lte($now));
    }

    /** The calendar day of an offer's window start in the booking zone; offsetless times are UTC. */
    public function offerDay(mixed $offer): ?string
    {
        $start = data_get($offer, 'windowStartDatetime');
        if (! is_string($start) || $start === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($start, 'UTC')->setTimezone($this->timeZone)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** The earliest window start across every offer of a services reply, valid or not, as a UTC instant. */
    public static function earliestWindowStart(array $offers): ?string
    {
        $earliest = null;
        foreach (self::allOffers($offers) as $offer) {
            $start = AgendaPresenter::utcMoment(data_get($offer, 'windowStartDatetime'));
            if ($start !== null && ($earliest === null || $start->lt($earliest))) {
                $earliest = $start;
            }
        }

        return $earliest?->toIso8601String();
    }

    /** The latest window end across every offer of a services reply, valid or not, as a UTC instant. */
    public static function latestWindowEnd(array $offers): ?string
    {
        $latest = null;
        foreach (self::allOffers($offers) as $offer) {
            $end = AgendaPresenter::utcMoment(data_get($offer, 'windowEndDatetime'));
            if ($end !== null && ($latest === null || $end->gt($latest))) {
                $latest = $end;
            }
        }

        return $latest?->toIso8601String();
    }

    /** A UTC instant in the form services reads for appointmentTemplateDatetime: Y-m-d\TH:i:s, no offset. */
    public static function templateDatetime(CarbonImmutable $from): string
    {
        return $from->utc()->format('Y-m-d\\TH:i:s');
    }

    /**
     * The picker's wall time read in the app zone, sent as UTC without an offset: services validates
     * inputDatetime as Y-m-d\TH:i:s and reads it as UTC. Null when blank or unparseable.
     */
    public static function inputDatetimeUtc(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->utc()->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<mixed> */
    private static function allOffers(array $offers): array
    {
        return [...(array) data_get($offers, 'validOffers', []), ...(array) data_get($offers, 'allOfferValues', [])];
    }
}
