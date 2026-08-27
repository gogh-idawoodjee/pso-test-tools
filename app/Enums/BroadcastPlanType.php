<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BroadcastPlanType: string implements HasLabel
{
    case CHANGE = 'CHANGE';
    case COMPLETE = 'COMPLETE';
    case INTERNAL = 'INTERNAL';
    case ADMIN = 'ADMIN';
    case WORKBENCH = 'WORKBENCH';

    public function getLabel(): string|null
    {
        return match ($this) {
            self::CHANGE => 'Change',
            self::COMPLETE => 'Complete',
            self::INTERNAL => 'Internal',
            self::ADMIN => 'Admin',
            self::WORKBENCH => 'Workbench',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CHANGE => 'Only changes in the last plan.',
            self::COMPLETE => 'Whole plan sent.',
            self::INTERNAL => 'Used to inform the DSE of when to write plans internally, where no external plans are required.',
            self::ADMIN => 'Broadcasts for internal administrative purposes. Only used by the Scheduling Administration Service.',
            self::WORKBENCH => 'Broadcasts manual change requests to a third party rather than sending them to the Schedule Input Manager.',
        };
    }
}
