# Booking history, rebook and Previous Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement sections 1 (web side), 2 and 3 of the spec on the booking page.

**Architecture:** `BookingHistory` fetches and maps rows through `BookingClient`; the page gains history, rebooking and Previous state; the blade gains the section, banner, tags and buttons. Requests and accept logic stay as they are.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Pest.

**Spec:** `docs/superpowers/specs/2026-10-19-booking-history-rebook-previous-design.md`

## Global Constraints

- Plain English, no em dashes. Booking behaviour unchanged (check-then-accept, expiry, release on leave, locked state). Pint clean; suite green except the pre-existing `ExampleTest` failure. Stage explicit paths. Commit attribution `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Every time shown is in the booking zone (`bookingTimeZone()`), UTC when the switch is on for the agenda only.
- Offsetless datetimes from services are UTC.

## Review Focus

- History when services answers an error: the section shows the error text, nothing else breaks.
- A booked task whose window is before today: banner still shows, no day ringed (the day is outside the window).
- Rebooking the same window: the Current block has no Book button, so accept cannot be sent for it.
- Previous on the agenda when the reply starts in the past relative to now: floors at now, button disabled when that would repeat the current reply.
- Previous on the month when the window starts today: disabled.

---

### Task 1: Booking history

**Files:**
- Create: `app/Support/Booking/BookingHistory.php`
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`public bool $historyOpen = false`, `#[Locked] public ?array $history = null`, `#[Locked] public ?string $historyError = null`, `toggleHistory()`, `loadHistory()`; mount reads `?history=1` from the request to open it)
- Modify: `app/Filament/Resources/TaskResource/Pages/EditTask.php` (header link "History" to the booking page with `history=1`)
- Modify: `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php` (collapsible section at the bottom, loads on first open with a loading state)
- Test: `tests/Unit/Booking/BookingHistoryTest.php`, `tests/Feature/AppointmentBookingPageTest.php`

**Interfaces:**
- `final class BookingHistory { public function __construct(private Task $task) {} /** @return array{rows: list<array{when: string, what: string, outcome: string, offers: string, requestId: string, requestIdShort: string}>, error: ?string} */ public function fetch(): array; public static function rows(array $history, string $timeZone): array; }`. `fetch` calls `(new BookingClient($task))->send('appointment?activityId='.rawurlencode($task->friendly_id).'&datasetId='.rawurlencode($task->dataset->name), [], HttpMethod::GET)` (confirm how `BookingClient::send` handles GET with an empty payload; the trait sends GET when payload is null, so pass what makes it a GET) and maps `data.history` with `rows`.
- `rows` text, from the spec's table: when `M j, g:i A` in the zone; what: `Survey (wide net), 21 days from Oct 19` or `Offers, template <id>, <n> days from <day>[, rule set <id>][, blocking|not blocking]`; outcome per status: ACCEPTED `Booked <D M j, g:i A to g:i A zone>, SLA <booking> (previous <previous>)`, DECLINED `Released`, FAILED `Failed`, CHECKED `Checked, slot free|taken|unknown`, UNACKNOWLEDGED with expiry past `Expired, offers left to PSO`, else `Open`; a survey row always `Released`; offers `<n> returned, <m> valid`.

- [ ] **Step 1: Failing tests.** Unit: `rows` for the three statuses and the survey, Regina zone. Page: Http::fake the GET with two rows, `toggleHistory` loads once and renders the when/what/outcome texts; a services error shows its text; `?history=1` opens the section on mount; EditTask shows the History link.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the files, then the full suite.**
- [ ] **Step 5: Commit** `Booking page: history of every request for the task`.

### Task 2: Rebook

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`currentBooking(): ?array{day: string, label: string, start: string, end: string}` from the task's `appt_window_start/finish` in the booking zone; `#[Locked] public ?array $previousBooking = null` captured in `book()` before saving when the task was booked; `rebook()` from the booked panel to the month)
- Modify: `app/Support/Booking/AgendaPresenter.php` (`grid(..., ?string $currentStartUtc = null, ?string $currentEndUtc = null)`: a block whose window equals the current booking gets `'current' => true` and `'valid' => false` for the Book button's purposes while keeping its own styling flag)
- Modify: the blade (month banner + ringed day + Booked tag; agenda Current tag; booked panel Rebook button and Rebooked heading with the previous window struck through)
- Test: `tests/Unit/Booking/AgendaPresenterTest.php`, `tests/Feature/AppointmentBookingPageTest.php`

- [ ] **Step 1: Failing tests.** Presenter: the matching block has `current` true and renders no Book. Page: booked task mount shows the banner text and the ringed day; agenda tags the current block and has no `book(id)` for it; accept on a booked task sends `previousSlaTypeId` and the panel heading reads Rebooked with the old window.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the files, then the full suite.**
- [ ] **Step 5: Commit** `Booking page: rebook from the booked panel, current booking shown on the month and agenda`.

### Task 3: Previous

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`#[Locked] public ?string $cursorBack = null` = earliest window start of the open reply; `previousAgenda()`; `canGoPreviousAgenda(): bool`; `previousMonth()`; `canGoPreviousMonth(): bool`)
- Modify: the blade (Previous buttons left of Next on both views, disabled per the can* methods)
- Test: `tests/Feature/AppointmentBookingPageTest.php`

**Interfaces:**
- Agenda: `from = max(now, earliestStart - (latestEnd - earliestStart))`; `canGoPreviousAgenda` false when `cursorBack` is null or `from` equals the reply's template start (the request already began at now). Sends through `requestDay(dayOf(from), from as UTC Y-m-d\TH:i:s)`.
- Month: `windowStart = max(today, firstDay - 21 days)`, null when that is today; `canGoPreviousMonth` false when `windowStart` is null.

- [ ] **Step 1: Failing tests.** Agenda: reply Oct 20 8:00 to Oct 23 16:00 (zone Toronto) with now Oct 19 12:00; Previous asks from now (floored) and the day is Oct 19; a reply that already started at now has Previous disabled. Month: after Next, Previous returns to today and disables.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the file, then the full suite.**
- [ ] **Step 5: Commit** `Booking page: Previous on the agenda and the month`.
