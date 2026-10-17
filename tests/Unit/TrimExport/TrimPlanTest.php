<?php

use App\Support\TrimExport\TrimPlan;

it('removes the ticked tables in Remove mode and keeps only them in Keep mode', function () {
    $remove = TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Plan', 'Plan_Travel']], 'UTC');
    $keep = TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity']], 'UTC');
    $present = ['Input_Reference', 'Activity', 'Plan', 'Plan_Travel'];

    expect($remove->droppedTables($present))->toBe(['Plan', 'Plan_Travel'])
        ->and($keep->droppedTables($present))->toBe(['Plan', 'Plan_Travel'])
        ->and($keep->dropsTable('Activity'))->toBeFalse()
        ->and(TrimPlan::fromOptions([], 'UTC')->mode)->toBe(TrimPlan::MODE_REMOVE);
});

it('keeps Input_Reference in both modes until removal is allowed', function () {
    expect(TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Input_Reference']], 'UTC')->dropsTable('Input_Reference'))->toBeFalse()
        ->and(TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity']], 'UTC')->dropsTable('Input_Reference'))->toBeFalse()
        ->and(TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Input_Reference'], 'allow_input_reference_removal' => true], 'UTC')->dropsTable('Input_Reference'))->toBeTrue()
        ->and(TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity'], 'allow_input_reference_removal' => true], 'UTC')->dropsTable('Input_Reference'))->toBeTrue();
});

it('filters rows only when a filter is set, and carries every id as a string', function () {
    expect(TrimPlan::fromOptions(['tables' => ['Plan']], 'UTC')->filtersRows())->toBeFalse();

    $plan = TrimPlan::fromOptions(['region_ids' => ['V1', '', null], 'activity_ids' => [14420, '14420', 'A2'], 'resource_ids' => 'not a list'], 'UTC');

    expect($plan->filtersRows())->toBeTrue()
        ->and($plan->regionIds)->toBe(['V1'])
        ->and($plan->activityIds)->toBe(['14420', 'A2'])
        ->and($plan->resourceIds)->toBe([]);
});

it('takes the window as whole days in the run zone, both days or neither', function () {
    $plan = TrimPlan::fromOptions(['window_start' => '2026-10-05', 'window_end' => '2026-10-09'], 'America/Regina');

    expect($plan->windowStart)->toBe((new DateTimeImmutable('2026-10-05 06:00:00', new DateTimeZone('UTC')))->getTimestamp())
        ->and($plan->windowEnd)->toBe((new DateTimeImmutable('2026-10-10 05:59:59', new DateTimeZone('UTC')))->getTimestamp())
        ->and($plan->filtersRows())->toBeTrue()
        ->and(TrimPlan::fromOptions(['window_start' => '2026-10-05'], 'UTC')->windowStart)->toBeNull()
        ->and(TrimPlan::fromOptions(['window_start' => '2026-10-09', 'window_end' => '2026-10-05'], 'UTC')->windowStart)->toBeNull();
});

it('writes the override the way PSO writes times, in UTC with no offset', function () {
    expect(TrimPlan::fromOptions(['input_reference_datetime' => '2026-10-04 08:15'], 'America/Regina')->inputReferenceDatetime)->toBe('2026-10-04T08:15:00')
        ->and(TrimPlan::fromOptions(['input_reference_datetime' => 'not a time'], 'UTC')->inputReferenceDatetime)->toBeNull()
        ->and(TrimPlan::fromOptions(['count_only' => true], 'UTC')->countOnly)->toBeTrue();
});
