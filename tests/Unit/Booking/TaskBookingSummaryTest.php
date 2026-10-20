<?php

use App\Filament\Resources\TaskResource\Schemas\TaskBookingSummary;
use Carbon\CarbonImmutable;

it('writes the window in the booking zone', function () {
    expect(TaskBookingSummary::window('2026-10-22T12:00:00Z', '2026-10-22T16:00:00Z', 'America/Toronto'))
        ->toBe('Thursday October 22nd 08:00-12:00')
        ->and(TaskBookingSummary::window(null, '2026-10-22T16:00:00Z', 'America/Toronto'))->toBeNull();
});

it('calls a start under two hours away urgent', function () {
    $summaryNow = CarbonImmutable::parse('2026-10-19T12:00:00Z');

    expect(TaskBookingSummary::urgent('2026-10-19T13:00:00Z', $summaryNow))->toBeTrue()
        ->and(TaskBookingSummary::urgent('2026-10-19T15:00:00Z', $summaryNow))->toBeFalse()
        ->and(TaskBookingSummary::urgent('2026-10-19T11:00:00Z', $summaryNow))->toBeFalse()
        ->and(TaskBookingSummary::urgent(null, $summaryNow))->toBeFalse();
});
