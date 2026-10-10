<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use Filament\Support\Contracts\HasLabel;

enum Tool: string implements HasLabel
{
    case LoadAudit = 'load_audit';
    case ScheduleReview = 'schedule_review';
    case ScheduleRollup = 'schedule_rollup';
    case RegionAvailability = 'region_availability';
    case AppointmentTimeline = 'appointment_timeline';
    case PlanTravels = 'plan_travels';
    case SysCompare = 'sys_compare';

    public function scope(): string
    {
        return $this === self::SysCompare ? Environment::class : Dataset::class;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LoadAudit => 'Load and configuration health audit',
            self::ScheduleReview => 'Daily schedule review',
            self::ScheduleRollup => 'Schedule review roll-up',
            self::RegionAvailability => 'Region availability',
            self::AppointmentTimeline => 'Appointment timeline',
            self::PlanTravels => 'Plan travels',
            self::SysCompare => 'PSO sys file compare',
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $tool): bool => $tool->scope() === $scopeClass));
    }
}
