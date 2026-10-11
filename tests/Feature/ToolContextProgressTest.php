<?php

use App\Models\Dataset;
use App\Models\ToolRun;
use App\Tools\ToolContext;
use Illuminate\Support\Carbon;

function contextFor(ToolRun $run): ToolContext
{
    return new ToolContext($run, $run->scope, null, 'UTC');
}

it('saves the first report at once and throttles repeats of the same label to one every two seconds', function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();
    $context = contextFor($run);

    $context->reportProgress('Reading the LOAD', 10, 30);
    expect($run->fresh()->progress)->toBe(['label' => 'Reading the LOAD', 'percent' => 10, 'seconds_left' => 30]);

    Carbon::setTestNow('2026-10-11 10:00:01');
    $context->reportProgress('Reading the LOAD', 20, 20);
    expect($run->fresh()->progress['percent'])->toBe(10);

    Carbon::setTestNow('2026-10-11 10:00:02');
    $context->reportProgress('Reading the LOAD', 30, 10);
    expect($run->fresh()->progress['percent'])->toBe(30);
    Carbon::setTestNow();
});

it('saves at once when the label changes', function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();
    $context = contextFor($run);

    $context->reportProgress('Reading the LOAD', 99);
    $context->reportProgress('Reading the plan');
    expect($run->fresh()->progress)->toBe(['label' => 'Reading the plan', 'percent' => null, 'seconds_left' => null]);
    Carbon::setTestNow();
});

it('throttles on the stage when one is given, and saves at once when the stage changes', function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();
    $context = contextFor($run);

    $context->reportProgress('Reading the LOAD: 10%', 10, null, 'load');
    Carbon::setTestNow('2026-10-11 10:00:01');
    $context->reportProgress('Reading the LOAD: 20%', 20, null, 'load');
    expect($run->fresh()->progress['label'])->toBe('Reading the LOAD: 10%');

    $context->reportProgress('Reading the plan: 0%', 0, null, 'plan');
    expect($run->fresh()->progress['label'])->toBe('Reading the plan: 0%');
    Carbon::setTestNow();
});
