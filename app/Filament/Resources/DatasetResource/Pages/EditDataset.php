<?php

namespace App\Filament\Resources\DatasetResource\Pages;

use App\Filament\Resources\DatasetResource;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditDataset extends EditRecord
{
    protected static string $resource = DatasetResource::class;

    public function getContentTabIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedWrenchScrewdriver;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
