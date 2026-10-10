<?php

use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Jobs\SyncDatasetReferenceValuesJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('queues a sync for the dataset and takes the lock', function () {
    Queue::fake();

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->callAction(TestAction::make('sync'))
        ->assertNotified();

    Queue::assertPushed(SyncDatasetReferenceValuesJob::class, fn ($job) => $job->datasetId === $this->dataset->id);
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeTrue();
});

it('refuses a second sync while one is running', function () {
    Queue::fake();
    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);

    // The button is disabled while the lock is held, so a second sync cannot be started from the page.
    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertActionDisabled('sync');

    Queue::assertNothingPushed();
});

it('shows the last sync result', function () {
    $this->dataset->update([
        'last_synced_at' => now(),
        'last_sync_summary' => ['status' => 'succeeded', 'error' => null, 'finished_at' => now()->toIso8601String(), 'counts' => [
            'region' => ['created' => 3, 'updated' => 0, 'kept' => 1, 'missing' => 2],
        ]],
    ]);

    // A fresh visit shows the result and time, but not the counts list.
    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('Succeeded')
        ->assertDontSee('3 created');

    // The visit that watched the sync finish shows the counts once.
    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->call('refreshRecord')
        ->assertSet('showSyncCounts', true)
        ->assertSee('3 created')
        ->assertSee('2 missing');
});

it('does not open another user dataset', function () {
    $other = Dataset::factory()->create();

    expect(fn () => Livewire::test(ViewDataset::class, ['record' => $other->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the sync as running on the button and in the summary while the lock is held', function () {
    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('Syncing')
        ->assertSee('Sync in progress')
        ->assertActionDisabled('sync');
});

it('shows a plain Sync from PSO button when nothing is running', function () {
    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('Sync from PSO')
        ->assertDontSee('Sync in progress')
        ->assertActionEnabled('sync');
});
