<?php

namespace App\Filament\Resources\SlotUsageRuleResource\Pages;

use App\Filament\Resources\SlotUsageRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSlotUsageRule extends EditRecord
{
    protected static string $resource = SlotUsageRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
