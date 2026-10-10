<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\AppointmentTemplateResource\Pages\ListAppointmentTemplates;

class AppointmentTemplateResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::AppointmentTemplate;
    }

    public static function getPages(): array
    {
        return ['index' => ListAppointmentTemplates::route('/')];
    }
}
