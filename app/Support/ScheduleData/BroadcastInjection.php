<?php

namespace App\Support\ScheduleData;

/**
 * Everything the rewriters need to write the Broadcast and its
 * Broadcast_Parameter rows: the settings plus the webhook URL PSO will call.
 *
 * No `compression` parameter is ever written: the self-hosted webhook
 * service cannot store gzipped bodies (the script omits it there too).
 */
readonly class BroadcastInjection
{
    public function __construct(
        public BroadcastSettings $settings,
        public string $webhookUrl,
    ) {}

    /**
     * @param  bool  $typed  true for 6.15+ ScheduleData JSON (native booleans and numbers);
     *                       false for XML and legacy dsScheduleData JSON (strings throughout)
     * @return array<string, bool|int|string>
     */
    public function broadcastFields(bool $typed): array
    {
        return [
            'id' => $this->settings->broadcastId,
            'active' => $typed ? true : 'true',
            'broadcast_type_id' => 'REST',
            'plan_type' => $this->settings->planType,
            'minimum_plan_quality' => $typed ? $this->settings->minimumPlanQuality : (string) $this->settings->minimumPlanQuality,
            'maximum_wait' => $this->settings->maximumWait,
            'minimum_step_interval' => $typed ? 1 : '1',
            'once_only' => $typed ? true : 'true',
            'allocation_type' => $typed ? $this->settings->allocationType : (string) $this->settings->allocationType,
        ];
    }

    /**
     * @return list<array{broadcast_id: string, parameter_name: string, parameter_value: string}>
     */
    public function parameterRows(string $mediaType): array
    {
        $values = [
            'url' => $this->webhookUrl,
            'mediatype' => $mediaType,
            'httpmethod' => 'PATCH',
            'etag' => '*',
            'required_tables' => implode(',', $this->settings->requiredTables),
        ];

        $rows = [];

        foreach ($values as $name => $value) {
            $rows[] = [
                'broadcast_id' => $this->settings->broadcastId,
                'parameter_name' => $name,
                'parameter_value' => $value,
            ];
        }

        return $rows;
    }
}
