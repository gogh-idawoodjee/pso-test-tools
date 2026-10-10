<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\SlotUsageRuleResource\Pages\ListSlotUsageRules;

class SlotUsageRuleResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::SlotUsageRule;
    }

    public static function getPages(): array
    {
        return ['index' => ListSlotUsageRules::route('/')];
    }
}
