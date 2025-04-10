<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PSOEntities: string implements HasLabel
{
    case RESOURCE = 'Resource';
    case ACTIVITY = 'Activity';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::RESOURCE => 'Resource',
            self::ACTIVITY => 'Activity',

        };
    }

    public static function eventEntities(): array
    {
        return [
            self::RESOURCE->value,
            self::ACTIVITY->value,
        ];
    }
}
