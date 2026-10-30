<?php

use App\Jobs\SyncDatasetReferenceValuesJob;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Support\ReferenceValues\ReferenceValuesClient;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 2]);
    $this->environment = Environment::factory()->create([
        'base_url' => 'https://acme-pso-tst.ifs.cloud',
        'account_id' => 'ACME',
        'username' => 'GOGH_INT',
        'password' => Crypt::encryptString('secret'),
    ]);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
});

function servicesResponse(array $tables = []): array
{
    return ['data' => [
        'dataset_id' => 'NORTH',
        'fetched_at' => '2026-10-10T12:00:00Z',
        'tables' => array_replace(['Region' => [], 'Skill' => [], 'Activity_Type' => [], 'Slot_Usage_Rule_Set' => [], 'Appointment_Template' => [], 'SLA_Type' => []], $tables),
    ], 'status' => 200];
}

it('calls pso-services with the environment headers and upserts the tables', function () {
    Http::fake(['https://pso-services.test/api/2/dataset/reference-values' => Http::response(servicesResponse([
        'Region' => [['id' => 'R1', 'description' => 'Regina']],
    ]))]);

    runSyncJob($this->dataset->id);

    Http::assertSent(fn ($request) => $request->hasHeader('baseUrl', 'https://acme-pso-tst.ifs.cloud')
        && $request->hasHeader('accountId', 'ACME')
        && $request->hasHeader('datasetId', 'NORTH')
        && $request->hasHeader('username', 'GOGH_INT')
        && $request->hasHeader('password', 'secret'));

    $this->dataset->refresh();
    expect(DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'R1')->exists())->toBeTrue()
        ->and($this->dataset->last_synced_at)->not->toBeNull()
        ->and($this->dataset->last_sync_summary['status'])->toBe('succeeded')
        ->and($this->dataset->last_sync_summary['counts']['region']['created'])->toBe(1);
});

it('records a failure with the services message and leaves last_synced_at alone', function () {
    Http::fake(['*' => Http::response(['message' => ['error' => 'Unexpected response from the PSO gateway.', 'details' => 'root is <html>'], 'status' => 502], 502)]);

    runSyncJob($this->dataset->id);

    $this->dataset->refresh();
    expect($this->dataset->last_synced_at)->toBeNull()
        ->and($this->dataset->last_sync_summary['status'])->toBe('failed')
        ->and($this->dataset->last_sync_summary['error'])->toContain('Unexpected response from the PSO gateway');
});

it('records a connection failure in plain words', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    runSyncJob($this->dataset->id);

    expect($this->dataset->refresh()->last_sync_summary['error'])->toContain('could not be reached');
});

it('releases the sync lock when it finishes, success or failure', function () {
    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);
    Http::fake(['*' => Http::response(servicesResponse())]);

    runSyncJob($this->dataset->id);
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeFalse();

    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);
    Http::fake(['*' => Http::response('boom', 500)]);

    runSyncJob($this->dataset->id);
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeFalse();
});

it('does nothing when the dataset no longer exists', function () {
    Http::fake();

    runSyncJob('00000000-0000-0000-0000-000000000000');

    Http::assertNothingSent();
});

function runSyncJob(string $datasetId): void
{
    (new SyncDatasetReferenceValuesJob($datasetId))->handle(
        app(ReferenceValuesClient::class),
        app(ReferenceValuesUpserter::class),
    );
}
