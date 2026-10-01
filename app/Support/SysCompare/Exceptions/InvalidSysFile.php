<?php

namespace App\Support\SysCompare\Exceptions;

use RuntimeException;

/**
 * A sys file could not be read. The message names the file and the reason only,
 * and never includes any content from the file.
 */
class InvalidSysFile extends RuntimeException
{
    public static function notDsSystemData(string $fileName): self
    {
        return new self("{$fileName}: this is not a PSO system data export (the root element must be DsSystemData).");
    }

    public static function unreadable(string $fileName): self
    {
        return new self("{$fileName}: the file could not be read as XML. It may be damaged or incomplete.");
    }
}
