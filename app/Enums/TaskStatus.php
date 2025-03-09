<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskStatus: int implements HasLabel
{

    case DONOTSCHEDULE = -1;
    case SCHEDULABLE = 0;
    case ALLOCATED = 10;
    case COMMITTED = 30;
    case COMPLETED = 70;

    public function getLabel(): string|null
    {

        return match ($this) {
            self::DONOTSCHEDULE => 'Do Not Schedule',
            self::SCHEDULABLE => 'Schedulable',
            self::ALLOCATED => 'Allocated',
            self::COMMITTED => 'Committed',
            self::COMPLETED => 'Completed',
        };

    }
}
