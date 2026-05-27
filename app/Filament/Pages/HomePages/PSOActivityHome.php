<?php

namespace App\Filament\Pages\HomePages;

use Filament\Pages\Page;

class PSOActivityHome extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-document-text';

    protected static string|null|\BackedEnum $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $slug = 'pso-activity-services';

    //    protected static string $view = 'filament.pages.pso-activity-home';

    protected static ?string $title = 'Activity Services';

    protected static string|null|\UnitEnum $navigationGroup = 'API Services';
}
