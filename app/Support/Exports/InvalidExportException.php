<?php

namespace App\Support\Exports;

use RuntimeException;

/** An uploaded export is not what its kind says it is. The message is shown to the user as is. */
class InvalidExportException extends RuntimeException {}
