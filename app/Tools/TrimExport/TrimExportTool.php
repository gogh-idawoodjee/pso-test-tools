<?php

namespace App\Tools\TrimExport;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Trim export: one LOAD, plan or End of day export in, a trimmed copy filed next to it out. Its options sit on
 * two wizard steps (Tables, Rows) built by TrimExportEntry, because they depend on the chosen file.
 */
class TrimExportTool implements Tool
{
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

    public function optionFields(): array
    {
        return [];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        throw new RuntimeException('Trim export is not ready yet.');
    }
}
