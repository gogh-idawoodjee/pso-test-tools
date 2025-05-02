<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use App\Models\AppointmentTemplate;
use App\Models\Environment;
use App\Models\SlotUsageRule;
use App\Traits\FormTrait;
use App\Traits\PSOInteractionsTrait;
use Carbon\Carbon;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Support\Enums\VerticalAlignment;
use Override;


class AppointmentBooking extends Page
{
    use InteractsWithRecord, FormTrait, PSOInteractionsTrait;

    protected static string $resource = TaskResource::class;

    protected static string $view = 'filament.resources.tasks.pages.appointment-booking';

    public ?array $task_data = [];


    /**
     * Format a time window between two dates
     *
     * @param Carbon|string|null $startDate
     * @param Carbon|string|null $endDate
     * @return string
     */
    protected function formatAppointmentWindow($startDate, $endDate): string
    {
        // Parse dates if they're not already Carbon instances
        $start = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
        $end = $endDate instanceof Carbon ? $endDate : Carbon::parse($endDate);

        // Format the date and time
        $formattedDate = $start->format('l F jS');
        $formattedTime = $start->format('H:i') . '-' . $end->format('H:i');

        return $formattedDate . ' ' . $formattedTime;
    }

    /**
     * Format a duration in minutes to a human-readable string
     *
     * @param int $durationInMinutes
     * @return string
     */
    protected function formatDuration(int $durationInMinutes): string
    {
        $hours = floor($durationInMinutes / 60);
        $minutes = $durationInMinutes % 60;

        $formattedDuration = $hours . ' hour' . ($hours != 1 ? 's' : '');

        if ($minutes > 0) {
            $formattedDuration .= ' ' . $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }

        return $formattedDuration;
    }

    /**
     * Check if an appointment is urgent (less than 2 hours away)
     *
     * @param string|Carbon|null $appointmentStart
     * @return bool
     */
    protected function isAppointmentUrgent(Carbon|string|null $appointmentStart): bool
    {
        $start = $appointmentStart instanceof Carbon ? $appointmentStart : Carbon::parse($appointmentStart);
        $now = Carbon::now();
        $timeUntilStart = $now->diffInMinutes($start);

        return $timeUntilStart > 0 && $timeUntilStart < 120; // Less than 2 hours
    }

