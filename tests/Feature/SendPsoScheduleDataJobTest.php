<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
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
