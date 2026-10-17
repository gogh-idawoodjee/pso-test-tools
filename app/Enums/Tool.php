<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum Tool: string implements HasLabel
{
    case LoadAudit = 'load_audit';
    case ScheduleReview = 'schedule_review';
    case ScheduleRollup = 'schedule_rollup';
    case RegionAvailability = 'region_availability';
    case AppointmentTimeline = 'appointment_timeline';
    case PlanTravels = 'plan_travels';
    case SysCompare = 'sys_compare';
    case TrimExport = 'trim_export';

    public function scope(): string
    {
        return $this === self::SysCompare ? Environment::class : Dataset::class;
    }

    /** Tools that only read the files: they may run Unfiled, with no environment or dataset. */
    public function acceptsUnfiled(): bool
    {
        return in_array($this, [self::LoadAudit, self::AppointmentTimeline, self::PlanTravels, self::TrimExport], true);
    }

    /** The question a consultant has in mind when they reach for this tool; shown in the catalogue and the wizard. */
    public function question(): string
    {
        return match ($this) {
            self::LoadAudit => 'What is wrong with the data and configuration in this LOAD?',
            self::ScheduleReview => 'How did today\'s schedule come out, and what needs attention?',
            self::ScheduleRollup => 'How has the schedule trended over the last weeks?',
            self::RegionAvailability => 'Visualize Resource Regions',
            self::AppointmentTimeline => 'What happened to one activity: how it was booked and how PSO scheduled it?',
            self::PlanTravels => 'How long are the planned travels, and do they match real road times?',
            self::SysCompare => 'What differs between these SYS exports and the baseline?',
            self::TrimExport => 'Can I cut this export down to the tables and rows I need?',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LoadAudit => 'Load Health Audit',
            self::ScheduleReview => 'Daily schedule review',
            self::ScheduleRollup => 'Schedule review roll-up',
            self::RegionAvailability => 'Region availability',
            self::AppointmentTimeline => 'Trace an activity',
            self::PlanTravels => 'Plan travels',
            self::SysCompare => 'PSO sys file compare',
            self::TrimExport => 'Trim export',
        };
    }

    /** Shown on the home page tile and in the sidebar. */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::LoadAudit => Heroicon::OutlinedClipboardDocumentCheck,
            self::RegionAvailability => Heroicon::OutlinedMap,
            self::SysCompare => Heroicon::OutlinedArrowsRightLeft,
            self::AppointmentTimeline => Heroicon::OutlinedCalendarDays,
            self::PlanTravels => Heroicon::OutlinedTruck,
            self::ScheduleReview => Heroicon::OutlinedChartBar,
            self::ScheduleRollup => Heroicon::OutlinedPresentationChartLine,
            self::TrimExport => Heroicon::OutlinedScissors,
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $tool): bool => $tool->scope() === $scopeClass));
    }
}
