<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PreferenceCalculator extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $activeNavigationIcon = 'heroicon-s-calculator';
    protected static string $view = 'filament.pages.preference-calculator';


    protected static ?string $navigationGroup = 'Additional Tools';
}
