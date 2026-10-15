<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use Filament\Support\Contracts\HasLabel;

enum ExportKind: string implements HasLabel
{
    case Load = 'load';
    case Plan = 'plan';
    case Eod = 'eod';
    case Audits = 'audits';
    case Sys = 'sys';
    case EventLog = 'event_log';

    /** The model class an export of this kind hangs off. */
    public function allowedScope(): string
    {
        return $this === self::Sys ? Environment::class : Dataset::class;
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
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->allowedScope() === $scopeClass));
    }
}
