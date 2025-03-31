<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResource;


class ResourceGetDetails extends PSOResource
{

    protected static ?string $title = 'Get Resource Details';
    protected static ?string $slug = 'resource-details';


    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-get-details';
}
