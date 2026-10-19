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

function gridOffers(): array
{
    return [
        'validOffers' => [offerRow('12', '2026-10-22T14:00:00', '2026-10-22T18:00:00', [
            'offerValue' => 949.6,
            'prospectiveResourceId' => 'R-17',
            'prospectiveAllocationStart' => '2026-10-22T14:42:00',
        ])],
        'bestOffer' => ['id' => '12'],
        'allOfferValues' => [
            offerRow('12', '2026-10-22T14:00:00', '2026-10-22T18:00:00', ['offerValue' => 949.6]),
            offerRow('13', '2026-10-22T18:00:00', '2026-10-22T22:00:00', ['offerValue' => 120]),
        ],
    ];
}

it('lays out valid and invalid offers in a day column in the display zone', function () {
    $grid = (new AgendaPresenter('America/Regina'))->grid(gridOffers(), 'detail', 'America/Regina');

    expect($grid['axis'])->toBe([
        'startHour' => 8,
        'endHour' => 16,
        'labels' => ['8 AM', '9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM', '3 PM', '4 PM'],
    ])
        ->and(array_keys($grid['columns']))->toBe(['2026-10-22'])
        ->and($grid['columns']['2026-10-22']['label'])->toBe('Thu Oct 22');

    [$valid, $invalid] = $grid['columns']['2026-10-22']['blocks'];

    expect($valid)->toBe([
        'id' => '12', 'valid' => true, 'best' => true,
        'times' => '8:00 AM to 12:00 PM',
        'top' => 0.0, 'height' => 50.0,
        'lane' => 0, 'lanes' => 1,
        'value' => '950', 'resource' => 'R-17',
        'allocated' => '8:42 AM',
        'planId' => null, 'reason' => null, 'offerSpan' => null,
        'window' => 'Thu Oct 22, 8:00 AM to 12:00 PM America/Regina CST',
    ])
        ->and($invalid)->toBe([
            'id' => '13', 'valid' => false, 'best' => false,
            'times' => '12:00 PM to 4:00 PM',
            'top' => 50.0, 'height' => 50.0,
            'lane' => 0, 'lanes' => 1,
            'value' => '120', 'resource' => 'no resource',
            'allocated' => null,
            'planId' => null, 'reason' => null, 'offerSpan' => null,
            'window' => 'Thu Oct 22, 12:00 PM to 4:00 PM America/Regina CST',
        ]);
});

it('drops invalid offers in call centre mode', function () {
    $grid = (new AgendaPresenter('America/Regina'))->grid(gridOffers(), 'callcentre', 'America/Regina');

    expect($grid['columns']['2026-10-22']['blocks'])->toHaveCount(1)
        ->and($grid['columns']['2026-10-22']['blocks'][0]['id'])->toBe('12')
        ->and($grid['axis']['startHour'])->toBe(8)
        ->and($grid['axis']['endHour'])->toBe(12);
});

it('shows the same offers in UTC', function () {
    $grid = (new AgendaPresenter('America/Regina'))->grid(gridOffers(), 'detail', 'UTC');

    expect($grid['axis']['startHour'])->toBe(14)
        ->and($grid['axis']['endHour'])->toBe(22)
        ->and(array_keys($grid['columns']))->toBe(['2026-10-22'])
        ->and($grid['columns']['2026-10-22']['blocks'][0]['times'])->toBe('2:00 PM to 6:00 PM')
        ->and($grid['columns']['2026-10-22']['blocks'][0]['allocated'])->toBe('2:42 PM');
});

it('puts an offer in the column of its day in the display zone', function () {
    $offers = ['validOffers' => [offerRow('7', '2026-10-23T03:00:00', '2026-10-23T07:00:00')]];
    $presenter = new AgendaPresenter('America/Regina');

    $regina = $presenter->grid($offers, 'callcentre', 'America/Regina');
    $utc = $presenter->grid($offers, 'callcentre', 'UTC');

    expect(array_keys($regina['columns']))->toBe(['2026-10-22'])
        ->and($regina['columns']['2026-10-22']['label'])->toBe('Thu Oct 22')
        ->and($regina['columns']['2026-10-22']['blocks'][0]['times'])->toBe('9:00 PM to 1:00 AM')
        ->and(array_keys($utc['columns']))->toBe(['2026-10-23'])
        ->and($utc['columns']['2026-10-23']['label'])->toBe('Fri Oct 23')
        ->and($utc['axis']['startHour'])->toBe(3)
        ->and($utc['axis']['endHour'])->toBe(7);
});

