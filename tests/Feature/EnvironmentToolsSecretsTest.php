<?php

use App\Filament\Resources\EnvironmentResource\Pages\EditEnvironment;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\PsoServicesFake;

function secretsEnvironment(): Environment
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);
}

/**
 * Every key at any depth of a nested array.
 *
 * @return array<int, string>
 */
function secretsAllKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $item) {
        $keys[] = (string) $key;

        if (is_array($item)) {
            array_push($keys, ...secretsAllKeys($item));
        }
    }

    return $keys;
}

it('keeps the password ciphertext and commit token out of the Tools page state and HTML', function () {
    $environment = secretsEnvironment();

    $component = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()]);

    $snapshot = json_encode($component->snapshot, JSON_UNESCAPED_SLASHES);
    $html = html_entity_decode($component->html());
    $html = str_replace('\/', '/', $html);

    expect($snapshot)->not->toContain($environment->password)
        ->and($html)->not->toContain($environment->password)
        ->and($snapshot)->not->toContain('secret-password')
        ->and($html)->not->toContain('secret-password');

    foreach (['context_data', 'load_rota_data', 'system_usage_data', 'services_data', 'gateway_upload_data'] as $property) {
        // The Password field is there to say one is saved, but it holds nothing.
        expect(data_get($component->get($property), 'password'))->toBeNull();

        expect(secretsAllKeys((array) $component->get($property)))
            ->not->toContain('commit_token')
            ->not->toContain('user_id');
    }

    // The token is only ever there as part of the commit URL the Services tab shows.
    $withoutCommitUrl = str_replace((string) $component->get('services_data.commit_url'), '', $snapshot);
    expect($withoutCommitUrl)->not->toContain($environment->commit_token);
});

it('still shows the non-secret environment properties', function () {
    $environment = secretsEnvironment();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->assertSet('context_data.base_url', 'https://example.test')
        ->assertSet('context_data.account_id', 'acc-1')
        ->assertSet('context_data.username', 'test-user');
});

it('fetches system usage with the credentials from the environment record', function () {
    $environment = secretsEnvironment();

    Http::fake([
        ...PsoServicesFake::session(),
        '*' => Http::response(['data' => ['ScheduleDataUsages' => []]], 200),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->callAction(TestAction::make('fetch_system_usage')->schemaComponent(true, 'systemUsageForm'));

    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl()
        && $request->method() === 'POST'
        && $request->hasHeader('baseUrl', 'https://example.test')
        && $request->hasHeader('accountId', 'acc-1')
        && $request->hasHeader('username', 'test-user')
        && $request->hasHeader('password', 'secret-password'));
});

it('ignores credentials pushed into the form state from the browser', function () {
    $environment = secretsEnvironment();

    Http::fake([
        ...PsoServicesFake::session(),
        '*' => Http::response(['data' => ['ScheduleDataUsages' => []]], 200),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('context_data.base_url', 'https://attacker.test')
        ->set('context_data.password', Crypt::encryptString('other-password'))
        ->callAction(TestAction::make('fetch_system_usage')->schemaComponent(true, 'systemUsageForm'));

    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl()
        && $request->method() === 'POST'
        && $request->hasHeader('baseUrl', 'https://example.test')
        && $request->hasHeader('password', 'secret-password'));
});

it('sends the load with the credentials from the environment record', function () {
    $environment = secretsEnvironment();
    Dataset::factory()->for($environment)->create(['name' => 'ds-1']);

    Http::fake([
        ...PsoServicesFake::session(),
        '*' => Http::response(['data' => ['payloadToPso' => []]], 200),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['dataset_id' => 'ds-1'], 'sharedContextForm')
        ->fillForm(['send_to_pso' => true, 'keep_pso_data' => true], 'loadRotaForm')
        ->callAction(TestAction::make('push_it')->schemaComponent(true, 'loadRotaForm'));

    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl()
        && $request->method() === 'POST'
        && $request->hasHeader('username', 'test-user')
        && $request->hasHeader('password', 'secret-password'));

    Http::assertSent(static fn ($request) => $request->url() !== PsoServicesFake::sessionUrl()
        && data_get($request->data(), 'environment.baseUrl') === 'https://example.test'
        && data_get($request->data(), 'environment.token') === 'tok-abc');
});

it('keeps the commit token out of the edit page state and still tests the configuration', function () {
    $environment = secretsEnvironment();

    Http::fake(PsoServicesFake::session());

    $component = Livewire::test(EditEnvironment::class, ['record' => $environment->getRouteKey()]);

    $snapshot = json_encode($component->snapshot, JSON_UNESCAPED_SLASHES);

    expect($snapshot)->not->toContain($environment->commit_token)
        ->and($snapshot)->not->toContain($environment->password);

    $component->callAction('test_configuration');

    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl()
        && $request->method() === 'POST'
        && $request->hasHeader('password', 'secret-password'));
});
