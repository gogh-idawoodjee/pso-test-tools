<?php

namespace App\Support\Tools;

/**
 * The data a pso-tools page carries in its own script block (`const DATA = {...};`, written by the
 * script's render step as compact JSON on one line, every `<` escaped). Plan travels stores only the
 * page and the CSV, so its legs JSON is read back from here for the JSON download.
 */
class EmbeddedPageData
{
    private const MARKER = 'const DATA = ';

    /** @return array<string, mixed>|null null when the page has no data block or it does not parse */
    public static function fromHtml(string $html): ?array
    {
        $start = strpos($html, self::MARKER);
        if ($start === false) {
            return null;
        }
        $start += strlen(self::MARKER);
        $end = strpos($html, "\n", $start);
        $line = rtrim(substr($html, $start, $end === false ? null : $end - $start));
        $decoded = json_decode(rtrim($line, ';'), true);

        return is_array($decoded) ? $decoded : null;
    }
}
