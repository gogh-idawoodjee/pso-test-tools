<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class AppointmentTimelineEntry extends ToolWizardPage
{
    public static function tool(): ToolId
    {
        return ToolId::AppointmentTimeline;
    }
}
