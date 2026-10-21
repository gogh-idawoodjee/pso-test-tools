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
        '--noise-ids', '3041,1767,2959,1964,1965,24578',
        '--flag-activities', '5',
        '--flag-deletions', '30',
        '--tz', 'America/Regina',
        '--org=SaskTel',
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

    expect(preg_grep('/^--org=/', $argv))->toBeEmpty();
    expect($argv)->not->toContain('--plan')->not->toContain('--sys')->not->toContain('--ifs-release')
        ->and(array_slice($argv, array_search('--complete-grace-hours', $argv, true), 2))->toBe(['--complete-grace-hours', '24']);
});

it('treats a blank display name or ifs release as absent', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], ['ifs_release' => '  '], 'UTC', '');

    expect(preg_grep('/^--org=/', $argv))->toBeEmpty();
    expect($argv)->not->toContain('--ifs-release');
});

it('passes a label that starts with a dash as one joined item so argparse accepts it', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], [], 'UTC', '-load.xml');

    expect($argv)->toContain('--org=-load.xml')->not->toContain('-load.xml');
});

it('adds the System day inputs, messages and option flags after --sys and before --accounts-dir', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/scratch/run1',
        ['load' => '/scratch/run1/a.zip', 'plan' => '/scratch/run1/b.xml', 'sys' => '/scratch/run1/c.xml',
            'audits' => '/scratch/run1/d.zip', 'pso_events' => '/scratch/run1/e.json', 'appointment_history' => '/scratch/run1/f.csv'],
        ['noise_event_ids' => '3041, 24578', 'flag_activities' => 7, 'flag_deletions' => 40],
        'America/Regina', 'SaskTel', '/scratch/run1/messages.json');

    $start = array_search('--sys', $argv, true);
    expect(array_slice($argv, $start, 16))->toBe([
        '--sys', '/scratch/run1/c.xml',
        '--events', '/scratch/run1/d.zip',
        '--events', '/scratch/run1/e.json',
        '--appointments', '/scratch/run1/f.csv',
        '--messages', '/scratch/run1/messages.json',
        '--noise-ids', '3041,24578',
        '--flag-activities', '7',
        '--flag-deletions', '40',
    ])->and($argv)->toContain('--accounts-dir');
    expect(array_search('--flag-deletions', $argv, true))->toBeLessThan(array_search('--accounts-dir', $argv, true));
});

it('adds only the three option flags, with defaults, when there are no System day inputs', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], [], 'UTC', null, null);

    expect($argv)->not->toContain('--events')->not->toContain('--appointments')->not->toContain('--messages')
        ->and(array_slice($argv, array_search('--noise-ids', $argv, true), 6))->toBe([
            '--noise-ids', '3041,1767,2959,1964,1965,24578', '--flag-activities', '5', '--flag-deletions', '30',
        ]);
});

it('omits --noise-ids when the configured default is also blank, and uses the config default when the option is blank', function () {
    $blank = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], ['noise_event_ids' => '  '], 'UTC', null);
    expect($blank)->toContain('--noise-ids');

    config(['tools.load_audit.noise_event_ids' => '']);
    $none = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], ['noise_event_ids' => ''], 'UTC', null);
    expect($none)->not->toContain('--noise-ids');
});
