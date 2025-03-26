<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ActivityGenerateActivities extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.activity-generate-activities';
    protected static ?string $navigationParentItem = 'Activity Services';

    protected static ?string $navigationGroup = 'Services';
}
