<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEnvironment extends EditRecord
{
    protected static string $resource = EnvironmentResource::class;
    protected static ?string $title = 'Manage Environment';
    protected static ?string $breadcrumb = 'Manage Environment';

    protected function getHeaderActions(): array
    {
        return [
//            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
