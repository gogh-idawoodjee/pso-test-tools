<?php

use App\Models\PsoGatewayUpload;
use App\Support\GatewayUpload\UploadRunLog;
use App\Support\Webhook\WebhookEndpointProvisioner;
use App\Support\Webhook\WebhookProvisioningException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.webhook.base_url' => 'https://webhook.example.test',
        'services.webhook.alias_key' => 'alias-key-123',
    ]);
});

function provisionWebhook(string $environmentName = 'SaskTel TST', string $contentType = 'application/json'): array
{
    $upload = PsoGatewayUpload::factory()->create();
    $endpoint = app(WebhookEndpointProvisioner::class)->provision(
        $environmentName,
        $contentType,
        app(UploadRunLog::class, ['upload' => $upload]),
    );

    return [$endpoint, $upload->logLines()->orderBy('id')->get()];
}

it('creates the per-environment alias and returns its stable url', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->url)->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($endpoint->viewUrl)->toBe('https://webhook.example.test/#!/uuid-1');
    expect($endpoint->uuid)->toBe('uuid-1');
    expect($endpoint->alias)->toBe('pso-sasktel-tst');
    expect($endpoint->created)->toBeTrue();
    expect($lines->pluck('text')->implode("\n"))->toContain('Created new alias /pso-sasktel-tst')
        ->not->toContain('alias-key-123');

    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://webhook.example.test/_alias/pso-sasktel-tst'
        && $request->hasHeader('X-Alias-Key', 'alias-key-123')
        && $request['default_status'] === 200
        && $request['default_content'] === '{}'
        && $request['default_content_type'] === 'application/json');
});

it('uses an xml canned response for xml payloads', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    provisionWebhook('env', 'application/xml');

    Http::assertSent(static fn ($request) => $request->url() === 'https://webhook.example.test/_alias/pso-env'
        && $request->hasHeader('X-Alias-Key', 'alias-key-123')
        && $request['default_content'] === '<response/>'
        && $request['default_content_type'] === 'application/xml');
});

it('refreshes the canned response of an existing alias', function () {
    Http::fake([
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-9', 'created' => false], 200),
        'https://webhook.example.test/token/uuid-9' => Http::response([], 200),
    ]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->created)->toBeFalse();
    expect($lines->pluck('text')->implode("\n"))->toContain('already exists');

    Http::assertSent(static fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://webhook.example.test/token/uuid-9'
        && $request['default_content'] === '{}');
});

it('tolerates a failure refreshing the canned response', function () {
    Http::fake([
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-9', 'created' => false], 200),
        'https://webhook.example.test/token/uuid-9' => Http::response('boom', 500),
    ]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->uuid)->toBe('uuid-9');
    expect($lines->pluck('text')->implode("\n"))->toContain('Could not update');
});

it('falls back to a one-off token with a warning when no alias key is configured', function () {
    config(['services.webhook.alias_key' => null]);

    Http::fake(['https://webhook.example.test/token' => Http::response(['uuid' => 'uuid-77'], 201)]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->url)->toBe('https://webhook.example.test/uuid-77');
    expect($endpoint->viewUrl)->toBe('https://webhook.example.test/#!/uuid-77');
    expect($endpoint->alias)->toBeNull();
    expect($lines->pluck('text')->implode("\n"))->toContain('falling back to a fresh one-off token');

    Http::assertNotSent(static fn ($request) => str_contains($request->url(), '/_alias/'));
});

it('throws a provisioning exception when the alias call fails', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response('forbidden', 403)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, '403');
});

it('throws when the service is unreachable', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => static function () {
        throw new ConnectionException('timed out');
    }]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, 'could not be reached');
});

it('throws when the reply carries no uuid', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['created' => true], 200)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, 'uuid');
});

it('maps the alias per character like the PowerShell script', function (string $environmentName, string $alias) {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    [$endpoint] = provisionWebhook($environmentName);

    expect($endpoint->alias)->toBe($alias);
})->with([
    'accent' => ['Québec', 'pso-qu-bec'],
    'dashes' => ['-a-', 'pso--a-'],
]);

it('posts the one-off token with the canned response body', function () {
    config(['services.webhook.alias_key' => null]);
    Http::fake(['https://webhook.example.test/token' => Http::response(['uuid' => 'uuid-77'], 201)]);

    provisionWebhook();

    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://webhook.example.test/token'
        && $request['default_status'] === 200
        && $request['default_content'] === '{}'
        && $request['default_content_type'] === 'application/json');
});

it('throws on a server error from the alias call', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response('boom', 503)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, '503');
});

it('throws when the one-off reply carries no uuid', function () {
    config(['services.webhook.alias_key' => null]);
    Http::fake(['https://webhook.example.test/token' => Http::response(['x' => 1], 201)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, 'uuid');
});

it('falls back to the default base url when the configured one is empty', function () {
    config(['services.webhook.base_url' => '']);
    Http::fake(['https://webhook.thetechnodro.me/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    [$endpoint] = provisionWebhook();

    expect($endpoint->url)->toBe('https://webhook.thetechnodro.me/pso-sasktel-tst');
});
