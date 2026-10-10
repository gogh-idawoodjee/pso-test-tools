<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\SlaTypeResource\Pages\ListSlaTypes;

class SlaTypeResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::SlaType;
    }

    public static function getPages(): array
    {
        return ['index' => ListSlaTypes::route('/')];
    }
}
