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

    public function authenticatePSO(
        string $base_url,
        string $account_id,
        string $username,
        string $password
    ): ?string
    {

        if (!$base_url) {
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

            // Check for 401 before throwing anything else
            if ($response->status() === 400) {
                // todo build an event viewer
                $this->error_value = 401;
                Log::warning('Invalid PSO credentials provided.', compact('username', 'account_id'));

            }

            $sessionToken = $response->json('SessionToken');

            if (!$sessionToken) {
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
    public function sendToPSO(#[SensitiveParameter] $api_segment, $payload, $method = HttpMethod::POST)
    {

        // todo have to do some magic if this is appointment booking

        $version = config('psott.pso-services-api-version') ? 'v2/' : '';
        $url = 'https://' . config('psott.pso-services-api') . '/api/' . $version . $api_segment;


        $response = Http::contentType('application/json')
            ->accept('application/json')
            ->{$method->value}($url, $payload);
//
//        if (config('psott.pso-services-api-version') === '2') {
//            return $response->collect();
//        }

        $pass = $response->successful();

//        if ($response->unauthorized()) {
//            $body = 'invalid credentials';
//        } elseif ($response->failed()) {
//            $body = 'see the response below';
//        } else {
//            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR)->description;
//        }

        $body = 'sent to services API';

        if ($response->unauthorized()) {
            $body = 'invalid credentials';
        } elseif ($response->failed()) {
            $body = 'see the response below';
        }
        $this->notifyPayloadSent($pass ? 'Success' : 'Error', $body, $pass);

        if ($pass) {

            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR); // decode as array

            $payload = data_get($decoded, 'data.payloadToPso');

            return json_encode(['input_payload' => $payload], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        }


        return json_encode($response->body(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
//        return $response->collect()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    }

    public function prepareTokenizedPayload($send_to_pso, $payload)
    {

        $token = $send_to_pso ? $this->authenticatePSO(
            $this->selectedEnvironment->getAttribute('base_url'),
            $this->selectedEnvironment->getAttribute('account_id'),
            $this->selectedEnvironment->getAttribute('username'),
            Crypt::decryptString($this->selectedEnvironment->getAttribute('password'))
        ) : null;


        if ($send_to_pso && !$token) {

            $this->notifyPayloadSent('Send to PSO Failed', 'Please see the event log (when it is actually completed)', false);
            return false;
        }

        if ($token) {

            $payload = Arr::add($payload, 'environment.token', $token);

        }

        return $payload; // will either return a payload or false

    }

    public function notifyPayloadSent($title, $body, $pass): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->when($pass, function ($notification) {
                $notification->success();
            }, function ($notification) {
                $notification->danger();
            })
            ->send();
    }

}
