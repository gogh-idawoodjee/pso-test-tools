<?php

use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\RegionAvailabilityCommand;
use Carbon\Carbon;

function raCheckout(): PsoToolsCheckout
{
    return new PsoToolsCheckout(base_path('tests/Fixtures/pso-tools'), 'python3');
}

it('builds the full command without a week', function () {
    $argv = RegionAvailabilityCommand::build(raCheckout(), '/scratch/run-1/', ['load' => '/scratch/run-1/load.zip'], [], 'America/Regina', 'SATE');

    expect($argv)->toBe([
        'python3', '-I', base_path('tests/Fixtures/pso-tools').'/region-availability/region_availability.py',
        '--load', '/scratch/run-1/load.zip',
        '--account', 'SATE',
        '--time-zone', 'America/Regina',
        '--accounts-dir', '/scratch/run-1/accounts',
        '--out-root', '/scratch/run-1/out',
    ]);
});

it('adds the week when one is given', function () {
    $argv = RegionAvailabilityCommand::build(raCheckout(), '/scratch/run-1', ['load' => '/scratch/run-1/load.xml'], ['week_of' => '2026-10-07'], 'America/Regina', 'SATE');

    expect(array_slice($argv, -2))->toBe(['--week-of', '2026-10-07']);
});

it('normalises the week option', function (mixed $value, ?string $expected) {
    expect(RegionAvailabilityCommand::weekOf($value))->toBe($expected);
})->with([
    'blank' => ['', null],
    'null' => [null, null],
    'date string' => ['2026-10-07', '2026-10-07'],
    'datetime string' => ['2026-10-07T00:00:00', '2026-10-07'],
]);

it('turns a Carbon week into its date', function () {
    expect(RegionAvailabilityCommand::weekOf(Carbon::parse('2026-10-07')))->toBe('2026-10-07');
});
