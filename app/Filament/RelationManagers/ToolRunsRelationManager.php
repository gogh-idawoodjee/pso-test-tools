<?php

namespace App\Filament\RelationManagers;

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Models\ToolRun;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The Runs tab: start a tool against uploaded exports and open its dashboard. Subclasses are per resource. */
abstract class ToolRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'toolRuns';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPlay;

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
                ->exists() ? '5s' : null)
            ->defaultSort('queued_at', 'desc')
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

            foreach ($tool->optionFields() as $field) {
                $fields[] = $field;
            }

            $actions[] = Action::make("run_{$toolId->value}")
                ->label('Run '.$toolId->getLabel())
                ->icon('heroicon-o-play')
                ->schema($fields)
                ->action(function (array $data) use ($toolId): void {
                    try {
                        app(StartToolRun::class)->start($this->getOwnerRecord(), $toolId, $data['files'] ?? [], $data['options'] ?? [], auth()->user());
                    } catch (UnusableInputException $e) {
                        Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();
                    }
                });
        }

        return $actions;
    }
}
