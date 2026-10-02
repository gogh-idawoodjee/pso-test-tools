<?php

use App\Support\SysCompare\Comparer;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\Render\CsvBundle;
use App\Support\SysCompare\Render\HtmlReport;
use App\Support\SysCompare\Render\XlsxWorkbook;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;

/*
 * Golden test against the four real reference exports (PROD, ACC, STG, TST).
 * The exports are customer data, so they are NOT in the repo: point
 * SYS_COMPARE_SAMPLES at a folder holding prod.xml, acc.xml, stg.xml and tst.xml.
 * The test is skipped when they are not available.
 */

function sysCompareSampleFolder(): string
{
    return rtrim((string) (env('SYS_COMPARE_SAMPLES') ?: base_path('docs/sys-compare-handoff/reference')), '/');
}

function sysCompareSamplesAvailable(): bool
{
    foreach (['prod', 'acc', 'stg', 'tst'] as $name) {
        if (! is_file(sysCompareSampleFolder()."/{$name}.xml")) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function sysCompareExpected(): array
{
    return json_decode((string) file_get_contents(base_path('docs/sys-compare-handoff/expected/expected_results.json')), true, 512, JSON_THROW_ON_ERROR);
}

function sysCompareGoldenResult(): ComparisonResult
{
    static $result = null;

    $reader = app(SysFileReader::class);
    $environments = array_map(
        static fn (string $name): SysEnvironment => new SysEnvironment(strtoupper($name), $reader->read(sysCompareSampleFolder()."/{$name}.xml")),
        ['prod', 'acc', 'stg', 'tst'],
    );

    return $result ??= app(Comparer::class)->compare($environments, 'PROD', now: new DateTimeImmutable('2026-09-30 12:00:00', new DateTimeZone('UTC')));
}

beforeEach(function (): void {
    if (! sysCompareSamplesAvailable()) {
        $this->markTestSkipped('Reference sys files not available (set SYS_COMPARE_SAMPLES).');
    }
});

it('matches the golden tally per area', function (): void {
    $result = sysCompareGoldenResult();

    foreach (sysCompareExpected()['tally'] as $area => $expected) {
        if ($area === 'Total') {
            continue;
        }

        $tally = collect($result->tally)->firstWhere('area', $area);

        expect($tally)->not->toBeNull("Missing area {$area}")
            ->and($tally->rowsCompared)->toBe($expected['compared'], "{$area}: rows compared")
            ->and($tally->differencesByEnvironment['ACC'])->toBe($expected['ACC'], "{$area}: ACC")
            ->and($tally->differencesByEnvironment['STG'])->toBe($expected['STG'], "{$area}: STG")
            ->and($tally->differencesByEnvironment['TST'])->toBe($expected['TST'], "{$area}: TST")
            ->and($tally->notIdenticalAcrossAll)->toBe($expected['notIdenticalAcrossAll'], "{$area}: not identical across all");
    }
});

it('matches the golden grand totals', function (): void {
    $result = sysCompareGoldenResult();
    $total = sysCompareExpected()['tally']['Total'];

    expect($result->totalRowsCompared())->toBe($total['compared'])
        ->and($result->totalDifferencesFor('ACC'))->toBe($total['ACC'])
        ->and($result->totalDifferencesFor('STG'))->toBe($total['STG'])
        ->and($result->totalDifferencesFor('TST'))->toBe($total['TST'])
        ->and($result->totalNotIdentical())->toBe($total['notIdenticalAcrossAll']);
});

/**
 * @return list<string>
 */
function sysCompareRowValues(string $tabTitle, array $keyValues): array
{
    $row = collect(sysCompareGoldenResult()->tab($tabTitle)->rows())
        ->first(static fn ($candidate): bool => array_slice($candidate->keyValues, 0, count($keyValues)) === $keyValues);

    expect($row)->not->toBeNull('Row not found: '.implode(' / ', $keyValues));

    return $row->cellValues;
}

/**
 * @return array{0: string, 1: list<string>}
 */
function sysCompareParameterRow(string $profile, string $parameter): array
{
    $row = collect(sysCompareGoldenResult()->tab('Parameters')->rows())
        ->first(static fn ($candidate): bool => $candidate->keyValues[0] === $profile && $candidate->keyValues[1] === $parameter);

    expect($row)->not->toBeNull("Parameter row not found: {$profile} / {$parameter}");

    return [$row->status, $row->cellValues];
}

it('applies parameter defaults as the reference describes', function (): void {
    // unset = default: not a difference
    expect(sysCompareParameterRow('DEFAULT', 'AllowSplitTravel'))->toBe(['Same (default)', ['True', '(default: True)', '(default: True)', '(default: True)']])
        ->and(sysCompareParameterRow('DEFAULT', 'CommittedActivitiesConstraintsOption'))->toBe(['Same (default)', ['(default: 1)', '1', '1', '1']])
        ->and(sysCompareParameterRow('DEFAULT', 'RealTimeTravelProvider'))->toBe(['Same (default)', ['NONE', '(default: NONE)', 'NONE', '(default: NONE)']])
        // TIMESPAN: PT5M = PT0H5M0S
        ->and(sysCompareParameterRow('DEFAULT', 'GpsFrequency'))->toBe(['Same (default)', ['(default: PT0H5M0S)', 'PT5M', '(default: PT0H5M0S)', '(default: PT0H5M0S)']])
        ->and(sysCompareParameterRow('DEFAULT', 'SchedulingWindowLength')[0])->toBe('Same (default)');
});

it('still reports real parameter differences once defaults are applied', function (): void {
    expect(sysCompareParameterRow('DEFAULT', 'CommitToAllocatedShift'))->toBe(['DIFF', ['False', 'True', 'False', 'True']])
        ->and(sysCompareParameterRow('DEFAULT', 'ImplicitBreaksOnOffEventsRequired'))->toBe(['DIFF', ['True', '(default: False)', '(default: False)', 'False']])
        ->and(sysCompareParameterRow('DEFAULT', 'SortValuePrecedenceMaximumStatus'))->toBe(['DIFF', ['40', '(default: 30)', '40', '(default: 30)']])
        // BOOLEAN is case-insensitive, so ACC's 'False' equals the default 'false'; only TST's True differs
        ->and(sysCompareParameterRow('DEFAULT', 'StandardSendScheduleExceptionAccepts'))->toBe(['DIFF', ['(default: false)', 'False', '(default: false)', 'True']])
        // STRING stays case-sensitive: ACC's 'Default' is not the default 'DEFAULT'
        ->and(sysCompareParameterRow('DEFAULT', 'TravelTimeProfileId'))->toBe(['DIFF', ['(default: DEFAULT)', 'Default', '(default: DEFAULT)', 'DEFAULT']]);
});

it('does not let profiles inherit from each other', function (): void {
    // PROD's STRAIGHTLINE profile sets nothing: it shows the catalog default, never the DEFAULT profile's value,
    // and it equals TST's explicit StraightLine. ACC and STG have no such profile at all.
    expect(sysCompareParameterRow('STRAIGHTLINE', 'TravelCalculationOption')[1])->toBe(['(default: StraightLine)', '(no profile)', '(no profile)', 'StraightLine']);

    $travel = collect(sysCompareGoldenResult()->tab('Travel')->rows())->first(static fn ($row): bool => $row->keyValues[0] === 'Param: STRAIGHTLINE / TravelCalculationOption');
    $straightLine = $travel->compareValues;

    expect($straightLine[0])->toBe($straightLine[3]);
});

it('splits the parameter rows into the golden status counts', function (): void {
    $counts = collect(sysCompareGoldenResult()->tab('Parameters')->rows())->countBy(static fn ($row): string => $row->status)->all();

    expect($counts)->toEqualCanonicalizing(sysCompareExpected()['tally']['Parameters']['statusCounts']);
});

it('shows the catalog default and description on the parameter rows', function (): void {
    $row = collect(sysCompareGoldenResult()->tab('Parameters')->rows())->first(static fn ($candidate): bool => $candidate->keyValues[1] === 'GpsFrequency');

    expect($row->extra['Default'])->toBe('PT0H5M0S')
        ->and($row->extra['Definition'])->not->toBe('');
});

it('shows leading whitespace with the visible marker', function (): void {
    $values = sysCompareRowValues('Parameters', ['DEFAULT', 'ActivityBaseLabel']);

    expect($values[2])->toStartWith("\u{2423}")
        ->and($values[0])->not->toStartWith("\u{2423}");
});

it('masks the routing API key everywhere and keeps its value out of every cell', function (): void {
    $result = sysCompareGoldenResult();
    $reader = app(SysFileReader::class);

    $rawKeys = collect(['prod', 'acc', 'stg', 'tst'])
        ->flatMap(static fn (string $name): array => $reader->read(sysCompareSampleFolder()."/{$name}.xml")->rows('Profile_Parameter'))
        ->filter(static fn (array $row): bool => stripos($row['parameter_id'] ?? '', 'key') !== false && ($row['parameter_value'] ?? '') !== '')
        ->pluck('parameter_value')
        ->unique()
        ->values();

    expect(sysCompareRowValues('Parameters', ['CommittedActivitiesProfile', 'RoutingApiKey']))->toBe(array_fill(0, 4, '[API key set]'))
        ->and(sysCompareRowValues('Parameters', ['DEFAULT', 'RoutingApiKey']))->toBe(['(default: blank)', '(default: blank)', '[API key set]', '[API key set]'])
        ->and($rawKeys)->toHaveCount(1)
        ->and($result->apiKeyValuesDiffer)->toBeFalse();

    foreach ($result->tabs as $tab) {
        foreach ($tab->rows() as $row) {
            foreach ([...$row->keyValues, ...$row->cellValues, ...$row->compareValues, ...$row->extra] as $value) {
                expect($value)->not->toContain($rawKeys[0]);
            }
        }
    }
});

it('reports the exception type, group and permission spot checks', function (): void {
    $result = sysCompareGoldenResult();

    $attention = array_map(
        static fn (string $value): string => preg_match('/attn (\d+)/', $value, $matches) === 1 ? $matches[1] : '?',
        sysCompareRowValues('Exception Types', ['DEFAULT', '90']),
    );
    expect($attention)->toBe(['10', '2', '10', '2']);

    $presentAs = collect($result->tab('Groups')->rows())->filter(static fn ($row): bool => $row->keyValues[1] === 'Present as');
    $opsManager = $presentAs->first(static fn ($row): bool => strcasecmp($row->keyValues[0], 'ST_Ops_Mgr') === 0);

    expect($presentAs)->toHaveCount(19)
        ->and($opsManager->cellValues)->toBe(['ST_Ops_Mgr', 'ST_Ops_Mgr', 'ST_Ops_MGR', 'ST_Ops_MGR'])
        ->and(collect($result->tab('Group Permissions')->rows())->contains(
            static fn ($row): bool => strcasecmp($row->keyValues[0], 'ST_Ops_Mgr') === 0 && $row->keyValues[2] === 'group spelling differs across environments',
        ))->toBeTrue()
        ->and(sysCompareRowValues('Group Permissions', ['ST_Dispatch', 'Administrator'])[0])->toBe('F / T');
});

it('counts the explicit deny rows in the PROD group permissions', function (): void {
    $rows = app(SysFileReader::class)->read(sysCompareSampleFolder().'/prod.xml')->rows('Group_Permission');
    $denies = array_filter($rows, static fn (array $row): bool => ($row['allow'] ?? '') === 'false');

    expect($rows)->toHaveCount(341)
        ->and($denies)->toHaveCount(241);
});

it('summarises travel setup and table counts as in the golden file', function (): void {
    expect(sysCompareRowValues('Travel', ['Polygons defined']))->toBe(['2', '343', '0', '0'])
        ->and(sysCompareRowValues('Travel', ['Weighting rows'])[1])->toBe('1')
        ->and(sysCompareRowValues('Travel', ['Weighting time zone(s)'])[1])->toBe('America/Regina');

    $users = collect(sysCompareGoldenResult()->tableCounts)->firstWhere('table', 'Users');

    expect($users->counts)->toBe([147, 262, 99, 129])
        ->and($users->scope)->toStartWith('Not compared');
});

it('has a definition for every parameter in the reference files', function (): void {
    $result = sysCompareGoldenResult();
    $distinctParameters = collect($result->tab('Parameters')->rows())->map(static fn ($row): string => $row->keyValues[1])->unique();

    expect($distinctParameters)->toHaveCount(41)
        ->and($result->definitionTemplate)->toBe([]);
});

it('reports the PSO versions as in the golden file', function (): void {
    $versions = sysCompareGoldenResult()->versions;
    $expected = sysCompareExpected()['versions'];

    expect($versions->mismatch)->toBeFalse()
        ->and($versions->highestVersion)->toBe($expected['allEnvironmentsOn'])
        ->and($versions->bannerState())->toBe('match');

    $history = 0;

    foreach ($versions->environments as $environment) {
        $history += count($environment->history);

        expect($environment->current)->toBe($expected['allEnvironmentsOn'])
            ->and($environment->status)->toBe('LATEST')
            ->and($environment->upgradedAt->format('Y-m-d H:i'))->toBe($expected['lastUpgradeUtc'][$environment->name])
            ->and($environment->upgradedFrom.' -> '.$environment->upgradedTo)->toBe('6.15.0 -> 6.16.0')
            ->and($environment->patchVersion.' on '.$environment->patchedAt->format('Y-m-d H:i'))->toBe($expected['lastPatch'][$environment->name]);
    }

    expect($history)->toBe($expected['historyRowsTotal']);
});

it('renders every artifact from the real exports without leaking the API key or any user', function (): void {
    $result = sysCompareGoldenResult();
    $reader = app(SysFileReader::class);

    $rawKey = collect($reader->read(sysCompareSampleFolder().'/prod.xml')->rows('Profile_Parameter'))
        ->first(static fn (array $row): bool => stripos($row['parameter_id'] ?? '', 'key') !== false && ($row['parameter_value'] ?? '') !== '')['parameter_value'];

    $directory = storage_path('framework/testing');
    $xlsx = $directory.'/syscompare-golden.xlsx';
    $zipPath = $directory.'/syscompare-golden.zip';

    app(XlsxWorkbook::class)->write($result, $xlsx);
    app(CsvBundle::class)->write($result, $zipPath);

    $artifacts = ['html' => app(HtmlReport::class)->render($result, showSameRows: true)];

    foreach ([$xlsx, $zipPath] as $archive) {
        $zip = new ZipArchive;
        $zip->open($archive);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $artifacts[basename($archive).'/'.$zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();
    }

    foreach ($artifacts as $name => $content) {
        expect($content)->not->toContain($rawKey, "{$name} leaked the API key");
    }

    expect($artifacts['html'])->toContain('All 4 environments are on the same PSO version: 6.16.0.41')
        ->and($artifacts['html'])->toContain('vbanner ok');

    @unlink($xlsx);
    @unlink($zipPath);
});

it('warns that the 6.14 catalog does not match the 6.16 reference environments', function (): void {
    $result = sysCompareGoldenResult();

    expect($result->catalogVersion)->toBe('6.14')
        ->and($result->catalogVersionMismatches)->toBe(['PROD' => '6.16', 'ACC' => '6.16', 'STG' => '6.16', 'TST' => '6.16'])
        ->and(app(HtmlReport::class)->render($result))->toContain('Parameter defaults may not match these PSO versions');
});
