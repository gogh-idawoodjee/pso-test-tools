<?php

namespace App\Support\ReferenceValues;

use App\Models\Environment;
use App\Support\PsoServices\PsoServicesApi;
use App\Support\Settings\AppSettings;
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
        $url = PsoServicesApi::url('dataset/reference-values');

        try {
            $response = Http::acceptJson()
                ->timeout(app(AppSettings::class)->timeout('reference_values'))
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
            throw new ReferenceValuesFetchException(PsoServicesApi::errorMessage($response));
        }

        $tables = $response->json('data.tables');
        if (! is_array($tables)) {
            throw new ReferenceValuesFetchException('The PSO services API answered without the expected tables.');
        }

        return $tables;
    }
}
