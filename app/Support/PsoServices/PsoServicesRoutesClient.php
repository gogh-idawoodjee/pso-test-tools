<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\ConnectionException;
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
        $url = PsoServicesApi::url('routes/compute');

        try {
            $token = config('psott.routes.token');
            $response = Http::acceptJson()
                ->asJson()
                ->when(filled($token), static fn ($request) => $request->withHeaders(['X-PSO-Routes-Token' => (string) $token]))
                ->timeout((int) config('psott.routes.timeout', 300))
                ->connectTimeout(10)
                ->post($url, ['travelMode' => $travelMode, 'routingPreference' => $routingPreference, 'legs' => $legs]);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services routes: the API could not be reached', ['url' => $url, 'legs' => count($legs), 'message' => $exception->getMessage()]);

            throw new PsoServicesRoutesException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PsoServicesRoutesException(PsoServicesApi::errorMessage($response), $response->status());
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

    /**
     * What the shared cache holds (GET /routes/stats): counts and dates only.
     *
     * @return array<string, mixed>
     *
     * @throws PsoServicesRoutesException
     */
    public function stats(): array
    {
        $url = PsoServicesApi::url('routes/stats');

        try {
            $token = config('psott.routes.token');
            $response = Http::acceptJson()
                ->when(filled($token), static fn ($request) => $request->withHeaders(['X-PSO-Routes-Token' => (string) $token]))
                ->timeout(10)
                ->connectTimeout(5)
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services routes: the stats could not be fetched', ['url' => $url, 'message' => $exception->getMessage()]);

            throw new PsoServicesRoutesException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            Log::warning('PSO services routes: the stats request failed', ['url' => $url, 'message' => PsoServicesApi::errorMessage($response)]);

            throw new PsoServicesRoutesException(PsoServicesApi::errorMessage($response), $response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('rows', $body)) {
            throw new PsoServicesRoutesException('The PSO services API answered without cache stats.', $response->status());
        }

        return $body;
    }
}
