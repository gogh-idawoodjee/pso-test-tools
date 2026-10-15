<?php

use App\Tools\ActivityTrace\ActivityTraceCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;

beforeEach(function () {
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3']);
    $this->checkout = PsoToolsCheckout::fromConfig();
    $this->audits = ['audits' => '/w/audits.zip'];
});

function traceArgv(array $inputs, array $options, ?string $ifs = null): array
{
    return ActivityTraceCommand::build(PsoToolsCheckout::fromConfig(), '/w/', $inputs, $options, 'America/Regina', $ifs, 'NORTH');
}

it('builds the argument list for audits and an activity id only', function () {
    $argv = traceArgv($this->audits, ['activity_id' => 'abc-1']);

    expect($argv)->toBe([
        $this->checkout->python(), $this->checkout->activityTraceScript(),
        '--audits', '/w/audits.zip', '--activity', 'abc-1',
        '--tz', 'America/Regina', '--dataset-name', 'NORTH',
        '--out-dir', '/w', '--template', $this->checkout->activityTraceTemplate(),
    ]);
});

it('adds the load and the event log when given', function () {
    $argv = traceArgv([...$this->audits, 'load' => '/w/load.xml', 'event_log' => '/w/events.xlsx'], ['activity_id' => 'abc-1']);

    expect($argv)->toContain('--load', '/w/load.xml', '--event-log', '/w/events.xlsx');
});

it('passes only the work task when that is all there is', function () {
    $argv = traceArgv($this->audits, ['activity_id' => '', 'work_task_id' => 'wt-9']);

    expect($argv)->toContain('--work-task', 'wt-9')->not->toContain('--activity');
});

it('passes both ids when both are given', function () {
    $argv = traceArgv($this->audits, ['activity_id' => ' abc-1 ', 'work_task_id' => 'wt-9']);

    expect($argv)->toContain('--activity', 'abc-1', '--work-task', 'wt-9');
});

it('passes the IFS time zone only when set', function () {
    expect(traceArgv($this->audits, ['activity_id' => 'a']))->not->toContain('--ifs-tz')
        ->and(traceArgv($this->audits, ['activity_id' => 'a'], 'America/Toronto'))->toContain('--ifs-tz', 'America/Toronto');
});

it('leaves out the dataset name when blank', function () {
    $argv = ActivityTraceCommand::build($this->checkout, '/w', $this->audits, ['activity_id' => 'a'], 'UTC', null, ' ');

    expect($argv)->not->toContain('--dataset-name');
});

it('leaves out --tz when the dataset has no time zone, so the page says times are UTC', function () {
    $argv = ActivityTraceCommand::build($this->checkout, '/w', $this->audits, ['activity_id' => 'a'], null, null, 'NORTH');

    expect($argv)->not->toContain('--tz')->not->toContain('--ifs-tz')
        ->and(traceArgv($this->audits, ['activity_id' => 'a']))->toContain('--tz', 'America/Regina');
});
