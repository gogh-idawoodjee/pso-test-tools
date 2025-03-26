<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ActivityDeleteSla extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.activity-delete-sla';
    protected static ?string $navigationParentItem = 'Activity Services';

    protected static ?string $navigationGroup = 'Services';
}
