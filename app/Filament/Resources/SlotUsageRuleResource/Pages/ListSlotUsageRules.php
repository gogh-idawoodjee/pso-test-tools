<?php

namespace App\Filament\Resources\SlotUsageRuleResource\Pages;

use App\Filament\Resources\SlotUsageRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSlotUsageRules extends ListRecords
{
    protected static string $resource = SlotUsageRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->slideOver(),
        ];
    }
}
