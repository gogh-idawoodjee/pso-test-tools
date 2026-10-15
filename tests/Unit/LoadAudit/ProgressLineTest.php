<?php

use App\Tools\LoadAudit\ProgressLine;

it('turns a load line into a label with percent and estimate', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"load","percent":45,"seconds_left":6}'))
        ->toBe(['label' => 'Reading the LOAD: 45%, about 6 s left', 'percent' => 45, 'seconds_left' => 6, 'stage' => 'load']);
});

it('labels each stage in plain English', function (string $stage, string $label) {
    expect(ProgressLine::parse('PROGRESS {"stage":"'.$stage.'"}')['label'])->toBe($label);
})->with([['load', 'Reading the LOAD'], ['plan', 'Reading the plan'], ['sys', 'Reading the SYS file'], ['checks', 'Running the checks'], ['gather', "Working out each technician's regions"], ['finding', 'Finding the activity in the audit messages'], ['scanning', 'Finding the activity in the audit messages'], ['reading', 'Reading the audit messages']]);

it('ignores noise, broken json and unknown stages', function (string $line) {
    expect(ProgressLine::parse($line))->toBeNull();
})->with(['just noise', 'PROGRESS {not json', 'PROGRESS {"stage":"mystery"}', 'PROGRESS []', 'PROGRESS {"stage":[]}', 'PROGRESS {"stage":{"a":1}}', 'PROGRESS {"stage":1.5}', '']);

it('rounds the percent into the label without an estimate when there is none', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"plan","percent":100}')['label'])->toBe('Reading the plan: 100%');
});
