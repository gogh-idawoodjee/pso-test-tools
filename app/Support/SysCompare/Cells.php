<?php

namespace App\Support\SysCompare;

/**
 * Text conventions shared by every area and renderer.
 */
final class Cells
{
    public const string ABSENT = '(absent)';

    public const string NULL_TEXT = '(null)';

    public const string NONE = '(none)';

    /** Visible marker (U+2423) for a leading or trailing space. */
    public const string SPACE_MARK = "\u{2423}";

    /** Joins the parts of a composite key; cannot occur in a value. */
    public const string KEY_SEPARATOR = "\x1f";

    /**
     * Shows a stored value as-is, except: null/empty reads "(null)" and
     * leading/trailing spaces are made visible with the U+2423 marker.
     */
    public static function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::NULL_TEXT;
        }

        $leadingSpaces = strlen($value) - strlen(ltrim($value, ' '));
        $trailingSpaces = strlen($value) - strlen(rtrim($value, ' '));

        if ($leadingSpaces === 0 && $trailingSpaces === 0) {
            return $value;
        }

        return str_repeat(self::SPACE_MARK, $leadingSpaces)
            .trim($value, ' ')
            .str_repeat(self::SPACE_MARK, $trailingSpaces);
    }

    /**
     * "T / F" for allow / allow_edit.
     *
     * @param  array<string, string>  $row
     */
    public static function allowEdit(array $row): string
    {
        $allow = ($row['allow'] ?? null) === 'true' ? 'T' : 'F';
        $allowEdit = ($row['allow_edit'] ?? null) === 'true' ? 'T' : 'F';

        return "{$allow} / {$allowEdit}";
    }

    /**
     * Case-insensitive order with a case-sensitive tie-break, so the order is stable.
     */
    public static function compareText(string $left, string $right): int
    {
        return strcasecmp($left, $right) ?: strcmp($left, $right);
    }

    /**
     * Compares the leading integer of two strings (0 when not numeric).
     */
    public static function compareInteger(string $left, string $right): int
    {
        return self::toInteger($left) <=> self::toInteger($right);
    }

    public static function toInteger(string $value): int
    {
        return preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1 ? (int) $value : 0;
    }

    /**
     * Profile ordering used by the script: DEFAULT first, CONTRACTORS second, then the rest.
     */
    public static function profileOrder(string $profile): int
    {
        return match ($profile) {
            'DEFAULT' => 0,
            'CONTRACTORS' => 1,
            default => 2,
        };
    }
}
