<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Logs on and off the PSO gateway through pso-services (POST and DELETE
 * /session) so this app has one login path. Kept apart from
 * PSOInteractionsTrait::sendToPSONew() because that helper wraps replies in
 * the payload envelope and raises notifications.
 */
class PsoServicesSessionClient
{
    /**
     * @throws PsoServicesSessionException
     */
    public function logon(string $baseUrl, string $accountId, string $username, #[SensitiveParameter] string $password): string
    {
        $response = $this->send('post', [
            'baseUrl' => $baseUrl,
            'accountId' => $accountId,
            'username' => $username,
            'password' => $password,
        ]);

        $token = $response->json('message.SessionToken');

        if (! is_string($token) || $token === '') {
            throw new PsoServicesSessionException('The PSO services API answered the logon without a session token.', $response->status());
        }

        return $token;
    }

    /**
     * @throws PsoServicesSessionException
     */
    public function logoff(string $baseUrl, #[SensitiveParameter] string $sessionToken): void
    {
        $this->send('delete', [
            'baseUrl' => $baseUrl,
            'token' => $sessionToken,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws PsoServicesSessionException
     */
    private function send(string $method, #[SensitiveParameter] array $headers): Response
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('psott.session.timeout', 20))
                ->connectTimeout(10)
                ->withHeaders($headers)
                ->{$method}($url);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services session: the API could not be reached', [
                'url' => $url,
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);

            throw new PsoServicesSessionException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PsoServicesSessionException($this->errorMessage($response), $response->status());
        }

        return $response;
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'session';
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        $candidates = [
            data_get($body, 'error'),
            data_get($body, 'message'),
            data_get($body, 'details'),
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
