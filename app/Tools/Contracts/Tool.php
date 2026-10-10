<?php

namespace App\Tools\Contracts;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Tools\ToolContext;

interface Tool
{
    public static function id(): ToolId;

    /** @return list<ExportKind> */
    public function requiredKinds(): array;

    /** @return list<ExportKind> kinds that may be supplied; a dataset tool may list ExportKind::Sys to take the environment's newest SYS */
    public function optionalKinds(): array;

    /** @param array<string, string> $inputPaths kind value => local scratch path */
    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult;
}
