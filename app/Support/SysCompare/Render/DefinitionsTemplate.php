<?php

namespace App\Support\SysCompare\Render;

use App\Support\SysCompare\ComparisonResult;

/**
 * ParamDefinitions_Template.csv: every parameter found that has no specific
 * definition yet. Fill in Definition (and Basis), save as ParamDefinitions.csv
 * and upload it with the next comparison.
 */
final class DefinitionsTemplate
{
    public const string FILE_NAME = 'ParamDefinitions_Template.csv';

    /**
     * @return string|null null when every parameter already has a definition
     */
    public static function csv(ComparisonResult $result): ?string
    {
        if ($result->definitionTemplate === []) {
            return null;
        }

        $rows = [];

        foreach ($result->definitionTemplate as $parameter => $hint) {
            $rows[] = [$parameter, '', '', $hint];
        }

        return CsvWriter::document(['Parameter', 'Definition', 'Basis', 'CurrentHint'], $rows);
    }
}
