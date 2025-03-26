<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ActivityDeleteActivities extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationParentItem = 'Activity Services';

    protected static ?string $navigationGroup = 'Services';
    protected static string $view = 'filament.pages.activity-delete-activities';
}
