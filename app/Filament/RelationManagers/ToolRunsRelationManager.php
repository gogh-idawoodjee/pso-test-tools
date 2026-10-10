<?php

namespace App\Filament\RelationManagers;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Jobs\RunToolJob;
use App\Models\ToolRun;
use App\Support\Exports\RunInputPicker;
use App\Tools\ToolRegistry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The Runs tab: start a tool against uploaded exports and open its dashboard. Subclasses are per resource. */
abstract class ToolRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'toolRuns';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Runs';
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll(fn (): ?string => $this->getOwnerRecord()->toolRuns()
                ->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])
                ->exists() ? '10s' : null)
            ->defaultSort('queued_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('tool')->badge(),
                Tables\Columns\TextColumn::make('run_date')->date()->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->tooltip(static fn (ToolRun $record): ?string => $record->error_message),
                Tables\Columns\TextColumn::make('startedBy.name')->label('Started by'),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->placeholder('-'),
            ])
            ->headerActions($this->runActions())
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ToolRun $record): string => ToolRunPage::urlFor($this->getOwnerRecord(), $record)),
                DeleteAction::make(),
            ]);
    }

    /** @return list<Action> one "Run ..." action per tool of this scope that has an implementation registered */
    private function runActions(): array
    {
        $registry = app(ToolRegistry::class);
        $actions = [];

        foreach (ToolId::forScope($this->getOwnerRecord()::class) as $toolId) {
            if (! $registry->has($toolId)) {
                continue;
            }

            $tool = $registry->for($toolId);
            $fields = [];
            foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
                $fields[] = Select::make("files.{$kind->value}")
                    ->label($kind->getLabel().' export')
                    ->options(fn (): array => app(RunInputPicker::class)->optionsFor($this->getOwnerRecord(), $toolId)[$kind->value] ?? [])
                    ->default(fn (): ?string => app(RunInputPicker::class)->defaultsFor($this->getOwnerRecord(), $toolId)[$kind->value] ?? null)
                    ->required(in_array($kind, $tool->requiredKinds(), true))
                    ->native(false);
            }

            $actions[] = Action::make("run_{$toolId->value}")
                ->label('Run '.$toolId->getLabel())
                ->icon('heroicon-o-play')
                ->schema($fields)
                ->action(function (array $data) use ($toolId): void {
                    $run = $this->getOwnerRecord()->toolRuns()->create([
                        'tool' => $toolId,
                        'inputs' => [
                            'files' => array_filter($data['files'] ?? [], static fn ($fileId): bool => filled($fileId)),
                            'options' => $data['options'] ?? [],
                        ],
                        'started_by_user_id' => auth()->id(),
                        'status' => ToolRunStatus::Queued,
                    ]);

                    RunToolJob::dispatch($run->id);
                });
        }

        return $actions;
    }
}
