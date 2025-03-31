<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResource;


class ResourceUpdateShift extends PSOResource
{

    protected static ?string $title = 'Update Shift';
    protected static ?string $slug = 'resource-update-shift';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-update-shift';
}
