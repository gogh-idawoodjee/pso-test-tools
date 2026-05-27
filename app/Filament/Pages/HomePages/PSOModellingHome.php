<?php

namespace App\Filament\Pages\HomePages;

use Filament\Pages\Page;

class PSOModellingHome extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cube-transparent';

    protected static string|null|\BackedEnum $activeNavigationIcon = 'heroicon-s-cube-transparent';

    protected static ?string $slug = 'pso-modelling-services';

    protected string $view = 'filament.pages.modelling-services';

    protected static ?string $title = 'Modelling Services';

    protected static string|null|\UnitEnum $navigationGroup = 'API Services';
}
