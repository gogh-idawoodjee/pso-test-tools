<?php

namespace App\Filament\Widgets;

use App\Filament\Tables\ExportFilesTable;
use App\Models\ExportFile;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/** My files: the signed-in user's unfiled exports. Rendered by the My files page only, never on the dashboard. */
class MyExportFiles extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** The drop zone above dispatches this once a file is stored; the re-render shows the new row. */
    #[On('export-uploaded')]
    public function refreshAfterUpload(): void {}

    public function table(Table $table): Table
    {
        return ExportFilesTable::configure($table)
            ->heading('Files')
            ->query(self::unfiled())
            ->poll(static fn (): ?string => self::unfiled()->pending()->exists() ? '10s' : null)
            ->emptyStateHeading('No unfiled files')
            ->emptyStateDescription('Drop a file above, or choose Unfiled in Trace an activity, Load Health Audit or Plan travels.');
    }

    /** @return Builder<ExportFile> */
    private static function unfiled(): Builder
    {
        $user = auth()->user();

        return ExportFile::query()->where('scope_type', $user->getMorphClass())->where('scope_id', (string) $user->getKey());
    }
}
