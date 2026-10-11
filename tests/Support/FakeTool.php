<?php

namespace Tests\Support;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use Throwable;

class FakeTool implements Tool
{
    public static ?Throwable $throw = null;

    public static function id(): ToolId
    {
        return ToolId::LoadAudit;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Sys];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        if (self::$throw) {
            throw self::$throw;
        }

        return new ToolResult(
            result: [
                'seen_kinds' => array_keys($inputPaths),
                'bytes' => filesize($inputPaths['load']),
                'display_name' => $context->settings?->displayName,
                'time_zone' => $context->timeZone,
                'options' => $options,
            ],
            warnings: ['Fake warning'],
            summary: ['counts' => ['red' => 1]],
        );
    }
}
