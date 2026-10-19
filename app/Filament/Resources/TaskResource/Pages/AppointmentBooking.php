<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\HttpMethod;
use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Filament\Resources\TaskResource;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Support\Booking\AgendaPresenter;
use App\Support\Booking\BookingPayloads;
use App\Support\Booking\BookingSlaTypes;
use App\Traits\FormTrait;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Appointment booking for one task: a survey request fills a three week month view, a day opens
 * that day's offers for the chosen template (the agenda). Environment and dataset are the task's.
 */
class AppointmentBooking extends Page
{
    use FormTrait, InteractsWithRecord;

    private const int WINDOW_DAYS = 21;

    private const int OFFER_MINUTES = 5;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.pages.appointment-booking';

    /** Which part of the page shows: 'month', 'agenda' or 'booked'. */
    public string $screen = 'month';

    /** Y-m-d => 'slots' | 'none' | 'unknown', for the 21 days from today in the dataset zone. */
    public array $dayStates = [];

    public ?string $selectedDay = null;

    /** The services appointmentOffers block of the open request. */
    public ?array $offers = null;

    public ?string $appointmentRequestId = null;

    public ?string $countdownExpiresAt = null;

    /** The countdown ran out: the open request's offers can no longer be booked. */
    public bool $expired = false;

    /** Agenda layout: 'callcentre' (valid offers, Book) or 'detail' (every offer, values and resources). */
    public string $mode = 'callcentre';

    /** The acceptedAppointmentSummary of the booking made on this page. */
    public ?array $booking = null;

    public ?string $slotMessage = null;

    /** Whether the open request blocks its offers, fixed when it was sent. */
    public ?bool $requestBlocking = null;

    public ?string $pageError = null;

    /** Null means "as the template". */
    public ?bool $blockingOverride = null;

    public ?array $task_data = [];

    private ?BookingSlaTypes $slaTypesCache = null;

    /** The booked window in the dataset's zone, e.g. "Thursday October 22nd 08:00-12:00". */
    protected function formatAppointmentWindow(Carbon|string|null $startDate, Carbon|string|null $endDate): ?string
    {
        if (blank($startDate) || blank($endDate)) {
            return null;
        }

        $timeZone = $this->record->dataset->timeZone();
        $start = Carbon::parse($startDate)->setTimezone($timeZone);
        $end = Carbon::parse($endDate)->setTimezone($timeZone);

        return $start->format('l F jS').' '.$start->format('H:i').'-'.$end->format('H:i');
    }

    /** The booked assignment in the dataset's zone, e.g. "Thursday October 22nd 08:00-09:30"; offsetless times are UTC. */
    public function formatAssignment(mixed $start, mixed $finish): ?string
    {
        $from = self::utcMoment($start);
        $to = self::utcMoment($finish);
        if ($from === null || $to === null) {
            return null;
        }

        return $this->formatAppointmentWindow(Carbon::instance($from), Carbon::instance($to));
    }

    /**
     * Format a duration in minutes to a human-readable string
     */
    protected function formatDuration(int $durationInMinutes): string
    {
        return AgendaPresenter::formatDuration($durationInMinutes);
    }

