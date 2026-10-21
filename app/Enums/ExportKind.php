<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;

enum ExportKind: string implements HasLabel
{
    case Load = 'load';
    case Plan = 'plan';
    case Eod = 'eod';
    case Audits = 'audits';
    case Sys = 'sys';
    case EventLog = 'event_log';
    case PsoEvents = 'pso_events';
    case AppointmentHistory = 'appointment_history';
    case MessageTexts = 'message_texts';

    /** Whether an export of this kind may be stored on $scopeClass: SYS on an environment, the rest on a dataset, any kind Unfiled (on a user). */
    public function allows(string $scopeClass): bool
    {
        return match ($scopeClass) {
            User::class => true,
            Environment::class => $this === self::Sys,
            Dataset::class => ! in_array($this, [self::Sys, self::MessageTexts], true),
            default => false,
        };
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return match ($this) {
            self::Audits => ['zip'],
            self::EventLog => ['xlsx', 'csv'],
            self::PsoEvents => ['json', 'zip'],
            self::AppointmentHistory => ['csv', 'zip'],
            self::MessageTexts => ['xml', 'zip'],
            default => ['xml', 'json', 'zip'],
        };
    }

    /** Root element the summariser expects once a zip is opened. */
    public function expectedRoot(): ?string
    {
        return match ($this) {
            self::Sys, self::MessageTexts => 'DsSystemData',
            self::Audits, self::EventLog, self::PsoEvents, self::AppointmentHistory => null,
            default => 'dsScheduleData',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Load => 'LOAD',
            self::Plan => 'Plan',
            self::Eod => 'End of day',
            self::Audits => 'Audit messages',
            self::Sys => 'SYS',
            self::EventLog => 'IFS event log',
            self::PsoEvents => 'PSO system events',
            self::AppointmentHistory => 'Appointment history',
            self::MessageTexts => 'Message texts',
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->allows($scopeClass)));
    }
}
