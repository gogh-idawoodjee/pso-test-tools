<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
});

it('uploads a json file to the gateway and records the internal id on success', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{"Resources":[]}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);

    (new SendPsoScheduleDataJob(
        $upload->id,
        'https://example.test',
        'acc-1',
        'test-user',
        'secret-password',
    ))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->internal_id)->toBe('1857054');
    expect($upload->compressed_size_bytes)->toBeGreaterThan(0);
    expect($upload->completed_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), '/scheduling/data')
            && $request->hasHeader('apiKey', 'tok-abc')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $request->hasHeader('Content-Type', 'application/json');
    });

    Storage::disk('r2')->assertMissing('gateway-uploads/schedule.json');
});

it('sets Content-Type to application/xml for an xml upload', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.xml', '<dsScheduleData></dsScheduleData>');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.xml',
        'original_filename' => 'schedule.xml',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857055'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/scheduling/data')
        && $request->hasHeader('Content-Type', 'application/xml'));
});

it('fails cleanly when the gateway rejects credentials', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response([], 401),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'bad-user', 'bad-password'))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('Could not authenticate with the PSO gateway. Check the environment credentials.');
});

it('maps a gateway AUTHENTICATION_FAILED response to a plain-language error', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['Message' => 'AUTHENTICATION_FAILED'], 400),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('The PSO gateway rejected the provided credentials.');
});

it('records a plain-language error on a connection failure', function () {
    // Only the /scheduling/data call fails here — /scheduling/session must
    // still succeed, otherwise this exercises the auth-failure branch
    // instead (authenticatePSO() already catches ConnectionException
    // internally and returns null, so a global Http::fake(closure) would
    // never reach the job's own catch block).
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => function () {
            throw new ConnectionException('Connection timed out');
        },
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('Could not reach the PSO gateway (timed out or unreachable).');
});

it('extracts and uploads a zipped json file, and cleans up the extracted temp file', function () {
    $zip = new ZipArchive;
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gwzip').'.zip';
    $zip->open($tmpZipPath, ZipArchive::CREATE);
    $zip->addFromString('dsScheduleData.json', '{"dsScheduleData":{"Resources":[]}}');
    $zip->close();

    Storage::disk('r2')->put('gateway-uploads/schedule.zip', file_get_contents($tmpZipPath));
    unlink($tmpZipPath);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.zip',
        'original_filename' => 'schedule.zip',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857099'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->internal_id)->toBe('1857099');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/scheduling/data')
        && $request->hasHeader('Content-Type', 'application/json'));

    expect(is_dir(storage_path('app/private/gateway-uploads/'.$upload->id)))->toBeFalse();
});

it('removes the whole work directory when the zip entry carries a directory component', function () {
    $zip = new ZipArchive;
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gwzip').'.zip';
    $zip->open($tmpZipPath, ZipArchive::CREATE);
    // What "compress this folder" on macOS/Windows routinely produces: a
    // single entry whose name still carries its parent directory.
    $zip->addFromString('subdir/dsScheduleData.json', '{"dsScheduleData":{"Resources":[]}}');
    $zip->close();

    Storage::disk('r2')->put('gateway-uploads/nesteddir.zip', file_get_contents($tmpZipPath));
    unlink($tmpZipPath);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/nesteddir.zip',
        'original_filename' => 'nesteddir.zip',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857100'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect(is_dir(storage_path('app/private/gateway-uploads/'.$upload->id)))->toBeFalse();
});

it('refuses to touch a stored path outside the upload directory', function () {
    Storage::disk('r2')->put('livewire-tmp/other-users-file.json', '{"secret":true}');

    Http::fake();

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'livewire-tmp/other-users-file.json',
        'original_filename' => 'other-users-file.json',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('not a valid');

    // Neither read nor deleted.
    Storage::disk('r2')->assertExists('livewire-tmp/other-users-file.json');
    Http::assertNothingSent();
});

it('fails with a clear message when the stored file cannot be read back', function () {
    Http::fake();

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/vanished.json',
        'original_filename' => 'vanished.json',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('could not be read');
    Http::assertNothingSent();
});

it('marks a non-terminal upload as failed when the worker dies or times out', function () {
    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
        'status' => PsoGatewayUploadStatus::COMPRESSING,
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))
        ->failed(new TimeoutExceededException('Job has timed out.'));

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('did not finish');
    expect($upload->completed_at)->not->toBeNull();
});

it('leaves an already succeeded upload alone when the failed hook fires late', function () {
    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
        'status' => PsoGatewayUploadStatus::SUCCEEDED,
        'internal_id' => '1234567',
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))
        ->failed(new RuntimeException('worker killed'));

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->error_message)->toBeNull();
});

it('fails cleanly when the zip contains no json or xml entry', function () {
    $zip = new ZipArchive;
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gwzip').'.zip';
    $zip->open($tmpZipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'not schedule data');
    $zip->close();

    Storage::disk('r2')->put('gateway-uploads/bad.zip', file_get_contents($tmpZipPath));
    unlink($tmpZipPath);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/bad.zip',
        'original_filename' => 'bad.zip',
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('.json or .xml');
});
