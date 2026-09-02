<?php

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastType;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

it('autofills the url with this environment\'s commit URL when SDS allocation is selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    $test = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'broadcasts' => [
                ['broadcast_type_id' => BroadcastType::REST->value, 'allocation_type' => []],
            ],
        ], 'psoload');

    $itemKey = array_key_first($test->get('data.broadcasts'));

    $test->set("data.broadcasts.{$itemKey}.allocation_type", [BroadcastAllocationType::SCHEDULE_DISPATCH_SERVICE->value]);

    $expectedUrl = 'https://'.config('psott.pso-services-api').'/api/'.(config('psott.pso-services-api-version') ? config('psott.pso-services-api-version').'/' : '').'commit/'.$environment->commit_token;

    expect($test->get("data.broadcasts.{$itemKey}.url"))->toBe($expectedUrl);
});

it('autofills the url when switching to a URL-based broadcast type after SDS is already selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    $test = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'broadcasts' => [
                [
                    'broadcast_type_id' => BroadcastType::EMAIL->value,
                    'allocation_type' => [BroadcastAllocationType::SCHEDULE_DISPATCH_SERVICE->value],
                ],
            ],
        ], 'psoload');

    $itemKey = array_key_first($test->get('data.broadcasts'));

    $test->set("data.broadcasts.{$itemKey}.broadcast_type_id", BroadcastType::REST->value);

    $expectedUrl = 'https://'.config('psott.pso-services-api').'/api/'.(config('psott.pso-services-api-version') ? config('psott.pso-services-api-version').'/' : '').'commit/'.$environment->commit_token;

    expect($test->get("data.broadcasts.{$itemKey}.url"))->toBe($expectedUrl);
});

it('does not overwrite a url that was already typed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    $test = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'broadcasts' => [
                [
                    'broadcast_type_id' => BroadcastType::REST->value,
                    'allocation_type' => [],
                    'url' => 'https://example.test/manual-endpoint',
                ],
            ],
        ], 'psoload');

    $itemKey = array_key_first($test->get('data.broadcasts'));

    $test->set("data.broadcasts.{$itemKey}.allocation_type", [BroadcastAllocationType::SCHEDULE_DISPATCH_SERVICE->value]);

    expect($test->get("data.broadcasts.{$itemKey}.url"))->toBe('https://example.test/manual-endpoint');
});
