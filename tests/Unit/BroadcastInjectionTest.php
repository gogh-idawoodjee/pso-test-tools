<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;

it('defaults to the PowerShell script values', function () {
    $settings = BroadcastSettings::defaults();

    expect($settings->broadcastId)->toBe('WEBHOOK_COMPLETE');
    expect($settings->planType)->toBe('COMPLETE');
    expect($settings->minimumPlanQuality)->toBe(85);
    expect($settings->maximumWait)->toBe('PT5M');
    expect($settings->allocationType)->toBe(1);
    expect($settings->requiredTables)->toBe([
        'Allocation', 'Plan', 'Plan_Deletion', 'Appointment_Offer',
        'Appointment_Summary', 'Suggested_Dispatch', 'Schedule_Exception', 'Schedule_Exception_Data',
    ]);
});

it('overrides defaults per key and falls back for blanks', function () {
    $settings = BroadcastSettings::fromArray([
        'id' => 'MY_HOOK',
        'maximum_wait' => 'PT10M',
        'minimum_plan_quality' => '90',
        'plan_type' => '',
        'required_tables' => ['Plan', 'Allocation'],
    ]);

    expect($settings->broadcastId)->toBe('MY_HOOK');
    expect($settings->maximumWait)->toBe('PT10M');
    expect($settings->minimumPlanQuality)->toBe(90);
    expect($settings->planType)->toBe('COMPLETE');
    expect($settings->requiredTables)->toBe(['Plan', 'Allocation']);
    expect(BroadcastSettings::fromArray($settings->toArray()))->toEqual($settings);
});

it('builds typed broadcast fields for ScheduleData and string fields otherwise', function () {
    $injection = new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');

    expect($injection->broadcastFields(typed: true))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => true,
        'broadcast_type_id' => 'REST',
        'plan_type' => 'COMPLETE',
        'minimum_plan_quality' => 85,
        'maximum_wait' => 'PT5M',
        'minimum_step_interval' => 1,
        'once_only' => true,
        'allocation_type' => 1,
    ]);

    expect($injection->broadcastFields(typed: false))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => 'true',
        'broadcast_type_id' => 'REST',
        'plan_type' => 'COMPLETE',
        'minimum_plan_quality' => '85',
        'maximum_wait' => 'PT5M',
        'minimum_step_interval' => '1',
        'once_only' => 'true',
        'allocation_type' => '1',
    ]);
});

it('builds the parameter rows in the script order without a compression row', function () {
    $injection = new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');

    $rows = $injection->parameterRows('application/json');

    expect(array_column($rows, 'parameter_name'))->toBe(['url', 'mediatype', 'httpmethod', 'etag', 'required_tables']);
    expect(array_unique(array_column($rows, 'broadcast_id')))->toBe(['WEBHOOK_COMPLETE']);
    expect($rows[0]['parameter_value'])->toBe('https://webhook.example.test/pso-env');
    expect($rows[1]['parameter_value'])->toBe('application/json');
    expect($rows[2]['parameter_value'])->toBe('PATCH');
    expect($rows[3]['parameter_value'])->toBe('*');
    expect($rows[4]['parameter_value'])->toBe('Allocation,Plan,Plan_Deletion,Appointment_Offer,Appointment_Summary,Suggested_Dispatch,Schedule_Exception,Schedule_Exception_Data');
});

it('validates ISO 8601 durations', function (string $value, bool $valid) {
    expect(BroadcastSettings::isValidDuration($value))->toBe($valid);
})->with([
    ['PT5M', true],
    ['PT1H30M', true],
    ['P1DT2H', true],
    ['P1W', true],
    ['PT0.5S', true],
    ['', false],
    ['P', false],
    ['PT', false],
    ['5 minutes', false],
    ["PT5M\n", false],
    ['5', false],
]);
