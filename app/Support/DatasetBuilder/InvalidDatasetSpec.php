<?php

namespace App\Support\DatasetBuilder;

use RuntimeException;

/** A seed spec the Dataset Builder refuses to generate from; the message says what to fix. */
class InvalidDatasetSpec extends RuntimeException {}
