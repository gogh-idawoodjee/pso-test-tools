<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The kinds of PSO identifier a request can reference, stored per dataset.
 * Slot usage rules are mirrored at the Slot_Usage_Rule_Set level: that is the
 * id Appointment_Request carries, and Slot_Usage_Rule rows have no id of their own.
 *
 * in dataset_reference_values. Adding a kind is a new case here plus an
 * entry in App\Classes\PSOObjectRegistry; no migration.
 */
enum ReferenceEntityType: string implements HasLabel
{
    case Region = 'region';
    case Skill = 'skill';
    case TaskType = 'task_type';
    case SlotUsageRule = 'slot_usage_rule';
    case AppointmentTemplate = 'appointment_template';
    case SlaType = 'sla_type';

    /** The dsScheduleData table this type is read from. */
    public function psoEntity(): string
    {
        return match ($this) {
            self::Region => 'Region',
            self::Skill => 'Skill',
            self::TaskType => 'Activity_Type',
            self::SlotUsageRule => 'Slot_Usage_Rule_Set',
            self::AppointmentTemplate => 'Appointment_Template',
            self::SlaType => 'SLA_Type',
        };
    }

    public static function fromPsoEntity(string $entity): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->psoEntity() === $entity) {
                return $type;
            }
        }

        return null;
    }

    /** Key of this type's entry in App\Classes\PSOObjectRegistry. */
    public function registryKey(): string
    {
        return match ($this) {
            self::TaskType => 'activity_type',
            default => $this->value,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Region => 'Region',
            self::Skill => 'Skill',
            self::TaskType => 'Task Type',
            self::SlotUsageRule => 'Slot Usage Rule Set',
            self::AppointmentTemplate => 'Appointment Template',
            self::SlaType => 'SLA Type',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::SlaType => 'SLA Types',
            default => $this->getLabel().'s',
        };
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->value).'s';
    }

    /** JSON attribute keys PSO supplies for this type; a sync overwrites them. */
    public function attributeKeys(): array
    {
        return match ($this) {
            self::TaskType => ['priority', 'base_value', 'base_duration'],
            self::AppointmentTemplate => ['blocking'],
            default => [],
        };
    }

    /**
     * JSON attribute keys that never come from PSO: a sync keeps them as they
     * are, and editing one does not mark the row locally modified.
     */
    public function localAttributeKeys(): array
    {
        return match ($this) {
            self::TaskType => ['booking_sla_type_id'],
            default => [],
        };
    }

    public function navigationIcon(): Heroicon
    {
        return match ($this) {
            self::Region => Heroicon::OutlinedMap,
            self::Skill => Heroicon::OutlinedWrenchScrewdriver,
            self::TaskType => Heroicon::OutlinedTag,
            self::SlotUsageRule => Heroicon::OutlinedClock,
            self::AppointmentTemplate => Heroicon::OutlinedCalendarDays,
            self::SlaType => Heroicon::OutlinedShieldCheck,
        };
    }
}
