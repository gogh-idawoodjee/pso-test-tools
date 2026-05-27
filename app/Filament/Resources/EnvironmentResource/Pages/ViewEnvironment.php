<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class ViewEnvironment extends EditRecord
{
    protected static string $resource = EnvironmentResource::class;

    protected string $view = 'filament.resources.environment-resource.pages.pso-load';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
