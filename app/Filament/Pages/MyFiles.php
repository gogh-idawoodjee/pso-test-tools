<?php

namespace App\Filament\Pages;

use App\Filament\Navigation\Groups;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The signed-in user's Unfiled exports and runs: files that belong to no environment or dataset. */
class MyFiles extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = Groups::ANALYSE;

    protected static ?string $navigationLabel = 'My files';

    protected static ?string $title = 'My files';

    protected static ?string $slug = 'my-files';

    protected string $view = 'filament.pages.my-files';

    public function getSubheading(): ?string
    {
        return 'Exports and runs that belong to no environment or dataset. Only you can see them, and nothing here is sent anywhere.';
    }
}
