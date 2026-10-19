<?php

namespace App\Traits;

use App\Enums\HttpMethod;
use App\Support\PsoServices\PsoServicesSessionClient;
use App\Support\PsoServices\PsoServicesSessionException;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use SensitiveParameter;

trait PSOInteractionsTrait
{
    protected ?int $error_value = null;

    /**
     * Why the last authenticatePSO()/logoffPSO() call failed, as pso-services
     * reported it, for callers that want to show the reason.
     */
    protected ?string $session_error_message = null;

    /** The HTTP status of the last sendToPSONew() reply; null when services could not be reached. */
    protected ?int $last_response_status = null;

    public ?array $json_form_data = [];

    public function authenticatePSO(
        string $base_url,
        string $account_id,
        string $username,
        #[SensitiveParameter] string $password,
    ): ?string {
        $this->error_value = null;
        $this->session_error_message = null;

        if (! $base_url) {
            Log::warning('Base URL is missing for PSO authentication.');

            return null;
        }

        try {
            return app(PsoServicesSessionClient::class)->logon($base_url, $account_id, $username, $password);
        } catch (PsoServicesSessionException $exception) {
            $this->error_value = $exception->statusCode === 401 ? 401 : 500;
            $this->session_error_message = $exception->getMessage();

            Log::warning('PSO authentication through pso-services failed', [
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
                'username' => $username,
                'account_id' => $account_id,
            ]);

            return null;
        }
    }

    /**
     * Ends a session through pso-services. A failure is reported, not thrown:
     * callers have already finished their work by the time they log off.
     */
    public function logoffPSO(string $base_url, #[SensitiveParameter] string $session_token): bool
    {
        $this->session_error_message = null;

        try {
            app(PsoServicesSessionClient::class)->logoff($base_url, $session_token);

            return true;
        } catch (PsoServicesSessionException $exception) {
            $this->session_error_message = $exception->getMessage();

            Log::warning('PSO logoff through pso-services failed', [
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
            ]);

            return false;
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
        ?array $query = null,
        ?int $timeout = null,
    ): string {
        $headers ??= [];
        $this->last_response_status = null;
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
        $timeout ??= config('psott.defaults.timeout', 10);

        $request = Http::contentType('application/json')
            ->accept('application/json')
            ->timeout($timeout)
            ->connectTimeout($timeout);

        if (! empty($headers)) {
            $updatedHeaders = Arr::except(data_get($headers, 'environment'), ['sendToPso']);
            $request = $request->withHeaders($updatedHeaders);
        }

        Log::debug('Calling PSO services API', [
            'method' => $method->value,
            'url' => $url,
            'payload' => is_array($payload) ? self::redactSensitivePayload($payload) : $payload,
        ]);

        try {
            $response = $payload === null
                ? $request->{$method->value}($url, $query ?? [])
                : $request->{$method->value}($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('Connection error while calling PSO services API', ['url' => $url, 'message' => $e->getMessage()]);
            $this->notifyPayloadSent('Error', 'Could not reach the PSO services API (timed out or unreachable).', false);

            return json_encode(
                ['error' => 'Connection error: '.$e->getMessage()],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
        }

        $this->last_response_status = $response->status();
        $pass = $response->successful();

        $body = 'sent to services API';
        if ($response->unauthorized()) {
            $body = 'invalid credentials';
        } elseif ($response->failed()) {
            $body = 'see the response below';
        }

        if ($pass) {
            Log::debug('PSO services API response', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } else {
            Log::warning('PSO services API returned a non-successful response', [
                'url' => $url,
                'method' => $method->value,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]);
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

    private static function redactSensitivePayload(array $payload): array
    {
        return Arr::undot(
            collect(Arr::dot($payload))
                ->map(function ($value, string $key) {
                    $sensitive = ['environment.token', 'data.googleApiKey', 'environment.password'];

                    return in_array($key, $sensitive, true) ? '[REDACTED]' : $value;
                })
                ->all()
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
