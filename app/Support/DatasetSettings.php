<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Per-dataset settings the ported tools read (what the account files in
 * accounts/ hold in the pso-tools repo). Fixed key list; unknown keys are an
 * error so a typo in the form or a seed file cannot silently do nothing.
 */
class DatasetSettings implements Arrayable, JsonSerializable
{
    public const array KEYS = [
        'display_name', 'dataset_display_name', 'coverage_km', 'eod_cutoff_hour', 'exclude_resource_types',
        'resource_type_names', 'attribute_labels', 'region_pivot', 'region_labels', 'supervisor_label', 'region_label',
        'supervisor_label_plural', 'notes', 'windows_time_zone', 'time_zone', 'ifs_time_zone',
        'appointment_sla_type_id', 'default_booking_sla_type_id',
    ];

    /** Keys as they appear in a pso-tools account file that differ from ours. */
    private const array ACCOUNT_FILE_ALIASES = [
        'dataset_name' => 'dataset_display_name',
        'attributes' => 'attribute_labels',
        'regions' => 'region_labels',
    ];

    public function __construct(
        public ?string $displayName = null,
        public ?string $datasetDisplayName = null,
        public float $coverageKm = 50.0,
        public ?int $eodCutoffHour = null,
        public array $excludeResourceTypes = [],
        public array $resourceTypeNames = [],
        public array $attributeLabels = [],
        public array $regionPivot = ['red_below' => 1, 'amber_below' => 3],
        public array $regionLabels = [],
        public ?string $supervisorLabel = null,
        public ?string $supervisorLabelPlural = null,
        public ?string $regionLabel = null,
        public array $notes = [],
        public ?string $windowsTimeZone = null,
        public ?string $timeZone = null,
        public ?string $ifsTimeZone = null,
        public ?string $appointmentSlaTypeId = null,
        public ?string $defaultBookingSlaTypeId = null,
    ) {}

    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown dataset setting: '.implode(', ', $unknown));
        }

        $number = static function (string $key, mixed $value, float $default): float {
            if ($value === null || $value === '') {
                return $default;
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("{$key} must be a number.");
            }

            return (float) $value;
        };
        $intOrNull = static function (string $key, mixed $value): ?int {
            if ($value === null || $value === '') {
                return null;
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("{$key} must be a whole number.");
            }

            return (int) $value;
        };
        $map = static fn (mixed $value): array => is_array($value) ? array_map(static fn ($item) => is_scalar($item) ? (string) $item : $item, $value) : [];
        $list = static fn (mixed $value): array => is_array($value) ? array_values(array_filter(array_map('strval', $value), 'strlen')) : [];

        $timeZone = $values['time_zone'] ?? null;
        if ($timeZone !== null && $timeZone !== '' && ! in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("time_zone must be an IANA zone name, got {$timeZone}.");
        }

        $ifsTimeZone = $values['ifs_time_zone'] ?? null;
        if ($ifsTimeZone !== null && $ifsTimeZone !== '' && ! in_array($ifsTimeZone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("ifs_time_zone must be an IANA zone name, got {$ifsTimeZone}.");
        }

        $pivot = $values['region_pivot'] ?? ['red_below' => 1, 'amber_below' => 3];

        return new self(
            displayName: self::stringOrNull($values['display_name'] ?? null),
            datasetDisplayName: self::stringOrNull($values['dataset_display_name'] ?? null),
            coverageKm: $number('coverage_km', $values['coverage_km'] ?? null, 50.0),
            eodCutoffHour: $intOrNull('eod_cutoff_hour', $values['eod_cutoff_hour'] ?? null),
            excludeResourceTypes: $list($values['exclude_resource_types'] ?? []),
            resourceTypeNames: $map($values['resource_type_names'] ?? []),
            attributeLabels: $map($values['attribute_labels'] ?? []),
            regionPivot: ['red_below' => (int) ($pivot['red_below'] ?? 1), 'amber_below' => (int) ($pivot['amber_below'] ?? 3)],
            regionLabels: $map($values['region_labels'] ?? []),
            supervisorLabel: self::stringOrNull($values['supervisor_label'] ?? null),
            supervisorLabelPlural: self::stringOrNull($values['supervisor_label_plural'] ?? null),
            regionLabel: self::stringOrNull($values['region_label'] ?? null),
            notes: $map($values['notes'] ?? []),
            windowsTimeZone: self::stringOrNull($values['windows_time_zone'] ?? null),
            timeZone: self::stringOrNull($timeZone),
            ifsTimeZone: self::stringOrNull($ifsTimeZone),
            appointmentSlaTypeId: self::stringOrNull($values['appointment_sla_type_id'] ?? null),
            defaultBookingSlaTypeId: self::stringOrNull($values['default_booking_sla_type_id'] ?? null),
        );
    }

    /** Accepts the key names used by the pso-tools account files. */
    public static function fromAccountFile(array $json): self
    {
        $values = [];
        foreach ($json as $key => $value) {
            $values[self::ACCOUNT_FILE_ALIASES[$key] ?? $key] = $value;
        }

        return self::fromArray($values);
    }

    /**
     * These settings as the pso-tools scripts read them from accounts/<ACCOUNT>.json: their key names,
     * nulls and empty maps left out (an empty PHP array encodes as a JSON list, and the scripts call
     * .get() on these, so an empty one must be absent rather than []). fromAccountFile(toAccountFile())
     * gives the same settings back.
     *
     * @return array<string, mixed>
     */
    public function toAccountFile(): array
    {
        $out = [];
        foreach ($this->toArray() as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            $fileKey = array_search($key, self::ACCOUNT_FILE_ALIASES, true);
            $out[$fileKey === false ? $key : $fileKey] = $value;
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'display_name' => $this->displayName,
            'dataset_display_name' => $this->datasetDisplayName,
            'coverage_km' => $this->coverageKm,
            'eod_cutoff_hour' => $this->eodCutoffHour,
            'exclude_resource_types' => $this->excludeResourceTypes,
            'resource_type_names' => $this->resourceTypeNames,
            'attribute_labels' => $this->attributeLabels,
            'region_pivot' => $this->regionPivot,
            'region_labels' => $this->regionLabels,
            'supervisor_label' => $this->supervisorLabel,
            'supervisor_label_plural' => $this->supervisorLabelPlural,
            'region_label' => $this->regionLabel,
            'notes' => $this->notes,
            'windows_time_zone' => $this->windowsTimeZone,
            'time_zone' => $this->timeZone,
            'ifs_time_zone' => $this->ifsTimeZone,
            'appointment_sla_type_id' => $this->appointmentSlaTypeId,
            'default_booking_sla_type_id' => $this->defaultBookingSlaTypeId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
