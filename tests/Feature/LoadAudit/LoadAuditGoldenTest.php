<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Proves the web run hands the real script exactly what the command line does. Needs a real pso-tools
 * checkout and a folder holding one LOAD export, one plan export and (optionally) one SYS export, named
 * load.(xml|zip), plan.(xml|zip) and sys.(xml|zip). Keep that folder outside this repo (customer data):
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools LOAD_AUDIT_SAMPLE_DIR=/path/to/sample-folder php artisan test --filter=Golden
 * Skipped otherwise. Mind that some sample sets have load and plan swapped; check the file contents first.
 */
it('produces the same findings through the web as the command line', function () {
    $checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    $sample = env('LOAD_AUDIT_SAMPLE_DIR');
    if (blank($checkoutPath) || blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH and LOAD_AUDIT_SAMPLE_DIR to run the golden test.');
    }
    config(['tools.pso_tools_path' => $checkoutPath, 'tools.load_audit.timeout_seconds' => 840]);

    $find = static function (string $name) use ($sample): ?string {
        foreach (['xml', 'zip'] as $ext) {
            if (is_file("{$sample}/{$name}.{$ext}")) {
                return "{$sample}/{$name}.{$ext}";
            }
        }

        return null;
    };
    $paths = array_filter(['load' => $find('load'), 'plan' => $find('plan'), 'sys' => $find('sys')]);
    expect($paths)->toHaveKey('load');

    Storage::fake('r2');
    $scratchPath = sys_get_temp_dir().'/exports-golden-'.uniqid();
    $work = sys_get_temp_dir().'/golden-cli-'.uniqid();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => $scratchPath]);
    $removeDirectory = static fn (string $directory) => is_dir($directory) && File::deleteDirectory($directory);

    try {
        $dataset = Dataset::factory()->for(Environment::factory()->state(['account_id' => 'ACME']))->create(['name' => 'NORTH', 'settings' => ['display_name' => 'Example Org', 'time_zone' => 'America/Regina']]);
        $storage = app(ExportStorage::class);
        $files = [];
        foreach ($paths as $kind => $path) {
            $scope = $kind === 'sys' ? $dataset->environment : $dataset;
            $file = ExportFile::factory()->for($scope, 'scope')->create(['kind' => ExportKind::from($kind), 'content_type' => pathinfo($path, PATHINFO_EXTENSION)]);
            Storage::disk('r2')->put($storage->objectKey($file), file_get_contents($path));
            $files[$kind] = $file->id;
        }
        $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null, 'inputs' => ['files' => $files, 'options' => []]]);

        (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));
        $run->refresh();
        expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message);

        // The command line, with the same arguments the tool used, into a folder of its own.
        mkdir($work.'/'.LoadAuditCommand::ACCOUNTS_DIR, 0770, true);
        $argv = LoadAuditCommand::build(PsoToolsCheckout::fromConfig(), $work, $paths, [], 'America/Regina', 'Example Org');
        $process = Process::path($work)->timeout(840)->run($argv);
        expect($process->successful())->toBeTrue($process->errorOutput());

        $cli = json_decode(file_get_contents($work.'/'.LoadAuditCommand::OUT_JSON), true);
        expect($cli)->toBeArray();
        // The script also emits INFO findings, which the red/amber/green counts leave out on purpose.
        $infoFindings = count(array_filter($cli, static fn (array $finding): bool => strtoupper((string) ($finding['severity'] ?? '')) === 'INFO'));
        expect($run->result['findings'])->toEqual($cli)
            ->and($run->result['counts']['red'] + $run->result['counts']['amber'] + $run->result['counts']['green'] + $infoFindings)->toBe(count($cli));
    } finally {
        $removeDirectory($scratchPath);
        $removeDirectory($work);
    }
});
