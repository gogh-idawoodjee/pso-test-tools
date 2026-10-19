# Booking agenda grid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the booking agenda's period list with a day-column grid (Outlook week style) and add a dataset-zone / UTC switch.

**Architecture:** `AgendaPresenter::grid()` turns the services offers block into render-ready columns and an hour axis; the page holds `showUtc` and delegates; the blade draws the grid with absolute-positioned blocks. Requests and booking logic are untouched.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Alpine, Pest.

**Spec:** `docs/superpowers/specs/2026-10-19-agenda-grid-design.md`

## Global Constraints

- Plain English, no em dashes, in every label.
- Offsetless datetimes from services are UTC (`AgendaPresenter::utcMoment`).
- Booking behaviour unchanged: `book()`, check-then-accept, expiry, release on leave, `#[Locked]` state.
- Pint clean; full suite green except the pre-existing `ExampleTest` failure; `git add` explicit paths.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Review Focus

- A window that crosses midnight in the display zone (e.g. 22:00 to 02:00 UTC shown in UTC): block belongs to its start day and is clipped at the column bottom, not lost.
- Offers on seven days: seven columns, no overflow of the section; sideways scroll on a narrow viewport.
- UTC switch on with a Regina dataset: the same offer moves column when its start crosses the date line (14:00 UTC on Oct 22 is 8:00 AM Oct 22 Regina; 03:00 UTC on Oct 23 is 9:00 PM Oct 22 Regina).
- Detail mode shows "Allocated 8:42 AM" with no date when the allocation is on the column's day, and "Oct 23 8:42 AM" when it is not.
- Call centre mode hides invalid offers; detail mode shows them greyed with "Not available" and no Book button.

---

### Task 1: AgendaPresenter::grid

**Files:**
- Modify: `app/Support/Booking/AgendaPresenter.php` (add `grid`, remove `groups` and `periodOfDay`)
- Test: `tests/Unit/Booking/AgendaPresenterTest.php` (replace the `groups` and `periodOfDay` tests)

**Interfaces:**
- Produces: `public function grid(array $offers, string $mode, string $timeZone): array` returning
  ```php
  [
    'axis' => ['startHour' => 8, 'endHour' => 20, 'labels' => ['8 AM', '9 AM', ..., '8 PM']],   // whole hours, from floor(earliest start) to ceil(latest end) across the shown offers; [] when no offers
    'columns' => [ // keyed by Y-m-d in the display zone, ascending
      '2026-10-22' => [
        'label' => 'Thu Oct 22',
        'blocks' => [[
          'id' => 12, 'valid' => true, 'best' => false,
          'times' => '8:00 AM to 12:00 PM',
          'top' => 0.0, 'height' => 33.3,            // percent of the axis span; height clipped at 100 - top
          'lane' => 0, 'lanes' => 1,                  // side-by-side placement for overlapping blocks
          'value' => '950', 'resource' => 'R-17',     // detail fields; value rounded, 'no resource' when null
          'allocated' => '8:42 AM',                   // or 'Oct 23 8:42 AM' when not on the column's day; null when none
        ]],
      ],
    ],
  ]
  ```
  Rows come from `allOfferValues` merged with `validOffers` (same merge as today's `groups`), `valid` false for offers not in `validOffers`; mode `callcentre` drops invalid rows before anything else. Times use the given `$timeZone`, formatted `g:i A`, day labels `D M j`. `best` as today (isBestOffer or bestOffer.id).
- Consumes: nothing new.

- [ ] **Step 1: Write the failing tests** (replace the `groups`/`periodOfDay` ones; keep the helper): one valid and one invalid offer on Oct 22 UTC 14:00 to 18:00 and 18:00 to 22:00, zone America/Regina: axis 8 to 16, two blocks in `2026-10-22` with `times` "8:00 AM to 12:00 PM" / "12:00 PM to 4:00 PM", tops 0 and 50, heights 50; call centre mode has one block; UTC zone gives axis 14 to 22 and the same day; an offer at `2026-10-23T03:00:00` to `07:00:00` UTC lands in `2026-10-22` in Regina and in `2026-10-23` in UTC; overlapping valid offers get lanes 0 and 1 of 2; `allocated` with and without the date; empty offers give `['axis' => [], 'columns' => []]`.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement `grid`; delete `groups` and `periodOfDay`.**
- [ ] **Step 4: Run the unit file, expect pass** (the page test still references `agendaGroups`; it is fixed in Task 2, so run only the unit file here).
- [ ] **Step 5: Commit** `Booking agenda: presenter builds a day grid`.

### Task 2: Page and blade

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`public bool $showUtc = false`; `displayTimeZone(): string`; `timeZoneLabel(): string` giving "America/Toronto (EDT)" from the dataset zone and the selected day's abbreviation, or "UTC"; `agendaGrid(): array` delegating to `grid($this->offers, $this->mode, $this->displayTimeZone())`; remove `agendaGroups`)
- Modify: `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php` (agenda section per the spec: pill + switch in the header next to the countdown, axis column, day columns with absolutely positioned blocks, `overflow-x-auto` wrapper, the Book button and `x-on:click="released = $wire.appointmentRequestId"` kept on the block, the whole valid block clickable)
- Test: `tests/Feature/AppointmentBookingPageTest.php` (the `agendaGroups` assertion becomes `agendaGrid`; add: the pill shows the dataset zone and abbreviation; toggling `showUtc` makes the pill read "UTC" and the block times change; a seven-day response renders seven column headers)

**Interfaces:**
- Consumes: `AgendaPresenter::grid` from Task 1.

- [ ] **Step 1: Write the failing page tests.**
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement page and blade.** Use the pso CSS variables already in the blade for colours; hour row height is the builder's call (about 3rem). The switch is a Filament toggle bound to `showUtc` with `wire:model.live`, or a styled checkbox; label "Show in UTC".
- [ ] **Step 4: Run the page test file, then the full suite.**
- [ ] **Step 5: Commit** `Booking agenda: day grid with a dataset zone or UTC switch`.
