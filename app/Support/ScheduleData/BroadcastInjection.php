<?php

namespace App\Support\ScheduleData;

use Carbon\CarbonImmutable;
use DateInterval;
use Throwable;

/**
 * Everything the rewriters need to write the Broadcast and its
 * Broadcast_Parameter rows: the settings, the webhook URL PSO will call and
 * the time filter (both null when the LOAD has no DSE window).
 *
 * No `compression` parameter is ever written: the self-hosted webhook
 * service cannot store gzipped bodies (the script omits it there too, and
 * then sends the file's own media type).
 */
readonly class BroadcastInjection
{
    public function __construct(
        public BroadcastSettings $settings,
        public string $webhookUrl,
        public ?string $timeFilterStart = null,
        public ?string $timeFilterEnd = null,
    ) {}

    /**
     * The time filter for a LOAD: from now to the end of the day (UTC) that
     * the DSE window plus the appointment window (when there is one) reaches.
     * Null when the DSE window is missing or not a duration PHP can read.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function timeFilter(CarbonImmutable $now, ?string $duration, ?string $appointmentWindowDuration): ?array
    {
        $now = $now->utc();

        if (blank($duration)) {
            return null;
        }

        try {
            $end = $now->add(new DateInterval((string) $duration));

            if (filled($appointmentWindowDuration)) {
                $end = $end->add(new DateInterval((string) $appointmentWindowDuration));
            }
        } catch (Throwable) {
            return null;
        }

        return [$now->format('Y-m-d\TH:i:sP'), $end->endOfDay()->format('Y-m-d\TH:i:sP')];
    }

    /**
     * @param  bool  $typed  true for 6.15+ ScheduleData JSON (native booleans and numbers);
     *                       false for XML and legacy dsScheduleData JSON (strings throughout)
     * @return array<string, bool|int|string>
     */
    public function broadcastFields(bool $typed): array
    {
        $fields = [
            'id' => $this->settings->broadcastId,
            'active' => $typed ? true : 'true',
            'broadcast_type_id' => 'REST',
            'plan_type' => $this->settings->planType,
            'minimum_plan_quality' => $typed ? $this->settings->minimumPlanQuality : (string) $this->settings->minimumPlanQuality,
            'minimum_step_interval' => $typed ? 1 : '1',
            'once_only' => $typed ? false : 'false',
            'maximum_wait' => $this->settings->maximumWait,
            'maximum_frequency' => $this->settings->maximumFrequency,
            'allocation_type' => $typed ? $this->settings->allocationType : (string) $this->settings->allocationType,
        ];

        if ($this->timeFilterStart !== null && $this->timeFilterEnd !== null) {
            $fields['time_filter_start'] = $this->timeFilterStart;
            $fields['time_filter_end'] = $this->timeFilterEnd;
        }

        return $fields;
    }

    /**
     * @return list<array{broadcast_id: string, parameter_name: string, parameter_value: string}>
     */
    public function parameterRows(string $mediaType): array
    {
        $values = [
            'etag' => '*',
            'httpmethod' => 'PATCH',
            'mediatype' => $mediaType,
            'required_tables' => implode(',', $this->settings->requiredTables),
            'url' => $this->webhookUrl,
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
