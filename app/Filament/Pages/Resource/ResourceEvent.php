<?php

namespace App\Filament\Pages\Resource;

use App\Filament\BasePages\PSOResource;

class ResourceEvent extends PSOResource
{


    // Navigation

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';

// Page Information
    protected static ?string $title = 'Generate Event';
    protected static ?string $slug = 'resource-event';

    protected static string $view = 'filament.pages.resource-event';




}
