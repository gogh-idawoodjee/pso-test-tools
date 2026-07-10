<?php

namespace App\Traits;

use App\Enums\HttpMethod;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use SensitiveParameter;

trait PSOInteractionsTrait
{
    protected ?int $error_value = null;

    public ?array $json_form_data = [];

    public function authenticatePSO(
        string $base_url,
        string $account_id,
        string $username,
        #[SensitiveParameter] string $password,
    ): ?string {
        $this->error_value = null;

        if (! $base_url) {
            Log::warning('Base URL is missing for PSO authentication.');

            return null;
        }

        try {
            $timeout = config('psott.defaults.timeout', 10);

            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->post("{$base_url}/IFSSchedulingRESTfulGateway/api/v1/scheduling/session", [
                    'accountId' => $account_id,
                    'userName' => $username,
                    'password' => $password,
                ]);

            if ($response->unauthorized() || $response->status() === 400) {
                $this->error_value = 401;
                Log::warning('Invalid PSO credentials provided.', compact('username', 'account_id'));

                return null;
            }

            $sessionToken = $response->json('SessionToken');

            if (! $sessionToken) {
                Log::warning('Authentication successful but SessionToken is missing.');

                return null;
            }

            return $sessionToken;
        } catch (ConnectionException $e) {
            Log::error('Connection error during PSO authentication', ['message' => $e->getMessage()]);
            $this->error_value = 500;

            return null;
        } catch (RequestException $e) {
            $this->error_value = 500;
            Log::error('HTTP error during PSO authentication', [
                'message' => $e->getMessage(),
                'response' => $e->response?->json(),
                'status' => $e->response?->status(),
            ]);

            return null;
        } catch (Exception $e) {
            Log::error('Unexpected error during PSO authentication', ['message' => $e->getMessage()]);
            $this->error_value = 500;

            return null;
        }
    }

    /**
     * @throws JsonException
     */
    public function sendToPSONew(
        string $api_segment,
        mixed $payload = null,
        ?array $headers = null,
        ?HttpMethod $method = null,
        bool $isNonStandardResponse = false,
    ): string {
        $headers ??= [];
        $responseKey = 'data.payloadToPso';
        $returnKey = 'input_payload';

        if ($isNonStandardResponse) {
            $responseKey = 'data';
            $returnKey = 'data';
        }

        if ($method === null) {
            $method = $payload === null ? HttpMethod::GET : HttpMethod::POST;
        }

        $version = config('psott.pso-services-api-version');
        $url = 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').$api_segment;
        $timeout = config('psott.defaults.timeout', 10);

        $request = Http::contentType('application/json')
            ->accept('application/json')
            ->timeout($timeout)
            ->connectTimeout($timeout);

        if (! empty($headers)) {
            $updatedHeaders = Arr::except(data_get($headers, 'environment'), ['sendToPso']);
            $request = $request->withHeaders($updatedHeaders);
        }

        try {
            $response = $payload === null
                ? $request->{$method->value}($url)
                : $request->{$method->value}($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('Connection error while calling PSO services API', ['url' => $url, 'message' => $e->getMessage()]);
            $this->notifyPayloadSent('Error', 'Could not reach the PSO services API (timed out or unreachable).', false);

            return json_encode(
                ['error' => 'Connection error: '.$e->getMessage()],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
        }

        $pass = $response->successful();

        $body = 'sent to services API';
        if ($response->unauthorized()) {
            $body = 'invalid credentials';
        } elseif ($response->failed()) {
            $body = 'see the response below';
        }

        $this->notifyPayloadSent($pass ? 'Success' : 'Error', $body, $pass);

        if ($pass) {
            try {
                $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Log::error('Invalid JSON response from PSO services API', ['url' => $url, 'message' => $e->getMessage()]);

                return json_encode(
                    ['error' => 'Received an invalid (non-JSON) response from the PSO services API.'],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                );
            }

            $payload = data_get($decoded, $responseKey);

            return json_encode(
                [$returnKey => $payload],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
        }

        return json_encode(
            $response->body(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public function prepareTokenizedPayload(bool $send_to_pso, array $payload, array $environmentProperties = []): array|false
    {
        if ($send_to_pso) {
            $props = $environmentProperties ?: [
                'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
                'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
                'username' => $this->selectedEnvironment->getAttribute('username'),
                'password' => $this->selectedEnvironment->getAttribute('password'),
            ];

            $token = $this->authenticatePSO(
                data_get($props, 'base_url'),
                data_get($props, 'account_id'),
                data_get($props, 'username'),
                Crypt::decryptString(data_get($props, 'password'))
            );
        } else {
            $token = null;
        }

        if ($send_to_pso && ! $token) {
            $this->notifyPayloadSent('Send to PSO Failed', 'Please see the event log', false);

            return false;
        }

        if ($token) {
            $payload = Arr::add($payload, 'environment.token', $token);
        }

        return $payload;
    }

    public function notifyPayloadSent(string $title, string $body, bool $pass): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->{$pass ? 'success' : 'danger'}()
            ->send();
    }
}
