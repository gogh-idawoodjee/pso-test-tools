<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class RegionAvailabilityEntry extends ToolWizardPage
{
    public static function tool(): ToolId
    {
        return ToolId::RegionAvailability;
    }
}
