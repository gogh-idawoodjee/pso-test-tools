<?php

use App\Support\PsoServices\PsoServicesRoutesClient;
use App\Support\PsoServices\PsoServicesRoutesException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2']);
    $this->legs = [['id' => 'a', 'origin' => ['lat' => 52.1, 'lng' => -106.6], 'destination' => ['lat' => 52.2, 'lng' => -106.7]]];
});

it('posts the legs with mode and preference as JSON and returns results and counts', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => Http::response([
        'results' => [['id' => 'a', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'cache']],
        'counts' => ['cache' => 1, 'google' => 0, 'error' => 0],
    ])]);

    $response = app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');

    expect($response)->toBe([
        'results' => [['id' => 'a', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'cache']],
        'counts' => ['cache' => 1, 'google' => 0, 'error' => 0],
    ]);
    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://pso-services.test/api/v2/routes/compute'
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === ['travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'legs' => test()->legs]
        && ! $request->hasHeader('X-Goog-Api-Key'));
});

it('throws the pso-services message and status on a 503', function () {
    Http::fake(['*' => Http::response(['message' => 'Google Routes is not configured on pso-services.'], 503)]);

    try {
        app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');
        $this->fail('expected a PsoServicesRoutesException');
    } catch (PsoServicesRoutesException $e) {
        expect($e->getMessage())->toBe('Google Routes is not configured on pso-services.')
            ->and($e->statusCode)->toBe(503);
    }
});

it('names the first validation error on a 422', function () {
    Http::fake(['*' => Http::response(['message' => 'The legs field must not have more than 200 items.', 'errors' => ['legs' => ['The legs field must not have more than 200 items.']]], 422)]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The legs field must not have more than 200 items.');
});

it('says the API returned the status when a 500 has no body', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API returned 500.');
});

it('says the API could not be reached on a connection failure', function () {
    Http::fake(static fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API could not be reached (timed out or unreachable).');
});

it('refuses a 200 without a results array', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API answered without route results.');
});

it('sends the routes token header only when one is configured', function () {
    Http::fake(['*' => Http::response(['results' => [], 'counts' => []])]);

    app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');
    config(['psott.routes.token' => 'shared-secret']);
    app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');

    $requests = Http::recorded()->all();
    expect($requests[0][0]->hasHeader('X-PSO-Routes-Token'))->toBeFalse()
        ->and($requests[1][0]->header('X-PSO-Routes-Token'))->toBe(['shared-secret']);
});
