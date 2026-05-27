<?php

namespace App\Filament\Pages\HomePages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class PSOActivityHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $slug = 'pso-activity-services';

    protected string $view = 'filament.pages.pso-activity-home';

    protected static ?string $title = 'Activity Services';

    protected static string|UnitEnum|null $navigationGroup = 'API Services';
}
