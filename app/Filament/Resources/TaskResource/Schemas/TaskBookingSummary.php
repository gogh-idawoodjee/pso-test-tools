<?php

namespace App\Filament\Resources\TaskResource\Schemas;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\Booking\AgendaPresenter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** The task header on the booking page: duration, base value, status and the booked window. */
final class TaskBookingSummary
{
    /** The task header on the booking page: duration, base value, status, the booked window and its SLA type and request. */
    public static function schema(Schema $schema, Task $task, string $timeZone): Schema
    {
        $appointmentTime = self::window($task->appt_window_start, $task->appt_window_finish, $timeZone);
        $formattedDuration = AgendaPresenter::formatDuration((int) $task->duration);
        $isUrgent = self::urgent($task->appt_window_start);

        return $schema
            ->record($task)
            ->columns(1)
            ->schema([
                Section::make('Task '.data_get($task, 'friendly_id').' - '.data_get($task, 'taskType.label'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->schema([
                        Grid::make()
                            ->schema([
                                TextEntry::make('duration')
                                    ->label('Duration')
                                    ->state($formattedDuration)
                                    ->icon(Heroicon::OutlinedClock)
                                    ->size('md'),
                                TextEntry::make('base_value')
                                    ->label('Base value')
                                    ->weight('bold')
                                    ->color('primary')
                                    ->formatStateUsing(static function ($state) {
                                        return number_format((float) $state);
                                    }),

                                TextEntry::make('status')
                                    ->label('Status')
                                    ->formatStateUsing(static fn (TaskStatus|int|null $state) => ($state instanceof TaskStatus ? $state : TaskStatus::tryFrom((int) $state))?->getLabel())
                                    ->badge()
                                    ->color(static fn (TaskStatus|int|null $state) => match ($state instanceof TaskStatus ? $state : TaskStatus::tryFrom((int) $state)) {
                                        TaskStatus::IGNORE => 'gray',
                                        TaskStatus::UNALLOCATED, TaskStatus::ALLOCATED => 'warning',
                                        TaskStatus::FOLLOW_ON, TaskStatus::COMMITTED, TaskStatus::SENT, TaskStatus::DOWNLOADED => 'info',
                                        TaskStatus::ACCEPTED, TaskStatus::TRAVELLING, TaskStatus::WAITING, TaskStatus::ON_SITE => 'primary',
                                        TaskStatus::PENDING_COMPLETION, TaskStatus::VISIT_COMPLETE => 'secondary',
                                        TaskStatus::COMPLETED => 'success',
                                        TaskStatus::INCOMPLETE => 'danger',
                                        default => null,
                                    })
                                    ->size('md'),

                            ])
                            ->columns(3),

                        Section::make()
                            ->schema([
                                TextEntry::make('appointment')
                                    ->label($task->isBooked() ? 'Booked window' : 'Booking')
                                    ->state($appointmentTime ?? 'Not booked')
                                    ->color($appointmentTime === null ? 'gray' : ($isUrgent ? 'danger' : 'primary'))
                                    ->icon(Heroicon::OutlinedCalendar)
                                    ->iconColor($isUrgent ? 'danger' : 'primary')
                                    ->size('lg'),
                                TextEntry::make('booking_sla_type_id')
                                    ->label('Booking SLA type')
                                    ->visible($task->isBooked())
                                    ->placeholder('Not recorded'),
                                TextEntry::make('appointment_request_id')
                                    ->label('Appointment request')
                                    ->visible($task->isBooked()),
                            ]),

                    ]),
            ]);
    }

    /** The window in the booking zone, e.g. "Thursday October 22nd 08:00-12:00"; null without both ends. */
    public static function window(Carbon|CarbonImmutable|string|null $start, Carbon|CarbonImmutable|string|null $end, string $timeZone): ?string
    {
        if (blank($start) || blank($end)) {
            return null;
        }

        $from = Carbon::parse($start)->setTimezone($timeZone);
        $to = Carbon::parse($end)->setTimezone($timeZone);

        return $from->format('l F jS').' '.$from->format('H:i').'-'.$to->format('H:i');
    }

    /** Under two hours away. */
    public static function urgent(Carbon|CarbonImmutable|string|null $start, ?CarbonImmutable $now = null): bool
    {
        if (blank($start)) {
            return false;
        }

        $minutes = ($now ?? CarbonImmutable::now())->diffInMinutes(Carbon::parse($start));

        return $minutes > 0 && $minutes < 120;
    }
}
