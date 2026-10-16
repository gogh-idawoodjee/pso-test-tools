<?php

use App\Support\GatewayUpload\RunLogNode;
use App\Support\GatewayUpload\RunLogTimeline;

function timelineLine(string $level, string $text, string $at = '10:00:00'): array
{
    return ['level' => $level, 'text' => $text, 'at' => $at];
}

it('opens a node at each step and puts the lines after it in that node', function () {
    $nodes = RunLogTimeline::group([
        timelineLine('step', 'Step 1/3: Logging on', '10:00:01'),
        timelineLine('ok', 'Logon successful.'),
        timelineLine('step', 'Step 2/3: Preparing request body', '10:00:02'),
        timelineLine('sub', 'Gzip-compressing request body...'),
        timelineLine('sub', 'Compressed to 2.00 MB'),
    ], 'Uploading', false);

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]->title)->toBe('Step 1/3: Logging on')
        ->and($nodes[0]->at)->toBe('10:00:01')
        ->and(array_column($nodes[0]->details, 'text'))->toBe(['Logon successful.'])
        ->and($nodes[1]->title)->toBe('Step 2/3: Preparing request body')
        ->and(array_column($nodes[1]->details, 'text'))->toBe(['Gzip-compressing request body...', 'Compressed to 2.00 MB']);
});

it('gathers lines before the first step into a leading node titled with the status label', function () {
    $nodes = RunLogTimeline::group([
        timelineLine('sub', 'Webhook broadcast skipped', '09:59:58'),
        timelineLine('warn', 'No alias API key configured'),
        timelineLine('step', 'Rewriting the file', '10:00:00'),
    ], 'Preparing', true);

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]->title)->toBe('Preparing')
        ->and($nodes[0]->at)->toBe('09:59:58')
        ->and($nodes[0]->details)->toHaveCount(2)
        ->and($nodes[0]->hasWarn)->toBeTrue();
});

it('flags warnings, errors and confirmations per node', function () {
    $nodes = RunLogTimeline::group([
        timelineLine('step', 'Ensuring webhook alias'),
        timelineLine('warn', 'Could not update the alias token'),
        timelineLine('step', 'Step 1/3: Logging on'),
        timelineLine('ok', 'Logon successful.'),
        timelineLine('step', 'Step 3/3: POSTing'),
        timelineLine('error', 'UPLOAD FAILED'),
        timelineLine('step', 'Rewriting the file'),
        timelineLine('sub', 'Nothing changed'),
    ], 'Uploading', false);

    expect(array_map(static fn (RunLogNode $node) => [$node->hasWarn, $node->hasError, $node->hasOk], $nodes))->toBe([
        [true, false, false],
        [false, false, true],
        [false, true, false],
        [false, false, false],
    ])->and(array_map(static fn (RunLogNode $node) => $node->state(), $nodes))->toBe(['warn', 'ok', 'error', 'done']);
});

it('marks the last node current while the run is in progress, and none once it finished', function () {
    $lines = [
        timelineLine('step', 'Step 1/3: Logging on'),
        timelineLine('step', 'Step 2/3: Preparing request body'),
        timelineLine('sub', 'Gzip-compressing request body...'),
    ];

    $running = RunLogTimeline::group($lines, 'Compressing', true);
    $finished = RunLogTimeline::group($lines, 'Succeeded', false);

    expect(array_map(static fn (RunLogNode $node) => $node->isCurrent, $running))->toBe([false, true])
        ->and($running[1]->state())->toBe('current')
        ->and(array_map(static fn (RunLogNode $node) => $node->isCurrent, $finished))->toBe([false, false]);
});

it('shows an error over the current marker', function () {
    $nodes = RunLogTimeline::group([
        timelineLine('step', 'Step 1/3: Logging on'),
        timelineLine('error', 'LOGON FAILED'),
    ], 'Preparing', true);

    expect($nodes[0]->isCurrent)->toBeTrue()
        ->and($nodes[0]->state())->toBe('error');
});

it('drops the banner around a step title', function () {
    $nodes = RunLogTimeline::group([
        timelineLine('step', '=== PSO RESTful Gateway - Schedule Data Upload ==='),
        timelineLine('step', '==='),
    ], 'Queued', false);

    expect($nodes[0]->title)->toBe('PSO RESTful Gateway - Schedule Data Upload')
        ->and($nodes[1]->title)->toBe('===');
});

it('returns no nodes for an empty log', function () {
    expect(RunLogTimeline::group([], 'Queued', true))->toBe([]);
});
