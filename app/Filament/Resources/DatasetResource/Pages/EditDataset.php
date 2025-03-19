<?php

namespace App\Filament\Resources\DatasetResource\Pages;

use App\Filament\Resources\DatasetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDataset extends EditRecord
{
    protected static string $resource = DatasetResource::class;

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-wrench-screwdriver';
    }


    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
