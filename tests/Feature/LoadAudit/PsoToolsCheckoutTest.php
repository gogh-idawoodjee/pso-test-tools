<?php

use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;

function fakeCheckout(): string
{
    return base_path('tests/Fixtures/pso-tools');
}

it('resolves the script, kb files and python from the checkout', function () {
    config(['tools.pso_tools_path' => fakeCheckout(), 'tools.python' => 'python3']);

    $checkout = PsoToolsCheckout::fromConfig();

    expect($checkout->script())->toBe(fakeCheckout().'/load-audit/audit_load.py')
        ->and($checkout->paramsFile())->toBe(fakeCheckout().'/kb/pso_parameters_reference.csv')
        ->and($checkout->watchlistFile())->toBe(fakeCheckout().'/kb/28-release-notes-watchlist.md')
        ->and($checkout->python())->toBe('python3');
});

it('says the path is not configured', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => PsoToolsCheckout::fromConfig()->script())
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});

it('names the missing file', function () {
    $empty = sys_get_temp_dir().'/checkout-'.uniqid();
    mkdir($empty);
    config(['tools.pso_tools_path' => $empty]);

    try {
        expect(fn () => PsoToolsCheckout::fromConfig()->script())
            ->toThrow(ToolSetupException::class, "The pso-tools checkout is not set up on this server: {$empty}/load-audit/audit_load.py is missing.");
        expect(fn () => PsoToolsCheckout::fromConfig()->paramsFile())
            ->toThrow(ToolSetupException::class, 'kb/pso_parameters_reference.csv is missing.');
    } finally {
        @rmdir($empty);
    }
});

it('says python was not found', function () {
    config(['tools.pso_tools_path' => fakeCheckout(), 'tools.python' => '/nonexistent/python3']);

    expect(fn () => PsoToolsCheckout::fromConfig()->python())
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: Python (/nonexistent/python3) was not found.');

    config(['tools.python' => 'no-such-python-binary']);
    expect(fn () => PsoToolsCheckout::fromConfig()->python())
        ->toThrow(ToolSetupException::class, 'Python (no-such-python-binary) was not found.');
});

it('falls back to python3 when the configured python is blank', function () {
    config(['tools.pso_tools_path' => fakeCheckout(), 'tools.python' => '']);

    expect(PsoToolsCheckout::fromConfig()->python())->toBe('python3');
});

it('resolves the region availability script', function () {
    config(['tools.pso_tools_path' => fakeCheckout()]);

    expect(PsoToolsCheckout::fromConfig()->regionAvailabilityScript())
        ->toBe(fakeCheckout().'/region-availability/region_availability.py');
});
