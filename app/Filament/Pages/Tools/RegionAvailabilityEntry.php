<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class RegionAvailabilityEntry extends ToolEntryPage
{
    public static function tool(): ToolId
    {
        return ToolId::RegionAvailability;
    }
}
