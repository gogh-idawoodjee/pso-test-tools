<?php

namespace App\Support\ScheduleData;

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastPlanType;
use App\Support\Settings\AppSettings;
use BackedEnum;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The editable parts of the injected webhook Broadcast. The fixed parts
 * (REST, PATCH, not once only, step interval 1, the time filter) live in
 * BroadcastInjection.
 */
readonly class BroadcastSettings
{
    /** The form field names, on the Gateway upload form and the Settings page alike. */
    public const array FORM_FIELDS = [
        'broadcast_id',
        'broadcast_plan_type',
        'broadcast_minimum_plan_quality',
        'broadcast_maximum_wait',
        'broadcast_maximum_frequency',
        'broadcast_allocation_type',
        'broadcast_required_tables',
    ];

    /** Printable characters XML 1.0 can hold, on one line (as the dataset ID override). */
    private const string XML_SAFE_LINE_PATTERN = '/\A[\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u';

    /**
     * @param  list<string>  $requiredTables
     */
    public function __construct(
        public string $broadcastId,
        public string $planType,
        public int $minimumPlanQuality,
        public string $maximumWait,
        public string $maximumFrequency,
        public int $allocationType,
        public array $requiredTables,
    ) {}

    /** The Settings page's values, falling back to the shipped ones. */
    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /** The shipped values in config/psott.php alone. */
    public static function shipped(): self
    {
        return self::fromArray([], useSaved: false);
    }

    /**
     * Fills anything missing from the defaults: the Settings page's values
     * (unless $useSaved is false), then the shipped ones in config/psott.php.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values, bool $useSaved = true): self
    {
        $shipped = (array) config('psott.gateway_upload.broadcast');
        $saved = $useSaved ? (app(AppSettings::class)->gatewayBroadcastDefaults() ?? []) : [];

        $pick = static function (string $key) use ($values, $saved, $shipped): mixed {
            foreach ([$values, $saved] as $source) {
                if (filled($source[$key] ?? null)) {
                    return $source[$key];
                }
            }

            return $shipped[$key];
        };

        return new self(
            broadcastId: (string) $pick('id'),
            planType: (string) $pick('plan_type'),
            minimumPlanQuality: (int) $pick('minimum_plan_quality'),
            maximumWait: (string) $pick('maximum_wait'),
            maximumFrequency: (string) $pick('maximum_frequency'),
            allocationType: (int) $pick('allocation_type'),
            requiredTables: array_values(array_map('strval', (array) $pick('required_tables'))),
        );
    }

    /**
     * @return array{id: string, plan_type: string, minimum_plan_quality: int, maximum_wait: string, maximum_frequency: string, allocation_type: int, required_tables: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->broadcastId,
            'plan_type' => $this->planType,
            'minimum_plan_quality' => $this->minimumPlanQuality,
            'maximum_wait' => $this->maximumWait,
            'maximum_frequency' => $this->maximumFrequency,
            'allocation_type' => $this->allocationType,
            'required_tables' => $this->requiredTables,
        ];
    }

    /**
     * Validates the broadcast form fields (the Gateway upload form and the
     * Settings page use the same names). fromArray() casts silently, so
     * anything it would mangle is refused here. Returns null, with the first
     * error in $error and its form field in $errorField, when a value is invalid.
     *
     * @param  array<string, mixed>  $values  keyed by FORM_FIELDS
     */
    public static function validated(array $values, ?string &$error = null, ?string &$errorField = null): ?self
    {
        $normalise = static fn (mixed $value): mixed => match (true) {
            $value instanceof BackedEnum => $value->value,
            is_string($value) => trim($value),
            default => $value,
        };

        $values = array_map($normalise, array_intersect_key($values, array_flip(self::FORM_FIELDS)) + array_fill_keys(self::FORM_FIELDS, null));

        $duration = static function (string $label, string $example): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($label, $example): void {
                if (! is_string($value) || ! self::isValidDuration($value)) {
                    $fail("{$label} must be an ISO 8601 duration, such as {$example}.");
                }
            };
        };

        $validator = Validator::make($values, [
            'broadcast_id' => ['required', 'string', 'max:255', 'regex:'.self::XML_SAFE_LINE_PATTERN],
            'broadcast_plan_type' => ['required', Rule::enum(BroadcastPlanType::class)],
            'broadcast_minimum_plan_quality' => ['required', 'integer', 'between:0,100'],
            'broadcast_maximum_wait' => ['required', 'string', $duration('Maximum Wait', 'PT1M')],
            'broadcast_maximum_frequency' => ['required', 'string', $duration('Maximum Frequency', 'PT10M')],
            'broadcast_allocation_type' => ['required', Rule::enum(BroadcastAllocationType::class)],
            'broadcast_required_tables' => ['required', 'array', 'min:1'],
            'broadcast_required_tables.*' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_]+\z/'],
        ], [
            'broadcast_id.regex' => 'Broadcast ID contains a character that cannot be sent (a control character such as a tab or line break).',
            'broadcast_required_tables.required' => 'Required Tables needs at least one table name.',
            'broadcast_required_tables.min' => 'Required Tables needs at least one table name.',
            'broadcast_required_tables.*.regex' => 'Each required table must be a single table name (letters, digits and underscores), such as Allocation.',
        ], [
            'broadcast_id' => 'Broadcast ID',
            'broadcast_plan_type' => 'Plan Type',
            'broadcast_minimum_plan_quality' => 'Minimum Plan Quality',
            'broadcast_maximum_wait' => 'Maximum Wait',
            'broadcast_maximum_frequency' => 'Maximum Frequency',
            'broadcast_allocation_type' => 'Allocation Type',
            'broadcast_required_tables' => 'Required Tables',
        ]);

        if ($validator->fails()) {
            $error = (string) $validator->errors()->first();
            $errorField = strtok((string) array_key_first($validator->errors()->messages()), '.');

            return null;
        }

        return self::fromArray([
            'id' => $values['broadcast_id'],
            'plan_type' => $values['broadcast_plan_type'],
            'minimum_plan_quality' => (int) $values['broadcast_minimum_plan_quality'],
            'maximum_wait' => $values['broadcast_maximum_wait'],
            'maximum_frequency' => $values['broadcast_maximum_frequency'],
            'allocation_type' => (int) $values['broadcast_allocation_type'],
            'required_tables' => array_values($values['broadcast_required_tables']),
        ]);
    }

    /**
     * The form state for these settings, keyed by FORM_FIELDS.
     *
     * @return array<string, mixed>
     */
    public function toFormState(): array
    {
        return [
            'broadcast_id' => $this->broadcastId,
            'broadcast_plan_type' => $this->planType,
            'broadcast_minimum_plan_quality' => $this->minimumPlanQuality,
            'broadcast_maximum_wait' => $this->maximumWait,
            'broadcast_maximum_frequency' => $this->maximumFrequency,
            'broadcast_allocation_type' => $this->allocationType,
            'broadcast_required_tables' => $this->requiredTables,
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
