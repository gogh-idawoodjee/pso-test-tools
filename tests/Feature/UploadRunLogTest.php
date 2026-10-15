<?php

use App\Enums\GatewayUploadLogLevel;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use App\Support\GatewayUpload\UploadRunLog;

it('records lines in order with their level', function () {
    $upload = PsoGatewayUpload::factory()->create();
    $log = app(UploadRunLog::class, ['upload' => $upload]);

    $log->step('=== Upload ===');
    $log->sub('Gateway base URL : https://example.test');
    $log->warn('No alias key');
    $log->ok('Logon successful');
    $log->error('Boom');

    $lines = $upload->logLines()->orderBy('id')->get();

    expect($lines->pluck('text')->all())->toBe(['=== Upload ===', 'Gateway base URL : https://example.test', 'No alias key', 'Logon successful', 'Boom']);
    expect($lines->pluck('level')->all())->toBe([
        GatewayUploadLogLevel::STEP,
        GatewayUploadLogLevel::SUB,
        GatewayUploadLogLevel::WARN,
        GatewayUploadLogLevel::OK,
        GatewayUploadLogLevel::ERROR,
    ]);
    expect($lines->first()->created_at)->not->toBeNull();
});

it('deletes a log with its upload', function () {
    $upload = PsoGatewayUpload::factory()->create();
    app(UploadRunLog::class, ['upload' => $upload])->step('hello');

    $upload->delete();

    expect(PsoGatewayUploadLogLine::query()->count())->toBe(0);
});

it('stores and casts the webhook and response columns', function () {
    $upload = PsoGatewayUpload::factory()->create([
        'dataset_id_override' => 'NEW',
        'skip_webhook' => false,
        'broadcast_settings' => ['id' => 'WEBHOOK_COMPLETE', 'maximum_wait' => 'PT5M'],
        'response_status' => 200,
        'wire_bytes' => 1234,
        'post_duration_ms' => 5678,
    ])->refresh();

    expect($upload->skip_webhook)->toBeFalse();
    expect($upload->broadcast_settings)->toBe(['id' => 'WEBHOOK_COMPLETE', 'maximum_wait' => 'PT5M']);
    expect($upload->response_status)->toBe(200);
    expect($upload->wire_bytes)->toBe(1234);
    expect($upload->post_duration_ms)->toBe(5678);
});
