<?php

namespace App\Support\SysCompare;

/**
 * A canonical form of a parameter value, used ONLY to decide whether two values are the
 * same; what is shown is always the value as stored.
 *
 *  - BOOLEAN: case-insensitive (True = true)
 *  - INTEGER / DOUBLE: numeric (1 = 1.0 for a DOUBLE)
 *  - TIMESPAN: ISO-8601 duration in seconds (PT5M = PT0H5M0S, P2D = PT48H)
 *  - everything else: exact, case- and whitespace-sensitive
 */
final class ValueNormalizer
{
    private const string DURATION = '/^P(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/';

    public static function canonical(?string $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return match (strtoupper($type)) {
            'BOOLEAN' => strtolower(trim($value)),
            'INTEGER' => self::integer($value),
            'DOUBLE' => self::double($value),
            'TIMESPAN' => self::timespan($value),
            default => $value,
        };
    }

    private static function integer(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^[+-]?\d{1,18}$/', $trimmed) !== 1) {
            return $value;
        }

        return (string) (int) $trimmed;
    }

    private static function double(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed !== '' && is_numeric($trimmed) ? (string) (float) $trimmed : $value;
    }

    private static function timespan(string $value): string
    {
        if (preg_match(self::DURATION, trim($value), $matches) !== 1) {
            return $value;
        }

        $multipliers = [1 => 86400.0, 2 => 3600.0, 3 => 60.0, 4 => 1.0];
        $seconds = 0.0;
        $any = false;

        foreach ($multipliers as $group => $multiplier) {
            if (($matches[$group] ?? '') !== '') {
                $any = true;
                $seconds += (float) $matches[$group] * $multiplier;
            }
        }

        return $any ? 'dur:'.$seconds : $value;
    }
}
