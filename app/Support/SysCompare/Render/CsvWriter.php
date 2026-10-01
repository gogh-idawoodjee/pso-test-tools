<?php

namespace App\Support\SysCompare\Render;

/**
 * CSV in the shape PowerShell's ConvertTo-Csv produces: every field quoted,
 * embedded quotes doubled, CRLF line endings, and a UTF-8 BOM so Excel shows the
 * U+2423 whitespace marker correctly.
 */
final class CsvWriter
{
    public const string BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function document(array $headers, iterable $rows): string
    {
        $lines = [self::line($headers)];

        foreach ($rows as $row) {
            $lines[] = self::line($row);
        }

        return self::BOM.implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  list<string|int|null>  $values
     */
    private static function line(array $values): string
    {
        return implode(',', array_map(
            static fn (string|int|null $value): string => '"'.str_replace('"', '""', (string) $value).'"',
            $values,
        ));
    }
}
