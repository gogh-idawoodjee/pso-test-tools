<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;

class ViewEnvironment extends EditRecord
{
    protected static string $resource = EnvironmentResource::class;


    protected static string $view = 'filament.resources.environment-resource.pages.pso-load';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
