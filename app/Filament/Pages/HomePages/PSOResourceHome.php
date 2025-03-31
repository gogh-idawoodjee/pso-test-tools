<?php

namespace App\Filament\Pages\HomePages;

use Filament\Pages\Page;

class PSOResourceHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.p-s-o-resource-home';


    protected static ?string $title = 'Resource Services';
    protected static ?string $navigationGroup = 'API Services';
}