    /**
     * Build the infolist for task details
     *
     * @param Infolist $infolist
     * @return Infolist
     */
    public function infolist(Infolist $infolist): Infolist
    {
        // Get appointment window data
        $appointmentTime = $this->formatAppointmentWindow(
            $this->record->appt_window_start,
            $this->record->appt_window_finish
        );

        // Get duration data
        $formattedDuration = $this->formatDuration($this->record->duration);

        // Check urgency
        $isUrgent = $this->isAppointmentUrgent($this->record->appt_window_start);

        return $infolist
            ->record($this->record)
            ->columns(1) // Single column for the main container
            ->schema([
                \Filament\Infolists\Components\Section::make('Task ' . $this->record->friendly_id . ' - ' . $this->record->taskType->name)
                    ->icon('heroicon-o-clipboard-document-list')
                    ->collapsible()
                    ->schema([
                        // Activity ID and Status row
                        Grid::make()
                            ->schema([
                                Group::make()
                                    ->schema([
                                        TextEntry::make('duration_label')
                                            ->state('Duration')
                                            ->hiddenLabel()
                                            ->weight('bold'),
                                        TextEntry::make('duration')
                                            ->label(false)
                                            ->state($formattedDuration)
                                            ->icon('heroicon-o-clock')
                                            ->size('md'),
                                    ]),
                                Group::make()
                                    ->schema([
                                        TextEntry::make('base_value_label')
                                            ->state('Base Value')
                                            ->hiddenLabel()
                                            ->weight('bold'),
                                        TextEntry::make('base_value')
                                            ->label(false)
                                            ->weight('bold')
                                            ->color('primary')
                                            ->formatStateUsing(static function ($state) {
                                                return number_format((float)$state);
                                            }),
                                    ]),

                                // Right column - Status
                                Group::make()
                                    ->schema([
                                        TextEntry::make('status_label')
                                            ->state('Status')
                                            ->hiddenLabel()
                                            ->weight('bold'),
                                        TextEntry::make('status')
                                            ->label(false)
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
                                            })
                                            ->size('md'),
                                    ]),


                            ])
                            ->columns(3),


                        \Filament\Infolists\Components\Section::make()
                            ->schema([
                                TextEntry::make('appointment')
                                    ->label('Appointment Window')
                                    ->state($appointmentTime)
                                    ->formatStateUsing(static function ($state) use ($isUrgent) {
                                        $colorClass = $isUrgent ? 'text-red-600' : 'text-purple-600';
                                        return '<span class="' . $colorClass . '">' . $state . '</span>';
                                    })
                                    ->icon('heroicon-o-calendar')
                                    ->iconColor($isUrgent ? 'danger' : 'primary')
                                    ->size('lg')
                                    ->html(),


                            ])

//                        // Base Value, Task Type, and Location
//                        Grid::make()
//                            ->schema([
//
//
//                                TextEntry::make('location')
//                                    ->label('Location')
//                                    ->state(function () {
//                                        return $this->record->address ?? 'No address provided';
//                                    })
//                                    ->icon('heroicon-o-map-pin')
//                                    ->columnSpanFull(),
//                            ])
//                            ->columns(),
                    ]),
            ]);
    }


    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->environments = Environment::with('datasets')->get();
        $this->isAuthenticationRequired = true;

        // Initialize task_data
        $this->task_data = $this->record->toArray();


        // Call form fill to populate the forms
        $this->fillForms($this->getForms());


        $this->setTaskData();


    }

    public function setTaskData(): void
    {
        // maybe we can do this after in the payload instead of adding it to record
        // some of them we should just display though.
//        $this->record->lat = $this->record->customer->lat;
//        $this->record->long = $this->record->customer->long;
//        $this->record->activityId = $this->record->friendly_id;
//        $this->record->activityTypeId = $this->record->taskType?->name;
//        $this->record->priority = $this->record->taskType?->priority;
//        $this->record->slaTypeId = 'Appointment';
//        $this->record->appointmentTemplateDuration = 21;
        $this->record->sla_start = now()->toIso8601String();
//        $this->record->appointmentBaseDate = now()->startOfDay()->toIso8601String();
//        $this->task_data['sla_start'] = now();
    }

    #[Override] protected function getForms(): array
    {
        return ['env_form', 'taskForm'];
    }

    public function taskForm(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Section::make('Appointment Booking')->schema([
                        Select::make('appointmentTemplateId')
                            ->label('Appointment Template')
                            ->options(fn() => AppointmentTemplate::query()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->toArray()
                            )
                            ->searchable()
                            ->createOptionForm(AppointmentTemplate::getForm())
                            ->createOptionUsing(static function (Get $get, array $data): string {
                                return AppointmentTemplate::create($data)->id;
                            })
                            ->createOptionModalHeading('Create Template')
                            ->required(),

                        Select::make('slot_usage_rule_id')
                            ->label('Slot Usage Rule')
                            ->options(fn() => SlotUsageRule::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray()
                            )
                            ->searchable()
                            ->createOptionForm(SlotUsageRule::getForm())
                            ->createOptionUsing(static function (Get $get, array $data): string {
                                return SlotUsageRule::create($data)->id;
                            })
                            ->createOptionModalHeading('Create new Slot Usage Rule'),

                        DateTimePicker::make('sla_start')
                            ->label('SLA Start')
                            ->formatStateUsing(static function ($state) {
                                return Carbon::now()->toiso8601String();
                            })
                            ->closeOnDateSelection()
                            ->seconds(false)
                            ->native(false)
                            ->required()
                            ->live(),


                        Hidden::make('slaEnd')
                            ->dehydrated()
                            ->afterStateHydrated(fn(callable $set, $state) => $set('slaEnd', Carbon::parse($state)->addDays(21)->toIso8601String()))
                            ->visible(false),


                        Actions::make([Actions\Action::make('get_appointments')
                            ->label('Get Appointments')
                            ->icon('heroicon-o-calendar')
                            ->action(function () {
                                $this->getAppointments();
                            })
                        ])->verticalAlignment(VerticalAlignment::End),
                    ])->columns(),

                ])->statePath('task_data');


    }

    private function getAppointments()
    {
        dd($this->task_data);
    }


}
