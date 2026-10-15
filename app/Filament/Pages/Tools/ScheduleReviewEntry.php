<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class ScheduleReviewEntry extends ToolWizardPage
{
    public static function tool(): ToolId
    {
        return ToolId::ScheduleReview;
    }
}
