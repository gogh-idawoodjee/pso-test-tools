<?php

namespace App\Tools\Contracts;

use RuntimeException;

/**
 * A run that failed part of the way with something worth showing: RunToolJob stores the partial result,
 * warnings and summary, then marks the run failed with this message. Plan travels uses it when Google
 * fails after the legs were built, so the run still shows the legs and says why Google is missing.
 */
class ToolFailedWithResult extends RuntimeException
{
    public function __construct(string $message, public ToolResult $partial)
    {
        parent::__construct($message);
    }
}
