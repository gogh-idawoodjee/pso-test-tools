<?php

use App\Enums\ExportFileStatus;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.max_file_megabytes' => 400]);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

function fakeUploadUrl(): void
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUploadUrl')->andReturn(['url' => 'https://r2.example/put', 'headers' => ['Content-Type' => 'application/xml']]);
    Storage::set('r2', $disk);
}

function slotPayload(Dataset $dataset, array $overrides = []): array
{
    return array_replace(['scope_type' => 'dataset', 'scope_id' => $dataset->id, 'kind' => 'load', 'file_name' => 'load.xml', 'file_size' => 1024], $overrides);
}

it('creates an uploading row and returns a presigned url', function () {
    fakeUploadUrl();
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset))
        ->assertCreated()
        ->assertJsonPath('upload.url', 'https://r2.example/put')
        ->assertJsonStructure(['id', 'expires_at']);

    $file = ExportFile::first();
    expect($file->status)->toBe(ExportFileStatus::Uploading)
        ->and($file->content_type)->toBe('xml')
        ->and($file->uploaded_by_user_id)->toBe($this->user->id);
});

it('refuses a dataset the user does not own', function () {
    $other = Dataset::factory()->create();

    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($other))->assertStatus(404);
    expect(ExportFile::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a sys file on a dataset and a load file on an environment', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'sys', 'file_name' => 'sys.xml']))
        ->assertStatus(422)->assertJsonValidationErrors('kind');
    $this->postJson(route('filament.app.exports.slots.store'), ['scope_type' => 'environment', 'scope_id' => $this->dataset->environment_id, 'kind' => 'load', 'file_name' => 'load.xml', 'file_size' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('kind');
});

it('refuses files over the size cap and with the wrong extension', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['file_size' => 401 * 1024 * 1024]))
        ->assertStatus(422)->assertJsonValidationErrors('file_size');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['file_name' => 'load.csv']))
        ->assertStatus(422)->assertJsonValidationErrors('file_name');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'audits', 'file_name' => 'audits.xml']))
        ->assertStatus(422)->assertJsonValidationErrors('file_name');
});

it('uses the right article in the kind and extension errors', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'event_log', 'file_name' => 'events.xml']))
        ->assertStatus(422)->assertJsonPath('errors.file_name.0', 'An IFS event log export must be a .xlsx, .csv file.');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'audits', 'file_name' => 'audits.xml']))
        ->assertStatus(422)->assertJsonPath('errors.file_name.0', 'An Audit messages export must be a .zip file.');
    $this->postJson(route('filament.app.exports.slots.store'), ['scope_type' => 'environment', 'scope_id' => $this->dataset->environment_id, 'kind' => 'audits', 'file_name' => 'audits.zip', 'file_size' => 10])
        ->assertStatus(422)->assertJsonPath('errors.kind.0', 'An Audit messages export cannot be uploaded to an environment.');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['file_name' => 'load.csv']))
        ->assertStatus(422)->assertJsonPath('errors.file_name.0', 'A LOAD export must be a .xml, .json, .zip file.');
});

it('completes an upload whose object arrived with the declared size and queues the summary', function () {
    Queue::fake();
    $file = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['file_size_bytes' => 17, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), '<dsScheduleData/>');

    $this->postJson(route('filament.app.exports.complete', $file))->assertStatus(202);

    expect($file->refresh()->status)->toBe(ExportFileStatus::Summarising);
    Queue::assertPushed(SummariseExportFileJob::class, fn ($job) => $job->exportFileId === $file->id);
});

it('fails the row when the object is missing or the size differs', function () {
    Queue::fake();
    $missing = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create();
    $this->postJson(route('filament.app.exports.complete', $missing))->assertStatus(422);
    expect($missing->refresh()->status)->toBe(ExportFileStatus::Failed);

    $short = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['file_size_bytes' => 999, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($short), '<dsScheduleData/>');
    $this->postJson(route('filament.app.exports.complete', $short))->assertStatus(422);
    expect($short->refresh()->status)->toBe(ExportFileStatus::Failed)->and($short->error_message)->toContain('size');
    Queue::assertNothingPushed();
});

it('does not complete another user file', function () {
    $foreign = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create();

    $this->postJson(route('filament.app.exports.complete', $foreign))->assertStatus(404);
});
