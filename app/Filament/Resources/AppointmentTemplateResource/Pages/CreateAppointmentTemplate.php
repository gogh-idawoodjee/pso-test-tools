<?php

namespace App\Filament\Resources\AppointmentTemplateResource\Pages;

use App\Filament\Resources\AppointmentTemplateResource;
use App\Filament\Resources\CreateUserOwnedResource;

class CreateAppointmentTemplate extends CreateUserOwnedResource
{
    protected static string $resource = AppointmentTemplateResource::class;
}
