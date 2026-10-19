<?php

namespace App\Filament\Resources\DatasetResource\Pages;

use App\Filament\Resources\DatasetResource;
use App\Jobs\SyncDatasetReferenceValuesJob;
use App\Models\Dataset;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ViewDataset extends ViewRecord
{
    protected static string $resource = DatasetResource::class;

    /** True once a sync was seen finishing during this visit; the counts list shows only then. */
    public bool $showSyncCounts = false;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label(fn (): string => $this->isSyncRunning() ? 'Syncing…' : 'Sync from PSO')
                ->icon(Heroicon::OutlinedArrowPath)
                ->disabled(fn (): bool => $this->isSyncRunning())
                ->color(fn (): string => $this->isSyncRunning() ? 'gray' : 'primary')
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
            Actions\EditAction::make('editSettings')
                ->label('Edit settings')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->modalHeading(fn (): string => 'Settings for '.$this->record->name)
                ->schema(Dataset::settingsSchema())
                ->slideOver(),
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

        if (! $this->isSyncRunning()) {
            $this->showSyncCounts = true;
            $this->dispatch('dataset-synced');
        }
    }
}
