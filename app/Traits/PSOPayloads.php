<?php

namespace App\Traits;

use App\Enums\HttpMethod;
use App\Enums\InputMode;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;
use SensitiveParameter;


trait PSOPayloads
{

    // todo always get the token instead of sending user/pass to the API

    /**
     * @throws JsonException
     */
    public function sendToPSO(#[SensitiveParameter] $api_segment, $payload, $method = HttpMethod::POST)
    {

        $response = Http::contentType('application/json')
            ->accept('application/json')
            ->{$method->value}('https://' . config('psott.pso-services-api') . '/api/' . $api_segment, $payload);

        $pass = $response->successful();

        if ($response->failed()) {
            $body = 'see the response below';
        } elseif ($response->unauthorized()) {
            $body = 'invalid credentials';
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
