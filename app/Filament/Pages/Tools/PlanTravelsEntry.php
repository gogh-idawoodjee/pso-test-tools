<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class PlanTravelsEntry extends ToolEntryPage
{
    public static function tool(): ToolId
    {
        return ToolId::PlanTravels;
    }
}
