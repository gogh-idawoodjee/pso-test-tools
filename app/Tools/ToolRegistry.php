<?php

namespace App\Tools;

use App\Enums\Tool as ToolId;
use App\Tools\Contracts\Tool;
use RuntimeException;

/** Maps each tool id to its implementation; every tool port registers itself here. */
class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private array $tools = [];

    /** @param class-string<Tool> $class */
    public function register(ToolId $id, string $class): void
    {
        $this->tools[$id->value] = $class;
    }

    public function has(ToolId $id): bool
    {
        return isset($this->tools[$id->value]);
    }

    public function for(ToolId $id): Tool
    {
        if (! $this->has($id)) {
            throw new RuntimeException("No implementation registered for {$id->getLabel()}.");
        }

        return app($this->tools[$id->value]);
    }
}
