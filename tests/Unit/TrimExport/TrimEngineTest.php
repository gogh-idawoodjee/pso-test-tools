<?php

use App\Support\TrimExport\KeyCollector;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\Segment;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;
use App\Support\TrimExport\TrimReport;

/**
 * @param  array<string, mixed>  $options
 * @return array{0: TrimReport, 1: ?string}
 */
function runTrim(array $options, string $format = 'xml', ?array $tables = null, bool $write = true, array $singleObjects = []): array
{
    $tables ??= trimDataset();
    $source = $format === 'xml' ? trimXml($tables) : trimJson($tables, $singleObjects);
    $output = $write ? sys_get_temp_dir().'/trim-engine-'.uniqid().'.'.$format : null;

    return [app(TrimEngine::class)->run($source, $format, TrimPlan::fromOptions($options, 'UTC'), $output), $output];
}

/** @return array<string, int> */
function keptCounts(TrimReport $report): array
{
    return array_map(static fn (array $counts): int => $counts['kept'], $report->tables);
}

const REGION_AND_WINDOW = ['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'];

it('removes whole tables and copies every other row untouched', function () {
    [$report, $output] = runTrim(['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES]);
    $rows = trimmedRows($output, 'xml');

    expect($report->removedTables)->toBe(['Plan', 'Plan_Travel', 'Allocation'])
        ->and(array_keys($rows))->not->toContain('Plan', 'Plan_Travel', 'Allocation')
        ->and($rows['Activity'])->toBe(trimDataset()['Activity'])
        ->and($report->tables['Plan_Travel'])->toBe(['before' => 2, 'kept' => 0])
        ->and($report->dangling)->toBe([])
        ->and($report->summaryLine(false))->toBe('Removed 3 tables and 5 rows. Kept 18 tables and 45 rows.')
        ->and($report->bytesWritten)->toBe(filesize($output));
});

it('never cascades from a table the run removes', function () {
    [$report] = runTrim(['tables' => ['Plan']]);

    expect($report->tables['Plan_Travel']['kept'])->toBe(2)
        ->and($report->tables['Allocation']['kept'])->toBe(2);
});

it('keeps the rows a region and a window choose, and every row that depends on them', function (string $format) {
    [$report, $output] = runTrim(REGION_AND_WINDOW, $format);

    expect(keptCounts($report))->toBe([
        'Input_Reference' => 1, 'Region' => 2, 'Location' => 4, 'Location_Region' => 1, 'Resource_Type' => 1,
        'Resources' => 1, 'Resource_Region' => 1, 'Shift' => 1, 'Shift_Break' => 1, 'Availability' => 2,
        'Resource_Region_Availability' => 1, 'Resource_Skill_Availability' => 1, 'Activity_Type' => 2, 'Activity' => 2,
        'Activity_SLA' => 2, 'Activity_Status' => 1, 'Activity_Group' => 1, 'Activity_Skill' => 1, 'Plan' => 1,
        'Plan_Travel' => 1, 'Allocation' => 1,
    ])->and(array_column(trimmedRows($output, $format)['Activity_Group'], 'activity_id2'))->toBe(['A3'])
        ->and($report->summaryLine(false))->toBe('Removed 0 tables and 21 rows. Kept 21 tables and 29 rows.');
})->with(['xml', 'json']);

it('keeps every Availability row a kept row points at, whatever its date', function () {
    [, $output] = runTrim(REGION_AND_WINDOW);

    expect(array_column(trimmedRows($output, 'xml')['Availability'], 'id'))->toBe(['AV1', 'AV3']);
});

it('keeps a Location only when a kept row uses it', function () {
    [, $output] = runTrim(REGION_AND_WINDOW);

    expect(array_column(trimmedRows($output, 'xml')['Location'], 'id'))->toBe(['L1', 'L3', 'LS1', 'LT'])
        ->and(array_column(trimmedRows($output, 'xml')['Location_Region'], 'location_id'))->toBe(['L3']);
});

it('keeps the status of a kept activity fixed to a removed resource and lists it as dangling', function () {
    [$report, $output] = runTrim(REGION_AND_WINDOW);

    expect(trimmedRows($output, 'xml')['Activity_Status'])->toBe([['activity_id' => 'A1', 'status_id' => '30', 'resource_id' => 'R2']])
        ->and($report->dangling)->toBe([['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']]]);
});

it('lists the references a removed table leaves pointing at nothing, with three examples at most', function () {
    [$report] = runTrim(['tables' => ['Activity']]);

    expect($report->tables['Activity_Status']['kept'])->toBe(2)
        ->and($report->tables['Activity_Group']['kept'])->toBe(2)
        ->and($report->dangling)->toBe([
            ['table' => 'Activity_Group', 'field' => 'activity_id1', 'count' => 2, 'examples' => ['A1']],
            ['table' => 'Activity_Group', 'field' => 'activity_id2', 'count' => 2, 'examples' => ['A3', 'A2']],
            ['table' => 'Activity_SLA', 'field' => 'activity_id', 'count' => 5, 'examples' => ['A1', 'A2', 'A3']],
            ['table' => 'Activity_Skill', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
            ['table' => 'Activity_Status', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
            ['table' => 'Allocation', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
        ]);
});

it('sets the Input_Reference datetime when asked, in xml, json and a one-row json object', function () {
    [, $xml] = runTrim(['input_reference_datetime' => '2026-10-05 06:00']);
    [, $json] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], 'json');
    [, $single] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], 'json', singleObjects: ['Input_Reference']);

    expect(trimmedRows($xml, 'xml')['Input_Reference'][0]['datetime'])->toBe('2026-10-05T06:00:00')
        ->and(trimmedRows($json, 'json')['Input_Reference'][0]['datetime'])->toBe('2026-10-05T06:00:00')
        ->and(json_decode((string) file_get_contents($single), true)['dsScheduleData']['Input_Reference'])
        ->toBe(['id' => 'IR1', 'datetime' => '2026-10-05T06:00:00', 'dataset_id' => 'NORTH']);
});

