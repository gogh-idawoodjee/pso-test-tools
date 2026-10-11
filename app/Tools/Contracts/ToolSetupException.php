<?php

namespace App\Tools\Contracts;

use RuntimeException;

/** The server lacks something a tool needs (a path, a binary). The message is shown to the user as the run's failure. */
class ToolSetupException extends RuntimeException {}
