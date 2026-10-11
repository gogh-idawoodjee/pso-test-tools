<?php

namespace App\Tools\Contracts;

/** What a tool hands back: its data file, the plain-English warnings the dashboard shows, and small facts for lists. */
class ToolResult
{
    /**
     * @param  array<string, mixed>  $result  stored encrypted
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $summary  stored in plain json for tables (counts only, never customer text)
     */
    public function __construct(
        public array $result = [],
        public array $warnings = [],
        public array $summary = [],
    ) {}
}
