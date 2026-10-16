<?php

use App\Enums\GatewayUploadLogLevel;
use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use App\Support\GatewayUpload\StoredText;
use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\RewriteReport;
use App\Support\ScheduleData\ScheduleDataRewriter;
use App\Support\ScheduleData\ScheduleDataRewriterFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PsoServicesFake;

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
        ...PsoServicesFake::session(),
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

it('populates dataset id, input reference datetime, and entity counts from the uploaded file', function () {
    $xml = <<<'XML'
        <dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">
            <Input_Reference>
                <datetime>2026-09-10T12:26:05-04:00</datetime>
                <dataset_id>DCU</dataset_id>
            </Input_Reference>
            <Resources><id>Trailer_130</id></Resources>
            <Resources><id>Trailer_150</id></Resources>
            <Resources><id>Trailer_165</id></Resources>
        </dsScheduleData>
        XML;

    Storage::disk('r2')->put('gateway-uploads/schedule.xml', $xml);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.xml',
        'original_filename' => 'schedule.xml',
    ]);

    Http::fake([
        ...PsoServicesFake::session(),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    $upload->refresh();

    expect($upload->dataset_id)->toBe('DCU');
    expect($upload->input_reference_datetime)->not->toBeNull();
    expect($upload->resource_count)->toBe(3);
    expect($upload->activity_count)->toBe(0);
});

it('still succeeds even when the uploaded file cannot be parsed for summary metadata', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', 'not valid json at all');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        ...PsoServicesFake::session(),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->dataset_id)->toBeNull();
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
        ...PsoServicesFake::session(),
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
        ...PsoServicesFake::session(logonStatus: 401),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'bad-user', 'bad-password'))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('Could not log on to the PSO gateway: Unauthorized. Please check your session or login credentials.');
});

it('maps a gateway AUTHENTICATION_FAILED response to a plain-language error', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        ...PsoServicesFake::session(),
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
        ...PsoServicesFake::session(),
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
        ...PsoServicesFake::session(),
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
        ...PsoServicesFake::session(),
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

function webhookJobUpload(string $path, string $contents, array $attributes = []): PsoGatewayUpload
{
    Storage::disk('r2')->put($path, $contents);

    return PsoGatewayUpload::factory()
        ->for(Environment::factory()->create(['name' => 'SaskTel TST']), 'environment')
        ->create([
            'stored_path' => $path,
            'original_filename' => basename($path),
            'skip_webhook' => false,
            ...$attributes,
        ]);
}

function runUploadJob(PsoGatewayUpload $upload): void
{
    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();
}

function fakeWebhookAndGateway(array $extra = []): void
{
    config([
        'services.webhook.base_url' => 'https://webhook.example.test',
        'services.webhook.alias_key' => 'alias-key-123',
    ]);

    Http::fake([
        ...PsoServicesFake::session(),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
        ...$extra,
    ]);
}

function sentPayload(): array
{
    $sent = null;

    Http::assertSent(static function ($request) use (&$sent) {
        if (str_contains((string) $request->url(), '/scheduling/data')) {
            $sent = json_decode(gzdecode((string) $request->body()), true, flags: JSON_THROW_ON_ERROR);

            return true;
        }

        return false;
    });

    return $sent;
}

it('injects the webhook broadcast and records the webhook and response', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Resources":[{"id":"R1"}]}}');

    runUploadJob($upload);

    $upload->refresh();
    $payload = sentPayload();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->webhook_url)->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($upload->webhook_view_url)->toBe('https://webhook.example.test/#!/uuid-1');
    expect($upload->response_status)->toBe(200);
    expect($upload->response_body)->toContain('1857054');
    expect($upload->wire_bytes)->toBeGreaterThan(0);
    expect($upload->post_duration_ms)->not->toBeNull();
    expect($payload['ScheduleData']['Broadcast'][0]['maximum_wait'])->toBe('PT5M');
    expect($payload['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
    expect(collect($payload['ScheduleData']['Broadcast_Parameter'])->firstWhere('parameter_name', 'url')['parameter_value'])
        ->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($payload['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('OLD');
});

it('uses the per-upload broadcast settings', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Resources":[]}}',
        ['broadcast_settings' => BroadcastSettings::fromArray(['id' => 'MY_HOOK', 'maximum_wait' => 'PT10M', 'minimum_plan_quality' => 90])->toArray()],
    );

    runUploadJob($upload);

    $broadcast = sentPayload()['ScheduleData']['Broadcast'][0];

    expect($broadcast['id'])->toBe('MY_HOOK');
    expect($broadcast['maximum_wait'])->toBe('PT10M');
    expect($broadcast['minimum_plan_quality'])->toBe(90);
});

it('sends the original bytes when the webhook is skipped and no dataset id is given', function () {
    fakeWebhookAndGateway();
    $original = '{"ScheduleData":{"Resources":[{"id":"R1"}]}}';
    $upload = webhookJobUpload('gateway-uploads/schedule.json', $original, ['skip_webhook' => true]);

    runUploadJob($upload);

    expect(json_encode(sentPayload()))->toBe(json_encode(json_decode($original, true)));
    expect($upload->refresh()->webhook_url)->toBeNull();
    expect($upload->logLines->pluck('text')->implode("\n"))
        ->toContain('Existing Broadcast rows: none found')
        ->not->toContain('removed (0 Broadcast');
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), 'webhook.example.test'));
});

