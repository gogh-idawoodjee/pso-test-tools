<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\HttpMethod;
use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Schemas\TaskBookingSummary;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Support\Booking\AgendaPresenter;
use App\Support\Booking\BookingClient;
use App\Support\Booking\BookingHistory;
use App\Support\Booking\BookingPayloads;
use App\Support\Booking\BookingSlaTypes;
use App\Support\Booking\BookingWindow;
use App\Support\Booking\OfferRelease;
use App\Traits\FormTrait;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use RuntimeException;

/**
 * Appointment booking for one task: a survey request fills a three week month view, a day opens
 * that day's offers for the chosen template (the agenda). Environment and dataset are the task's.
 */
class AppointmentBooking extends Page
{
    use FormTrait, InteractsWithRecord;

    private const int OFFER_MINUTES = 5;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.pages.appointment-booking';

    /** Which part of the page shows: 'month', 'agenda' or 'booked'. */
    public string $screen = 'month';

    /** Y-m-d => 'slots' | 'none' | 'unknown', for the 21 days from today in the dataset zone. */
    public array $dayStates = [];

    public ?string $selectedDay = null;

    /** First day of the month view's window (Y-m-d in the booking zone); null means today. */
    public ?string $windowStart = null;

    /** The latest window end PSO returned for the survey, as a UTC instant; the month's Next starts there. */
    #[Locked]
    public ?string $surveyEnd = null;

    /** The latest window end of the open day request, as a UTC instant; the agenda's Next starts there. */
    #[Locked]
    public ?string $cursor = null;

    /** The earliest window start of the open reply, any offer, as a UTC instant: where agenda Previous steps back from. */
    #[Locked]
    public ?string $cursorBack = null;

    /** The appointmentTemplateDatetime sent for the open request (UTC, no offset). */
    #[Locked]
    public ?string $requestedFromUtc = null;

    /** The services appointmentOffers block of the open request. */
    #[Locked]
    public ?array $offers = null;

    #[Locked]
    public ?string $appointmentRequestId = null;

    #[Locked]
    public ?string $countdownExpiresAt = null;

    /** The countdown ran out: the open request's offers can no longer be booked. */
    #[Locked]
    public bool $expired = false;

    /** Agenda layout: 'callcentre' (valid offers, Book) or 'detail' (every offer, values and resources). */
    public string $mode = 'callcentre';

    /** Show the agenda's times in UTC instead of the dataset zone, for this page visit. */
    public bool $showUtc = false;

    /** The acceptedAppointmentSummary of the booking made on this page. */
    #[Locked]
    public ?array $booking = null;

    /** The booking this page replaced (window text and SLA type), when the task was booked before the accept. */
    #[Locked]
    public ?array $previousBooking = null;

    public ?string $slotMessage = null;

    /** Whether the open request blocks its offers, fixed when it was sent. */
    #[Locked]
    public ?bool $requestBlocking = null;

    public ?string $pageError = null;

    /** Null means "as the template". */
    public ?bool $blockingOverride = null;

    public ?array $task_data = [];

    /** Whether the booking history section is open. */
    public bool $historyOpen = false;

    /** The history rows (see BookingHistory::rows); null until loaded. */
    #[Locked]
    public ?array $history = null;

    #[Locked]
    public ?string $historyError = null;

    private ?BookingSlaTypes $slaTypesCache = null;

    /** The booked assignment in the dataset's zone, e.g. "Thursday October 22nd 08:00-09:30"; offsetless times are UTC. */
    public function formatAssignment(mixed $start, mixed $finish): ?string
    {
        $from = self::utcMoment($start);
        $to = self::utcMoment($finish);
        if ($from === null || $to === null) {
            return null;
        }

        return TaskBookingSummary::window($from, $to, $this->timeZone());
    }

