<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\Contracts\KeepsInputsPrivate;
use App\Tools\Contracts\TakesRunInputs;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\Contracts\WizardOnly;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Runs pso-tools/travel/travel_tuning.py on two or more Plan travels runs of one scope (baseline first), each with
 * an optional SYS export for its polygons, and keeps the page. The tool takes no export files: its inputs are
 * the run's source runs (ToolRun::sourceRuns(): inputs['runs'] with the labels from the encrypted private_inputs),
 * checked by SourceRuns when the run starts and again here. The labels, title, client, dataset name and notes are
 * typed by the user and can hold customer wording, so they are kept encrypted (KeepsInputsPrivate). Each source run's stored CSV and an
 * XML copy of each SYS export go into the run's scratch folder with a manifest; the SYS copies are deleted as soon
 * as the script ends (they hold live credentials). The page outlives the runs and exports it was built from.
 *
 * The script names the run labels in its warnings and its failure line ("reading <label>"). Only the encrypted result
 * keeps them: the warnings stored in the plain column, the failure message and anything ScriptRun logs carry "Run N"
 * (its place in the report, baseline Run 1) instead (see redactor()).
 */
class TravelTuningTool implements KeepsInputsPrivate, TakesRunInputs, Tool, WizardOnly
{
    public function __construct(private SourceRuns $sourceRuns, private SysPolygons $polygons, private ExportStorage $storage) {}

