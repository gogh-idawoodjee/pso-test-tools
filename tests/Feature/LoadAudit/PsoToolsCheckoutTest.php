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
        ->toThrow(ToolSetupException::class, 'The load audit is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});

it('names the missing file', function () {
    $empty = sys_get_temp_dir().'/checkout-'.uniqid();
    mkdir($empty);
    config(['tools.pso_tools_path' => $empty]);

    expect(fn () => PsoToolsCheckout::fromConfig()->script())
        ->toThrow(ToolSetupException::class, "The load audit is not set up on this server: {$empty}/load-audit/audit_load.py is missing.");
    expect(fn () => PsoToolsCheckout::fromConfig()->paramsFile())
        ->toThrow(ToolSetupException::class, 'kb/pso_parameters_reference.csv is missing.');
});
