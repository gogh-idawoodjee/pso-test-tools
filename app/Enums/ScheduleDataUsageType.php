<?php

namespace App\Enums;

enum ScheduleDataUsageType: int
{
    case RESOURCE_COUNT = 0;
    case ACTIVITY_COUNT = 1;
    case SCHEDULE_WINDOW_LENGTH = 2;
    case APPOINTMENT_BOOKING_WINDOW_LENGTH = 3;
    case DATASET_COUNT = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::RESOURCE_COUNT => 'Resource Count',
            self::ACTIVITY_COUNT => 'Activity Count',
            self::SCHEDULE_WINDOW_LENGTH => 'Schedule Window Length',
            self::APPOINTMENT_BOOKING_WINDOW_LENGTH => 'Appointment Booking Window Length',
            self::DATASET_COUNT => 'Dataset Count',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::RESOURCE_COUNT => 'The total number of resources across all data sets.',
            self::ACTIVITY_COUNT => 'The total number of activities across all data sets.',
            self::SCHEDULE_WINDOW_LENGTH => 'The schedule window length in days.',
            self::APPOINTMENT_BOOKING_WINDOW_LENGTH => 'The appointment booking window length in days.',
            self::DATASET_COUNT => 'The number of datasets owned by the organisation.',
        };
    }

    public function getUnit(): ?string
    {
        return match ($this) {
            self::SCHEDULE_WINDOW_LENGTH, self::APPOINTMENT_BOOKING_WINDOW_LENGTH => 'days',
            default => null,
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::RESOURCE_COUNT => 'heroicon-o-user-group',
            self::ACTIVITY_COUNT => 'heroicon-o-bolt',
            self::SCHEDULE_WINDOW_LENGTH => 'heroicon-o-calendar-days',
            self::APPOINTMENT_BOOKING_WINDOW_LENGTH => 'heroicon-o-clock',
            self::DATASET_COUNT => 'heroicon-o-circle-stack',
        };
    }
}
