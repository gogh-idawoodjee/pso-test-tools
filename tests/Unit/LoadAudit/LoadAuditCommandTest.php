<?php

use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;

function checkoutForCommand(): PsoToolsCheckout
{
    return new PsoToolsCheckout(dirname(__DIR__, 2).'/Fixtures/pso-tools', 'python3');
}

it('builds the full command for a LOAD, plan and SYS', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/scratch/run1',
        ['load' => '/scratch/run1/a.zip', 'plan' => '/scratch/run1/b.xml', 'sys' => '/scratch/run1/c.xml'],
        ['ifs_release' => '25.2.7', 'complete_grace_hours' => 12],
        'America/Regina', 'SaskTel');

    $root = dirname(__DIR__, 2).'/Fixtures/pso-tools';
    expect($argv)->toBe([
        'python3', '-I', "{$root}/load-audit/audit_load.py",
        '--load', '/scratch/run1/a.zip',
        '--plan', '/scratch/run1/b.xml',
        '--sys', '/scratch/run1/c.xml',
        '--tz', 'America/Regina',
        '--org', 'SaskTel',
        '--accounts-dir', '/scratch/run1/accounts',
        '--params', "{$root}/kb/pso_parameters_reference.csv",
        '--watchlist', "{$root}/kb/28-release-notes-watchlist.md",
        '--ifs-release', '25.2.7',
        '--complete-grace-hours', '12',
        '--review', 'none',
        '--out', '/scratch/run1/load_audit.md',
        '--json', '/scratch/run1/load_audit.json',
        '--html', '/scratch/run1/load_audit.html',
    ]);
});

it('omits plan, sys, org and ifs release when absent and defaults the grace hours', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], [], 'UTC', null);

    expect($argv)->not->toContain('--plan')->not->toContain('--sys')->not->toContain('--org')->not->toContain('--ifs-release')
        ->and(array_slice($argv, array_search('--complete-grace-hours', $argv, true), 2))->toBe(['--complete-grace-hours', '24']);
});

it('treats a blank display name or ifs release as absent', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], ['ifs_release' => '  '], 'UTC', '');

    expect($argv)->not->toContain('--org')->not->toContain('--ifs-release');
});
