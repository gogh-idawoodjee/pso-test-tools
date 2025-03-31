<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventType: string implements HasLabel
{

    case ATTENTIONON = 'AO';
    case ATTENTIONOFF = 'AF';
    case BREAKON = 'BO';
    case BREAKOFF = 'BF';
    case CE = 'CE';
    case GPSFIX = 'FIX';
    case LOGON = 'RO';
    case LOGOFF = 'RF';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ATTENTIONON => 'Attention On',
            self::ATTENTIONOFF => 'Attention Off',
            self::BREAKON => 'Break On',
            self::BREAKOFF => 'Break Off',
            self::CE => 'CE Mode',
            self::GPSFIX => 'GPS Fixed',
            self::LOGON => 'Logged On',
            self::LOGOFF => 'Logged Off',
        };
    }
}
