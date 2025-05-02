<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\TaskStatus;
use App\Filament\Resources\EnvironmentResource;
use App\Filament\Resources\TaskResource;
use App\Models\Environment;
use App\Models\Task;
use App\Models\AppointmentTemplate;
use App\Models\SlotUsageRule;

use App\Traits\FormTrait;


use Filament\Forms\Components\Section;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Table;

use Override;

class TasksRelationManager extends RelationManager
{
    use FormTrait;

    protected static string $relationship = 'tasks';

    #[Override]
    public function isReadOnly(): bool
    {
        parent::isReadOnly();
        return false;
    }

    #[Override]
    public function form(Form $form): Form
    {
        return $form->schema(Task::getForm());
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('friendly_id')
            ->columns([
                Tables\Columns\TextColumn::make('friendly_id')
                    ->label('Task ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('taskType.name')
                    ->label('Task Type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn(int $state) => TaskStatus::tryFrom($state)?->getLabel())
                    ->badge()
                    ->color(static fn(int $state) => match (TaskStatus::tryFrom($state)) {
                        TaskStatus::IGNORE => 'gray',
                        TaskStatus::UNALLOCATED, TaskStatus::ALLOCATED => 'warning',
                        TaskStatus::FOLLOW_ON, TaskStatus::COMMITTED, TaskStatus::SENT, TaskStatus::DOWNLOADED => 'info',
                        TaskStatus::ACCEPTED, TaskStatus::TRAVELLING, TaskStatus::WAITING, TaskStatus::ON_SITE => 'primary',
                        TaskStatus::PENDING_COMPLETION, TaskStatus::VISIT_COMPLETE => 'secondary',
                        TaskStatus::COMPLETED => 'success',
                        TaskStatus::INCOMPLETE => 'danger',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('appt_window_start')
                    ->label('Start')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('appt_window_finish')
                    ->label('Finish')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration')
                    ->suffix(' min')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->slideOver()
                    ->label('Create Task'),
            ])
            ->actions([
                ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\Action::make('bookOrRebook')
                        ->label(static fn(Task $record) => $record->appt_window_start ? 'Rebook' : 'Book')
                        ->icon('heroicon-o-calendar')
                        ->color(static fn(Task $record) => $record->appt_window_start ? 'warning' : 'primary')
                        ->slideOver()->modalWidth('6xl')
                        ->visible(static fn(Task $record) => !in_array(
                            TaskStatus::tryFrom($record->status),
                            TaskStatus::endStateStatuses(),
                            true
                        ))
                        ->url(function (Task $record) {
                            return TaskResource::getUrl('bookAppointment', compact('record'));
                        }),

                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Build the SlideOver form for booking or rebooking an appointment.
     */
    protected function buildBookForm(Task $record): array
    {
        if (!$this->environments) {
            $this->environments = Environment::with('datasets')->get();
        }
        $this->isAuthenticationRequired = true;


        $priority = $record->priority ?? $record->taskType?->priority;
        $baseValue = $record->base_value ?? $record->taskType?->base_value;

        return array_merge(
            $this->getEnvFormSchema(),
            [
                Section::make('Appointment Search')
                    ->statePath('data')
                    ->schema($this->getAppointmentSearchFields($record, $baseValue, $priority))
                    ->columns(),
            ]
        );
    }

    /**
     * Returns the form fields for the appointment search section.
     *
     * @param Task $record
     * @param int $baseValue
     * @param int $priority
     * @return array
     */
//    protected function getAppointmentSearchFields(Task $record, int $baseValue, int $priority): array
//    {
//        return [
//            Select::make('appointmentTemplateId')
//                ->label('Appointment Template')
//                ->options(fn() => AppointmentTemplate::query()
//                    ->orderBy('name')
//                    ->pluck('name', 'name')
//                    ->toArray()
//                )
//                ->searchable()
//                ->createOptionForm(AppointmentTemplate::getForm())
//                ->createOptionUsing(static function (Get $get, array $data): string {
//                    return AppointmentTemplate::create($data)->id;
//                })
//                ->createOptionModalHeading('Create Template')
//                ->required(),
//
//            Select::make('slotUsageRuleId')
//                ->label('Slot Usage Rule')
//                ->options(fn() => SlotUsageRule::query()
//                    ->orderBy('name')
//                    ->pluck('name', 'id')
//                    ->toArray()
//                )
//                ->searchable()
//                ->createOptionForm(SlotUsageRule::getForm())
//                ->createOptionUsing(static function (Get $get, array $data): string {
//                    return SlotUsageRule::create($data)->id;
//                })
//                ->createOptionModalHeading('Create New Slot Usage Rule'),
//
//            TextInput::make('baseValue')
//                ->label('Base Value')
//                ->numeric()
//                ->default($baseValue)
//                ->required(),
//
//            TextInput::make('priority')
//                ->label('Priority')
//                ->numeric()
//                ->default($priority)
//                ->required(),
//
//            DateTimePicker::make('slaStart')
//                ->label('SLA Start')
//                ->default(now())
//                ->required()
//                ->live()
//                ->afterStateUpdated(fn(callable $set, $state) => $state && $set('slaEnd', Carbon::parse($state)->addDays(21)->toIso8601String())),
//
//            Hidden::make('slaEnd')
//                ->dehydrated()
//                ->afterStateHydrated(fn(callable $set, $state) => $set('slaEnd', Carbon::parse($state)->addDays(21)->toIso8601String()))
//                ->visible(false),
//
//            Hidden::make('activityId')
//                ->default(fn() => $record->getKey()),
//            Hidden::make('taskTypeId')
//                ->default(fn() => $record->taskType?->name),
//            Hidden::make('duration')
//                ->default(fn() => $record->getAttribute('duration')),
//            Hidden::make('lat')
//                ->default(fn() => $record->customer?->lat),
//            Hidden::make('long')
//                ->default(fn() => $record->customer?->long),
//            Hidden::make('slaTypeId')
//                ->default(static fn() => 'Appointment'),
//            Hidden::make('appointmentTemplateDuration')
//                ->default(static fn() => 21),
//            Hidden::make('appointmentTemplateDatetime')
//                ->default(fn() => now()->toIso8601String()),
//            Hidden::make('appointmentBaseDate')
//                ->default(fn() => now()->startOfDay()->toIso8601String()),
//
//            Actions::make([
//                Action::make('getAppointments')
//                    ->label('Get Appointments')
//                    ->action('bookAppointment')
//                    ->color('primary'),
//            ]),
//        ];
//    }


}