    public static function id(): ToolId
    {
        return ToolId::TravelTuning;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function needs(): string
    {
        return 'Needs two or more Plan travels runs checked with Google';
    }

    public function checkRunInputs(Model $scope, array $picked): array
    {
        return $this->sourceRuns->check($scope, $picked);
    }

    public function privateOptionKeys(): array
    {
        return ['title', 'client', 'dataset', 'notes'];
    }

    public function privateRunKeys(): array
    {
        return ['label'];
    }

    public function optionFields(): array
    {
        return [
            TextInput::make('options.title')->label('Report title')->default('Travel Tuning Report')->maxLength(120)->required(),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('options.client')->label('Client')->helperText('Shown in the page header. Filled in from the dataset settings.')->maxLength(120),
                TextInput::make('options.dataset')->label('Dataset name')->maxLength(120),
            ]),
            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('options.review_minutes')->label('Review threshold (minutes)')->helperText('A leg is flagged when PSO and Google differ by at least this much.')->numeric()->minValue(0)->maxValue(1440)->default(20)->required(),
                TextInput::make('options.small_minutes')->label('Small error (minutes)')->helperText('Errors under this count as close enough.')->numeric()->minValue(0)->maxValue(1440)->default(5)->required(),
                TextInput::make('options.long_leg_km')->label('Long leg (km)')->helperText('Legs at least this long are left off the map.')->numeric()->minValue(0)->maxValue(5000)->default(300)->required(),
                TextInput::make('options.model_tol_min')->label('Model tolerance (minutes)')->helperText('How close a leg must move to the polygon prediction to count as predicted.')->numeric()->minValue(0)->maxValue(1440)->default(1)->required(),
                TextInput::make('options.model_tol_pct')->label('Model tolerance (%)')->numeric()->minValue(0)->maxValue(100)->default(5)->required(),
                TextInput::make('options.region_label')->label('Region label')->helperText('What the page calls a region, as in "Region summary". Filled in from the dataset\'s Region label setting, else Region.')->default('Region')->maxLength(20)->required(),
            ]),
            Select::make('options.tiles')->label('Map tiles')->options(TravelTuningCommand::TILES)->default('esri-gray')->native(false)->required(),
            Textarea::make('options.notes')->label('Consultant notes')
                ->helperText('Markdown: headings, lists, tables, bold, italics, code and links. Shown on the page under Consultant notes. Stored with the run.')
                ->rows(6)->maxLength(20000),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $rows = $context->run->sourceRuns();
        if (count($rows) < 2) {
            throw new RuntimeException('This run has fewer than two plan travels runs to compare.');
        }

        $checkout = PsoToolsCheckout::fromConfig();
        // The folder RunToolJob made for this run (and removes afterwards); this tool reads no export files of its own.
        $workDir = $this->storage->scratchDirectoryFor((string) $context->run->id);
        $sysCopies = [];
        $firstDate = null;
        $redact = self::redactor(array_map(static fn (array $row, int $index): string => SourceRuns::label($row['label'] ?? null, $index), $rows, array_keys($rows)));

        try {
            $context->reportProgress('Gathering the runs', 0, null, 'gather');
            $manifest = [];
            foreach ($rows as $index => $row) {
                $number = $index + 1;
                $source = $this->sourceRun($context, (string) ($row['run_id'] ?? ''), $number);
                if ($index === 0) {
                    $firstDate = is_string($source->summary['first_date'] ?? null) ? $source->summary['first_date'] : null;
                }
                $csvName = "run-{$number}.csv";
                if (file_put_contents("{$workDir}/{$csvName}", $source->result['csv']) === false) {
                    throw new RuntimeException("Row {$number}: the plan travels CSV could not be written for the report.");
                }
                unset($source);

                $polygonName = '';
                if (filled($row['sys_file_id'] ?? null)) {
                    $copy = $this->sysCopy($context, (string) $row['sys_file_id'], $workDir, $number);
                    $sysCopies[] = $copy;
                    $polygonName = basename($copy);
                }
                $manifest[] = [SourceRuns::label($row['label'] ?? null, $index), $csvName, $polygonName];
            }
            $this->writeManifest($workDir, $manifest);

            $notes = trim((string) ($options['notes'] ?? ''));
            if ($notes !== '' && file_put_contents($workDir.'/'.TravelTuningCommand::NOTES, $notes."\n") === false) {
                throw new RuntimeException('The notes could not be written for the report.');
            }

            $generated = Carbon::now($context->timeZone)->format('Y-m-d H:i');
            $argv = TravelTuningCommand::build($checkout, $workDir, $options, $generated, $notes !== '');
            $context->reportProgress('Building the report', 20, null, 'build');
            ScriptRun::execute($context, $workDir, $argv, (int) config('tools.travel_tuning.timeout_seconds', 600), 'The travel tuning report', redact: $redact);
        } finally {
            foreach ($sysCopies as $copy) {
                @unlink($copy);
            }
        }

        $html = @file_get_contents($workDir.'/'.TravelTuningCommand::OUT_HTML);
        $json = @file_get_contents($workDir.'/'.TravelTuningCommand::OUT_JSON);
        $data = $json === false ? null : json_decode($json, true);
        if ($html === false || $html === '' || ! is_array($data)) {
            throw new RuntimeException('The travel tuning report finished but wrote no page.');
        }

        // The script's warnings name labels and counts (spec 3.1); the labels are typed by the user and kept encrypted,
        // so the plain column gets Run N in their place. The page (encrypted) shows them as written.
        $warnings = [];
        foreach ((array) ($data['meta']['warnings'] ?? []) as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $warnings[] = $redact(trim($warning));
            }
        }

        return new ToolResult(result: ['html' => $html], warnings: $warnings, summary: self::summary($data, $firstDate));
    }

    /**
     * Counts only (no labels, no ids), from the report data: the headline the page shows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function summary(array $data, ?string $firstDate): array
    {
        $timeline = array_values(array_filter((array) ($data['timeline'] ?? []), 'is_array'));
        $first = $timeline[0] ?? [];
        $last = $timeline[count($timeline) - 1] ?? [];
        $pair = (array) ($data['acc']['pair'] ?? []);

        $summary = [
            'runs' => count($timeline),
            'baseline_flagged' => (int) ($first['flagged'] ?? 0),
            'final_flagged' => (int) ($last['flagged'] ?? 0),
            'baseline_error_hours' => (float) ($first['total_h'] ?? 0),
            'final_error_hours' => (float) ($last['total_h'] ?? 0),
            'fixed' => (int) ($pair['fixed'] ?? 0),
            'newly_broken' => (int) ($pair['newly_broken'] ?? 0),
            'shared_legs' => (int) ($data['meta']['shared'] ?? 0),
            'first_date' => $firstDate,
        ];
        $summary['line'] = number_format($summary['runs']).' runs: flagged '.number_format($summary['baseline_flagged']).' to '.number_format($summary['final_flagged'])
            .', total error '.number_format($summary['baseline_error_hours'], 1).' to '.number_format($summary['final_error_hours'], 1).' hours, '
            .number_format($summary['fixed']).' fixed, '.number_format($summary['newly_broken']).' newly broken, '
            .number_format($summary['shared_legs']).' shared legs';

        return $summary;
    }

    /**
     * Swaps each run label for "Run N" (its 1-based place in the report, baseline Run 1). strtr() tries the longest
     * label first and replaces in one pass, so "North" inside "North v2" is not half-matched and a label that is
     * itself "Run 1" does not get swapped twice. A label used twice keeps its first place.
     *
     * @param  list<string>  $labels  in report order
     * @return Closure(string): string
     */
    public static function redactor(array $labels): Closure
    {
        $map = [];
        foreach (array_values($labels) as $index => $label) {
            if ($label !== '' && ! array_key_exists($label, $map)) {
                $map[$label] = 'Run '.($index + 1);
            }
        }

        return static fn (string $text): string => $map === [] ? $text : strtr($text, $map);
    }

    private function sourceRun(ToolContext $context, string $runId, int $number): ToolRun
    {
        $source = $runId === '' ? null : ToolRun::query()->withoutGlobalScopes()->find($runId);
        $usable = $source !== null
            && $source->scope_type === $context->scope->getMorphClass()
            && (string) $source->scope_id === (string) $context->scope->getKey()
            && $source->tool === ToolId::PlanTravels
            && $source->status === ToolRunStatus::Succeeded;
        if (! $usable) {
            throw new RuntimeException("Row {$number}: the plan travels run is no longer available, so the report cannot be built. Start again with runs that are still listed.");
        }

        $csv = $source->result['csv'] ?? null;
        $header = is_string($csv) ? (string) strstr($csv."\n", "\n", true) : '';
        if (! str_contains($header, 'Google Duration (min)')) {
            throw new RuntimeException("Row {$number}: the plan travels run has no Google columns. Run plan travels again with Google on.");
        }

        return $source;
    }

    /** The SYS export as polygons-<row>.xml in the scratch folder. It must sit on the dataset's environment, or on the user for Unfiled. */
    private function sysCopy(ToolContext $context, string $fileId, string $workDir, int $number): string
    {
        $scope = $context->scope;
        [$ownerType, $ownerId] = $scope instanceof Dataset
            ? [(new Environment)->getMorphClass(), (string) $scope->environment_id]
            : [$scope->getMorphClass(), (string) $scope->getKey()];

        $file = ExportFile::query()->withoutGlobalScopes()->find($fileId);
        if ($file === null || $file->kind !== ExportKind::Sys || $file->scope_type !== $ownerType || (string) $file->scope_id !== $ownerId) {
            throw new RuntimeException("Row {$number}: the SYS export chosen for the polygons does not belong to this run's environment or unfiled files.");
        }
        if (! $file->isUsable()) {
            throw new RuntimeException("Row {$number}: the SYS export chosen for the polygons is no longer available (exports are kept for ".(int) config('exports.ttl_hours', 12).' hours). Upload it again and start a new run.');
        }

        return $this->polygons->localXml($file, $workDir, "polygons-{$number}");
    }

    /** @param list<array{0: string, 1: string, 2: string}> $rows label, run CSV, polygon file (relative to the manifest) */
    private function writeManifest(string $workDir, array $rows): void
    {
        $handle = @fopen($workDir.'/'.TravelTuningCommand::MANIFEST, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The travel tuning manifest could not be written.');
        }

        try {
            fputcsv($handle, ['Label', 'RunCsv', 'PolygonXml'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }
}
