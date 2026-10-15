<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RecentRuns;
use App\Filament\Widgets\ToolCatalogue;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/** Home: what can I do here, and what did I run lately. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Home';

    public function getWidgets(): array
    {
        return [AccountWidget::class, ToolCatalogue::class, RecentRuns::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
