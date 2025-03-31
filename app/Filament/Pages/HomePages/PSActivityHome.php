<?php

namespace App\Filament\Pages\HomePages;

use Filament\Pages\Page;

class PSActivityHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.p-s-activity-home';


    protected static ?string $title = 'Activity Services';
    protected static ?string $navigationGroup = 'API Services';
}
