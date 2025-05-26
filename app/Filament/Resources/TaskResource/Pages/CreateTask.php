<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\CreateUserOwnedResource;
use App\Filament\Resources\TaskResource;

class CreateTask extends CreateUserOwnedResource
{
    protected static string $resource = TaskResource::class;
}
