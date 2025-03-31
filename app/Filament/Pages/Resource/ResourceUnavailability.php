<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResource;


class ResourceUnavailability extends PSOResource
{

    protected static ?string $title = 'Generate Unavailabiltiy';
    protected static ?string $slug = 'resource-unavailability';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-unavailability';
}
