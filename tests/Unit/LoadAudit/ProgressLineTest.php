<?php

use App\Tools\LoadAudit\ProgressLine;

it('turns a load line into a label with percent and estimate', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"load","percent":45,"seconds_left":6}'))
        ->toBe(['label' => 'Reading the LOAD: 45%, about 6 s left', 'percent' => 45, 'seconds_left' => 6, 'stage' => 'load', 'details' => []]);
});

it('puts the step in the label and its own stage key, and the detail under it', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"checks","step":"PSO system","detail":"version history, 31 parameter rows"}'))
        ->toBe(['label' => 'Running the checks: PSO system', 'percent' => null, 'seconds_left' => null, 'stage' => 'checks:PSO system',
            'details' => ['version history, 31 parameter rows']]);
    expect(ProgressLine::parse('PROGRESS {"stage":"audits","percent":30,"seconds_left":18,"detail":"5,000 of 16,169 files read"}'))
        ->toBe(['label' => 'Reading the audit messages zip: 30%, about 18 s left', 'percent' => 30, 'seconds_left' => 18, 'stage' => 'audits',
            'details' => ['5,000 of 16,169 files read']]);
});

it('ignores a step or detail that is not text', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"checks","step":[1],"detail":{"a":1}}'))
        ->toBe(['label' => 'Running the checks', 'percent' => null, 'seconds_left' => null, 'stage' => 'checks', 'details' => []]);
});

it('labels each stage in plain English', function (string $stage, string $label) {
    expect(ProgressLine::parse('PROGRESS {"stage":"'.$stage.'"}')['label'])->toBe($label);
})->with([['load', 'Reading the LOAD'], ['plan', 'Reading the plan'], ['sys', 'Reading the SYS file'], ['checks', 'Running the checks'], ['gather', "Working out each technician's regions"], ['finding', 'Finding the activity in the audit messages'], ['scanning', 'Finding the activity in the audit messages'], ['reading', 'Reading the audit messages'], ['audits', 'Reading the audit messages zip'], ['events', 'Reading the PSO events export'], ['appointments', 'Reading the appointment history'], ['page', 'Building the page']]);

it('ignores noise, broken json and unknown stages', function (string $line) {
    expect(ProgressLine::parse($line))->toBeNull();
})->with(['just noise', 'PROGRESS {not json', 'PROGRESS {"stage":"mystery"}', 'PROGRESS []', 'PROGRESS {"stage":[]}', 'PROGRESS {"stage":{"a":1}}', 'PROGRESS {"stage":1.5}', '']);

it('rounds the percent into the label without an estimate when there is none', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"plan","percent":100}')['label'])->toBe('Reading the plan: 100%');
});
