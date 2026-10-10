<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Filament\Resources\TaskResource;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Traits\FormTrait;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

class AppointmentBooking extends Page
{
    use FormTrait, InteractsWithRecord;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.pages.appointment-booking';

    public ?string $countdownExpiresAt = null;

    public bool $countdownExpired = false;

    public ?array $task_data = [];

    /**
     * Format a time window between two dates
     */
    protected function formatAppointmentWindow(Carbon|string|null $startDate, Carbon|string|null $endDate): string
    {
        // Parse dates if they're not already Carbon instances
        $start = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
        $end = $endDate instanceof Carbon ? $endDate : Carbon::parse($endDate);

        // Format the date and time
        $formattedDate = $start->format('l F jS');
        $formattedTime = $start->format('H:i').'-'.$end->format('H:i');

        return $formattedDate.' '.$formattedTime;
    }

    /**
     * Format a duration in minutes to a human-readable string
     */
    protected function formatDuration(int $durationInMinutes): string
    {
        $hours = floor($durationInMinutes / 60);
        $minutes = $durationInMinutes % 60;

        $formattedDuration = $hours.' hour'.($hours != 1 ? 's' : '');

        if ($minutes > 0) {
            $formattedDuration .= ' '.$minutes.' minute'.($minutes !== 1 ? 's' : '');
        }

        return $formattedDuration;
    }

    /**
     * Check if an appointment is urgent (less than 2 hours away)
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
     */
    public function infolist(Schema $infolist): Schema
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
                Section::make('Task '.data_get($this->record, 'friendly_id').' - '.data_get($this->record, 'taskType.label'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
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
                                            ->icon(Heroicon::OutlinedClock)
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
                                                return number_format((float) $state);
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
                                    ]),

                            ])
                            ->columns(3),

                        Section::make()
                            ->schema([
                                TextEntry::make('appointment')
                                    ->label('Appointment Window')
                                    ->state($appointmentTime)
                                    ->formatStateUsing(static function ($state) use ($isUrgent) {
                                        $colorClass = $isUrgent ? 'text-red-600' : 'text-purple-600';

                                        return '<span class="'.$colorClass.'">'.$state.'</span>';
                                    })
                                    ->icon(Heroicon::OutlinedCalendar)
                                    ->iconColor($isUrgent ? 'danger' : 'primary')
                                    ->size('lg')
                                    ->html(),

                            ]),

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

        if (array_key_exists('Appointment', $this->slaTypeOptions())) {
            $this->task_data['slaTypeId'] = 'Appointment';
        }

    }

    protected function getForms(): array
    {
        return ['env_form', 'taskForm'];
    }

    /** Appointment templates of the task's dataset, keyed by the PSO id the request carries. */
    public function templateOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::AppointmentTemplate);
    }

    public function slotUsageRuleOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::SlotUsageRule);
    }

    public function slaTypeOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::SlaType);
    }

    private function createManualReferenceValue(ReferenceEntityType $type, array $data): string
    {
        return DatasetReferenceValue::createManual($this->record->dataset_id, $type, $data)->pso_id;
    }

    public function taskForm(Schema $form): Schema
    {
        return $form
            ->schema(
                [
                    Section::make('Appointment Booking')->schema([
                        Select::make('appointmentTemplateId')
                            ->label('Appointment Template')
                            ->options(fn (): array => $this->templateOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::AppointmentTemplate, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::AppointmentTemplate, $data))
                            ->createOptionModalHeading('Create Template')
                            ->required(),
                        Select::make('slotUsageRuleId')
                            ->label('Slot Usage Rule Set')
                            ->options(fn (): array => $this->slotUsageRuleOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::SlotUsageRule, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::SlotUsageRule, $data))
                            ->createOptionModalHeading('Create Slot Usage Rule Set'),
                        Select::make('slaTypeId')
                            ->label('SLA Type')
                            ->options(fn (): array => $this->slaTypeOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::SlaType, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::SlaType, $data))
                            ->createOptionModalHeading('Create SLA Type')
                            ->required(),
                        DateTimePicker::make('sla_start')
                            ->label('Search for Appointments from:')
                            ->closeOnDateSelection()
                            ->seconds(false)
                            ->suffixAction(
                                Action::make('set_sla_start')
                                    ->icon(Heroicon::Clock)
                                    ->action(function (Get $get, Set $set) {
                                        $this->setSlaStart($set);

                                    }))
                            ->hint('Reset to current date/time')
                            ->required()
                            ->live()->columnSpan(2),

                        $this->inputDatetimeField(),

                        Actions::make([Action::make('get_appointments')
                            ->label('Get Appointments')
                            ->icon(Heroicon::OutlinedCalendar)
                            ->action(function () {
                                $this->getAppointments();
                            }),
                        ])->verticalAlignment(VerticalAlignment::End),
                    ])->columns(),

                ])->statePath('task_data');

    }

    private function getAppointments()
    {

        $this->validateForms($this->getForms());
        $this->response = null;
        $data = $this->setDataPayload();

        // done eventually do this on the formtrait since all payloads are now env => env_d
        $environment = $this->environment_payload_data();

        $payload = array_merge($data, $environment);

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSONew('appointment', $tokenized_payload);

            if (! empty($this->response['data']['appointment_offers']['valid_offers'] ?? [])) {
                $this->countdownExpiresAt = now()->addMinutes(3)->toIso8601String(); // DO NOT USE ->format(), do not force UTC
            }

        }

    }

    private function setSlaStart(Set $set)
    {
        $set('sla_start', now()->format('Y-m-d\TH:i'));

    }

    private function setDataPayload(): array
    {

        $data = [
            'data' => [
                'appointmentTemplateId' => data_get($this->task_data, 'appointmentTemplateId'),
                'baseValue' => data_get($this->record, 'base_value'),
                'priority' => data_get($this->record, 'taskType.attributes.priority'),
                'slaStart' => Carbon::parse(data_get($this->task_data, 'sla_start'))->format('Y-m-d\TH:i:s'),
                'slaEnd' => Carbon::parse(data_get($this->task_data, 'sla_start'))->addDays(21)->format('Y-m-d\TH:i:s'),
                'duration' => data_get($this->record, 'duration'),
                'activityId' => data_get($this->record, 'friendly_id'),
                'activityTypeId' => data_get($this->record, 'taskType.pso_id'),
                'lat' => data_get($this->record, 'customer.lat'),
                'long' => data_get($this->record, 'customer.long'),
                'slaTypeId' => data_get($this->task_data, 'slaTypeId'),
                'appointmentTemplateDuration' => 21,
                'appointmentBaseDate' => now()->startOfDay()->toIso8601String(),
            ],
        ];

        if (data_get($this->task_data, 'slotUsageRuleId')) {
            // Arr::add() returns a copy, so the old call here never sent the rule set.
            $data['data']['slotUsageRuleId'] = data_get($this->task_data, 'slotUsageRuleId');
        }

        if ($inputDatetime = $this->inputDatetimePayloadValue(data_get($this->task_data, 'input_datetime'))) {
            $data['data']['inputDatetime'] = $inputDatetime;
        }

        return $data;
    }
}
