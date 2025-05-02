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
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
     * @param string|Carbon|null $startDate
     * @param string|Carbon|null $endDate
     * @return string
     */
    protected function formatAppointmentWindow(Carbon|string|null $startDate, Carbon|string|null $endDate): string
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
                \Filament\Infolists\Components\Section::make('Task ' . data_get($this->record, 'friendly_id') . ' - ' . data_get($this->record, 'taskType.name'))
                    ->icon('heroicon-o-clipboard-document-list')

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
        $this->task_data['sla_start'] = now()->format('Y-m-d\TH:i');

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
                                ->pluck('name', 'id')
                                ->toArray()
                            )
                            ->searchable()
                            ->createOptionForm(AppointmentTemplate::getForm())
                            ->createOptionUsing(static function (Get $get, array $data): string {
                                return AppointmentTemplate::create($data)->id;
                            })
                            ->createOptionModalHeading('Create Template')
                            ->required(),

                        Select::make('slotUsageRuleSetId')
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
                            ->label('Search for Appointments from:')
                            ->closeOnDateSelection()
                            ->seconds(false)
                            ->suffixAction(
                                Actions\Action::make('set_sla_start')
                                    ->icon('heroicon-m-clock')
                                    ->action(function (Get $get, Set $set) {
                                        $this->setSlaStart($set);

                                    }))
                            ->hint('Reset to current date/time')
                            ->required()
                            ->live()->columnSpan(2),


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

        $this->validateForms($this->getForms());
        $data = $this->setDataPayload();

        $environment = $this->environnment_payload_data();
        dd($environment, $data);

    }

    private function setSlaStart(Set $set)
    {
        $set('sla_start', now()->format('Y-m-d\TH:i'));

    }

    private function setDataPayload(): array
    {
        return [
            'data' =>
                [
                    'appointmentTemplateId' => data_get($this->task_data, 'appointmentTemplateId'),
                    'slotUsageRuleId' => data_get($this->task_data, 'slotUsageRuleSetId'),
                    'baseValue' => data_get($this->record, 'base_value'),
                    'priority' => data_get($this->record, 'taskType.priority'),
                    'slaStart' => data_get($this->task_data, 'sla_start'),
                    'slaEnd' => Carbon::parse(data_get($this->task_data, 'sla_start'))->addDays(21)->toIso8601String(),
                    'duration' => data_get($this->record, 'duration'),
                    'activityId' => data_get($this->record, 'friendly_id'),
                    'taskTypeId' => data_get($this->record, 'taskType.name'),
                    'lat' => data_get($this->record, 'customer.lat'),
                    'long' => data_get($this->record, 'customer.long'),
                    'slaTypeId' => 'Appointment', // todo parameterize this
                    'appointmentTemplateDuration' => 21,
                    'appointmentBaseDate' => now()->startOfDay()->toIso8601String(),
                ]
        ];
    }


}