it('applies a dataset id override even when the webhook is skipped', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}',
        ['skip_webhook' => true, 'dataset_id_override' => 'NEW'],
    );

    runUploadJob($upload);

    $upload->refresh();

    expect(sentPayload()['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($upload->dataset_id)->toBe('NEW');
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain("'OLD' -> 'NEW'");
});

it('injects into an xml upload', function () {
    fakeWebhookAndGateway();
    $xml = '<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd"><Resources><id>R1</id></Resources></dsScheduleData>';
    $upload = webhookJobUpload('gateway-uploads/schedule.xml', $xml);

    runUploadJob($upload);

    $sentXml = null;
    Http::assertSent(static function ($request) use (&$sentXml) {
        if (str_contains((string) $request->url(), '/scheduling/data')) {
            $sentXml = gzdecode((string) $request->body());

            return $request->hasHeader('Content-Type', 'application/xml');
        }

        return false;
    });

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($sentXml)->toContain('<Broadcast>')->toContain('<maximum_wait>PT5M</maximum_wait>');
});

it('fails the run and sends nothing to PSO when the webhook cannot be provisioned', function () {
    fakeWebhookAndGateway(['https://webhook.example.test/_alias/*' => Http::response('forbidden', 403)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('403');
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
    Http::assertNotSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl());
});

it('fails with the script message when the file has no schedule data wrapper', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"Other":{}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
});

it('logs off through pso-services after a successful upload', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    Http::assertSent(static fn ($request) => $request->method() === 'DELETE'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('token', 'tok-abc'));
});

it('still logs off when the gateway POST fails', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => Http::response(['Message' => 'Invalid Parameters'], 400)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    Http::assertSent(static fn ($request) => $request->method() === 'DELETE' && $request->url() === PsoServicesFake::sessionUrl());
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain('HTTP status      : 400');
});

it('still logs off when the gateway POST times out', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => static function () {
        throw new ConnectionException('Connection timed out');
    }]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    Http::assertSent(static fn ($request) => $request->method() === 'DELETE' && $request->url() === PsoServicesFake::sessionUrl());
});

it('treats a failing logoff as a warning, not a failed run', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        ...PsoServicesFake::session(logoffStatus: 500),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->logLines->where('level', GatewayUploadLogLevel::WARN)->pluck('text')->implode("\n"))->toContain('Logoff failed');
});

it('puts the pso-services reason in the run log when logon is refused', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        PsoServicesFake::sessionUrl() => Http::response(['message' => 'Production URLs are not allowed', 'errors' => ['baseUrl' => ['Production URLs are not allowed']]], 422),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain('Production URLs are not allowed');
});

