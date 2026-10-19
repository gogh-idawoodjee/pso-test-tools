<?php

use App\Support\Booking\AgendaPresenter;

function offerRow(string $id, string $start, string $end, array $extra = []): array
{
    return [
        'id' => $id,
        'windowStartDatetime' => $start,
        'windowEndDatetime' => $end,
        ...$extra,
    ];
}

it('shows only valid offers in call centre mode', function () {
    $offers = [
        'validOffers' => [offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00')],
        'allOfferValues' => [
            offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00'),
            offerRow('b', '2026-10-22T19:00:00', '2026-10-22T23:00:00'),
        ],
    ];

    $groups = (new AgendaPresenter('America/Regina'))->groups($offers, 'callcentre');

    expect($groups)->toHaveKey('Thu, Oct 22, 2026');
    $rows = array_merge(...array_values($groups['Thu, Oct 22, 2026']));
    expect(array_column($rows, 'id'))->toBe(['a'])
        ->and($rows[0]['valid'])->toBeTrue();
});

it('keeps invalid offers greyed in detail mode', function () {
    $offers = [
        'validOffers' => [offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00')],
        'bestOffer' => ['id' => 'a'],
        'allOfferValues' => [
            offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00'),
            offerRow('b', '2026-10-22T19:00:00', '2026-10-22T23:00:00'),
        ],
    ];

    $groups = (new AgendaPresenter('America/Regina'))->groups($offers, 'detail');

    $rows = array_merge(...array_values($groups['Thu, Oct 22, 2026']));
    $byId = array_column($rows, null, 'id');
    expect(array_keys($byId))->toBe(['a', 'b'])
        ->and($byId['a']['valid'])->toBeTrue()
        ->and($byId['a']['best'])->toBeTrue()
        ->and($byId['b']['valid'])->toBeFalse()
        ->and($byId['b']['best'])->toBeFalse()
        ->and(array_keys($groups['Thu, Oct 22, 2026']))->toBe(['Morning', 'Midday']);
});

it('puts rows without a window under an unknown day', function () {
    $groups = (new AgendaPresenter('America/Regina'))->groups(['validOffers' => [['id' => 'x']]], 'callcentre');

    expect($groups)->toHaveKey('Unknown day');
});

it('splits the day into periods at 12, 15 and 18', function (mixed $time, string $period) {
    expect(AgendaPresenter::periodOfDay($time))->toBe($period);
})->with([
    ['8:00 AM', 'Morning'],
    ['11:59 AM', 'Morning'],
    ['12:00 PM', 'Midday'],
    ['2:59 PM', 'Midday'],
    ['3:00 PM', 'Afternoon'],
    ['5:59 PM', 'Afternoon'],
    ['6:00 PM', 'Evening'],
    [null, 'Morning'],
]);

it('derives the English window in the dataset zone from offsetless UTC times', function () {
    $row = (new AgendaPresenter('America/Regina'))->withEnglishWindow(
        offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00'),
    );

    expect($row['windowDayEnglish'])->toBe('Thu, Oct 22, 2026')
        ->and($row['windowStartTime'])->toBe('8:00 AM')
        ->and($row['windowEndTime'])->toBe('12:00 PM');
});

it('keeps English window fields that are already filled', function () {
    $row = (new AgendaPresenter('America/Regina'))->withEnglishWindow(
        offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00', ['windowStartTime' => '9:00 AM']),
    );

    expect($row['windowStartTime'])->toBe('9:00 AM');
});

it('describes the slot length from the first offer', function () {
    $presenter = new AgendaPresenter('America/Regina');

    expect($presenter->slotLength(['allOfferValues' => [offerRow('a', '2026-10-22T14:00:00', '2026-10-22T18:00:00')]]))->toBe('4 hours')
        ->and($presenter->slotLength(['validOffers' => [offerRow('a', '2026-10-22T14:00:00', '2026-10-22T15:30:00')]]))->toBe('1 hour 30 minutes')
        ->and($presenter->slotLength(['validOffers' => [offerRow('a', '2026-10-22T14:00:00', '2026-10-22T14:00:00')]]))->toBeNull()
        ->and($presenter->slotLength([]))->toBeNull();
});
