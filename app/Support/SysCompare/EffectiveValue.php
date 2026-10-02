<?php

namespace App\Support\SysCompare;

/**
 * What an environment really uses for one parameter: the text to show, and the canonical
 * form used to decide sameness. The canonical form of a secret is a hash, never the value.
 */
final readonly class EffectiveValue
{
    public function __construct(
        public string $display,
        public string $compare,
    ) {}
}
