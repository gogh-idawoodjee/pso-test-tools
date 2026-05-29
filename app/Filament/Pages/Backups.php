<?php

namespace App\Filament\Pages;

use App\Traits\AdminViewable;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;

class Backups extends BaseBackups
{
    use AdminViewable;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedCpuChip;

    public function getHeading(): string|Htmlable
    {
        return 'Application Backups';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Core';
    }
}
