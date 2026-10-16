<?php

namespace App\Support\ScheduleData;

use RuntimeException;

/**
 * The file has no top-level `ScheduleData` or `dsScheduleData` wrapper, so a
 * Broadcast cannot be injected into it.
 */
class MissingScheduleDataWrapperException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - can't inject a Broadcast.");
    }
}
