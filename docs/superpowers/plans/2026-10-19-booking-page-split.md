# Booking Page Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the window and cursor maths and the task summary infolist out of `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (1043 lines) into their own classes, with no visible change.

**Architecture:** `App\Support\Booking\BookingWindow` is a pure value object built from the page's window state (zone, windowStart, surveyEnd, cursor, cursorBack, requestedFromUtc, an injectable now). It answers every "where does the month or agenda go next" question and carries the offer scanning statics. `App\Filament\Resources\TaskResource\Schemas\TaskBookingSummary` builds the task header infolist. The page keeps its Livewire state and actions and delegates.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Carbon, Pest.

**Spec:** none. This is a pure refactor recommended by the final review of `docs/superpowers/plans/2026-10-19-booking-history-rebook-previous.md`. The binding authority is the current behaviour: `tests/Feature/AppointmentBookingPageTest.php` must pass unchanged (no edits to that file except where a step says so).

## Global Constraints

- No behaviour change: the blade `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php` is not edited; every public method it calls keeps its name and return shape (`bookingTimeZone`, `currentBooking`, `canGoPreviousMonth`, `canGoPreviousAgenda`, `agendaGrid`, `timeZoneLabel`, `slotLength`, `formatAssignment`, `slaTypes`, `taskForm`, `infolist`).
- Livewire public properties and `#[Locked]` attributes on the page stay exactly as they are.
- `tests/Feature/AppointmentBookingPageTest.php` is not edited.
- Plain English in doc comments, no em dashes.
- Run `vendor/bin/pint --dirty` before each commit.
- Commits end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Stage explicit paths, never `git add -A`.

## Review Focus

1. Previous on the agenda when the reply span is zero (one offer, earliest start equals latest end): `previousAgendaFrom` is the earliest start itself, floored at now. Test in Task 1.
2. Previous on the month when `windowStart` is today's date string: `canGoPreviousMonth` is false and `previousMonthStart` is null. Test in Task 1.
3. Month Next with no survey reply (`surveyEnd` null): start is first day plus 21 days. Test in Task 1.
4. Offers with offsetless datetimes are read as UTC in `earliestWindowStart`, `latestWindowEnd` and `offerDay`. Test in Task 1.
5. The booked-window header shows the window in the booking zone, not the app zone. Test in Task 2.

---

### Task 1: BookingWindow

**Files:**
- Create: `app/Support/Booking/BookingWindow.php`
- Create: `tests/Unit/Booking/BookingWindowTest.php`
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`

**Interfaces:**
- Produces:

```php
final class BookingWindow
{
    public const int DAYS = 21;

    public function __construct(
        private readonly string $timeZone,
        private readonly ?string $windowStart = null,      // Y-m-d in $timeZone, null = today
        private readonly ?string $surveyEnd = null,        // ISO 8601 UTC instant from the last survey
        private readonly ?string $cursor = null,           // latest window end of the open agenda reply
        private readonly ?string $cursorBack = null,       // earliest window start of the open agenda reply
        private readonly ?string $requestedFromUtc = null, // Y-m-d\TH:i:s the open reply was asked from
        ?CarbonImmutable $now = null,                      // defaults to CarbonImmutable::now()
    ) {}

    public function firstDay(): CarbonImmutable;              // start of windowStart (or today) in the zone
    /** @return list<string> 21 Y-m-d strings from firstDay */
    public function days(): array;
    public function nextMonthStart(): string;                 // Y-m-d: surveyEnd in the zone, else firstDay + 21 days
    public function previousMonthStart(): ?string;            // Y-m-d: firstDay - 21 days, null when that is today or earlier
    public function canGoPreviousMonth(): bool;               // windowStart set and firstDay after today
    public function nextAgendaFrom(): ?CarbonImmutable;       // cursor as a UTC instant, null without one
    public function previousAgendaFrom(): ?CarbonImmutable;   // earliest - (latest - earliest), floored at now, to the second; null without both
    public function canGoPreviousAgenda(): bool;              // previousAgendaFrom not null, and not (floored at now and requestedFrom <= now)
    public function offerDay(mixed $offer): ?string;          // Y-m-d of windowStartDatetime in the zone; offsetless = UTC

