<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProcessType: string implements HasLabel
{
    case DYNAMIC = 'dynamic';
    case APPOINTMENT = 'appointment';
    case REACTIVE = 'reactive';
    case STATIC = 'static';

    public function getLabel(): string|null
    {

        return match ($this) {
            self::DYNAMIC => 'Dynamic',
            self::APPOINTMENT => 'Appointment',
            self::REACTIVE => 'Reactive',
            self::STATIC => 'Static',

        };

    }


}
