<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use App\Support\GatewayUploadPath;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Creates an environment owned by a freshly created, authenticated user.
 */
function gatewayUploadEnvironment(array $attributes = []): Environment
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
        ...$attributes,
    ]);
}

it('creates a queued upload row and dispatches the job on submit', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);

    $file = UploadedFile::fake()->create('schedule.json', 500, 'application/json');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(1);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->first();
    expect($upload->status)->toBe(PsoGatewayUploadStatus::QUEUED);
    expect($upload->pso_environment_id)->toBe($environment->id);
    expect($upload->initiated_by_user_id)->toBe($user->id);

    // The path Filament actually generates for this field must satisfy the
    // same guard that rejects crafted paths, otherwise legitimate uploads
    // would be blocked.
    expect($upload->stored_path)->toStartWith('gateway-uploads/');
    expect(GatewayUploadPath::isAllowed($upload->stored_path))->toBeTrue();
    Storage::disk('r2')->assertExists($upload->stored_path);

    Queue::assertPushed(SendPsoScheduleDataJob::class, function ($job) use ($upload) {
        return $job->psoGatewayUploadId === $upload->id
            && $job->baseUrl === 'https://example.test'
            && $job->accountId === 'acc-1'
            && $job->username === 'test-user'
            && $job->password === 'secret-password';
    });
});

it('disables the submit action while an upload is in flight', function () {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    $file = UploadedFile::fake()->create('schedule.json', 500, 'application/json');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'))
        ->assertActionDisabled(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));
});

it('re-enables the submit action once the tracked upload reaches a terminal status', function () {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    $file = UploadedFile::fake()->create('schedule.json', 500, 'application/json');

    $component = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->first();
    $upload->update(['status' => PsoGatewayUploadStatus::SUCCEEDED, 'completed_at' => now()]);

    $component->assertActionEnabled(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));
});

it('accepts every legitimate extension the field allows', function (string $extension, string $mimeType) {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    $file = UploadedFile::fake()->create("schedule.{$extension}", 10, $mimeType);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->first();

    expect($upload)->not->toBeNull();
    expect(GatewayUploadPath::isAllowed($upload->stored_path))->toBeTrue();
    expect($upload->original_filename)->toBe("schedule.{$extension}");
    Queue::assertPushed(SendPsoScheduleDataJob::class);
})->with([
    ['json', 'application/json'],
    ['xml', 'application/xml'],
    ['zip', 'application/zip'],
]);

it('rejects submission when no file is chosen', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);
});

it('rejects a crafted stored path before any r2 read or delete happens', function (string $craftedPath) {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    // Something else in the shared bucket that must survive the submission.
    Storage::disk('r2')->put('livewire-tmp/other-users-file.json', '{"secret":true}');
    Storage::disk('r2')->put('process-files/someone-elses.json', '{"secret":true}');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('gateway_upload_data.gateway_upload_file', $craftedPath)
        ->set('gateway_upload_data.gateway_upload_original_filename', 'looks-legit.json')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);

    Storage::disk('r2')->assertExists('livewire-tmp/other-users-file.json');
    Storage::disk('r2')->assertExists('process-files/someone-elses.json');
})->with([
    'another directory' => ['livewire-tmp/other-users-file.json'],
    'traversal out of the upload directory' => ['gateway-uploads/../process-files/someone-elses.json'],
    'nested under the upload directory' => ['gateway-uploads/nested/other.json'],
    'disallowed extension' => ['gateway-uploads/01JABCDEF.php'],
    'absolute path' => ['/etc/passwd'],
    'no directory at all' => ['other.json'],
]);

it('rejects a stored file that is larger than the 200MB ceiling', function () {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    // A sparse file: 201MB of apparent size without writing 201MB of bytes.
    $oversizePath = Storage::disk('r2')->path('gateway-uploads/01JOVERSIZE.json');
    File::ensureDirectoryExists(dirname($oversizePath));
    $handle = fopen($oversizePath, 'wb');
    fseek($handle, (201 * 1024 * 1024) - 1);
    fwrite($handle, "\0");
    fclose($handle);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('gateway_upload_data.gateway_upload_file', 'gateway-uploads/01JOVERSIZE.json')
        ->set('gateway_upload_data.gateway_upload_original_filename', 'huge.json')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);
});

it('rejects an original filename whose extension is not json, xml or zip', function () {
    Storage::fake('r2');
    Queue::fake();

    $environment = gatewayUploadEnvironment();

    Storage::disk('r2')->put('gateway-uploads/01JABCDEF.json', '{"dsScheduleData":{}}');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('gateway_upload_data.gateway_upload_file', 'gateway-uploads/01JABCDEF.json')
        ->set('gateway_upload_data.gateway_upload_original_filename', 'payload.php')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);
});

it('allows temporary uploads up to the 200MB the feature targets', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toContain('max:204800');
});

it('does not let the client rewrite the tracked upload id', function () {
    Storage::fake('r2');

    $environment = gatewayUploadEnvironment();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('gatewayUploadId', 'some-other-uploads-uuid');
})->throws(CannotUpdateLockedPropertyException::class);

it('never surfaces an upload belonging to another environment', function () {
    Storage::fake('r2');

    $environment = gatewayUploadEnvironment();
    $otherEnvironment = Environment::factory()->create();

    $foreignUpload = PsoGatewayUpload::factory()->for($otherEnvironment, 'environment')->create([
        'stored_path' => 'gateway-uploads/01JFOREIGN.json',
        'original_filename' => 'someone-elses-schedule.json',
        'status' => PsoGatewayUploadStatus::SUCCEEDED,
        'internal_id' => '9999999',
    ]);

    $page = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])->instance();

    // Simulating what a tampered request would produce if `#[Locked]` were
    // ever removed: the scoping query is the second line of defence.
    $page->gatewayUploadId = $foreignUpload->id;

    expect($page->currentGatewayUpload())->toBeNull();
    expect($page->gatewayUploadHistory()->pluck('id'))->not->toContain($foreignUpload->id);
});

it('fails cleanly without creating a row when the stored password is not encrypted', function () {
    Storage::fake('r2');
    Queue::fake();

    // An operator retyped the password field in plaintext, so the ciphertext
    // the tab expects to decrypt is not there.
    $environment = gatewayUploadEnvironment(['password' => 'plaintext-password']);

    $file = UploadedFile::fake()->create('schedule.json', 10, 'application/json');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);
});
