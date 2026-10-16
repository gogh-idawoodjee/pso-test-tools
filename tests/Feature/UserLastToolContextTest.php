<?php

use App\Models\User;

it('remembers the last environment and dataset used in a tool', function () {
    $user = User::factory()->create();

    expect($user->last_tool_context)->toBeNull();

    $user->rememberToolContext(environmentId: 'env-7', datasetId: 'ds-12');

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => 'ds-12', 'unfiled' => false, 'time_zone' => null]);
});

it('stores a null dataset for environment-scoped tools', function () {
    $user = User::factory()->create();
    $user->rememberToolContext(environmentId: 'env-7', datasetId: null);

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => null, 'unfiled' => false, 'time_zone' => null]);
});

it('remembers an unfiled run with its time zone', function () {
    $user = User::factory()->create();
    $user->rememberToolContext(environmentId: 'env-7', datasetId: 'ds-12', unfiled: true, timeZone: 'America/Regina');

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => 'ds-12', 'unfiled' => true, 'time_zone' => 'America/Regina']);
});
