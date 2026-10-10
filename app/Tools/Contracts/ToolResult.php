<?php

namespace App\Tools\Contracts;

/** What a tool hands back: its data file and the plain-English warnings the dashboard shows. */
class ToolResult
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $result = [],
        public array $warnings = [],
    ) {}
}