it('writes the script-style steps to the run log and never a secret', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Broadcast":[{"id":"X"}]}}');

    runUploadJob($upload);

    $text = $upload->refresh()->logLines()->orderBy('id')->pluck('text')->implode("\n");

    expect($text)->toContain('PSO RESTful Gateway - Schedule Data Upload');
    expect($text)->toContain('Created new alias /pso-sasktel-tst');
    expect($text)->toContain('Stripped existing broadcast from the file: 1 Broadcast and 0 Broadcast_Parameter row(s) removed.');
    expect($text)->toContain("Injected Broadcast 'WEBHOOK_COMPLETE'");
    expect($text)->toContain('maximum_wait=PT5M');
    expect($text)->toContain('required_tables: Allocation,Plan,');
    expect($text)->toContain('Logon successful');
    expect($text)->toContain('Response body:');
    expect($text)->toContain('Done in');
    expect($text)->toContain('Webhook endpoint for this run: https://webhook.example.test/#!/uuid-1');
    expect($text)->not->toContain('secret-password');
    expect($text)->not->toContain('tok-abc');
    expect($text)->not->toContain('alias-key-123');
});

it('logs "nothing to strip" when the file has no broadcast rows', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->logLines->pluck('text')->implode("\n"))->toContain('No existing Broadcast or Broadcast_Parameter in the file - nothing to strip.');
});

it('puts the pso-services reason on the upload when logon is refused', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        PsoServicesFake::sessionUrl() => Http::response(['message' => 'Production URLs are not allowed', 'errors' => ['baseUrl' => ['Production URLs are not allowed']]], 422),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->error_message)->toBe('Could not log on to the PSO gateway: Production URLs are not allowed');
    expect($upload->logLines->where('level', GatewayUploadLogLevel::ERROR)->pluck('text')->implode("\n"))->toContain('Production URLs are not allowed');
    expect($upload->logLines->where('level', GatewayUploadLogLevel::ERROR)->pluck('text')->implode("\n"))->toContain('LOGON FAILED');
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
    Http::assertNotSent(static fn ($request) => $request->method() === 'DELETE');
});

it('shows an unreachable pso-services as the logon failure reason', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        PsoServicesFake::sessionUrl() => static function () {
            throw new ConnectionException('cURL error 28');
        },
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('could not be reached');
});

it('writes the log steps in the script order', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Resources":[]}}',
        ['dataset_id_override' => 'NEW'],
    );

    runUploadJob($upload);

    $text = $upload->refresh()->logLines()->orderBy('id')->pluck('text')->implode("\n");

    $expectedOrder = [
        '=== PSO RESTful Gateway - Schedule Data Upload ===',
        'File found:',
        'Content type     : application/json (from extension)',
        "Ensuring webhook alias '/pso-sasktel-tst' exists",
        'Webhook endpoint : https://webhook.example.test/pso-sasktel-tst',
        'Dashboard        : https://webhook.example.test/#!/uuid-1',
        "Broadcast compression: none (self-hosted webhook can't store gzipped bodies)",
        'Rewriting the file (streaming)...',
        "Changed Input_Reference.dataset_id: 'OLD' -> 'NEW'",
        'No existing Broadcast or Broadcast_Parameter in the file - nothing to strip.',
        "Injected Broadcast 'WEBHOOK_COMPLETE'",
        'Sending modified copy instead of the original: schedule_withBroadcast.json',
        'Step 1/3: Logging on',
        'Logon successful',
        'Step 2/3: Preparing request body',
        'Compressed to',
        'Step 3/3: POSTing to https://example.test/IFSSchedulingRESTfulGateway/api/v1/scheduling/data',
        'Upload complete.',
        'HTTP status      : 200',
        'Wire bytes sent',
        'POST duration',
        'Effective rate',
        'Response body:',
        'Logging off session...',
        'Session logged off.',
        '=== Done in',
        'Webhook endpoint for this run: https://webhook.example.test/#!/uuid-1',
    ];

    $position = -1;

    foreach ($expectedOrder as $fragment) {
        $found = strpos($text, $fragment, $position + 1);

        expect($found)->not->toBeFalse("'{$fragment}' is missing or out of order");

        $position = $found;
    }
});

