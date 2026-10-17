<?php

namespace App\Tools\Contracts;

use App\Enums\ExportKind;

/** A tool that takes exactly one export of any one of these kinds. It declares them optional, so no kind is required on its own. */
interface TakesOneInput
{
    /** @return list<ExportKind> */
    public function oneOfKinds(): array;
}
