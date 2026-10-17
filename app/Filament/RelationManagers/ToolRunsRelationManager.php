<?php

namespace App\Filament\RelationManagers;

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Tables\ToolRunsTable;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Tools\Contracts\TakesOneInput;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
        return ToolRunsTable::configure($table, $this->getOwnerRecord())
            ->poll(fn (): ?string => $this->getOwnerRecord()->toolRuns()
                ->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])
                ->exists() ? '5s' : null)
            ->headerActions($this->runActions());
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
                    ->default(fn (): ?string => $this->defaultsFor($toolId)[$kind->value] ?? null)
                    ->required(in_array($kind, $tool->requiredKinds(), true))
                    ->native(false);
            }

            foreach ($tool->optionFields() as $field) {
                $fields[] = $field;
            }

            $actions[] = Action::make("run_{$toolId->value}")
                ->label('Run: '.$toolId->getLabel())
                ->icon('heroicon-o-play')
                ->schema($fields)
                ->action(function (array $data) use ($toolId): void {
                    try {
                        app(StartToolRun::class)->start($this->getOwnerRecord(), $toolId, $data['files'] ?? [], $data['options'] ?? [], auth()->user());
                    } catch (UnusableInputException|InvalidArgumentException $e) {
                        Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();
                    }
                });
        }

        return $actions;
    }

    /**
     * The picker's preselection; a tool that takes one export keeps only the first of its kinds that has one.
     *
     * @return array<string, string>
     */
    private function defaultsFor(ToolId $toolId): array
    {
        $defaults = app(RunInputPicker::class)->defaultsFor($this->getOwnerRecord(), $toolId);
        $tool = app(ToolRegistry::class)->for($toolId);
        if (! $tool instanceof TakesOneInput) {
            return $defaults;
        }

        $kept = false;
        foreach ($tool->oneOfKinds() as $kind) {
            if (isset($defaults[$kind->value])) {
                if ($kept) {
                    unset($defaults[$kind->value]);
                }
                $kept = true;
            }
        }

        return $defaults;
    }
}