    /** A direct link back to the task (the task has no view page, so edit is the task page). */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToTask')
                ->label('Back to task')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(TaskResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function infolist(Schema $infolist): Schema
    {
        return TaskBookingSummary::schema($infolist, $this->record, $this->timeZone());
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

        // ?history=1 (the task page's History link) opens the section; it loads after the first render (wire:init).
        $this->historyOpen = request()->boolean('history');
    }

    /** Open or close the booking history; the first open loads it. */
    public function toggleHistory(): void
    {
        $this->historyOpen = ! $this->historyOpen;
        if ($this->historyOpen) {
            $this->loadHistory();
        }
    }

    /** Read the task's booking history from services, once (a failed read is tried again on the next open). */
    public function loadHistory(): void
    {
        if ($this->history !== null) {
            return;
        }

        $result = (new BookingHistory($this->record))->fetch();
        $this->historyError = $result['error'];
        $this->history = $result['error'] === null ? $result['rows'] : null;
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
                            // The Blocking toggle is not dehydrated (it saves through a hook this form never runs), so read it from the raw state.
                            ->createOptionUsing(fn (array $data, Schema $schema): string => $this->createManualReferenceValue(
                                ReferenceEntityType::AppointmentTemplate,
                                $data + ['blocking_override' => data_get($schema->getRawState(), 'blocking_override')],
                            ))
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

    /** The 21 calendar days of the month view in the booking zone, from today or from windowStart, as Y-m-d. */
    public function windowDays(): array
    {
        return $this->window()->days();
    }

    /** The zone the month and agenda are based in: the customer's, else the dataset's, else UTC. */
    public function bookingTimeZone(): string
    {
        return $this->timeZone();
    }

    /** Month view Next: three more weeks, starting where the last offer ended (or the day after the window). */
    public function nextMonth(): void
    {
        $this->windowStart = $this->window()->nextMonthStart();
        $this->loadSurvey();
    }

    /** Month view Previous: three weeks back from the window's first day, never before today (null means today). */
    public function previousMonth(): void
    {
        if (! $this->canGoPreviousMonth()) {
            return;
        }

        $this->windowStart = $this->window()->previousMonthStart();
        $this->loadSurvey();
    }

    /** Month Previous is off when the window already starts today (or earlier). */
    public function canGoPreviousMonth(): bool
    {
        return $this->window()->canGoPreviousMonth();
    }

    /**
     * The task's current booking: its day (Y-m-d in the booking zone), the window as readable text, and the window
     * start and end as UTC instants. Null when the task is not booked or has no window.
     *
     * @return array{day: string, label: string, start: string, end: string}|null
     */
    public function currentBooking(): ?array
    {
        if (! $this->record->isBooked() || blank($this->record->appt_window_start) || blank($this->record->appt_window_finish)) {
            return null;
        }

        // The cast reads the stored wall time in the app zone; the instant is what matters.
        $start = CarbonImmutable::instance($this->record->appt_window_start)->utc();
        $end = CarbonImmutable::instance($this->record->appt_window_finish)->utc();

        return [
            'day' => $start->setTimezone($this->timeZone())->toDateString(),
            'label' => (string) AgendaPresenter::readableWindow($start->toIso8601String(), $end->toIso8601String(), $this->timeZone()),
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
        ];
    }

    /** The booked panel's Rebook: back to the month, which shows the booking just made and asks PSO again. */
    public function rebook(): void
    {
        $this->backToMonth();
        $this->booking = null;
        $this->previousBooking = null;
        $this->loadSurvey();
    }

    /** Month view: back to the three weeks from today. */
    public function backToToday(): void
    {
        $this->windowStart = null;
        $this->loadSurvey();
    }

    /** Agenda Next: ask for the template's offers again, starting where the last offer (available or not) ended. */
    public function nextAgenda(): void
    {
        $from = $this->window()->nextAgendaFrom();
        if ($from === null || $this->screen !== 'agenda') {
            return;
        }

        $this->requestDay($from->setTimezone($this->timeZone())->toDateString(), BookingWindow::templateDatetime($from));
    }

    /**
     * Agenda Previous: ask again with the template starting the reply's span (latest end minus earliest start) before
     * its earliest start, never before now.
     */
    public function previousAgenda(): void
    {
        $from = $this->window()->previousAgendaFrom();
        if ($from === null || $this->screen !== 'agenda' || ! $this->canGoPreviousAgenda()) {
            return;
        }

        $this->requestDay($from->setTimezone($this->timeZone())->toDateString(), BookingWindow::templateDatetime($from));
    }

    /**
     * Agenda Previous is off when the reply had no offers, or when Previous would ask from now and the open request
     * already began at now or earlier: there is nothing earlier to show.
     */
    public function canGoPreviousAgenda(): bool
    {
        return $this->window()->canGoPreviousAgenda();
    }

    /** The customer's zone, else the dataset's, else UTC. */
    private function timeZone(): string
    {
        return $this->record->timeZone();
    }

    /** Where the display zone comes from, for the pill: 'customer', 'dataset' or 'none'. */
    public function timeZoneSource(): string
    {
        return match (true) {
            (bool) $this->record->customer?->hasOwnTimeZone() => 'customer',
            $this->record->dataset->hasTimeZone() => 'dataset',
            default => 'none',
        };
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
            $payload = $this->payloads()->survey($this->window()->firstDay());
        } catch (RuntimeException $exception) {
            $this->pageError = $exception->getMessage();

            return;
        }

        $result = $this->sendAppointment('appointment', $payload);
        if ($result['error'] !== null) {
            $this->pageError = $result['error'];

            return;
        }

        $this->surveyEnd = BookingWindow::latestWindowEnd((array) data_get($result['data'], 'appointmentOffers', []));

        $window = $this->window();
        $states = array_fill_keys($days, 'none');
        foreach ((array) data_get($result['data'], 'appointmentOffers.validOffers', []) as $offer) {
            $day = $window->offerDay($offer);
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

        $this->requestDay($day);
    }

    /** One day request: the template from the base day, or from an exact UTC instant (Next, Previous). */
    private function requestDay(string $day, ?string $templateDatetimeUtc = null): void
    {
        // Every request adds a history row: forget the loaded list so the section reloads when opened.
        $this->history = null;
        $this->historyError = null;

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
                BookingWindow::inputDatetimeUtc(data_get($this->task_data, 'input_datetime')),
                $templateDatetimeUtc,
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
        $this->cursor = BookingWindow::latestWindowEnd($offers);
        $this->cursorBack = BookingWindow::earliestWindowStart($offers);
        $this->requestedFromUtc = data_get($payload, 'data.appointmentTemplateDatetime');
        $this->appointmentRequestId = data_get($offers, 'appointmentRequestId');
        $this->countdownExpiresAt = now()->addMinutes(self::OFFER_MINUTES)->toIso8601String(); // DO NOT USE ->format(), do not force UTC
        $this->requestBlocking = $blocking;
        $this->expired = false;
        $this->slotMessage = null;
        $this->booking = null;
        $this->selectedDay = $day;
        $this->screen = 'agenda';
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
        if ($offer === null || $this->isCurrentBooking($offer)) {
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
            // Only a spent request (406, or services saying it expired) needs a new search; otherwise it stays
            // open, so Book can be tried again and leaving the page still releases it.
            if ($accept['status'] === 406 || str_contains(strtolower($accept['error']), 'expired')) {
                $this->expired = true;
            }

            return;
        }

        $summary = data_get($accept['data'], 'acceptedAppointmentSummary');
        if (! is_array($summary)) {
            $this->pageError = 'The PSO services API answered without the accepted appointment.';

            return;
        }

        // A rebooking: keep the window being replaced for the panel (the history section is the lasting record).
        $this->previousBooking = $this->record->isBooked() ? [
            'window' => TaskBookingSummary::window($this->record->appt_window_start, $this->record->appt_window_finish, $this->timeZone()),
            'slaTypeId' => $this->record->booking_sla_type_id,
        ] : null;

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
        // The history gained a row: read it again the next time the section is opened.
        $this->history = null;
        $this->historyError = null;
    }

    /** Whether an offer's window is the task's current booking (same start and end instants). */
    private function isCurrentBooking(array $offer): bool
    {
        $current = $this->currentBooking();
        $start = self::utcMoment(data_get($offer, 'windowStartDatetime'));
        $end = self::utcMoment(data_get($offer, 'windowEndDatetime'));

        return $current !== null && $start !== null && $end !== null
            && $start->eq(CarbonImmutable::parse($current['start'])) && $end->eq(CarbonImmutable::parse($current['end']));
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
     * The agenda as a day grid for the current mode, in the display zone: one column per day with each offer a
     * block against a shared hour axis. See AgendaPresenter::grid for the shape.
     */
    public function agendaGrid(): array
    {
        $current = $this->currentBooking();

        return $this->agenda()->grid((array) $this->offers, (string) $this->mode, $this->displayTimeZone(), $current['start'] ?? null, $current['end'] ?? null);
    }

    /** The zone the agenda shows its times in: UTC when the switch is on, else the dataset's. */
    public function displayTimeZone(): string
    {
        return $this->showUtc ? 'UTC' : $this->timeZone();
    }

    /** The time zone pill: the dataset zone with its abbreviation on the selected day, e.g. "America/Toronto (EDT)", or "UTC". */
    public function timeZoneLabel(): string
    {
        $zone = $this->displayTimeZone();
        if ($zone === 'UTC') {
            return $this->showUtc || $this->timeZoneSource() !== 'none' ? 'UTC' : 'UTC (no time zone on the customer or the dataset)';
        }

        $day = filled($this->selectedDay) ? CarbonImmutable::parse($this->selectedDay, $zone)->setTime(12, 0) : CarbonImmutable::now($zone);
        $abbreviation = $day->format('T');
        $label = $abbreviation === $zone ? $zone : $zone.' ('.$abbreviation.')';

        return $label.', '.$this->timeZoneSource();
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

        // A release changes the request's outcome: the history list is stale once this runs.
        $this->history = null;
        $this->historyError = null;

        // An expired request is gone on the PSO side: services would answer 406, so only forget it here.
        if (! $this->expired) {
            $release = (new OfferRelease($this->record))->release($requestId);
            if (! $release['ok']) {
                // Set after selectDay clears the error, so a following day request that works leaves it showing.
                $this->pageError = 'The offers could not be released: '.$release['error'];
            }
        }

        $this->appointmentRequestId = null;
        $this->offers = null;
        $this->countdownExpiresAt = null;
        $this->expired = false;
    }

    private function window(): BookingWindow
    {
        return new BookingWindow($this->timeZone(), $this->windowStart, $this->surveyEnd, $this->cursor, $this->cursorBack, $this->requestedFromUtc);
    }

    private function payloads(): BookingPayloads
    {
        return new BookingPayloads($this->record, $this->slaTypes());
    }

    /**
     * Send one appointment call through services and read the reply.
     *
     * @return array{data: ?array, error: ?string, status: ?int}
     */
    private function sendAppointment(string $segment, array $payload, ?HttpMethod $method = null): array
    {
        return (new BookingClient($this->record))->send($segment, $payload, $method);
    }

    /** Seconds allowed for each appointment call (config psott.appointment.timeout, env APPOINTMENT_TIMEOUT). */
    public static function appointmentTimeout(): int
    {
        return BookingClient::appointmentTimeout();
    }
}
