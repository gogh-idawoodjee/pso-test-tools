<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;

class SlotUsageRulesRelationManager extends ReferenceValuesRelationManager
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::SlotUsageRule;
    }
}
