<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResource;


class ResourceUpdateUnavailability extends PSOResource
{

    protected static ?string $title = 'Update Unavailablity';
    protected static ?string $slug = 'resource-update-unavailability';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-update-unavailability';
}
