<?php

use App\Support\Booking\BookingWindow;
use Carbon\CarbonImmutable;

function bookingWindow(array $args = []): BookingWindow
{
    return new BookingWindow(
        'America/Toronto',
        $args['windowStart'] ?? null,
        $args['surveyEnd'] ?? null,
        $args['cursor'] ?? null,
        $args['cursorBack'] ?? null,
        $args['requestedFromUtc'] ?? null,
        CarbonImmutable::parse('2026-10-19 12:00', 'America/Toronto'),
    );
}

it('spans 21 days from today when there is no window start', function () {
    $days = bookingWindow()->days();

    expect($days)->toHaveCount(21)
        ->and($days[0])->toBe('2026-10-19')
        ->and($days[20])->toBe('2026-11-08');
});

it('spans 21 days from the window start', function () {
    $days = bookingWindow(['windowStart' => '2026-11-09'])->days();

    expect($days[0])->toBe('2026-11-09')
        ->and($days[20])->toBe('2026-11-29');
});

it('month next starts where the survey ended, in the zone', function () {
    expect(bookingWindow(['surveyEnd' => '2026-11-06T05:00:00Z'])->nextMonthStart())->toBe('2026-11-06');
});

it('month next without a survey end starts 21 days after the first day', function () {
    expect(bookingWindow()->nextMonthStart())->toBe('2026-11-09');
});

it('month previous steps 21 days back and floors at today', function () {
    $window = bookingWindow(['windowStart' => '2026-11-30']);
    expect($window->previousMonthStart())->toBe('2026-11-09')
        ->and($window->canGoPreviousMonth())->toBeTrue();

    expect(bookingWindow(['windowStart' => '2026-11-01'])->previousMonthStart())->toBeNull();

    $today = bookingWindow(['windowStart' => '2026-10-19']);
    expect($today->canGoPreviousMonth())->toBeFalse()
        ->and($today->previousMonthStart())->toBeNull();

    expect(bookingWindow()->canGoPreviousMonth())->toBeFalse();
});

it('agenda next picks up from the cursor as a UTC instant', function () {
    $from = bookingWindow(['cursor' => '2026-10-22T16:00:00-04:00'])->nextAgendaFrom();

    expect($from->equalTo(CarbonImmutable::parse('2026-10-22T20:00:00Z')))->toBeTrue()
        ->and(BookingWindow::templateDatetime($from))->toBe('2026-10-22T20:00:00')
        ->and(bookingWindow()->nextAgendaFrom())->toBeNull();
});

it('agenda previous steps back by the span of the reply', function () {
    $window = bookingWindow(['cursorBack' => '2026-10-25T12:00:00Z', 'cursor' => '2026-10-26T00:00:00Z']);

    expect($window->previousAgendaFrom()->equalTo(CarbonImmutable::parse('2026-10-25T00:00:00Z')))->toBeTrue()
        ->and($window->canGoPreviousAgenda())->toBeTrue();
});

it('agenda previous floors at now and is off once it has floored', function () {
    $reply = ['cursorBack' => '2026-10-19T17:00:00Z', 'cursor' => '2026-10-19T22:00:00Z'];

    expect(bookingWindow($reply)->previousAgendaFrom()->equalTo(CarbonImmutable::parse('2026-10-19T16:00:00Z')))->toBeTrue()
        ->and(bookingWindow([...$reply, 'requestedFromUtc' => '2026-10-19T16:00:00'])->canGoPreviousAgenda())->toBeFalse()
        ->and(bookingWindow([...$reply, 'requestedFromUtc' => '2026-10-19T17:00:00'])->canGoPreviousAgenda())->toBeTrue();
});

it('agenda previous with a zero span is the earliest start itself', function () {
    $window = bookingWindow(['cursorBack' => '2026-10-25T12:00:00Z', 'cursor' => '2026-10-25T12:00:00Z']);

    expect($window->previousAgendaFrom()->equalTo(CarbonImmutable::parse('2026-10-25T12:00:00Z')))->toBeTrue();
});

it('agenda previous needs both ends of the reply', function () {
    $window = bookingWindow(['cursorBack' => '2026-10-25T12:00:00Z']);

    expect($window->previousAgendaFrom())->toBeNull()
        ->and($window->canGoPreviousAgenda())->toBeFalse();
});

it('reads offsetless offer times as UTC', function () {
    $offers = [
        'validOffers' => [['windowStartDatetime' => '2026-10-22T02:00:00', 'windowEndDatetime' => '2026-10-22T06:00:00']],
        'allOfferValues' => [['windowStartDatetime' => '2026-10-21T23:00:00', 'windowEndDatetime' => '2026-10-22T10:00:00']],
    ];
    $window = bookingWindow();

    expect(BookingWindow::earliestWindowStart($offers))->toBe('2026-10-21T23:00:00+00:00')
        ->and(BookingWindow::latestWindowEnd($offers))->toBe('2026-10-22T10:00:00+00:00')
        ->and($window->offerDay(['windowStartDatetime' => '2026-10-22T02:00:00']))->toBe('2026-10-21')
        ->and($window->offerDay(['windowStartDatetime' => '']))->toBeNull()
        ->and($window->offerDay([]))->toBeNull();
});

it('turns the picker wall time into UTC', function () {
    config(['app.timezone' => 'America/Toronto']);

    expect(BookingWindow::inputDatetimeUtc('2026-10-22 09:30'))->toBe('2026-10-22T13:30:00')
        ->and(BookingWindow::inputDatetimeUtc(''))->toBeNull()
        ->and(BookingWindow::inputDatetimeUtc(null))->toBeNull()
        ->and(BookingWindow::inputDatetimeUtc('not a date'))->toBeNull();
});