it('keeps the customer broadcast rows when the webhook is skipped and removal is off', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Broadcast":[{"id":"CUSTOMER"}],"Broadcast_Parameter":[{"broadcast_id":"CUSTOMER"}]}}',
        ['skip_webhook' => true, 'dataset_id_override' => 'NEW', 'strip_existing_broadcast' => false],
    );

    runUploadJob($upload);

    $payload = sentPayload();

    expect($payload['ScheduleData']['Broadcast'])->toBe([['id' => 'CUSTOMER']]);
    expect($payload['ScheduleData']['Broadcast_Parameter'])->toBe([['broadcast_id' => 'CUSTOMER']]);
    expect($payload['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($upload->refresh()->logLines->pluck('text')->implode("\n"))
        ->not->toContain('Stripped existing broadcast')
        ->toContain('Existing Broadcast rows: kept')
        ->toContain(SendPsoScheduleDataJob::KEPT_BROADCAST_WARNING);
});

it('removes the customer broadcast rows when the webhook is skipped and removal is on', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Resources":[{"id":"R1"}],"Broadcast":[{"id":"CUSTOMER"}],"Broadcast_Parameter":[{"broadcast_id":"CUSTOMER"},{"broadcast_id":"CUSTOMER"}]}}',
        ['skip_webhook' => true],
    );

    runUploadJob($upload);

    $payload = sentPayload();

    expect($payload['ScheduleData'])->toBe(['Resources' => [['id' => 'R1']]]);
    expect($upload->refresh()->webhook_url)->toBeNull();
    expect($upload->logLines->pluck('text')->implode("\n"))
        ->toContain('Existing Broadcast rows: removed (1 Broadcast, 2 Broadcast_Parameter)')
        ->not->toContain(SendPsoScheduleDataJob::KEPT_BROADCAST_WARNING);
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), 'webhook.example.test'));
});

it('sends the original bytes and warns when the webhook is skipped, removal is off and nothing else changes', function () {
    fakeWebhookAndGateway();
    $original = '{"ScheduleData":{"Broadcast":[{"id":"CUSTOMER"}]}}';
    $upload = webhookJobUpload('gateway-uploads/schedule.json', $original, ['skip_webhook' => true, 'strip_existing_broadcast' => false]);

    runUploadJob($upload);

    $lines = $upload->refresh()->logLines()->orderBy('id')->get();

    expect(json_encode(sentPayload()))->toBe(json_encode(json_decode($original, true)));
    expect($lines->pluck('text')->implode("\n"))
        ->toContain('Existing Broadcast rows: kept')
        ->toContain('sending the file exactly as uploaded');
    expect($lines->firstWhere('text', SendPsoScheduleDataJob::KEPT_BROADCAST_WARNING)?->level)->toBe(GatewayUploadLogLevel::WARN);
});

it('sends a json file with no wrapper unchanged when only a dataset id override is set', function () {
    fakeWebhookAndGateway();
    $original = '{"Other":{"Input_Reference":[{"dataset_id":"OLD"}]}}';
    $upload = webhookJobUpload('gateway-uploads/schedule.json', $original, ['skip_webhook' => true, 'dataset_id_override' => 'NEW']);

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect(json_encode(sentPayload()))->toBe(json_encode(json_decode($original, true)));
    expect($upload->logLines->pluck('text')->implode("\n"))
        ->toContain("No top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file")
        ->toContain('No Input_Reference.dataset_id in the file - nothing to keep or change.');
});

it('suggests the skip toggle when the wrapper is missing', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.xml', '<Other><Resources/></Other>');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain("Turn on 'Skip webhook broadcast'");
    Http::assertNotSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl());
});

