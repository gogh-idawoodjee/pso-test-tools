<?php

namespace App\Filament\Resources\DatasetResource\Pages;

use App\Filament\Resources\DatasetResource;
use App\Jobs\SyncDatasetReferenceValuesJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ViewDataset extends ViewRecord
{
    protected static string $resource = DatasetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sync from PSO')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Pulls the whole dataset from the PSO gateway and refreshes the regions, skills, task types, slot usage rule sets, appointment templates and SLA types. Rows you edited keep your values. Takes up to a few minutes on a large dataset.')
                ->action(function (): void {
                    $lockKey = SyncDatasetReferenceValuesJob::lockKey($this->record->id);

                    if (! Cache::add($lockKey, true, 600)) {
                        Notification::make()->title('A sync is already running for this dataset.')->warning()->send();

                        return;
                    }

                    SyncDatasetReferenceValuesJob::dispatch($this->record->id);

                    Notification::make()->title('Sync queued')->body('This page refreshes itself while the sync runs.')->success()->send();
                }),
            Actions\EditAction::make(),
        ];
    }

    public function isSyncRunning(): bool
    {
        return Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->record->id));
    }

    /** Target of wire:poll while a sync runs: re-reads the dataset row. */
    public function refreshRecord(): void
    {
        $this->record->refresh();
    }
}
