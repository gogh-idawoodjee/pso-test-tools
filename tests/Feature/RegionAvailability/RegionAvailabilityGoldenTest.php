<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\DatasetSettings;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\AccountSettingsFile;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Proves the web run hands the real script what the command line does. Needs a real pso-tools checkout and
 * a folder holding one LOAD export named load.(xml|zip). Keep that folder outside this repo (customer data):
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools REGION_AVAILABILITY_SAMPLE_DIR=/path/to/folder php artisan test --filter=RegionAvailabilityGolden
 * Skipped otherwise.
 */
it('gathers the same JSON through the web as the command line', function () {
    $checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    $sample = env('REGION_AVAILABILITY_SAMPLE_DIR');
    if (blank($checkoutPath) || blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH and REGION_AVAILABILITY_SAMPLE_DIR to run the golden test.');
    }
    config(['tools.pso_tools_path' => $checkoutPath, 'tools.region_availability.timeout_seconds' => 600]);

    $load = null;
    foreach (['xml', 'zip'] as $ext) {
        if (is_file("{$sample}/load.{$ext}")) {
            $load = "{$sample}/load.{$ext}";
        }
    }
    expect($load)->not->toBeNull();

    Storage::fake('r2');
    $scratchPath = sys_get_temp_dir().'/exports-golden-ra-'.uniqid();
    $work = sys_get_temp_dir().'/golden-ra-cli-'.uniqid();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => $scratchPath]);
    $removeDirectory = static fn (string $directory) => is_dir($directory) && File::deleteDirectory($directory);
    $settings = ['display_name' => 'Acme', 'time_zone' => 'America/Regina', 'exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'], 'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email']];

    try {
        $dataset = Dataset::factory()->for(Environment::factory()->state(['account_id' => 'ACME']))->create(['name' => 'NORTH', 'settings' => $settings]);
        $storage = app(ExportStorage::class);
        $file = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => pathinfo($load, PATHINFO_EXTENSION)]);
        Storage::disk('r2')->put($storage->objectKey($file), file_get_contents($load));
        $run = ToolRun::factory()->for($dataset, 'scope')->create([
            'tool' => ToolId::RegionAvailability, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null,
            'inputs' => ['files' => ['load' => $file->id], 'options' => ['week_of' => '2026-10-07']],
        ]);

        (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));
        $run->refresh();
        expect($run->status)->toBe(ToolRunStatus::Succeeded, $run->error_message ?? '');

        // The command line, with the same account file and week.
        mkdir($work, 0770, true);
        AccountSettingsFile::write(DatasetSettings::fromArray($settings), "{$work}/accounts", 'ACME');
        $checkout = PsoToolsCheckout::fromConfig();
        $cli = Process::path($work)->timeout(600)->run([
            $checkout->python(), '-I', $checkout->regionAvailabilityScript(), '--load', $load, '--account', 'ACME',
            '--time-zone', 'America/Regina', '--accounts-dir', "{$work}/accounts", '--out-root', "{$work}/out", '--week-of', '2026-10-07',
        ]);
        expect($cli->successful())->toBeTrue($cli->errorOutput());
        $cliJson = json_decode(file_get_contents(glob("{$work}/out/*/*/region_availability_*.json")[0]), true);

        $webData = $run->result['data'];
        unset($cliJson['meta']['generated'], $webData['meta']['generated']);
        expect($webData)->toEqual($cliJson)
            ->and($run->summary['week'])->toBe($cliJson['meta']['week'])
            ->and($run->summary['counts']['techs'])->toBe($cliJson['meta']['counts']['techs']);
    } finally {
        // The fake disk holds a copy of the customer LOAD; never leave it under storage/.
        Storage::disk('r2')->deleteDirectory('');
        $removeDirectory($scratchPath);
        $removeDirectory($work);
    }
});
