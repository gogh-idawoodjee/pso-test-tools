<?php

use App\Enums\BroadcastPlanType;
use App\Enums\BroadcastType;
use App\Enums\InputMode;
use App\Enums\ProcessType;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;

function loadSchema(array $overrides = []): array
{
    return array_merge([
        'base_url' => 'https://example.test',
        'dataset_id' => 'dataset-1',
        'description' => 'Load From Tool Box',
        'send_to_pso' => false,
        'keep_pso_data' => false,
        'account_id' => 'account-1',
        'dse_duration' => 3,
        'appointment_window' => 7,
        'process_type' => ProcessType::APPOINTMENT->value,
        'datetime' => '2026-01-01T00:00:00Z',
        'input_mode' => InputMode::LOAD,
        'pso_api_version' => null,
        'include_arp_data' => false,
        'rota_id' => null,
        'broadcasts' => [],
    ], $overrides);
}

it('omits psoApiVersion, includeArpData and broadcasts when not provided', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema());

    expect($payload['environment'])->not->toHaveKey('psoApiVersion')
        ->and($payload['data'])->not->toHaveKey('includeArpData')
        ->and($payload['data'])->not->toHaveKey('rotaId')
        ->and($payload['data'])->not->toHaveKey('broadcasts');
});

it('includes psoApiVersion when set', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema(['pso_api_version' => 2]));

    expect($payload['environment']['psoApiVersion'])->toBe(2);
});

it('includes rotaId only when includeArpData is true', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema([
        'include_arp_data' => true,
        'rota_id' => 'rota-42',
    ]));

    expect($payload['data']['includeArpData'])->toBeTrue()
        ->and($payload['data']['rotaId'])->toBe('rota-42');
});

it('builds a REST broadcast with its required parameters', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema([
        'broadcasts' => [
            [
                'active' => true,
                'broadcast_type_id' => BroadcastType::REST,
                'plan_type' => BroadcastPlanType::COMPLETE,
                'allocation_type' => ['1', '4'],
                'mediatype' => 'application/json',
                'url' => 'https://example.test/hook',
            ],
        ],
    ]));

    $broadcast = $payload['data']['broadcasts'][0];

    expect($broadcast['broadcastTypeId'])->toBe('REST')
        ->and($broadcast['planType'])->toBe('COMPLETE')
        ->and($broadcast['allocationType'])->toBe([1, 4])
        ->and($broadcast['parameters'])->toEqualCanonicalizing([
            ['name' => 'mediatype', 'value' => 'application/json'],
            ['name' => 'url', 'value' => 'https://example.test/hook'],
        ]);
});

it('adds application_type_id and check_in_expired_time parameters for ADMIN plan type', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema([
        'broadcasts' => [
            [
                'broadcast_type_id' => BroadcastType::EMAIL,
                'plan_type' => BroadcastPlanType::ADMIN,
                'to_address' => 'test@example.test',
                'smtp_server' => 'smtp.example.test',
                'application_type_id' => '3',
                'check_in_expired_time' => '60',
            ],
        ],
    ]));

    $names = array_column($payload['data']['broadcasts'][0]['parameters'], 'name');

    expect($names)->toEqualCanonicalizing([
        'to_address',
        'smtp_server',
        'application_type_id',
        'check_in_expired_time',
    ]);
});

it('excludes data.* and broadcasts entirely in CHANGE mode', function () {
    $payload = (new EnvironmentTools)->initialize_payload(loadSchema([
        'input_mode' => InputMode::CHANGE,
        'broadcasts' => [
            [
                'broadcast_type_id' => BroadcastType::FILE,
                'plan_type' => BroadcastPlanType::COMPLETE,
                'file_path' => '/tmp/out.json',
            ],
        ],
    ]));

    expect($payload)->not->toHaveKey('data');
});
