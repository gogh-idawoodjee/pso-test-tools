<?php

namespace App\Tools\TrimExport;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\ExportFile;
use App\Support\TrimExport\ExportSource;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimmedExportStore;
use App\Support\TrimExport\TrimPlan;
use App\Tools\Contracts\TakesOneInput;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use RuntimeException;
use ZipArchive;

/**
 * Trim export: one LOAD, plan or End of day export in (XML or JSON, zipped or not), a trimmed copy in the same
 * format filed next to it out, or only the counts on Count only. Its options sit on two wizard steps (Tables,
 * Rows) built by TrimExportEntry, because they depend on the chosen file.
 */
class TrimExportTool implements TakesOneInput, Tool
{
    public function __construct(
        private ExportSource $source,
        private TrimEngine $engine,
        private TrimmedExportStore $store,
    ) {}

    public static function id(): ToolId
    {
        return ToolId::TrimExport;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];
    }

    public function oneOfKinds(): array
    {
        return $this->optionalKinds();
    }

    public function optionFields(): array
    {
        return [];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        if (count($inputPaths) !== 1) {
            throw new RuntimeException('Trim export takes exactly one LOAD, plan or End of day export.');
        }

        $kindValue = (string) array_key_first($inputPaths);
        $localPath = $inputPaths[$kindValue];
        $input = ExportFile::withoutGlobalScopes()->find($context->run->inputs['files'][$kindValue] ?? null)
            ?? throw new RuntimeException('The export used by this run is no longer available.');
        $scratch = dirname($localPath);

        $context->reportProgress('Opening the export', 0, null, 'open');
        $source = $this->source->open($localPath);
        $plan = TrimPlan::fromOptions($options, $context->timeZone);
        $output = $plan->countOnly ? null : $scratch.'/trimmed.'.$source->format;

        $report = $this->engine->run($source->path, $source->format, $plan, $output,
            static fn (string $label, int $percent) => $context->reportProgress($label, $percent, null, $label));

        $newFile = null;
        if ($output !== null) {
            $context->reportProgress('Storing the trimmed export', 100, null, 'store');
            $newFile = $source->zipped()
                ? $this->store->store($context, $input, $this->zip($output, (string) $source->memberName, $scratch.'/trimmed.zip'), 'zip')
                : $this->store->store($context, $input, $output, $source->format);
        }

        $warnings = [...TableDependencies::warnings(array_map('strval', array_keys($report->tables)), $report->removedTables), ...$report->notes];
        if ($report->dangling !== []) {
            $warnings[] = 'Some kept rows point at rows that are not in the file. The list below names them.';
        }

        return new ToolResult(
            result: [
                'tables' => $report->tables,
                'removed_tables' => $report->removedTables,
                'dangling' => $report->dangling,
                'warnings' => $warnings,
                'count_only' => $plan->countOnly,
                'format' => $source->format,
                'zipped' => $source->zipped(),
                'export_file_id' => $newFile?->id,
            ],
            warnings: $warnings,
            summary: [
                'line' => $report->summaryLine($plan->countOnly),
                'tables_removed' => count($report->removedTables),
                'rows_kept' => $report->rowsKept(),
                'rows_removed' => $report->rowsRemoved(),
                'count_only' => $plan->countOnly,
            ],
        );
    }

    /** A zipped input gives a zip back, holding the one trimmed member. */
    private function zip(string $memberPath, string $memberName, string $zipPath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The trimmed export could not be zipped.');
        }

        $zip->addFile($memberPath, TrimmedExportStore::trimmedName($memberName, pathinfo($memberPath, PATHINFO_EXTENSION)));
        if (! $zip->close()) {
            throw new RuntimeException('The trimmed export could not be zipped.');
        }

        return $zipPath;
    }
}
