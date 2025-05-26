<?php

namespace App\Filament\Resources\TaskTypeResource\Pages;

use App\Filament\Resources\CreateUserOwnedResource;
use App\Filament\Resources\TaskTypeResource;

class CreateTaskType extends CreateUserOwnedResource
{
    protected static string $resource = TaskTypeResource::class;
}