    /**
     * Check if an appointment is urgent (less than 2 hours away)
     */
    protected function isAppointmentUrgent(Carbon|string|null $appointmentStart): bool
    {
        if (blank($appointmentStart)) {
            return false;
        }

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
        $formattedDuration = $this->formatDuration((int) $this->record->duration);

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
                                    ->label($this->record->isBooked() ? 'Booked window' : 'Booking')
                                    ->state($appointmentTime ?? 'Not booked')
                                    ->color($appointmentTime === null ? 'gray' : ($isUrgent ? 'danger' : 'primary'))
                                    ->icon(Heroicon::OutlinedCalendar)
                                    ->iconColor($isUrgent ? 'danger' : 'primary')
                                    ->size('lg'),
                                TextEntry::make('booking_sla_type_id')
                                    ->label('Booking SLA type')
                                    ->visible($this->record->isBooked())
                                    ->placeholder('Not recorded'),
                                TextEntry::make('appointment_request_id')
                                    ->label('Appointment request')
                                    ->visible($this->record->isBooked()),
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
        $this->record->loadMissing(['dataset.environment', 'customer', 'taskType']);

        $this->environments = Environment::with('datasets')->get();
        $this->isAuthenticationRequired = true;
        $this->isEnvironmentLocked = true;

        $this->task_data = $this->record->toArray();
        $this->fillForms($this->getForms());

        $this->lockEnvironment();

        $templates = $this->templateOptions();
        if (count($templates) === 1) {
            $this->task_data['appointmentTemplateId'] = array_key_first($templates);
        }
        $this->task_data['blocking_choice'] = 'template';

        // The survey runs after the first render (wire:init), so the grid shows its loading state.
        $this->dayStates = array_fill_keys($this->windowDays(), 'unknown');
        $this->pageError = $this->slaTypes()->missingMessage();
    }

    /** The task's dataset decides the environment: shown, not selectable. */
    private function lockEnvironment(): void
    {
        $dataset = $this->record->dataset;

        // Load the environment through the user's list (owner scope), falling back to the relation.
        $this->selectedEnvironment = $this->environments->find($dataset->environment_id) ?? $dataset->environment;
        // PSO knows a dataset by its name; the env form's dataset options are keyed by name too.
        $this->selectedDataset = $dataset->name;

        $this->env_form->fill([
            'environment_id' => $dataset->environment_id,
            'dataset_id' => $dataset->name,
            'send_to_pso' => true,
        ]);
    }

    protected function getForms(): array
    {
        return ['env_form', 'taskForm'];
    }

