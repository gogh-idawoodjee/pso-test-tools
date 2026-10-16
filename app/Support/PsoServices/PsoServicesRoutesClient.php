<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Routes through pso-services (POST /routes/compute, spec 5.3): pso-services holds the key and the
 * shared cache, so this app never sees a Google key. One call per chunk of up to 200 trips.
 */
class PsoServicesRoutesClient
{
    /**
     * @param  list<array{id: string, origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>  $legs
     * @return array{results: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws PsoServicesRoutesException
     */
    public function compute(array $legs, string $travelMode, string $routingPreference): array
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('psott.routes.timeout', 300))
                ->connectTimeout(10)
                ->post($url, ['travelMode' => $travelMode, 'routingPreference' => $routingPreference, 'legs' => $legs]);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services routes: the API could not be reached', ['url' => $url, 'legs' => count($legs), 'message' => $exception->getMessage()]);

            throw new PsoServicesRoutesException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PsoServicesRoutesException($this->errorMessage($response), $response->status());
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            throw new PsoServicesRoutesException('The PSO services API answered without route results.', $response->status());
        }

        $counts = $response->json('counts');

        return [
            'results' => array_values(array_filter($results, 'is_array')),
            'counts' => is_array($counts) ? array_map('intval', $counts) : [],
        ];
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'routes/compute';
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();
        $candidates = [data_get($body, 'error'), data_get($body, 'message')];
        $validationErrors = data_get($body, 'errors');
        if (is_array($validationErrors)) {
            $candidates[] = collect($validationErrors)->flatten()->first();
        }

        $parts = array_values(array_unique(array_filter($candidates, static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '')));

        return $parts === [] ? "The PSO services API returned {$response->status()}." : implode(' ', $parts);
    }
}
