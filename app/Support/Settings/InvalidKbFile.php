<?php

namespace App\Support\Settings;

use RuntimeException;

/** An uploaded reference document the kb builder could not turn into its file; the message is for the user. */
class InvalidKbFile extends RuntimeException {}
