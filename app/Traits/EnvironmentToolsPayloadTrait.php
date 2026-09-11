<?php

namespace App\Traits;

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastParameterType;
use App\Enums\BroadcastPlanType;
use App\Enums\BroadcastType;
use App\Enums\InputMode;
use App\Enums\ProcessType;
use Carbon\Carbon;
use Illuminate\Support\Arr;

trait EnvironmentToolsPayloadTrait
{
    private function buildLoadRotaPayload($data): array
    {
        $schema = [
            'base_url' => $data('base_url'),
            'dse_duration' => $data('dse_duration'),
            'dataset_id' => $data('dataset_id'),
            'description' => $data('input_mode') === InputMode::CHANGE ? 'Update Rota From Tool Box' : 'Load From Tool Box',
            'send_to_pso' => $data('send_to_pso'),
            'keep_pso_data' => $data('keep_pso_data'),
            'account_id' => $data('account_id'),
            'appointment_window' => $data('appointment_window'),
            'process_type' => ProcessType::from($data('process_type')?->value ?? ProcessType::APPOINTMENT->value)->value,
            'datetime' => $data('datetime'),
            'input_mode' => $data('input_mode'),
            'pso_api_version' => $data('pso_api_version'),
            'include_arp_data' => $data('include_arp_data'),
            'rota_id' => $data('rota_id'),
            'broadcasts' => $data('broadcasts'),
        ];

        return $this->initializeLoadRotaPayload($schema);
    }

    public function initializeLoadRotaPayload($data): array
    {
        $payload = [
            'environment' => [
                'baseUrl' => data_get($data, 'base_url'),
                'description' => data_get($data, 'description'),
                'datasetId' => data_get($data, 'dataset_id'),
                'sendToPso' => data_get($data, 'send_to_pso'),
            ],
            'data' => [
                'inputDatetime' => filled(data_get($data, 'datetime'))
                    ? Carbon::parse(data_get($data, 'datetime'))->toAtomString()
                    : Carbon::now()->toAtomString(),
            ],
        ];

        if (filled(data_get($data, 'pso_api_version'))) {
            $payload = Arr::add($payload, 'environment.psoApiVersion', (int) data_get($data, 'pso_api_version'));
        }

        if (data_get($data, 'input_mode') === InputMode::LOAD) {
            $payload = Arr::add($payload, 'data.dseDuration', data_get($data, 'dse_duration'));
            $payload = Arr::add($payload, 'data.keepPsoData', data_get($data, 'keep_pso_data'));
            $payload = Arr::add($payload, 'data.processType', data_get($data, 'process_type'));
            $payload = Arr::add($payload, 'data.appointmentWindow', data_get($data, 'appointment_window'));

            if (data_get($data, 'include_arp_data')) {
                $payload = Arr::add($payload, 'data.includeArpData', true);
                $payload = Arr::add($payload, 'data.rotaId', data_get($data, 'rota_id'));
            }

            $broadcasts = $this->buildBroadcastsPayload((array) data_get($data, 'broadcasts', []));

            if (filled($broadcasts)) {
                $payload = Arr::add($payload, 'data.broadcasts', $broadcasts);
            }
        }

        if (data_get($data, 'send_to_pso')) {
            $payload = Arr::add($payload, 'environment.accountId', data_get($data, 'account_id'));
        }

        return $payload;
    }

    /**
     * Transforms the raw `broadcasts` repeater state into the `data.broadcasts[]`
     * shape the PSO-Services load endpoint expects, including the `parameters[]`
     * pairs required for the chosen broadcastTypeId/planType.
     */
    public function buildBroadcastsPayload(array $broadcasts): array
    {
        return collect($broadcasts)
            ->values()
            ->map(function (array $broadcast) {
                $type = $broadcast['broadcast_type_id'] ?? null;
                $type = $type instanceof BroadcastType ? $type : BroadcastType::tryFrom((string) $type);

                $planType = $broadcast['plan_type'] ?? null;
                $planType = $planType instanceof BroadcastPlanType ? $planType : BroadcastPlanType::tryFrom((string) $planType);

                $requiredParameterNames = $type?->requiredParameters() ?? [];

                if ($planType === BroadcastPlanType::ADMIN) {
                    $requiredParameterNames = [
                        ...$requiredParameterNames,
                        BroadcastParameterType::APPLICATION_TYPE_ID,
                        BroadcastParameterType::CHECK_IN_EXPIRED_TIME,
                    ];
                }

                $parameters = collect($requiredParameterNames)
                    ->map(static fn (BroadcastParameterType $parameter) => [
                        'name' => $parameter->value,
                        'value' => data_get($broadcast, $parameter->value),
                    ])
                    ->filter(static fn (array $parameter) => filled($parameter['value']))
                    ->values()
                    ->all();

                return array_filter([
                    'active' => $broadcast['active'] ?? true,
                    'broadcastTypeId' => $type?->value,
                    'planType' => $planType?->value,
                    'allocationType' => filled($broadcast['allocation_type'] ?? null)
                        ? array_map(
                            static fn ($value) => $value instanceof BroadcastAllocationType ? $value->value : (int) $value,
                            $broadcast['allocation_type']
                        )
                        : null,
                    'description' => $broadcast['description'] ?? null,
                    'onceOnly' => $broadcast['once_only'] ?? null,
                    'minimumPlanQuality' => $broadcast['minimum_plan_quality'] ?? null,
                    'minimumStepInterval' => $broadcast['minimum_step_interval'] ?? null,
                    'expiryDatetime' => $broadcast['expiry_datetime'] ?? null,
                    'maximumFrequency' => filled($broadcast['maximum_frequency'] ?? null) ? (int) $broadcast['maximum_frequency'] : null,
                    'maximumWait' => filled($broadcast['maximum_wait'] ?? null) ? (int) $broadcast['maximum_wait'] : null,
                    'minimumVisitStatus' => $broadcast['minimum_visit_status'] ?? null,
                    'timeFilterStart' => $broadcast['time_filter_start'] ?? null,
                    'timeFilterEnd' => $broadcast['time_filter_end'] ?? null,
                    'parameters' => $parameters,
                ], static fn ($value) => $value !== null);
            })
            ->all();
    }
}
