<?php

namespace App\Filament\Resources\TaskResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\TaskResource;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;
}
