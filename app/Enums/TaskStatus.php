<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskStatus: int implements HasLabel
{

    case IGNORE = -1;
    case UNALLOCATED = 0;
    case ALLOCATED = 10;
    case FOLLOW_ON = 20;
    case COMMITTED = 30;
    case SENT = 32;
    case DOWNLOADED = 35;
    case ACCEPTED = 40;
    case TRAVELLING = 50;
    case WAITING = 55;
    case ON_SITE = 60;
    case PENDING_COMPLETION = 65;
    case VISIT_COMPLETE = 68;
    case COMPLETED = 70;
    case INCOMPLETE = 80;

    public function getLabel(): string|null
    {

        return match ($this) {

            self::IGNORE => 'Ignore',
            self::UNALLOCATED => 'Unallocated',
            self::ALLOCATED => 'Allocated',
            self::FOLLOW_ON => 'Follow On',
            self::COMMITTED => 'Committed',
            self::SENT => 'Sent',
            self::DOWNLOADED => 'Downloaded',
            self::ACCEPTED => 'Accepted',
            self::TRAVELLING => 'Travelling',
            self::WAITING => 'Waiting',
            self::ON_SITE => 'On Site',
            self::PENDING_COMPLETION => 'Pending Completion',
            self::VISIT_COMPLETE => 'Visit Complete',
            self::COMPLETED => 'Completed',
            self::INCOMPLETE => 'Incomplete',
        };

    }

    public static function endStateStatuses(): array
    {
        return array_filter(self::cases(), static fn (self $status) => $status->value >= 65);
    }

    public function ishServicesValue(): string|null
    {

        return match ($this) {

            self::IGNORE => 'ignore',
            self::UNALLOCATED => 'unallocated',
            self::ALLOCATED => 'allocated',
            self::FOLLOW_ON => 'followon',
            self::COMMITTED => 'committed',
            self::SENT => 'sent',
            self::DOWNLOADED => 'downloaded',
            self::ACCEPTED => 'accepted',
            self::TRAVELLING => 'travelling',
            self::WAITING => 'waiting',
            self::ON_SITE => 'onsite',
            self::PENDING_COMPLETION => 'pendingcompletion',
            self::VISIT_COMPLETE => 'visitcomplete',
            self::COMPLETED => 'completed',
            self::INCOMPLETE => 'incomplete',
        };

    }
}
