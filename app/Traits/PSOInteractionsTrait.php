<?php

namespace App\Traits;

use App\Enums\HttpMethod;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use SensitiveParameter;


trait PSOInteractionsTrait
{

    // todo always get the token instead of sending user/pass to the API


    public function authenticatePSO($base_url, $account_id, $username, $password)
    {
        if (!$base_url) {
            // Handle the case where base_url is not provided
            Log::warning('Base URL is missing for PSO authentication.');
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(config('psott.defaults.timeout'))
                ->connectTimeout(config('psott.defaults.timeout'))
                ->post("{$base_url}/IFSSchedulingRESTfulGateway/api/v1/scheduling/session", [
                    'accountId' => $account_id,
                    'userName' => $username,
                    'password' => $password,
                ]);

            // Throw an exception if a client or server error occurred
            $response->throw();

            // Attempt to retrieve the session token from the response
            $sessionToken = $response->json('SessionToken');

            if ($sessionToken) {
                return $sessionToken;
            }

            // Log a warning if the session token is missing
            Log::warning('Authentication successful but SessionToken is missing in the response.');
            return null;

        } catch (ConnectionException $e) {
            // Handle connection exceptions (e.g., timeout, DNS issues)
            Log::error('Connection error during PSO authentication: ' . $e->getMessage());
            return null;
        } catch (RequestException $e) {
            // Handle HTTP response errors (4xx and 5xx status codes)
            Log::error('HTTP error during PSO authentication: ' . $e->getMessage(), [
                'response' => $e->response->json(),
                'status' => $e->response->status(),
            ]);
            return null;
        } catch (Exception $e) {
            // Handle any other unforeseen exceptions
            Log::error('Unexpected error during PSO authentication: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @throws JsonException
     */
    public function sendToPSO(#[SensitiveParameter] $api_segment, $payload, $method = HttpMethod::POST)
    {

        $response = Http::contentType('application/json')
            ->accept('application/json')
            ->{$method->value}('https://' . config('psott.pso-services-api') . '/api/' . $api_segment, $payload);


        $pass = $response->successful();

        if ($response->unauthorized()) {
            $body = 'invalid credentials';
        } elseif ($response->failed()) {
            $body = 'see the response below';
        } else {
            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR)->description;
        }

        $this->notifyPayloadSent($pass ? 'Success' : 'Error', $body, $pass);

        if ($pass) {
            return json_encode(['input_payload' => json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR)->original_payload], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $response->collect()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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
