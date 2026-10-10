<?php

namespace App\Filament\Resources\SlotUsageRuleResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\SlotUsageRuleResource;

class ListSlotUsageRules extends ListDatasetReferenceValues
{
    protected static string $resource = SlotUsageRuleResource::class;
}
