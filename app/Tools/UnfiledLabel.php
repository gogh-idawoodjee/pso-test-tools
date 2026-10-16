<?php

namespace App\Tools;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Models\User;

/**
 * An Unfiled run has no dataset, so a tool page names it after the file it read: the original file name of
 * the run's input of one kind. It goes only into the script's argv and so into the encrypted result page.
 */
class UnfiledLabel
{
    public static function for(ToolContext $context, ExportKind $kind): ?string
    {
        if (! $context->scope instanceof User) {
            return null;
        }

        $fileId = $context->run->inputs['files'][$kind->value] ?? null;
        if (blank($fileId)) {
            return null;
        }

        $name = ExportFile::withoutGlobalScopes()->whereKey($fileId)->value('original_filename');

        return filled($name) ? (string) $name : null;
    }
}
