<?php

use App\Support\Booking\BookingHistory;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T23:00:00Z'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function historyRow(array $extra = []): array
{
    return [
        'appointmentRequestId' => 'a3054d10-7f2e-4c1b-9d0a-000000000001',
        'createdAt' => '2026-10-19T22:41:00+00:00',
        'status' => 'DECLINED',
        'survey' => false,
        'blocking' => null,
        'appointmentTemplateId' => 'Half Day',
        'appointmentTemplateDatetime' => '2026-10-20T06:00:00+00:00',
        'appointmentTemplateDuration' => null,
        'slotUsageRuleId' => null,
        'offersReturned' => 7,
        'validOffers' => 7,
        'acceptedOfferId' => null,
        'acceptedWindowStart' => null,
        'acceptedWindowEnd' => null,
        'bookingSlaTypeId' => null,
        'previousSlaTypeId' => null,
        'appointedCheck' => null,
        'acceptDeclineDatetime' => null,
        'offerExpiryDatetime' => '2026-10-19T22:46:00+00:00',
        'requiredManualCleanup' => false,
        ...$extra,
    ];
}

it('reads a survey as a wide net that was released, in the booking zone', function () {
    $rows = BookingHistory::rows([historyRow([
        'appointmentRequestId' => 'a3054c28-0000-0000-0000-000000000000',
        'createdAt' => '2026-10-19T22:36:00+00:00',
        'survey' => true,
        'status' => 'UNACKNOWLEDGED',
        'appointmentTemplateId' => null,
        'appointmentTemplateDatetime' => '2026-10-19T06:00:00+00:00',
        'appointmentTemplateDuration' => 21,
        'offersReturned' => 9,
        'validOffers' => 6,
    ])], 'America/Regina');

    expect($rows)->toBe([[
        'when' => 'Oct 19, 4:36 PM',
        'what' => 'Survey (wide net), 21 days from Oct 19',
        'outcome' => 'Released',
        'offers' => '9 returned, 6 valid',
        'requestId' => 'a3054c28-0000-0000-0000-000000000000',
        'requestIdShort' => 'a3054c28',
    ]]);
});

it('reads an accepted request as booked with the window in the zone and both sla types', function () {
    $rows = BookingHistory::rows([historyRow([
        'status' => 'ACCEPTED',
        'blocking' => true,
        'appointmentTemplateDuration' => 21,
        'slotUsageRuleId' => 'AM',
        'acceptedOfferId' => 3,
        'acceptedWindowStart' => '2026-10-22T14:00:00+00:00',
        'acceptedWindowEnd' => '2026-10-22T18:00:00+00:00',
        'bookingSlaTypeId' => 'INSTALL',
        'previousSlaTypeId' => 'Appointment',
    ])], 'America/Regina');

    expect($rows[0]['when'])->toBe('Oct 19, 4:41 PM')
        ->and($rows[0]['what'])->toBe('Offers, template Half Day, 21 days from Oct 20, rule set AM, blocking')
        ->and($rows[0]['outcome'])->toBe('Booked Thu Oct 22, 8:00 AM to 12:00 PM America/Regina CST, SLA INSTALL (previous Appointment)')
        ->and($rows[0]['offers'])->toBe('7 returned, 7 valid')
        ->and($rows[0]['requestIdShort'])->toBe('a3054d10');
});

it('leaves the previous sla type out of a first booking', function () {
    $rows = BookingHistory::rows([historyRow([
        'status' => 'ACCEPTED',
        'acceptedWindowStart' => '2026-10-22T14:00:00+00:00',
        'acceptedWindowEnd' => '2026-10-22T18:00:00+00:00',
        'bookingSlaTypeId' => 'INSTALL',
    ])], 'America/Regina');

    expect($rows[0]['outcome'])->toBe('Booked Thu Oct 22, 8:00 AM to 12:00 PM America/Regina CST, SLA INSTALL');
});

it('reads the other statuses in plain words', function (array $extra, string $what, string $outcome) {
    $rows = BookingHistory::rows([historyRow($extra)], 'America/Regina');

    expect($rows[0]['what'])->toBe($what)
        ->and($rows[0]['outcome'])->toBe($outcome);
})->with([
    'declined' => [['status' => 'DECLINED'], 'Offers, template Half Day, from Oct 20', 'Released'],
    'failed' => [['status' => 'FAILED', 'blocking' => true], 'Offers, template Half Day, from Oct 20, blocking', 'Failed'],
    'checked free' => [['status' => 'CHECKED', 'blocking' => false, 'appointedCheck' => 'available'], 'Offers, template Half Day, from Oct 20, not blocking', 'Checked, slot free'],
    'checked taken' => [['status' => 'CHECKED', 'blocking' => false, 'appointedCheck' => 'unavailable'], 'Offers, template Half Day, from Oct 20, not blocking', 'Checked, slot taken'],
    'checked unknown' => [['status' => 'CHECKED', 'blocking' => false, 'appointedCheck' => 'unknown'], 'Offers, template Half Day, from Oct 20, not blocking', 'Checked, slot unknown'],
    'expired' => [['status' => 'UNACKNOWLEDGED'], 'Offers, template Half Day, from Oct 20', 'Expired, offers left to PSO'],
    'open' => [['status' => 'UNACKNOWLEDGED', 'offerExpiryDatetime' => '2026-10-19T23:04:00+00:00'], 'Offers, template Half Day, from Oct 20', 'Open'],
]);

it('keeps the rows in the order services sent them, newest first', function () {
    $rows = BookingHistory::rows([
        historyRow(['appointmentRequestId' => 'bbbbbbbb-2', 'createdAt' => '2026-10-19T22:41:00+00:00']),
        historyRow(['appointmentRequestId' => 'aaaaaaaa-1', 'createdAt' => '2026-10-19T22:36:00+00:00']),
    ], 'America/Regina');

    expect(array_column($rows, 'requestIdShort'))->toBe(['bbbbbbbb', 'aaaaaaaa']);
});
