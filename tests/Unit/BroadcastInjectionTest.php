<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use Carbon\CarbonImmutable;

it('ships the PowerShell script values', function () {
    $settings = BroadcastSettings::shipped();

    expect($settings->broadcastId)->toBe('WEBHOOK_COMPLETE');
    expect($settings->planType)->toBe('CHANGE');
    expect($settings->minimumPlanQuality)->toBe(85);
    expect($settings->maximumWait)->toBe('PT1M');
    expect($settings->maximumFrequency)->toBe('PT10M');
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
    ], useSaved: false);

    expect($settings->broadcastId)->toBe('MY_HOOK');
    expect($settings->maximumWait)->toBe('PT10M');
    expect($settings->maximumFrequency)->toBe('PT10M');
    expect($settings->minimumPlanQuality)->toBe(90);
    expect($settings->planType)->toBe('CHANGE');
    expect($settings->requiredTables)->toBe(['Plan', 'Allocation']);
    expect(BroadcastSettings::fromArray($settings->toArray(), useSaved: false))->toEqual($settings);
});

it('builds typed broadcast fields for ScheduleData and string fields otherwise, in the agreed order', function () {
    $injection = new BroadcastInjection(BroadcastSettings::shipped(), 'https://webhook.example.test/pso-env', '2026-10-30T13:00:00+00:00', '2027-05-05T23:59:59+00:00');

    expect($injection->broadcastFields(typed: true))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => true,
        'broadcast_type_id' => 'REST',
        'plan_type' => 'CHANGE',
        'minimum_plan_quality' => 85,
        'minimum_step_interval' => 1,
        'once_only' => false,
        'maximum_wait' => 'PT1M',
        'maximum_frequency' => 'PT10M',
        'allocation_type' => 1,
        'time_filter_start' => '2026-10-30T13:00:00+00:00',
        'time_filter_end' => '2027-05-05T23:59:59+00:00',
    ]);

    expect($injection->broadcastFields(typed: false))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => 'true',
        'broadcast_type_id' => 'REST',
        'plan_type' => 'CHANGE',
        'minimum_plan_quality' => '85',
        'minimum_step_interval' => '1',
        'once_only' => 'false',
        'maximum_wait' => 'PT1M',
        'maximum_frequency' => 'PT10M',
        'allocation_type' => '1',
        'time_filter_start' => '2026-10-30T13:00:00+00:00',
        'time_filter_end' => '2027-05-05T23:59:59+00:00',
    ]);
});

it('leaves the time filter out when there is none', function () {
    $injection = new BroadcastInjection(BroadcastSettings::shipped(), 'https://webhook.example.test/pso-env');

    expect($injection->broadcastFields(typed: true))->not->toHaveKeys(['time_filter_start', 'time_filter_end']);
});

it('runs the time filter from now to the end of the UTC day the DSE and appointment windows reach', function () {
    $now = CarbonImmutable::parse('2026-10-30T13:37:10-06:00');

    expect(BroadcastInjection::timeFilter($now, 'P7D', 'P180D'))->toBe(['2026-10-30T19:37:10+00:00', '2027-05-05T23:59:59+00:00']);
    expect(BroadcastInjection::timeFilter($now, 'P7D', null))->toBe(['2026-10-30T19:37:10+00:00', '2026-11-06T23:59:59+00:00']);
    expect(BroadcastInjection::timeFilter($now, 'P7D', ''))->toBe(['2026-10-30T19:37:10+00:00', '2026-11-06T23:59:59+00:00']);
    expect(BroadcastInjection::timeFilter($now, 'PT36H', null))->toBe(['2026-10-30T19:37:10+00:00', '2026-11-01T23:59:59+00:00']);
    expect(BroadcastInjection::timeFilter($now, null, 'P180D'))->toBeNull();
    expect(BroadcastInjection::timeFilter($now, 'seven days', null))->toBeNull();
});

it('builds the parameter rows in alphabetical order without a compression row', function () {
    $injection = new BroadcastInjection(BroadcastSettings::shipped(), 'https://webhook.example.test/pso-env');

    $rows = $injection->parameterRows('application/json');

    expect(array_column($rows, 'parameter_name'))->toBe(['etag', 'httpmethod', 'mediatype', 'required_tables', 'url']);
    expect(array_unique(array_column($rows, 'broadcast_id')))->toBe(['WEBHOOK_COMPLETE']);
    expect(array_column($rows, 'parameter_value'))->toBe([
        '*',
        'PATCH',
        'application/json',
        'Allocation,Plan,Plan_Deletion,Appointment_Offer,Appointment_Summary,Suggested_Dispatch,Schedule_Exception,Schedule_Exception_Data',
        'https://webhook.example.test/pso-env',
    ]);
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