    public static function earliestWindowStart(array $offers): ?string; // ISO 8601 UTC across validOffers + allOfferValues
    public static function latestWindowEnd(array $offers): ?string;
    public static function templateDatetime(CarbonImmutable $from): string; // UTC, Y-m-d\TH:i:s
    public static function inputDatetimeUtc(mixed $value): ?string;        // picker wall time in config('app.timezone') -> UTC Y-m-d\TH:i:s, null when blank or unparseable
}
```

- [ ] **Step 1: Write the failing tests** in `tests/Unit/Booking/BookingWindowTest.php` (Pest, no database). Use `America/Toronto` and a fixed `$now = CarbonImmutable::parse('2026-10-19 12:00', 'America/Toronto')` passed to the constructor.

```php
it('spans 21 days from today when there is no window start', ...)
    // days() has 21 entries, first '2026-10-19', last '2026-11-08'
it('spans 21 days from the window start', ...)
    // windowStart '2026-11-09' -> first '2026-11-09', last '2026-11-29'
it('month next starts where the survey ended, in the zone', ...)
    // surveyEnd '2026-11-06T05:00:00Z' -> nextMonthStart() '2026-11-06' (05:00Z is 00:00 Toronto on Nov 6, EST)
it('month next without a survey end starts 21 days after the first day', ...)
    // windowStart null -> '2026-11-09'
it('month previous steps 21 days back and floors at today', ...)
    // windowStart '2026-11-30' -> previousMonthStart() '2026-11-09', canGoPreviousMonth() true
    // windowStart '2026-11-01' -> previousMonthStart() null (Oct 11 is before today)
    // windowStart '2026-10-19' (today) -> canGoPreviousMonth() false, previousMonthStart() null
    // windowStart null -> canGoPreviousMonth() false
it('agenda next picks up from the cursor as a UTC instant', ...)
    // cursor '2026-10-22T16:00:00-04:00' -> nextAgendaFrom() equals '2026-10-22T20:00:00Z'; templateDatetime of it '2026-10-22T20:00:00'
    // cursor null -> null
it('agenda previous steps back by the span of the reply', ...)
    // cursorBack '2026-10-25T12:00:00Z', cursor '2026-10-26T00:00:00Z' -> previousAgendaFrom() '2026-10-25T00:00:00Z', canGoPreviousAgenda() true
it('agenda previous floors at now and is off once it has floored', ...)
    // cursorBack '2026-10-19T17:00:00Z', cursor '2026-10-19T22:00:00Z' (now is 16:00Z) -> previousAgendaFrom() equals now (16:00Z)
    // with requestedFromUtc '2026-10-19T16:00:00' -> canGoPreviousAgenda() false
    // with requestedFromUtc '2026-10-19T17:00:00' -> canGoPreviousAgenda() true
it('agenda previous with a zero span is the earliest start itself', ...)
    // cursorBack = cursor = '2026-10-25T12:00:00Z' -> previousAgendaFrom() '2026-10-25T12:00:00Z'
it('agenda previous needs both ends of the reply', ...)
    // cursorBack only -> null, canGoPreviousAgenda() false
it('reads offsetless offer times as UTC', ...)
    // offers ['validOffers' => [['windowStartDatetime' => '2026-10-22T02:00:00', 'windowEndDatetime' => '2026-10-22T06:00:00']], 'allOfferValues' => [['windowStartDatetime' => '2026-10-21T23:00:00', 'windowEndDatetime' => '2026-10-22T10:00:00']]]
    // earliestWindowStart '2026-10-21T23:00:00+00:00', latestWindowEnd '2026-10-22T10:00:00+00:00'
    // offerDay(['windowStartDatetime' => '2026-10-22T02:00:00']) -> '2026-10-21' (22:00 Toronto the day before)
    // offerDay(['windowStartDatetime' => '']) -> null; offerDay([]) -> null
it('turns the picker wall time into UTC', ...)
    // config(['app.timezone' => 'America/Toronto']); inputDatetimeUtc('2026-10-22 09:30') -> '2026-10-22T13:30:00'
    // inputDatetimeUtc('') -> null; inputDatetimeUtc(null) -> null; inputDatetimeUtc('not a date') -> null
