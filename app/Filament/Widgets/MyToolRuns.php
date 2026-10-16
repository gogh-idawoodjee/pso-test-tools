<?php

namespace App\Filament\Widgets;

use App\Enums\ToolRunStatus;
use App\Filament\Tables\ToolRunsTable;
use App\Models\ToolRun;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** My files: the signed-in user's unfiled runs, each opening its run page. Rendered by the My files page only. */
class MyToolRuns extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return ToolRunsTable::configure($table, auth()->user())
            ->heading('Runs')
            ->query(self::unfiled())
            ->poll(static fn (): ?string => self::unfiled()->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])->exists() ? '5s' : null)
            ->emptyStateDescription('Run Trace an activity, Load Health Audit or Plan travels on Unfiled; the run will appear here.');
    }

    /** @return Builder<ToolRun> */
    private static function unfiled(): Builder
    {
        $user = auth()->user();

        return ToolRun::query()->where('scope_type', $user->getMorphClass())->where('scope_id', (string) $user->getKey());
    }
}
