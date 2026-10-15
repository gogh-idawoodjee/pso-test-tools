<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ToolRunPage;
use App\Models\ToolRun;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRuns extends TableWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent runs')
            ->query(ToolRun::query()->withoutResult()->with(['scope', 'startedBy'])->latest('queued_at')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('tool')->badge(),
                TextColumn::make('scope.name')->label('Dataset or environment'),
                TextColumn::make('status')->badge(),
                TextColumn::make('queued_at')->label('Started')->since(),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->url(static fn (ToolRun $record): string => ToolRunPage::urlFor($record->scope, $record)),
            ])
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Pick a tool above to run one.');
    }
}
