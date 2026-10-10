<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReferenceValueOrigin: string implements HasColor, HasLabel
{
    case Pso = 'pso';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pso => 'PSO',
            self::Manual => 'Manual',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pso => 'info',
            self::Manual => 'gray',
        };
    }
}
