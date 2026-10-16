<?php

use App\Support\PsoServices\PsoServicesApi;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

function psoResponse(int $status, array $body): Response
{
    return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));
}

it('builds the url from the configured host and version', function (): void {
    config(['psott.pso-services-api' => 'services.example', 'psott.pso-services-api-version' => 'v2']);
    expect(PsoServicesApi::url('routes/compute'))->toBe('https://services.example/api/v2/routes/compute');

    config(['psott.pso-services-api-version' => null]);
    expect(PsoServicesApi::url('/session'))->toBe('https://services.example/api/session');
});

it('turns each pso-services error shape into one sentence', function (array $body, string $expected): void {
    expect(PsoServicesApi::errorMessage(psoResponse(422, $body)))->toBe($expected);
})->with([
    'laravel validation' => [['message' => 'The legs field is required.', 'errors' => ['legs' => ['The legs field is required.']]], 'The legs field is required.'],
    'error and details' => [['error' => 'Request could not be dispatched', 'details' => 'Permission denied'], 'Request could not be dispatched Permission denied'],
    'nested message' => [['message' => ['error' => 'Login failed', 'details' => 'Bad password']], 'Login failed Bad password'],
    'gateway Message' => [['details' => ['Message' => 'DATASET_NOT_FOUND']], 'DATASET_NOT_FOUND'],
    'duplicate text once' => [['error' => 'Same', 'message' => 'Same'], 'Same'],
]);

it('falls back to the status code when the body says nothing', function (): void {
    expect(PsoServicesApi::errorMessage(psoResponse(503, [])))->toBe('The PSO services API returned 503.');
});
