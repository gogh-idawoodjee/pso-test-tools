<?php

use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\PsoServicesFake;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2']);

    // Panel users: the route sits behind Filament's Authenticate, which checks canAccessPanel.
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $environment = Environment::factory()->for($this->user)->create(['password' => Crypt::encryptString('secret')]);
    $this->dataset = Dataset::factory()->for($environment)->create();
    $customer = Customer::factory()->for($this->dataset)->create();
    $this->task = Task::factory()->for($customer)->create();

    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['data' => ['message' => 'Declined.']])]);
});

/** Method and URL of the appointment calls, without the session logons. */
function releaseCalls(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/api/v2/appointment'))
        ->map(fn (Request $request) => $request->method().' '.$request->url())
        ->values()
        ->all();
}

it('releases the offers of the owner\'s open request', function () {
    $this->actingAs($this->user)
        ->post(route('tasks.release-offers', $this->task), ['appointment_request_id' => 'REQ-22'])
        ->assertNoContent();

    expect(releaseCalls())->toBe(['DELETE https://pso-services.test/api/v2/appointment/REQ-22']);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && data_get($request->data(), 'data.appointmentRequestId') === 'REQ-22'
        && data_get($request->data(), 'environment.datasetId') === $this->dataset->name
        && data_get($request->data(), 'environment.token') === 'tok-abc');
});

it('still answers 204 when services refuses the release', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['error' => 'Request expired.'], 406)]);

    $this->actingAs($this->user)
        ->post(route('tasks.release-offers', $this->task), ['appointment_request_id' => 'REQ-22'])
        ->assertNoContent();
});

it('refuses another user\'s task and sends nothing', function () {
    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']))
        ->post(route('tasks.release-offers', $this->task), ['appointment_request_id' => 'REQ-22'])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('redirects a guest and sends nothing', function () {
    $this->post(route('tasks.release-offers', $this->task), ['appointment_request_id' => 'REQ-22'])
        ->assertRedirect();

    Http::assertNothingSent();
});

it('needs a request id', function () {
    $this->actingAs($this->user)
        ->post(route('tasks.release-offers', $this->task), ['appointment_request_id' => str_repeat('x', 65)])
        ->assertSessionHasErrors('appointment_request_id');

    Http::assertNothingSent();
});
