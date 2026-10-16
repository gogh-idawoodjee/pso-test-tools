<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\Response;

/**
 * What every pso-services client shares: the API base URL and how an error
 * body is turned into one readable sentence. Kept here so the session, routes
 * and reference values clients cannot drift apart (#41).
 */
final class PsoServicesApi
{
    public static function url(string $path): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').ltrim($path, '/');
    }

    /**
     * pso-services answers errors in a few shapes: {error}, {message}, {message: {error, details}},
     * {details}, {details: {Message}} from the PSO gateway, and Laravel's {message, errors}.
     * Every string found is joined once, in that order; an empty body gives the status code.
     */
    public static function errorMessage(Response $response): string
    {
        $body = $response->json();

        $candidates = [
            data_get($body, 'error'),
            data_get($body, 'message.error'),
            data_get($body, 'message'),
            data_get($body, 'details'),
            data_get($body, 'message.details'),
            data_get($body, 'details.Message'),
        ];

        $validationErrors = data_get($body, 'errors');

        if (is_array($validationErrors)) {
            $candidates[] = collect($validationErrors)->flatten()->first();
        }

        $parts = array_values(array_unique(array_filter(
            $candidates,
            static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '',
        )));

        return $parts === []
            ? "The PSO services API returned {$response->status()}."
            : implode(' ', $parts);
    }
}
