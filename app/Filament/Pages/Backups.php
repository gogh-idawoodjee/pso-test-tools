<?php

namespace App\Filament\Pages;

use App\Traits\AdminViewable;
use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;

class Backups extends BaseBackups
{
    use AdminViewable;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    public function getHeading(): string|Htmlable
    {
        return 'Application Backups';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Core';
    }
}
