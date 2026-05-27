<?php

namespace App\Filament\Pages\HomePages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class PSOResourceHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-users';

    protected static ?string $slug = 'pso-resource-services';

    protected string $view = 'filament.pages.pso-resource-home';

    protected static ?string $title = 'Resource Services';

    protected static string|UnitEnum|null $navigationGroup = 'API Services';
}
