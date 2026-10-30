<?php

namespace App\Support\DatasetBuilder;

/** How the Dataset Builder writes numbers: 45 -> "45", 0.6 -> "0.6", no trailing zeros, never scientific notation. */
final class Decimal
{
    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