it('clips a block that runs past midnight at the bottom of the axis', function () {
    $offers = ['validOffers' => [offerRow('7', '2026-10-23T03:00:00', '2026-10-23T07:00:00')]];

    $grid = (new AgendaPresenter('America/Regina'))->grid($offers, 'callcentre', 'America/Regina');
    $block = $grid['columns']['2026-10-22']['blocks'][0];

    expect($grid['axis']['startHour'])->toBe(21)
        ->and($grid['axis']['endHour'])->toBe(24)
        ->and($grid['axis']['labels'])->toBe(['9 PM', '10 PM', '11 PM', '12 AM'])
        ->and($block['top'])->toBe(0.0)
        ->and($block['height'])->toBe(100.0);
});

it('puts overlapping offers side by side', function () {
    $offers = ['validOffers' => [
        offerRow('1', '2026-10-22T14:00:00', '2026-10-22T18:00:00'),
        offerRow('2', '2026-10-22T16:00:00', '2026-10-22T20:00:00'),
        offerRow('3', '2026-10-22T20:00:00', '2026-10-22T22:00:00'),
    ]];

    $blocks = (new AgendaPresenter('America/Regina'))->grid($offers, 'callcentre', 'America/Regina')['columns']['2026-10-22']['blocks'];
    $byId = array_column($blocks, null, 'id');

    expect([$byId['1']['lane'], $byId['1']['lanes']])->toBe([0, 2])
        ->and([$byId['2']['lane'], $byId['2']['lanes']])->toBe([1, 2])
        ->and([$byId['3']['lane'], $byId['3']['lanes']])->toBe([0, 1])
        ->and($byId['1']['top'])->toBe(0.0)
        ->and($byId['1']['height'])->toBe(50.0)
        ->and($byId['3']['top'])->toBe(75.0)
        ->and($byId['3']['height'])->toBe(25.0);
});

it('gives the allocation date only when it is not the column day', function () {
    $offers = ['validOffers' => [
        offerRow('1', '2026-10-22T14:00:00', '2026-10-22T18:00:00', ['prospectiveAllocationStart' => '2026-10-22T14:42:00']),
        offerRow('2', '2026-10-22T14:00:00', '2026-10-22T18:00:00', ['prospectiveAllocationStart' => '2026-10-23T14:42:00']),
    ]];

    $blocks = (new AgendaPresenter('America/Regina'))->grid($offers, 'callcentre', 'America/Regina')['columns']['2026-10-22']['blocks'];
    $byId = array_column($blocks, null, 'id');

    expect($byId['1']['allocated'])->toBe('8:42 AM')
        ->and($byId['2']['allocated'])->toBe('Oct 23 8:42 AM')
        ->and($byId['1']['value'])->toBeNull();
});

it('gives an empty grid when there are no offers', function () {
    $presenter = new AgendaPresenter('America/Regina');

    expect($presenter->grid([], 'detail', 'America/Regina'))->toBe(['axis' => [], 'columns' => []])
        ->and($presenter->grid(['validOffers' => [['id' => 'x']]], 'callcentre', 'UTC'))->toBe(['axis' => [], 'columns' => []]);
});

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

it('carries the plan, reason, offer span and window with offset on a block', function () {
    $offers = ['validOffers' => [], 'allOfferValues' => [offerRow('7', '2026-10-22T14:00:00', '2026-10-22T18:00:00', [
        'planId' => 32035, 'reasonTypeId' => 2, 'offerStartDatetime' => '2026-10-22T14:00:00', 'offerEndDatetime' => '2026-10-22T16:30:00',
    ])]];

    $block = (new AgendaPresenter('UTC'))->grid($offers, 'detail', 'America/Regina')['columns']['2026-10-22']['blocks'][0];

    expect($block['planId'])->toBe('32035')
        ->and($block['reason'])->toBe('2')
        ->and($block['offerSpan'])->toBe('8:00 AM to 10:30 AM')
        ->and($block['window'])->toBe('Thu Oct 22, 8:00 AM to 12:00 PM America/Regina CST');
});
