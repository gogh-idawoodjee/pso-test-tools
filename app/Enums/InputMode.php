<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InputMode: string implements HasLabel
{
    case LOAD = 'load';
    case CHANGE = 'change';


    public function getLabel(): string|null
    {

        return match ($this) {
            self::LOAD => 'Load',
            self::CHANGE => 'Change',
        };

    }


}