it('removes a partial rewrite and sends nothing when the rewriter throws', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    $destinations = new ArrayObject;

    $rewriter = new class($destinations) implements ScheduleDataRewriter
    {
        public function __construct(private ArrayObject $destinations) {}

        public function rewrite(string $sourcePath, string $destinationPath, ?string $datasetIdOverride, ?BroadcastInjection $injection, bool $stripExisting = true): RewriteReport
        {
            $this->destinations[] = $destinationPath;
            file_put_contents($destinationPath, '{"ScheduleData":{"Resou');

            throw new RuntimeException('The rewritten schedule data could not be written.');
        }
    };

    $factory = Mockery::mock(ScheduleDataRewriterFactory::class);
    $factory->shouldReceive('forFormat')->andReturn($rewriter);
    app()->instance(ScheduleDataRewriterFactory::class, $factory);

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('could not be written');
    expect($destinations)->toHaveCount(1);
    expect(file_exists($destinations[0]))->toBeFalse();
    expect(is_dir(dirname($destinations[0])))->toBeFalse();
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
    Http::assertNotSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl());
    Storage::disk('r2')->assertMissing('gateway-uploads/schedule.json');
});

it('never writes a secret to a log line, the response body or the error message', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        ...PsoServicesFake::session(logoffStatus: 500),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
        '*/scheduling/data' => Http::response(['Message' => 'Invalid Parameters'], 400),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();
    $everything = $upload->logLines->pluck('text')->implode("\n")."\n{$upload->response_body}\n{$upload->error_message}";

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($everything)->not->toContain('secret-password')->not->toContain('tok-abc')->not->toContain('alias-key-123');
});

/**
 * Runs a migration's up() against a MySQL schema grammar in pretend mode
 * (no server needed) and returns the SQL it would run, so the column types
 * MySQL gets are asserted rather than what SQLite accepts.
 *
 * @return list<string>
 */
function mysqlSqlForMigration(string $migrationPath): array
{
    $connection = new MySqlConnection(static fn () => throw new RuntimeException('no MySQL server in tests'), 'pso', '', ['driver' => 'mysql', 'name' => 'mysql-grammar']);
    $migration = require $migrationPath;

    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(static fn () => $migration->up());
    } finally {
        Schema::swap($originalSchema);
    }

    return array_column($queries, 'query');
}

it('gives MySQL MEDIUMTEXT for the response body, the error message and the log line text', function () {
    $uploadSql = implode("\n", mysqlSqlForMigration(database_path('migrations/2026_10_11_000001_add_webhook_broadcast_fields_to_pso_gateway_uploads_table.php')));
    $logSql = implode("\n", mysqlSqlForMigration(database_path('migrations/2026_10_11_000002_create_pso_gateway_upload_log_lines_table.php')));

    expect($uploadSql)->toContain('add `response_body` mediumtext null');
    expect($uploadSql)->toContain('modify `error_message` mediumtext null');
    expect($logSql)->toContain('`text` mediumtext not null');
});

it('keeps a 70 KB gateway response whole on the row and caps the log line', function () {
    $body = json_encode(['InternalId' => '1857054', 'Detail' => str_repeat('é', 35000)]);
    expect(strlen($body))->toBeGreaterThan(70000);
    fakeWebhookAndGateway(['*/scheduling/data' => Http::response($body, 200)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();
    $longestLine = $upload->logLines->max(static fn (PsoGatewayUploadLogLine $line): int => strlen($line->text));

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->internal_id)->toBe('1857054');
    expect($upload->response_body)->toBe($body);
    expect($longestLine)->toBeLessThanOrEqual(StoredText::LOG_LINE_BYTES);
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain(StoredText::TRUNCATED_MARKER);
});

it('cuts a gateway response over 1 MB with a truncated marker', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => Http::response(str_repeat('x', 1100000), 200)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect(strlen($upload->response_body))->toBeLessThanOrEqual(StoredText::RESPONSE_BODY_BYTES);
    expect($upload->response_body)->toEndWith(StoredText::TRUNCATED_MARKER);
});

