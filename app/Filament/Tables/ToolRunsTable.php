<?php

namespace App\Filament\Tables;

use App\Filament\Pages\ToolRunPage;
use App\Models\ToolRun;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The runs table: a dataset's or environment's Runs tab, and My files. $scope is whose runs these are. */
class ToolRunsTable
{
    public static function configure(Table $table, Model $scope): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query) => $query->withoutResult())
            ->defaultSort('queued_at', 'desc')
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Start a run from a tool under Analysis tools; it will appear here.')
            ->columns([
                Tables\Columns\TextColumn::make('tool')->badge(),
                Tables\Columns\TextColumn::make('run_date')->date()->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(static fn (ToolRun $record): ?string => $record->progress['label'] ?? null)
                    ->tooltip(static fn (ToolRun $record): ?string => $record->error_message),
                Tables\Columns\TextColumn::make('result_summary')
                    ->label('Result')
                    ->placeholder('-')
                    ->getStateUsing(static function (ToolRun $record): ?string {
                        $summary = $record->summary ?? [];
                        if (is_string($summary['line'] ?? null) && $summary['line'] !== '') {
                            return $summary['line'];
                        }
                        $counts = $summary['counts'] ?? null;
                        if (! is_array($counts) || ! array_key_exists('red', $counts)) {
                            return null;
                        }

                        return ((int) ($counts['red'] ?? 0)).' red, '.((int) ($counts['amber'] ?? 0)).' amber, '.((int) ($counts['green'] ?? 0)).' green';
                    }),
                Tables\Columns\TextColumn::make('startedBy.name')->label('Started by'),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->placeholder('-'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(static fn (ToolRun $record): string => ToolRunPage::urlFor($scope, $record)),
                DeleteAction::make(),
            ]);
    }
}
