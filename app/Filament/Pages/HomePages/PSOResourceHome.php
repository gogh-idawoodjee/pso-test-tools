<?php

namespace App\Filament\Pages\HomePages;

use Filament\Pages\Page;

class PSOResourceHome extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-users';

    protected static string|null|\BackedEnum $activeNavigationIcon = 'heroicon-s-users';

    protected static ?string $slug = 'pso-resource-services';

    //    protected static string $view = 'filament.pages.pso-resource-home';

    protected static ?string $title = 'Resource Services';

    protected static string|null|\UnitEnum $navigationGroup = 'API Services';
}
