<?php

use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\TravelTuning\TravelTuningCommand;

beforeEach(function () {
    $this->root = base_path('tests/Fixtures/pso-tools');
    config(['tools.pso_tools_path' => $this->root, 'tools.python' => 'python3']);
});

it('passes the manifest, outputs, template, generated time, options and defaults', function () {
    $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), '/work/', ['title' => 'Travel Tuning Report', 'client' => 'SaskTel', 'dataset' => 'North', 'region_label' => 'Territory'], '2026-10-18 09:30', false);

    expect($argv)->toBe([
        'python3', $this->root.'/travel/travel_tuning.py',
        '--manifest', '/work/manifest.csv', '--out', '/work/travel_tuning.html', '--json', '/work/travel_tuning.json',
        '--template', $this->root.'/travel/travel_tuning_template.html', '--generated', '2026-10-18 09:30',
        '--title=Travel Tuning Report', '--client=SaskTel', '--dataset=North', '--region-label=Territory',
        '--review-minutes=20', '--small-minutes=5', '--model-tol-min=1', '--model-tol-pct=5', '--long-leg-km=300',
        '--tiles=esri-gray',
    ]);
});

it('keeps text that starts with a dash, folds newlines, drops blanks and falls back on bad numbers and tiles', function () {
    $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), '/work', [
        'title' => "-draft\nround 2", 'client' => '  ', 'review_minutes' => '12.5', 'small_minutes' => '-1', 'model_tol_pct' => 'lots', 'tiles' => 'satellite',
    ], '2026-10-18 09:30', true);

    expect($argv)->toContain('--title=-draft round 2', '--review-minutes=12.5', '--small-minutes=5', '--model-tol-pct=5', '--tiles=esri-gray', '--notes', '/work/notes.md')
        ->and(implode(' ', $argv))->not->toContain('--client')->not->toContain('--offline')->not->toContain('--review-csv');
});

it('says the checkout is not set up when the script is missing', function () {
    config(['tools.pso_tools_path' => sys_get_temp_dir()]);

    expect(fn () => PsoToolsCheckout::fromConfig()->travelTuningScript())
        ->toThrow(ToolSetupException::class, 'travel/travel_tuning.py is missing');
});

it('always passes the region label and falls back to Region when it is blank', function () {
    $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), '/work', ['region_label' => "  \n "], '2026-10-18 09:30', false);

    expect($argv)->toContain('--region-label=Region');
});
