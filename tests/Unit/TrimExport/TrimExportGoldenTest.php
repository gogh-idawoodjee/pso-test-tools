<?php

use App\Support\TrimExport\ExportSource;
use App\Support\TrimExport\TableCounter;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;
use Illuminate\Support\Facades\File;

/*
 * Golden tests against real SaskTel exports. They are customer data, so they are NOT in the repo:
 * point PSO_DATA_DIR (in your local .env) at a folder holding SATE/2026-10-04/load.zip and
 * SATE/1005/north_travel_1005.xml. The files are read in place (the LOAD zip is copied to a temp
 * folder removed afterwards) and each test is skipped when its file is not available.
 *   vendor/bin/pest tests/Unit/TrimExport/TrimExportGoldenTest.php
 */
function trimGoldenFile(string $relative): ?string
{
    $folder = env('PSO_DATA_DIR');
    if (! is_string($folder) || $folder === '') {
        return null;
    }

    $path = rtrim($folder, '/').'/'.$relative;

    return is_file($path) ? $path : null;
}

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/trim-golden-'.uniqid();
    mkdir($this->scratch);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('keeps 85 resources and 8 activities for region V1, 5 to 9 Oct, on the SaskTel LOAD (#42)', function () {
    $zip = trimGoldenFile('SATE/2026-10-04/load.zip');
    if ($zip === null) {
        $this->markTestSkipped('The SaskTel LOAD of 2026-10-04 is not available (set PSO_DATA_DIR).');
    }

    copy($zip, $this->scratch.'/load.zip');
    $source = app(ExportSource::class)->open($this->scratch.'/load.zip');
    // Filter Load File took the days in the app zone (America/Toronto); the #42 figures were taken that way.
    $plan = TrimPlan::fromOptions(['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], 'America/Toronto');
    $output = $this->scratch.'/trimmed.'.$source->format;

    $report = app(TrimEngine::class)->run($source->path, $source->format, $plan, $output);

    // Resource_Type start and end location '1' is not in this LOAD at all (#26): a source finding, not ours.
    $locationDangling = array_values(array_filter($report->dangling,
        static fn (array $finding): bool => str_starts_with($finding['field'], 'location_id') && $finding['table'] !== 'Resource_Type'));

    expect($source->format)->toBe('xml')
        ->and($report->tables['Input_Reference']['kept'])->toBe(1)
        ->and($report->tables['Activity']['before'])->toBe(7620)
        ->and($report->tables['Resources']['kept'])->toBe(85)
        ->and($report->tables['Activity']['kept'])->toBe(8)
        ->and($report->tables['Location']['before'])->toBe(3928)
        ->and($report->tables['Location']['kept'])->toBeGreaterThan(0)->toBeLessThan(3928)
        ->and($locationDangling)->toBe([])
        ->and(app(TableCounter::class)->count($output, 'xml'))->toMatchArray(['Input_Reference' => 1, 'Resources' => 85, 'Activity' => 8]);
});

it('removes every Plan and Allocation table from the 135 MB SaskTel export with the Cleanup preset, in time and in flat memory', function () {
    $path = trimGoldenFile('SATE/1005/north_travel_1005.xml');
    if ($path === null) {
        $this->markTestSkipped('The 135 MB SaskTel export is not available (set PSO_DATA_DIR).');
    }

    $plan = TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES], 'UTC');
    $output = $this->scratch.'/trimmed.xml';
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    $report = app(TrimEngine::class)->run($path, 'xml', $plan, $output);

    $seconds = (hrtime(true) - $started) / 1e9;
    $growth = memory_get_peak_usage() - $before;
    $kept = app(TableCounter::class)->count($output, 'xml');
    $planTables = array_values(array_filter(array_keys($kept),
        static fn (string $table): bool => str_starts_with($table, 'Plan') || str_starts_with($table, 'Allocation')));

    fwrite(STDERR, sprintf("\n  135 MB Cleanup trim: %.1f s, peak memory growth %.1f MB\n", $seconds, $growth / 1048576));

    expect($planTables)->toBe([])
        ->and($report->tables['Plan_Travel'])->toBe(['before' => 5081, 'kept' => 0])
        ->and($kept['Input_Reference'] ?? 0)->toBe(1)
        ->and($kept['Activity'])->toBe($report->tables['Activity']['before'])
        ->and($seconds)->toBeLessThan(180.0)
        ->and($growth)->toBeLessThan(64 * 1024 * 1024);
});
