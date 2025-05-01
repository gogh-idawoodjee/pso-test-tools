<?php

namespace App\Filament\Resources\AppointmentTemplateResource\Pages;

use App\Filament\Resources\AppointmentTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAppointmentTemplates extends ListRecords
{
    protected static string $resource = AppointmentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
