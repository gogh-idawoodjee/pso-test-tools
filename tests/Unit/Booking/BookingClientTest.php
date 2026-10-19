<?php

use App\Enums\HttpMethod;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use App\Support\Booking\BookingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\PsoServicesFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2']);

    $user = User::factory()->create();
    $this->actingAs($user);
    $environment = Environment::factory()->for($user)->create(['password' => Crypt::encryptString('secret')]);
    $dataset = Dataset::factory()->for($environment)->create();
    $this->task = Task::factory()->for(Customer::factory()->for($dataset))->create();
});

it('returns the data of a 2xx reply with its status', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['data' => ['appointmentOffers' => ['validOffers' => []]]])]);

    $result = (new BookingClient($this->task))->send('appointment', ['data' => ['activityId' => 'A1']]);

    expect($result)->toBe(['data' => ['appointmentOffers' => ['validOffers' => []]], 'error' => null, 'status' => 200]);
});

it('returns the services error text and the status of a non-2xx reply', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['error' => 'Request expired.'], 406)]);

    $result = (new BookingClient($this->task))->send('appointment/REQ-1', ['data' => []], HttpMethod::PATCH);

    expect($result)->toBe(['data' => null, 'error' => 'Request expired.', 'status' => 406]);
});

it('reports a request reply without appointment offers', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['data' => ['message' => 'Done.']])]);

    $result = (new BookingClient($this->task))->send('appointment', ['data' => []]);

    expect($result)->toBe(['data' => null, 'error' => 'The PSO services API answered without appointment offers.', 'status' => 200]);
});

it('reads with a GET carrying only the query parameters, without a PSO logon', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['data' => ['history' => []]])]);

    $result = (new BookingClient($this->task))->get('appointment', ['activityId' => 'A 1', 'datasetId' => 'NORTH']);

    expect($result)->toBe(['data' => ['history' => []], 'error' => null, 'status' => 200]);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://pso-services.test/api/v2/appointment?activityId=A%201&datasetId=NORTH'
        && ! $request->hasHeader('token'));
    Http::assertNotSent(fn ($request) => $request->url() === PsoServicesFake::sessionUrl());
});
