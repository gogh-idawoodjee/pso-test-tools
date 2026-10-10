<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\TaskTypeResource\Pages\ListTaskTypes;

class TaskTypeResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::TaskType;
    }

    public static function getPages(): array
    {
        return ['index' => ListTaskTypes::route('/')];
    }
}
