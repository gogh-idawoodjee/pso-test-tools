<?php

namespace App\Support\Webhook;

use App\Support\GatewayUpload\UploadRunLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Creates (or finds) the webhook endpoint PSO will broadcast the plan to,
 * the way Send-PsoScheduleData.ps1 does: a stable per-environment alias on
 * the self-hosted service, or a fresh one-off token when no alias key is set.
 * The alias key is read from config and never written to the run log.
 */
class WebhookEndpointProvisioner
{
    public function provision(string $environmentName, string $contentType, UploadRunLog $log): WebhookEndpoint
    {
        $baseUrl = rtrim((string) config('services.webhook.base_url'), '/');
        $aliasKey = config('services.webhook.alias_key');

        $tokenConfig = [
            'default_status' => 200,
            'default_content' => $contentType === 'application/json' ? '{}' : '<response/>',
            'default_content_type' => $contentType,
        ];

        if (blank($aliasKey)) {
            $log->warn('No alias API key configured (WEBHOOK_ALIAS_KEY) - falling back to a fresh one-off token.');

            $endpoint = $this->createOneOff($baseUrl, $tokenConfig, $log);
        } else {
            $endpoint = $this->ensureAlias($baseUrl, (string) $aliasKey, $this->aliasFor($environmentName), $tokenConfig, $log);
        }

        $log->sub("Configured default response: status 200, content-type {$contentType}, body '{$tokenConfig['default_content']}'");

        return $endpoint;
    }

    private function aliasFor(string $environmentName): string
    {
        return 'pso-'.preg_replace('/[^a-z0-9-]/', '-', Str::lower($environmentName));
    }

    /**
     * @param  array<string, mixed>  $tokenConfig
     */
    private function ensureAlias(string $baseUrl, #[SensitiveParameter] string $aliasKey, string $alias, array $tokenConfig, UploadRunLog $log): WebhookEndpoint
    {
        $log->step("Ensuring webhook alias '/{$alias}' exists and injecting Broadcast...");

        $response = $this->send(
            fn (): Response => $this->client()->withHeaders(['X-Alias-Key' => $aliasKey])->post("{$baseUrl}/_alias/{$alias}", $tokenConfig),
            "Failed to create/look up webhook alias '{$alias}' at {$baseUrl}/_alias",
        );

        $uuid = $response->json('uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new WebhookProvisioningException("The webhook service answered the alias request for '{$alias}' without a uuid.");
        }

        $created = (bool) $response->json('created');

        if ($created) {
            $log->sub("Created new alias /{$alias} -> token {$uuid}");
        } else {
            $log->sub("Alias /{$alias} already exists -> token {$uuid}");

            // Keep the canned response in step with this run's format. Non-fatal.
            try {
                $refresh = $this->client()->put("{$baseUrl}/token/{$uuid}", $tokenConfig);

                if (! $refresh->successful()) {
                    $log->warn("Could not update the alias token's default response (non-fatal): HTTP {$refresh->status()}");
                }
            } catch (ConnectionException $exception) {
                $log->warn("Could not update the alias token's default response (non-fatal): {$exception->getMessage()}");
            }
        }

        $endpoint = new WebhookEndpoint(
            url: "{$baseUrl}/{$alias}",
            viewUrl: "{$baseUrl}/#!/{$uuid}",
            uuid: $uuid,
            alias: $alias,
            created: $created,
        );

        $log->sub("Webhook endpoint : {$endpoint->url}");
        $log->sub("Dashboard        : {$endpoint->viewUrl}");

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $tokenConfig
     */
    private function createOneOff(string $baseUrl, array $tokenConfig, UploadRunLog $log): WebhookEndpoint
    {
        $log->step('Creating one-off webhook endpoint and injecting Broadcast...');

        $response = $this->send(
            fn (): Response => $this->client()->post("{$baseUrl}/token", $tokenConfig),
            "Failed to create webhook token at {$baseUrl}/token",
        );

        $uuid = $response->json('uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new WebhookProvisioningException('The webhook service answered the token request without a uuid.');
        }

        $endpoint = new WebhookEndpoint(
            url: "{$baseUrl}/{$uuid}",
            viewUrl: "{$baseUrl}/#!/{$uuid}",
            uuid: $uuid,
            alias: null,
            created: true,
        );

        $log->sub("New webhook endpoint: {$endpoint->viewUrl}");

        return $endpoint;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $failureMessage): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new WebhookProvisioningException("{$failureMessage}: the webhook service could not be reached (timed out or unreachable).", previous: $exception);
        }

        if (! $response->successful()) {
            throw new WebhookProvisioningException("{$failureMessage}: HTTP {$response->status()}.");
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::asJson()->acceptJson()->timeout(15)->connectTimeout(10);
    }
}
