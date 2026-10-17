<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Tools\ToolRegistry;
use App\Tools\TrimExport\TrimExportTool;
use Filament\Support\Icons\Heroicon;

it('registers Trim export, taking one LOAD, plan or End of day export', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::TrimExport);

    expect($tool)->toBeInstanceOf(TrimExportTool::class)
        ->and($tool->requiredKinds())->toBe([])
        ->and($tool->optionalKinds())->toBe([ExportKind::Load, ExportKind::Plan, ExportKind::Eod])
        ->and($tool->optionFields())->toBe([])
        ->and(ToolId::TrimExport->getLabel())->toBe('Trim export')
        ->and(ToolId::TrimExport->question())->toBe('Can I cut this export down to the tables and rows I need?')
        ->and(ToolId::TrimExport->icon())->toBe(Heroicon::OutlinedScissors)
        ->and(ToolId::TrimExport->scope())->toBe(Dataset::class)
        ->and(ToolId::TrimExport->acceptsUnfiled())->toBeTrue();
});