    public function slaTypes(): BookingSlaTypes
    {
        return $this->slaTypesCache ??= BookingSlaTypes::for($this->record);
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

    /** The selected template's row in the task's dataset, if any. */
    private function selectedTemplate(): ?DatasetReferenceValue
    {
        $templateId = data_get($this->task_data, 'appointmentTemplateId');
        if (blank($templateId)) {
            return null;
        }

        return DatasetReferenceValue::query()
            ->where('dataset_id', $this->record->dataset_id)
            ->ofType(ReferenceEntityType::AppointmentTemplate)
            ->where('pso_id', $templateId)
            ->first();
    }

    /** Whether the next request blocks its offers: the override, else the template's flag, else true. */
    public function blockingForRequest(): bool
    {
        return $this->blockingOverride ?? $this->selectedTemplate()?->blockingDefault() ?? true;
    }

    public function taskForm(Schema $form): Schema
    {
        $slaLabel = static fn (?string $id, string $source): string => $id === null
            ? 'Not set'
            : "{$id} (from the {$source})";

        return $form
            ->schema([
                Section::make('Appointment booking')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->schema([
                        Select::make('appointmentTemplateId')
                            ->label('Appointment template')
                            ->options(fn (): array => $this->templateOptions())
                            ->searchable()
                            ->live()
                            ->helperText(function (): ?string {
                                $template = $this->selectedTemplate();

                                return $template === null ? null : 'Template blocking: '.($template->blockingDefault() ? 'yes' : 'no').'.';
                            })
                            ->createOptionForm(fn (): array => DatasetReferenceValueResource::formSchema(ReferenceEntityType::AppointmentTemplate, lockDataset: true, datasetId: fn (): string => $this->record->dataset_id))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::AppointmentTemplate, $data))
                            ->createOptionModalHeading('Create template')
                            ->required(),
                        Select::make('blocking_choice')
                            ->label('Blocking for this request')
                            ->options([
                                'template' => 'As the template',
                                'yes' => 'Blocking',
                                'no' => 'Not blocking',
                            ])
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->blockingOverride = match ($state) {
                                    'yes' => true,
                                    'no' => false,
                                    default => null,
                                };
                            }),
                        Select::make('slotUsageRuleId')
                            ->label('Slot usage rule set')
                            ->options(fn (): array => $this->slotUsageRuleOptions())
                            ->searchable()
                            ->createOptionForm(fn (): array => DatasetReferenceValueResource::formSchema(ReferenceEntityType::SlotUsageRule, lockDataset: true, datasetId: fn (): string => $this->record->dataset_id))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::SlotUsageRule, $data))
                            ->createOptionModalHeading('Create slot usage rule set'),
                        $this->inputDatetimeField(),
                        TextEntry::make('appointment_sla_type')
                            ->label('Appointment SLA type')
                            ->state(fn (): string => $slaLabel($this->slaTypes()->appointmentSlaTypeId, $this->slaTypes()->appointmentSource)),
                        TextEntry::make('booking_sla_type')
                            ->label('Booking SLA type')
                            ->state(fn (): string => $slaLabel($this->slaTypes()->bookingSlaTypeId, $this->slaTypes()->bookingSource)),
                    ])->columns(),
            ])->statePath('task_data');
    }

    /** The 21 calendar days from today in the dataset's zone, as Y-m-d. */
    public function windowDays(): array
    {
        $today = CarbonImmutable::now($this->timeZone())->startOfDay();

        return array_map(static fn (int $i): string => $today->addDays($i)->toDateString(), range(0, self::WINDOW_DAYS - 1));
    }

    private function timeZone(): string
    {
        return $this->record->dataset->timeZone();
    }

    /** Ask PSO which of the next 21 days have slots (survey request, no template). */
    public function loadSurvey(): void
    {
        $days = $this->windowDays();
        $this->dayStates = array_fill_keys($days, 'unknown');
        $this->pageError = null;

        if (! $this->slaTypes()->complete()) {
            $this->pageError = $this->slaTypes()->missingMessage();

            return;
        }

        try {
            $payload = $this->payloads()->survey(CarbonImmutable::now($this->timeZone()));
        } catch (RuntimeException $exception) {
            $this->pageError = $exception->getMessage();

            return;
        }

        $result = $this->sendAppointment('appointment', $payload);
        if ($result['error'] !== null) {
            $this->pageError = $result['error'];

            return;
        }

        $states = array_fill_keys($days, 'none');
        foreach ((array) data_get($result['data'], 'appointmentOffers.validOffers', []) as $offer) {
            $day = $this->offerDay($offer);
            if ($day !== null && array_key_exists($day, $states)) {
                $states[$day] = 'slots';
            }
        }
        $this->dayStates = $states;
    }

    /** Ask PSO for the chosen template's slots on one day and open the agenda. */
    public function selectDay(string $day): void
    {
        if (! array_key_exists($day, $this->dayStates)) {
            return;
        }

        if (! $this->slaTypes()->complete()) {
            $this->pageError = $this->slaTypes()->missingMessage();

            return;
        }

        $templateId = data_get($this->task_data, 'appointmentTemplateId');
        if (blank($templateId)) {
            $this->pageError = 'Choose an appointment template first.';

            return;
        }

        $this->pageError = null;
        $this->declineOpenRequest();
        $blocking = $this->blockingForRequest();

        try {
            $payload = $this->payloads()->day(
                CarbonImmutable::parse($day, $this->timeZone()),
                $templateId,
                data_get($this->task_data, 'slotUsageRuleId'),
                $this->blockingOverride,
                self::inputDatetimeUtc(data_get($this->task_data, 'input_datetime')),
            );
        } catch (RuntimeException $exception) {
            $this->pageError = $exception->getMessage();
            $this->screen = 'month';
            $this->selectedDay = null;

            return;
        }

        $result = $this->sendAppointment('appointment', $payload);
        if ($result['error'] !== null) {
            $this->pageError = $result['error'];
            $this->screen = 'month';
            $this->selectedDay = null;

            return;
        }

        $offers = (array) data_get($result['data'], 'appointmentOffers', []);
        $this->offers = $offers;
        $this->appointmentRequestId = data_get($offers, 'appointmentRequestId');
        $this->countdownExpiresAt = now()->addMinutes(self::OFFER_MINUTES)->toIso8601String(); // DO NOT USE ->format(), do not force UTC
        $this->requestBlocking = $blocking;
        $this->expired = false;
        $this->slotMessage = null;
        $this->booking = null;
        $this->selectedDay = $day;
        $this->screen = 'agenda';
    }

    /**
     * The picker's wall time read in the app zone, sent as UTC without an offset: services validates
     * inputDatetime as Y-m-d\TH:i:s and reads it as UTC.
     */
    public static function inputDatetimeUtc(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->utc()->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public function backToMonth(): void
    {
        $this->declineOpenRequest();
        $this->selectedDay = null;
        $this->slotMessage = null;
        $this->screen = 'month';
    }

    /** The "Release offers" button: decline the open request and return to the month. */
    public function releaseOffers(): void
    {
        $this->backToMonth();
    }

    /** Called once by the countdown when the offers run out. */
    public function markExpired(): void
    {
        $this->expired = true;
    }

    /**
     * Book one offer of the open request: on a non-blocking request check it first, then accept with the
     * resolved booking SLA type (and the task's current one as the previous type on a rebooking).
     */
    public function book(int $offerId): void
    {
        if ($this->expired || $this->screen !== 'agenda' || blank($this->appointmentRequestId)) {
            return;
        }

        $offer = $this->validOffer($offerId);
        if ($offer === null) {
            return;
        }

        $this->slotMessage = null;
        $this->pageError = null;
        $segment = 'appointment/'.rawurlencode($this->appointmentRequestId);
        $payloads = $this->payloads();

        if (! ($this->requestBlocking ?? $this->blockingForRequest())) {
            $check = $this->sendAppointment($segment, $payloads->check($offerId), HttpMethod::POST);
            if ($check['error'] !== null) {
                $this->pageError = $check['error'];

                return;
            }

            // Services may send the flag as a string; null still means it did not say.
            $available = data_get($check['data'], 'appointment_summary.isSlotAvailable');
            $available = is_bool($available) ? $available : (is_scalar($available) ? filter_var($available, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
            if ($available === false) {
                $this->slotMessage = 'That slot was taken, pick another';

                return;
            }
            if ($available !== true) {
                $this->pageError = 'The PSO services API did not say whether the slot is still free.';

                return;
            }
        }

        $accept = $this->sendAppointment($segment, $payloads->accept($offerId, $this->record->booking_sla_type_id), HttpMethod::PATCH);
        if ($accept['error'] !== null) {
            $this->pageError = $accept['error'];
            // The request may be spent after a failed accept: make the person search again.
            $this->expired = true;

            return;
        }

        $summary = data_get($accept['data'], 'acceptedAppointmentSummary');
        if (! is_array($summary)) {
            $this->pageError = 'The PSO services API answered without the accepted appointment.';

            return;
        }

        $this->record->forceFill([
            'appointment_request_id' => data_get($summary, 'appointmentRequestId') ?: $this->appointmentRequestId,
            'booking_sla_type_id' => data_get($summary, 'bookingSlaTypeId') ?: $this->slaTypes()->bookingSlaTypeId,
            'booked_at' => now(),
            // Booked: the real activity is in PSO at status 0 now.
            'status' => TaskStatus::UNALLOCATED,
            // The datetime cast keeps the wall time and reads it in the app zone, so hand it the instant in that zone.
            'appt_window_start' => self::utcMoment(data_get($offer, 'windowStartDatetime'))?->setTimezone(config('app.timezone')),
            'appt_window_finish' => self::utcMoment(data_get($offer, 'windowEndDatetime'))?->setTimezone(config('app.timezone')),
        ])->save();

        // The request is accepted: nothing left to release.
        $this->appointmentRequestId = null;
        $this->countdownExpiresAt = null;
        $this->booking = $summary;
        $this->screen = 'booked';
    }

    /** The open request's valid offer with this id, if any. */
    private function validOffer(int $offerId): ?array
    {
        foreach ((array) data_get($this->offers, 'validOffers', []) as $offer) {
            if ((int) data_get($offer, 'id') === $offerId) {
                return (array) $offer;
            }
        }

        return null;
    }

    /** An offer time as UTC; times with an offset keep it, offsetless times are UTC. */
    private static function utcMoment(mixed $value): ?CarbonImmutable
    {
        return AgendaPresenter::utcMoment($value);
    }

    /**
     * The agenda rows for the current mode, grouped by windowDayEnglish then period of day. Call centre: the
     * valid offers. Detail: allOfferValues merged with validOffers by id, 'valid' false for any id not valid.
     *
     * @return array<string, array<string, list<array>>>
     */
    public function agendaGroups(): array
    {
        return $this->agenda()->groups((array) $this->offers, (string) $this->mode);
    }

    /** The slot length of the open request, from its first offer's window, e.g. "4 hours". */
    public function slotLength(): ?string
    {
        return $this->agenda()->slotLength((array) $this->offers);
    }

    private function agenda(): AgendaPresenter
    {
        return new AgendaPresenter($this->timeZone());
    }

    /** Release the open request's offers (DELETE) so they do not wait to time out. */
    protected function declineOpenRequest(): void
    {
        $requestId = $this->appointmentRequestId;
        if (blank($requestId)) {
            return;
        }

        // An expired request is gone on the PSO side: services would answer 406, so only forget it here.
        if (! $this->expired) {
            $this->sendAppointment('appointment/'.rawurlencode($requestId), ['data' => ['appointmentRequestId' => $requestId]], HttpMethod::DELETE);
        }

        $this->appointmentRequestId = null;
        $this->offers = null;
        $this->countdownExpiresAt = null;
        $this->expired = false;
    }

    private function payloads(): BookingPayloads
    {
        return new BookingPayloads($this->record, $this->slaTypes());
    }

    /** The calendar day of an offer's window start in the dataset's zone; offsetless times are UTC. */
    private function offerDay(mixed $offer): ?string
    {
        $start = data_get($offer, 'windowStartDatetime');
        if (! is_string($start) || $start === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($start, 'UTC')->setTimezone($this->timeZone())->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Send one appointment call through services and read the reply.
     *
     * @return array{data: ?array, error: ?string}
     */
    private function sendAppointment(string $segment, array $payload, ?HttpMethod $method = null): array
    {
        $payload = array_merge($payload, $this->environment_payload_data());

        $tokenized = $this->prepareTokenizedPayload((bool) data_get($this->environment_data, 'send_to_pso'), $payload);
        if ($tokenized === false) {
            return ['data' => null, 'error' => 'Could not log on to PSO'.($this->session_error_message ? ': '.$this->session_error_message : '.')];
        }

        try {
            $raw = $this->sendToPSONew($segment, $tokenized, method: $method, isNonStandardResponse: true, timeout: self::appointmentTimeout());
        } catch (JsonException) {
            return ['data' => null, 'error' => 'The PSO services API answered with something that is not JSON.'];
        }

        $decoded = json_decode($raw, true);

        // A non-2xx reply comes back as the raw body, JSON-encoded once more.
        if (is_string($decoded)) {
            return ['data' => null, 'error' => self::servicesErrorText(json_decode($decoded, true)) ?? 'The PSO services API returned an error.'];
        }

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            return ['data' => null, 'error' => self::servicesErrorText($decoded) ?? 'The PSO services API returned an error.'];
        }

        $data = $decoded['data'];
        if ($method === null && ! is_array(data_get($data, 'appointmentOffers'))) {
            return ['data' => null, 'error' => 'The PSO services API answered without appointment offers.'];
        }

        return ['data' => is_array($data) ? $data : [], 'error' => null];
    }

    /** Seconds allowed for each appointment call (config psott.appointment.timeout, env APPOINTMENT_TIMEOUT). */
    public static function appointmentTimeout(): int
    {
        return (int) config('psott.appointment.timeout', 60);
    }

    private static function servicesErrorText(mixed $body): ?string
    {
        foreach (['error', 'message.error', 'message', 'details', 'details.Message'] as $key) {
            $value = data_get($body, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