it('caps a 70 KB error message on the row and in the log', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => Http::response(['Message' => str_repeat('bad ', 17500)], 400)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();
    $errorLine = $upload->logLines->where('level', GatewayUploadLogLevel::ERROR)->last();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect(strlen($upload->error_message))->toBeLessThanOrEqual(StoredText::ERROR_MESSAGE_BYTES);
    expect($upload->error_message)->toStartWith('The PSO gateway returned an error: bad bad')->toEndWith(StoredText::TRUNCATED_MARKER);
    expect(strlen($errorLine->text))->toBeLessThanOrEqual(StoredText::LOG_LINE_BYTES);
});

it('stores the dataset id in the file apart from the one sent', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Resources":[]}}',
        ['dataset_id_override' => 'NEW'],
    );

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->dataset_id_in_file)->toBe('OLD');
    expect($upload->dataset_id)->toBe('NEW');
});

it('stores the file dataset id as both values when nothing overrides it', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}', ['skip_webhook' => true]);

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->dataset_id_in_file)->toBe('OLD');
    expect($upload->dataset_id)->toBe('OLD');
});

it('says a timed-out upload may have reached PSO, without an em dash', function () {
    $upload = PsoGatewayUpload::factory()->for(Environment::factory()->create(), 'environment')->create([
        'status' => PsoGatewayUploadStatus::UPLOADING,
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))
        ->failed(new TimeoutExceededException('Job has timed out.'));

    expect($upload->refresh()->error_message)->toBe('The upload did not finish within 15 minutes; check PSO before resending.');
    expect($upload->error_message)->not->toContain("\u{2014}");
});

it('says a stopped upload may have reached PSO when the worker dies for another reason', function () {
    $upload = PsoGatewayUpload::factory()->for(Environment::factory()->create(), 'environment')->create([
        'status' => PsoGatewayUploadStatus::UPLOADING,
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))
        ->failed(new RuntimeException('worker killed'));

    expect($upload->refresh()->error_message)->toContain('check PSO before resending')->not->toContain("\u{2014}");
});

it('warns that an xml file with no wrapper is sent unchanged, as for json', function () {
    fakeWebhookAndGateway();
    $original = '<Other><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></Other>';
    $upload = webhookJobUpload('gateway-uploads/schedule.xml', $original, ['skip_webhook' => true, 'dataset_id_override' => 'NEW']);

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->logLines->where('level', GatewayUploadLogLevel::WARN)->pluck('text')->implode("\n"))
        ->toContain("No top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - sending it unchanged.");
});

it('shows Preparing during the webhook and rewrite steps and Compressing only for gzip', function () {
    $statusesSeen = [];
    $uploadIdHolder = new ArrayObject;

    fakeWebhookAndGateway([
        'https://webhook.example.test/_alias/*' => static function () use (&$statusesSeen, $uploadIdHolder) {
            $statusesSeen[] = PsoGatewayUpload::find($uploadIdHolder['id'])->status;

            return Http::response(['uuid' => 'uuid-1', 'created' => true], 201);
        },
        '*/scheduling/data' => static function () use (&$statusesSeen, $uploadIdHolder) {
            $statusesSeen[] = PsoGatewayUpload::find($uploadIdHolder['id'])->status;

            return Http::response(['InternalId' => '1857054'], 200);
        },
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');
    $uploadIdHolder['id'] = $upload->id;

    runUploadJob($upload);

    expect(PsoGatewayUploadStatus::PREPARING->getLabel())->toBe('Preparing');
    expect($statusesSeen)->toBe([PsoGatewayUploadStatus::PREPARING, PsoGatewayUploadStatus::UPLOADING]);
});

it('caps the dataset ids in the file to the column width and still succeeds', function () {
    fakeWebhookAndGateway();
    $inputReferences = collect(range(1, 6))
        ->map(static fn (int $number): array => ['dataset_id' => str_repeat("DS{$number}", 30)])
        ->all();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        json_encode(['ScheduleData' => ['Input_Reference' => $inputReferences]]),
        ['dataset_id_override' => 'NEW'],
    );

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect(strlen($upload->dataset_id_in_file))->toBeLessThanOrEqual(StoredText::DATASET_ID_BYTES);
    expect($upload->dataset_id_in_file)->toStartWith(str_repeat('DS1', 30).', ')->toEndWith(StoredText::TRUNCATED_MARKER);
});
