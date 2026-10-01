<?php

namespace App\Support\SysCompare;

use App\Support\SysCompare\Exceptions\InvalidDefinitionsFile;

/**
 * Reads a user-supplied ParamDefinitions.csv (columns Parameter, Definition, Basis).
 */
final class DefinitionsCsv
{
    /**
     * @return list<array{Parameter: string, Definition: string, Basis: string}>
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

            if (! isset($columns['parameter'], $columns['definition'])) {
                throw new InvalidDefinitionsFile("{$displayName}: the first row must have the columns Parameter, Definition and (optionally) Basis.");
            }

            $rows = [];

            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = [
                    'Parameter' => (string) ($fields[$columns['parameter']] ?? ''),
                    'Definition' => (string) ($fields[$columns['definition']] ?? ''),
                    'Basis' => isset($columns['basis']) ? (string) ($fields[$columns['basis']] ?? '') : '',
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
