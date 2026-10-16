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

    /** Whether an export of this kind may be stored on $scopeClass: SYS on an environment, the rest on a dataset, any kind Unfiled (on a user). */
    public function allows(string $scopeClass): bool
    {
        return match ($scopeClass) {
            User::class => true,
            Environment::class => $this === self::Sys,
            Dataset::class => $this !== self::Sys,
            default => false,
        };
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return match ($this) {
            self::Audits => ['zip'],
            self::EventLog => ['xlsx', 'csv'],
            default => ['xml', 'json', 'zip'],
        };
    }

    /** Root element the summariser expects once a zip is opened. */
    public function expectedRoot(): ?string
    {
        return match ($this) {
            self::Sys => 'DsSystemData',
            self::Audits, self::EventLog => null,
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
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->allows($scopeClass)));
    }
}
