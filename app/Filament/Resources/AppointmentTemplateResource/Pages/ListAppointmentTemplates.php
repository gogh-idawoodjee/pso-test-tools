<?php

namespace App\Filament\Resources\AppointmentTemplateResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\AppointmentTemplateResource;

class ListAppointmentTemplates extends ListDatasetReferenceValues
{
    protected static string $resource = AppointmentTemplateResource::class;
}
