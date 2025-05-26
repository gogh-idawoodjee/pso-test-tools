<?php

namespace App\Filament\Pages;

use App\Traits\AdminViewable;
use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults as BaseHealthCheckResults;

class HealthCheckResults extends BaseHealthCheckResults
{
    use AdminViewable;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    public function getHeading(): string|Htmlable
    {
        return 'Health Check Results';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Core';
    }
}