it('says so when there is no Input_Reference datetime to override', function () {
    [$report] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], tables: ['Activity' => [['id' => 'A1']]]);

    expect($report->notes)->toBe(['The Input_Reference datetime was not changed: the file has no Input_Reference row with a datetime, or it is removed.']);
});

it('keeps Input_Reference in Keep mode unless its removal is allowed', function () {
    [$locked] = runTrim(['table_mode' => 'keep', 'tables' => ['Activity']]);
    [$allowed] = runTrim(['table_mode' => 'keep', 'tables' => ['Activity'], 'allow_input_reference_removal' => true]);

    expect(array_filter(keptCounts($locked)))->toBe(['Input_Reference' => 1, 'Activity' => 4])
        ->and(array_filter(keptCounts($allowed)))->toBe(['Activity' => 4])
        ->and($allowed->removedTables)->toContain('Input_Reference');
});

it('counts the same rows on Count only and writes nothing', function () {
    $options = ['tables' => TableDependencies::CLEANUP_TABLES, ...REGION_AND_WINDOW];
    [$written] = runTrim($options);
    [$counted, $output] = runTrim($options, write: false);

    expect($output)->toBeNull()
        ->and($counted->tables)->toBe($written->tables)
        ->and($counted->dangling)->toBe($written->dangling)
        ->and($counted->bytesWritten)->toBe(0)
        ->and($counted->summaryLine(true))->toBe('Would remove 3 tables and 24 rows, and keep 18 tables and 26 rows. Nothing was written (Count only).');
});

it('gives the same kept rows and counts for the same data as xml and as json', function () {
    [$xmlReport, $xml] = runTrim(REGION_AND_WINDOW);
    [$jsonReport, $json] = runTrim(REGION_AND_WINDOW, 'json');

    expect(trimmedRows($json, 'json'))->toBe(trimmedRows($xml, 'xml'))
        ->and($jsonReport->tables)->toBe($xmlReport->tables)
        ->and($jsonReport->dangling)->toBe($xmlReport->dangling);
});

it('notes filters that match nothing and still writes a loadable file', function () {
    [$report, $output] = runTrim(['region_ids' => ['NOPE']]);

    expect($report->notes)->toBe([
        'No resources match the row filters, so the file keeps none.',
        'No activities match the row filters, so the file keeps none.',
    ])->and($report->tables['Activity']['kept'])->toBe(0)
        ->and(trimmedRows($output, 'xml')['Input_Reference'])->toHaveCount(1);
});

it('reports progress for each pass', function () {
    $calls = [];
    app(TrimEngine::class)->run(trimXml(trimDataset()), 'xml', TrimPlan::fromOptions(REGION_AND_WINDOW, 'UTC'), null,
        static function (string $label, int $percent) use (&$calls): void {
            $calls[] = [$label, $percent];
        });
    $passTwo = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === TrimEngine::PASS_TWO));

    expect(array_values(array_unique(array_column($calls, 0))))->toBe([TrimEngine::PASS_ONE, TrimEngine::PASS_TWO])
        ->and($passTwo[0][1])->toBe(50)
        ->and(end($calls))->toBe([TrimEngine::PASS_TWO, 100]);
});

