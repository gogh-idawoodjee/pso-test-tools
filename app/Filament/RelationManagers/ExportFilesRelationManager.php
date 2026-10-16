<?php

namespace App\Filament\RelationManagers;

use App\Filament\Tables\ExportFilesTable;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** The Exports tab: drop zone above a table of uploaded files. Scope-agnostic; subclasses are per resource. */
abstract class ExportFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'exportFiles';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedArrowUpTray;

    /** Filament makes relation managers read-only on a view page; uploads and deletes happen here. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Exports';
    }

    /** The drop zone dispatches this once a file is stored; the re-render shows the new row. */
    #[On('export-uploaded')]
    public function refreshAfterUpload(): void {}

    public function table(Table $table): Table
    {
        return ExportFilesTable::configure($table)
            ->header(fn () => view('livewire.export-drop-zone-header', ['scope' => $this->getOwnerRecord()]))
            ->poll(fn (): ?string => $this->getOwnerRecord()->exportFiles()->pending()->exists() ? '10s' : null);
    }
}