```

- [ ] **Step 2: Run them to see them fail**: `php artisan test tests/Unit/Booking/BookingWindowTest.php` fails with class not found.

- [ ] **Step 3: Implement `BookingWindow`** per the interface. Use `AgendaPresenter::utcMoment` for instant parsing (it already exists and is what the page uses today). Mirror the page's current bodies exactly (`windowFirstDay`, `nextMonth`, `previousMonth`, `canGoPreviousMonth`, `nextAgenda`, `previousAgendaFrom`, `canGoPreviousAgenda`, `earliestWindowStart`, `latestWindowEnd`, `offerDay`, `inputDatetimeUtc`), replacing `CarbonImmutable::now(...)` with the injected now (converted to the zone or UTC as each body needs).

- [ ] **Step 4: Run the unit tests**: all pass.

- [ ] **Step 5: Make the page delegate.** In `AppointmentBooking.php`: add `private function window(): BookingWindow` building from `$this->timeZone(), $this->windowStart, $this->surveyEnd, $this->cursor, $this->cursorBack, $this->requestedFromUtc`. Replace the bodies of `windowDays`, `windowFirstDay`, `nextMonth`, `previousMonth`, `canGoPreviousMonth`, `nextAgenda`, `previousAgenda`, `canGoPreviousAgenda`, `loadSurvey` (surveyEnd and offerDay), `requestDay` (cursor, cursorBack, inputDatetimeUtc) with calls to the window. Delete `previousAgendaFrom`, `earliestWindowStart`, `latestWindowEnd`, `offerDay`, `inputDatetimeUtc` and the `WINDOW_DAYS` constant from the page (use `BookingWindow::DAYS` if anything still needs it). Keep `nextAgenda` and `previousAgenda` screen guards on the page. `inputDatetimeUtc` was public static on the page: nothing outside the page calls it (checked), so it moves.

- [ ] **Step 6: Run the booking suite**: `php artisan test tests/Feature/AppointmentBookingPageTest.php tests/Unit/Booking` all pass.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Booking/BookingWindow.php tests/Unit/Booking/BookingWindowTest.php app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php
git commit -m "Booking page: window and cursor maths move into BookingWindow"
```

---

### Task 2: Task summary infolist

**Files:**
- Create: `app/Filament/Resources/TaskResource/Schemas/TaskBookingSummary.php`
- Create: `tests/Unit/Booking/TaskBookingSummaryTest.php`
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`

**Interfaces:**
- Produces:

```php
final class TaskBookingSummary
{
    /** The task header on the booking page: duration, base value, status, the booked window and its SLA type and request. */
    public static function schema(Schema $schema, Task $task, string $timeZone): Schema;

    /** The window in the booking zone, e.g. "Thursday October 22nd 08:00-12:00"; null without both ends. */
    public static function window(Carbon|CarbonImmutable|string|null $start, Carbon|CarbonImmutable|string|null $end, string $timeZone): ?string;

    /** Under two hours away. */
    public static function urgent(Carbon|CarbonImmutable|string|null $start, ?CarbonImmutable $now = null): bool;
}
```

- [ ] **Step 1: Write the failing tests** in `tests/Unit/Booking/TaskBookingSummaryTest.php` (no database):

```php
it('writes the window in the booking zone', ...)
    // window('2026-10-22T12:00:00Z', '2026-10-22T16:00:00Z', 'America/Toronto') -> 'Thursday October 22nd 08:00-12:00'
    // window(null, '2026-10-22T16:00:00Z', 'America/Toronto') -> null
it('calls a start under two hours away urgent', ...)
    // now 2026-10-19T12:00:00Z: urgent('2026-10-19T13:00:00Z') true; urgent('2026-10-19T15:00:00Z') false; urgent('2026-10-19T11:00:00Z') false; urgent(null) false
```

- [ ] **Step 2: Run them to see them fail.**

- [ ] **Step 3: Implement `TaskBookingSummary`.** Move the body of the page's `infolist()` into `schema()`, replacing `$this->record` with `$task` and the three helpers with the statics. Drop the commented-out "Base Value, Task Type, and Location" block and the "Right column" comments. `formatDuration` is `AgendaPresenter::formatDuration` directly. The `urgent` body mirrors `isAppointmentUrgent` (diff in minutes from now, positive and under 120).

- [ ] **Step 4: Run the unit tests**: pass.

- [ ] **Step 5: Make the page delegate.** `infolist(Schema $infolist): Schema` becomes `return TaskBookingSummary::schema($infolist, $this->record, $this->timeZone());`. `formatAssignment` (the blade calls it) and `book()` (previousBooking window) call `TaskBookingSummary::window(...)`. Delete `formatAppointmentWindow`, `formatDuration`, `isAppointmentUrgent` and the now-unused imports (`Carbon`, `TextEntry`, `Grid`, `Group`, `Section`, `Heroicon`, `TaskStatus` only if nothing else on the page uses them; check before removing).

- [ ] **Step 6: Run the booking suite and the full suite**: `php artisan test tests/Feature/AppointmentBookingPageTest.php tests/Unit/Booking`, then `php artisan test` (ExampleTest is a known failure, nothing else may fail). Record the page's line count in the report.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/TaskResource/Schemas/TaskBookingSummary.php tests/Unit/Booking/TaskBookingSummaryTest.php app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php
git commit -m "Booking page: task summary infolist moves into TaskBookingSummary"
```
