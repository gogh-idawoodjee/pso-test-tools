<?php

namespace App\Support\ScheduleData;

/**
 * The editable parts of the injected webhook Broadcast. The fixed parts
 * (REST, PATCH, once only, step interval 1) live in BroadcastInjection.
 */
readonly class BroadcastSettings
{
    /**
     * @param  list<string>  $requiredTables
     */
    public function __construct(
        public string $broadcastId,
        public string $planType,
        public int $minimumPlanQuality,
        public string $maximumWait,
        public int $allocationType,
        public array $requiredTables,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = (array) config('psott.gateway_upload.broadcast');

        $pick = static fn (string $key): mixed => filled($values[$key] ?? null) ? $values[$key] : $defaults[$key];

        return new self(
            broadcastId: (string) $pick('id'),
            planType: (string) $pick('plan_type'),
            minimumPlanQuality: (int) $pick('minimum_plan_quality'),
            maximumWait: (string) $pick('maximum_wait'),
            allocationType: (int) $pick('allocation_type'),
            requiredTables: array_values(array_map('strval', (array) $pick('required_tables'))),
        );
    }

    /**
     * @return array{id: string, plan_type: string, minimum_plan_quality: int, maximum_wait: string, allocation_type: int, required_tables: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->broadcastId,
            'plan_type' => $this->planType,
            'minimum_plan_quality' => $this->minimumPlanQuality,
            'maximum_wait' => $this->maximumWait,
            'allocation_type' => $this->allocationType,
            'required_tables' => $this->requiredTables,
        ];
    }

    public static function isValidDuration(string $value): bool
    {
        return preg_match(
            '/\AP(?=\d|T\d)(?:\d+W|(?:\d+Y)?(?:\d+M)?(?:\d+D)?)(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?\z/',
            $value,
        ) === 1;
    }
}
