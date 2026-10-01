<?php

use App\Support\SysCompare\EnvironmentVersion;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;
use App\Support\SysCompare\VersionAnalyzer;
use App\Support\SysCompare\VersionReport;
use Tests\Support\SysFileBuilder;

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/syscompare-*')) ?: [] as $file) {
        @unlink($file);
    }
});

function versionEnvironment(string $name, SysFileBuilder $builder): SysEnvironment
{
    return new SysEnvironment($name, app(SysFileReader::class)->read($builder->write($name)));
}

function versionHistory(string $latest, string $stamp = '2025-01-10T08:00:00+00:00'): SysFileBuilder
{
    return SysFileBuilder::make()
        ->version('6.10.0.12', 'Creation', '2022-10-24T16:26:00+00:00', 'SYSTEM_USER')
        ->version('6.15.0', 'Upgrade from 6.14.0', '2024-12-01T01:00:00+00:00', 'ifs')
        ->version($latest, 'Update data', $stamp, 'ifs');
}

function analyzeVersions(array $environments): VersionReport
{
    return app(VersionAnalyzer::class)->analyze($environments, new DateTimeImmutable('2025-02-01 08:00:00', new DateTimeZone('UTC')));
}

it('compares versions numerically, never as text', function (): void {
    expect(VersionAnalyzer::compareVersions('6.13.0.9', '6.13.0.67'))->toBe(-1)
        ->and(VersionAnalyzer::compareVersions('6.13.0.67', '6.13.0.9'))->toBe(1)
        ->and(VersionAnalyzer::compareVersions('6.9.0', '6.10.0'))->toBe(-1)
        ->and(VersionAnalyzer::compareVersions('6.16.0.41', '6.16.0.41'))->toBe(0)
        ->and(VersionAnalyzer::compareVersions('6.13', '6.13.0'))->toBe(-1);
});

it('takes the current version from the highest version id, not the record order or timestamp', function (): void {
    $builder = SysFileBuilder::make()
        ->version('6.13.0.67', 'Update', '2024-08-15T18:22:33+00:00', 'SYSTEM_USER')
        ->version('6.13.0.9', 'Update', '2024-12-31T00:00:00+00:00', 'ifs');

    $report = analyzeVersions([versionEnvironment('A', $builder), versionEnvironment('B', versionHistory('6.13.0.67'))]);

    expect($report->environments[0]->current)->toBe('6.13.0.67');
});

it('finds the last upgrade, last patch, creation date and days since upgrade', function (): void {
    $report = analyzeVersions([versionEnvironment('A', versionHistory('6.15.0.25')), versionEnvironment('B', versionHistory('6.15.0.25'))]);
    $version = $report->environments[0];

    expect($version->upgradedFrom)->toBe('6.14.0')
        ->and($version->upgradedTo)->toBe('6.15.0')
        ->and($version->upgradedAt->format('Y-m-d H:i'))->toBe('2024-12-01 01:00')
        ->and($version->patchVersion)->toBe('6.15.0.25')
        ->and($version->patchedAt->format('Y-m-d'))->toBe('2025-01-10')
        ->and($version->createdAt->format('Y-m-d'))->toBe('2022-10-24')
        ->and($version->daysSinceUpgrade)->toBe(62)
        ->and($version->release)->toBe('6.15');
});

it('lists the full history oldest first', function (): void {
    $builder = SysFileBuilder::make()
        ->version('6.15.0', 'Upgrade from 6.14.0', '2025-05-13T07:10:28+00:00')
        ->version('6.10.0.12', 'Creation', '2022-10-24T16:26:00+00:00');

    $report = analyzeVersions([versionEnvironment('A', $builder), versionEnvironment('B', versionHistory('6.15.0.25'))]);

    expect(array_map(static fn ($record): string => $record->version, $report->environments[0]->history))->toBe(['6.10.0.12', '6.15.0']);
});

it('shows a green match when every environment is on the same version', function (): void {
    $report = analyzeVersions([versionEnvironment('A', versionHistory('6.16.0.41')), versionEnvironment('B', versionHistory('6.16.0.41'))]);

    expect($report->mismatch)->toBeFalse()
        ->and($report->bannerState())->toBe(VersionReport::BANNER_MATCH)
        ->and($report->highestVersion)->toBe('6.16.0.41')
        ->and(collect($report->environments)->pluck('status')->all())->toBe(['LATEST', 'LATEST']);
});

it('flags the same release on different patch builds', function (): void {
    $report = analyzeVersions([
        versionEnvironment('PROD', versionHistory('6.16.0.41')),
        versionEnvironment('TST', versionHistory('6.16.0.33')),
    ]);

    expect($report->mismatch)->toBeTrue()
        ->and($report->bannerState())->toBe(VersionReport::BANNER_MISMATCH)
        ->and($report->kindText)->toBe('Same release (6.16), different patch builds.')
        ->and($report->environments[0]->status)->toBe(EnvironmentVersion::LATEST)
        ->and($report->environments[1]->status)->toBe(EnvironmentVersion::BEHIND)
        ->and(array_map(static fn ($environment): string => $environment->name, $report->behind()))->toBe(['TST']);
});

it('flags different releases and names them', function (): void {
    $report = analyzeVersions([
        versionEnvironment('PROD', versionHistory('6.16.0.41')),
        versionEnvironment('STG', versionHistory('6.15.0.25')),
    ]);

    expect($report->mismatch)->toBeTrue()
        ->and($report->kindText)->toBe('Different RELEASES: PROD 6.16, STG 6.15')
        ->and($report->environments[1]->status)->toBe(EnvironmentVersion::BEHIND);
});

it('never shows a green banner when version data is missing', function (): void {
    $withoutVersions = SysFileBuilder::make()->parameter('DEFAULT', 'A', '1');

    $oneKnown = analyzeVersions([versionEnvironment('A', versionHistory('6.16.0.41')), versionEnvironment('B', $withoutVersions)]);

    expect($oneKnown->bannerState())->toBe(VersionReport::BANNER_UNKNOWN)
        ->and($oneKnown->comparable)->toBeFalse()
        ->and($oneKnown->missingNames)->toBe(['B'])
        ->and($oneKnown->environments[1]->status)->toBe(EnvironmentVersion::UNKNOWN);

    $noneKnown = analyzeVersions([versionEnvironment('A', $withoutVersions), versionEnvironment('B', $withoutVersions)]);

    expect($noneKnown->bannerState())->toBe(VersionReport::BANNER_UNKNOWN);
});

it('still flags a mismatch when a third environment has no version data', function (): void {
    $report = analyzeVersions([
        versionEnvironment('A', versionHistory('6.16.0.41')),
        versionEnvironment('B', versionHistory('6.16.0.33')),
        versionEnvironment('C', SysFileBuilder::make()->parameter('DEFAULT', 'A', '1')),
    ]);

    expect($report->bannerState())->toBe(VersionReport::BANNER_MISMATCH)
        ->and($report->missingNames)->toBe(['C']);
});

it('tolerates unparseable timestamps', function (): void {
    $builder = SysFileBuilder::make()
        ->version('6.15.0', 'Upgrade from 6.14.0', 'not a date')
        ->version('6.15.0.25', 'Update', '');

    $report = analyzeVersions([versionEnvironment('A', $builder), versionEnvironment('B', versionHistory('6.15.0.25'))]);

    expect($report->environments[0]->current)->toBe('6.15.0.25')
        ->and($report->environments[0]->upgradedAt)->toBeNull()
        ->and($report->environments[0]->daysSinceUpgrade)->toBeNull();
});
