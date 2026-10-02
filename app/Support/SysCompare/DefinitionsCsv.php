<?php

namespace App\Support\SysCompare;

use App\Support\SysCompare\Exceptions\InvalidDefinitionsFile;

/**
 * Reads a ParamDefinitions.csv: columns Parameter, Definition, Note and Basis.
 *
 *  - Definition replaces the official description (for parameters the catalog does not cover,
 *    or as an override);
 *  - Note is appended after the official description;
 *  - Basis says where it came from (blank = "User-supplied").
 *
 * Only Parameter and at least one of Definition or Note are required.
 */
final class DefinitionsCsv
{
    /**
     * @return list<array{Parameter: string, Definition: string, Note: string, Basis: string}>
     */
    public static function parse(string $path, string $displayName = 'ParamDefinitions.csv'): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidDefinitionsFile("{$displayName}: the file could not be read.");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                throw new InvalidDefinitionsFile("{$displayName}: the file is empty.");
            }

            $columns = [];

            foreach ($header as $position => $name) {
                $columns[strtolower(trim(ltrim((string) $name, "\xEF\xBB\xBF")))] = $position;
            }

            if (! isset($columns['parameter']) || (! isset($columns['definition']) && ! isset($columns['note']))) {
                throw new InvalidDefinitionsFile("{$displayName}: the first row must have the columns Parameter, Definition, Note and Basis (Parameter and at least one of Definition or Note are required).");
            }

            $rows = [];

            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = [
                    'Parameter' => (string) ($fields[$columns['parameter']] ?? ''),
                    'Definition' => isset($columns['definition']) ? (string) ($fields[$columns['definition']] ?? '') : '',
                    'Note' => isset($columns['note']) ? (string) ($fields[$columns['note']] ?? '') : '',
                    'Basis' => isset($columns['basis']) ? (string) ($fields[$columns['basis']] ?? '') : '',
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
