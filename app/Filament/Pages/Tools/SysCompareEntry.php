<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class SysCompareEntry extends ToolWizardPage
{
    public static function tool(): ToolId
    {
        return ToolId::SysCompare;
    }
}
