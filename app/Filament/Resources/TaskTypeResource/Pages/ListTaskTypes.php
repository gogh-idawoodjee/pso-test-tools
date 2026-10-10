<?php

namespace App\Filament\Resources\TaskTypeResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\TaskTypeResource;

class ListTaskTypes extends ListDatasetReferenceValues
{
    protected static string $resource = TaskTypeResource::class;
}