it('copies every row of the kept tables byte for byte when no row filter is set, without reading the keys', function (string $format) {
    $collector = Mockery::mock(KeyCollector::class);
    $collector->shouldNotReceive('collect');
    app()->instance(KeyCollector::class, $collector);

    $withoutPlan = array_diff_key(trimDataset(), ['Plan' => true]);
    $expected = $format === 'xml' ? trimXml($withoutPlan) : trimJson($withoutPlan);
    [$report, $output] = runTrim(['tables' => ['Plan']], $format);

    expect(file_get_contents($output))->toBe(file_get_contents($expected))
        ->and($report->removedTables)->toBe(['Plan'])
        ->and($report->rowsRemoved())->toBe(1);
})->with(['xml', 'json']);

it('leaves no part file and no output when the file breaks off mid-stream', function (string $format) {
    $source = $format === 'xml' ? trimXml(trimDataset()) : trimJson(trimDataset());
    $bytes = (string) file_get_contents($source);
    file_put_contents($source, substr($bytes, 0, intdiv(strlen($bytes), 2)));
    $output = sys_get_temp_dir().'/trim-engine-'.uniqid().'.'.$format;

    expect(fn () => app(TrimEngine::class)->run($source, $format, TrimPlan::fromOptions(['tables' => ['Plan']], 'UTC'), $output))
        ->toThrow(RuntimeException::class);
    expect(file_exists($output.'.part'))->toBeFalse()
        ->and(file_exists($output))->toBeFalse();
})->with(['xml', 'json']);

it('leaves no part file and no output when a row filter run fails while writing', function () {
    app()->instance(XmlTableReader::class, new class extends XmlTableReader
    {
        private int $passes = 0;

        public function segments(string $path): Generator
        {
            $pass = ++$this->passes;
            $rows = 0;
            foreach (parent::segments($path) as $segment) {
                if ($pass === 2 && $segment->type === Segment::ROW && ++$rows === 10) {
                    throw new RuntimeException('Stopped.');
                }
                yield $segment;
            }
        }
    });
    $output = sys_get_temp_dir().'/trim-engine-'.uniqid().'.xml';

    expect(fn () => app(TrimEngine::class)->run(trimXml(trimDataset()), 'xml', TrimPlan::fromOptions(REGION_AND_WINDOW, 'UTC'), $output))
        ->toThrow(RuntimeException::class, 'Stopped.');
    expect(file_exists($output.'.part'))->toBeFalse()
        ->and(file_exists($output))->toBeFalse();
});

it('keeps an Availability row a kept activity points at even when its resource is removed, and lists that resource as dangling', function (string $format) {
    $tables = trimDataset();
    $tables['Activity'][0]['availability_id'] = 'AV2';
    [$report, $output] = runTrim(REGION_AND_WINDOW, $format, $tables);

    expect(array_column(trimmedRows($output, $format)['Availability'], 'id'))->toBe(['AV1', 'AV2', 'AV3'])
        ->and(array_column(trimmedRows($output, $format)['Resource_Region_Availability'], 'availability_id'))->toBe(['AV1'])
        ->and($report->dangling)->toBe([
            ['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']],
            ['table' => 'Availability', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']],
        ]);
})->with(['xml', 'json']);

it('gives the same kept rows and counts when the tables come in reverse order', function (string $format) {
    [$forward, $forwardOutput] = runTrim(REGION_AND_WINDOW, $format);
    [$reversed, $reversedOutput] = runTrim(REGION_AND_WINDOW, $format, array_reverse(trimDataset(), true));

    $forwardRows = trimmedRows($forwardOutput, $format);
    $reversedRows = trimmedRows($reversedOutput, $format);
    $forwardTables = $forward->tables;
    $reversedTables = $reversed->tables;
    ksort($forwardRows);
    ksort($reversedRows);
    ksort($forwardTables);
    ksort($reversedTables);

    expect(array_keys($reversed->tables))->toBe(array_reverse(array_keys($forward->tables)))
        ->and($reversedRows)->toBe($forwardRows)
        ->and($reversedTables)->toBe($forwardTables)
        ->and($reversed->dangling)->toBe($forward->dangling)
        ->and($reversed->summaryLine(false))->toBe($forward->summaryLine(false));
})->with(['xml', 'json']);

it('builds the keep sets from removed tables too, so a removed table still decides which rows of kept tables go', function (string $format) {
    [$report, $output] = runTrim(['tables' => ['Activity'], 'region_ids' => ['V1']], $format);
    $rows = trimmedRows($output, $format);

    expect($report->removedTables)->toBe(['Activity'])
        ->and(array_keys($rows))->not->toContain('Activity')
        ->and(array_column($rows['Activity_SLA'], 'activity_id'))->toBe(['A1', 'A3', 'A4', 'A4'])
        ->and(array_column($rows['Activity_Skill'], 'activity_id'))->toBe(['A1']);
})->with(['xml', 'json']);
