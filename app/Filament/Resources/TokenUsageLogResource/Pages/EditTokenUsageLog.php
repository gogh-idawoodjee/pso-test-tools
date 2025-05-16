<?php

namespace App\Filament\Resources\TokenUsageLogResource\Pages;

use App\Filament\Resources\TokenUsageLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTokenUsageLog extends EditRecord
{
    protected static string $resource = TokenUsageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
