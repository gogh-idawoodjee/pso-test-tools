<?php

use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\PlanTravels\PlanTravelsCommand;

beforeEach(function () {
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3']);
    $this->checkout = PsoToolsCheckout::fromConfig();
});

it('builds the build argument list with the date, days, dataset name, out dir and template', function () {
    $argv = PlanTravelsCommand::build($this->checkout, '/w/', '/w/plan.xml', '2026-10-05', 5, 'NORTH');

    expect($argv)->toBe([
        $this->checkout->python(), $this->checkout->planTravelsScript(), 'build',
        '--export', '/w/plan.xml', '--date', '2026-10-05', '--days', '5', '--dataset-name', 'NORTH',
        '--out-dir', '/w', '--template', $this->checkout->planTravelsTemplate(),
    ]);
});

it('leaves out the dataset name when there is none', function (?string $name) {
    expect(PlanTravelsCommand::build($this->checkout, '/w', '/w/plan.xml', '2026-10-05', 1, $name))->not->toContain('--dataset-name');
})->with(['null' => [null], 'blank' => ['  ']]);

it('builds the merge-google argument list from the work dir with both thresholds', function () {
    $argv = PlanTravelsCommand::mergeGoogle($this->checkout, '/w', 20.0, 12.5);

    expect($argv)->toBe([
        $this->checkout->python(), $this->checkout->planTravelsScript(), 'merge-google',
        '--legs', '/w/legs.json', '--google', '/w/google.json', '--out-dir', '/w',
        '--template', $this->checkout->planTravelsTemplate(),
        '--review-minutes', '20', '--review-pct', '12.5',
    ]);
});

it('prints a zero percent threshold as 0', function () {
    expect(PlanTravelsCommand::mergeGoogle($this->checkout, '/w', 0.0, 0.0))->toContain('--review-minutes', '0', '--review-pct', '0');
});

it('names the missing script as a setup problem', function () {
    config(['tools.pso_tools_path' => sys_get_temp_dir()]);

    expect(fn () => PsoToolsCheckout::fromConfig()->planTravelsScript())
        ->toThrow(ToolSetupException::class, 'plan-travels/plan_travels.py is missing');
});
