<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\RegionResource\Pages\ListRegions;

class RegionResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::Region;
    }

    public static function getPages(): array
    {
        return ['index' => ListRegions::route('/')];
    }
}
