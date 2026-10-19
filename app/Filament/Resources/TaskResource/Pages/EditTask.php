<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bookAppointment')
                ->label(fn (Task $record): string => $record->isBooked() ? 'Rebook' : 'Appointment booking')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(fn (Task $record): string => TaskResource::getUrl('bookAppointment', ['record' => $record])),
            Actions\DeleteAction::make(),
        ];
    }
}
