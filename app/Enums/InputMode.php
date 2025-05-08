<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InputMode: string implements HasLabel
{
    case LOAD = 'LOAD';
    case CHANGE = 'CHANGE';

    public function getLabel(): string|null
    {
        return match ($this) {
            self::LOAD => 'Load',
            self::CHANGE => 'Change'

        };

    }

    public function getSegment(): string|null
    {
        return match ($this) {
            self::LOAD => 'load',
            self::CHANGE => 'rota',
        };
    }

}
