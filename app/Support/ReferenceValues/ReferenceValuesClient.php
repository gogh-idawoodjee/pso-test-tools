<?php

namespace App\Support\ReferenceValues;

use App\Models\Environment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls pso-services GET /dataset/reference-values for one environment and
 * dataset. Kept apart from PSOInteractionsTrait::sendToPSONew() because that
 * helper uses the short default timeout and this call takes tens of seconds.
 */
class ReferenceValuesClient
{
    /**
     * @return array<string, array<int, array<string, mixed>>> the six tables keyed by wire entity name
     *
     * @throws ReferenceValuesFetchException
     */
    public function fetch(Environment $environment, string $datasetName): array
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('psott.reference_values.timeout', 600))
                ->connectTimeout(10)
                ->withHeaders([
                    'baseUrl' => $environment->base_url,
                    'accountId' => $environment->account_id,
                    'datasetId' => $datasetName,
                    'username' => $environment->username,
                    'password' => Crypt::decryptString($environment->password),
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('Reference values: pso-services could not be reached', ['url' => $url, 'message' => $exception->getMessage()]);

            throw new ReferenceValuesFetchException('The PSO services API could not be reached (timed out or unreachable).', previous: $exception);
        }

        if (! $response->successful()) {
            throw new ReferenceValuesFetchException($this->errorMessage($response->json(), $response->status()));
        }

        $tables = $response->json('data.tables');
        if (! is_array($tables)) {
            throw new ReferenceValuesFetchException('The PSO services API answered without the expected tables.');
        }

        return $tables;
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'dataset/reference-values';
    }

    private function errorMessage(mixed $body, int $status): string
    {
        $error = data_get($body, 'message.error') ?? data_get($body, 'message');
        $details = data_get($body, 'message.details');

        $text = is_string($error) ? $error : "The PSO services API returned {$status}.";
        if (is_string($details) && $details !== '') {
            $text .= ' '.$details;
        }

        return $text;
    }
}
