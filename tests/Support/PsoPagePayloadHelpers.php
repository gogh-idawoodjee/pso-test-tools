<?php

use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Logs in a panel user and fakes every outgoing HTTP call (nothing reaches pso-services).
 * Returns the environment_id / dataset name a page needs to build its payload.
 *
 * @return array{environment: Environment, dataset: Dataset}
 */
function fakePsoPagePayloadSetup(object $test): array
{
    $user = User::factory()->create();
    $test->actingAs($user);

    Http::fake(['*' => Http::response(['data' => ['payloadToPso' => []]], 200)]);

    $environment = Environment::factory()->for($user)->create(['base_url' => 'https://example.test', 'account_id' => 'ACC1']);
    $dataset = Dataset::factory()->for($environment)->create(['name' => 'NORTH']);

    return compact('environment', 'dataset');
}

/** Opens a page with the environment and dataset picked and Send to PSO off. */
function psoPageWithEnvironment(string $page, Environment $environment): Testable
{
    return Livewire::test($page)
        ->set('environment_data.environment_id', $environment->id)
        ->set('environment_data.dataset_id', 'NORTH')
        ->set('environment_data.send_to_pso', false);
}

/** The single request sent to pso-services, or fails the test. */
function sentPsoRequest(): Request
{
    $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
    expect($sent)->toHaveCount(1);

    return $sent->first();
}
