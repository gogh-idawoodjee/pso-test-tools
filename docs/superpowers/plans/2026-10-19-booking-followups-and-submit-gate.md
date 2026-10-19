# Booking follow-ups and live action submit gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close issues #48 (booking page follow-ups) and #46 (submit buttons disabled until the required inputs are filled) in pso-test-tools.

**Architecture:** The booking page keeps its shape; a release-on-close path is added as a tiny POST route hit by `navigator.sendBeacon`, backed by a support class that does what the page's `declineOpenRequest` does without Livewire. The agenda presenter leaves the page class. The live action pages get one shared gate helper on `FormTrait`.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Pest.

**Spec:** `docs/superpowers/specs/2026-10-19-appointment-booking-design.md` (section 5.4) and GitHub issues gogh-idawoodjee/pso-test-tools#48 and #46.

## Global Constraints

- Plain English, no em dashes, in every label and message.
- No key or export committed. `git status` before every commit.
- Pint clean (`vendor/bin/pint --dirty`), full suite green except the pre-existing `ExampleTest` failure (`php artisan test`).
- The booking page's behaviour from the spec stays: 5 minute expiry, check-then-accept on non-blocking, survey on open.

## Review Focus

- Closing the tab on the agenda: a DELETE for the open request reaches services; a tab closed on the month view sends nothing.
- A failed accept that is not a 406/expired reply: the offers stay releasable, and leaving the agenda still sends the DELETE.
- Creating a template from the booking page with Blocking off: the stored row has `blocking_override` false, and the next day request is non-blocking.
- A live action page with the environment chosen but one required field blank: the submit button is disabled; filling the last field enables it without clicking.
- Regina dataset zone with the app in Toronto: the agenda day label and the booked window read in Regina time.

---

### Task 1: Agenda presenter out of the page class

**Files:**
- Create: `app/Support/Booking/AgendaPresenter.php`
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`agendaGroups`, `withEnglishWindow`, `periodOfDay`, `slotLength`, `formatDuration` move; the page keeps thin delegating methods the blade calls)
- Test: `tests/Unit/Booking/AgendaPresenterTest.php`

**Interfaces:**
- Produces: `final class AgendaPresenter { public function __construct(private string $timeZone) {} public function groups(array $offers, string $mode): array; public static function periodOfDay(mixed $startTime): string; public function slotLength(array $offers): ?string; public function withEnglishWindow(array $row): array; }` with exactly the behaviour the page has today (read it first; keep outputs byte for byte).

- [ ] **Step 1: Write unit tests** for `groups` (callcentre hides invalid offers, detail keeps them greyed), `periodOfDay` (morning, afternoon, evening boundaries as the page defines them), `withEnglishWindow` for a Regina zone (`America/Regina`, offsetless UTC input `2026-10-22T14:00:00` gives `8:00 AM`), `slotLength`.
- [ ] **Step 2: Run, expect failure (class missing).**
- [ ] **Step 3: Move the code**, page delegates. Existing `AppointmentBookingPageTest` must still pass unchanged.
- [ ] **Step 4: Run `tests/Unit/Booking` and `tests/Feature/AppointmentBookingPageTest.php`, expect pass.**
- [ ] **Step 5: Commit** `Booking page: agenda presenter in its own class`.

### Task 2: Release offers on leaving or closing the page

**Files:**
- Create: `app/Support/Booking/OfferRelease.php`
- Create: `app/Http/Controllers/ReleaseAppointmentOffersController.php`
- Modify: `routes/web.php` (POST `/tasks/{task}/release-offers`, name `tasks.release-offers`, `auth` middleware; CSRF via `_token` in the beacon body)
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`declineOpenRequest` uses `OfferRelease`; after a failed accept, only mark expired when services answered 406 or the error text says expired, else leave the request open)
- Modify: `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php` (Alpine: on `pagehide` and on `livewire:navigating`, when `$wire.appointmentRequestId` is filled and `$wire.expired` is false, `navigator.sendBeacon(route, FormData{_token, appointment_request_id})`)
- Test: `tests/Feature/ReleaseAppointmentOffersTest.php`, `tests/Feature/AppointmentBookingPageTest.php`

**Interfaces:**
- Produces: `final class OfferRelease { public function __construct(private Task $task) {} /** @return array{ok: bool, error: ?string} */ public function release(string $appointmentRequestId): array; }`. It authenticates to PSO with the task's dataset environment the way `PSOInteractionsTrait::prepareTokenizedPayload` does (reuse the trait on the class, setting `selectedEnvironment`, `selectedDataset`, `environment_data`), and sends `DELETE appointment/{id}` with `['data' => ['appointmentRequestId' => $id]]` plus `environment_payload_data()` through `sendToPSONew(..., isNonStandardResponse: true, timeout: AppointmentBooking::appointmentTimeout())`. Notifications from the trait are harmless outside Livewire; if they throw, guard them.
- Controller: validates `appointment_request_id` (string, max 64), authorises `$task->dataset->environment->user_id === auth()->id()`, calls `OfferRelease`, returns 204. Never throws to the beacon.

- [ ] **Step 1: Failing tests.** `ReleaseAppointmentOffersTest`: (a) owner posts with a request id, Http::fake sees one DELETE to `.../api/v2/appointment/{id}`, response 204; (b) another user's task gives 403 and nothing sent; (c) guest redirected. `AppointmentBookingPageTest`: (d) a failed accept with a 500 reply leaves `appointmentRequestId` set and `expired` false, and `backToMonth` then sends the DELETE; (e) a failed accept with a 406 reply marks expired and `backToMonth` sends nothing.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement** per Interfaces. Blade: the Alpine block sits on the agenda wrapper; the route URL comes from `route('tasks.release-offers', $this->record)`; `_token` from `csrf_token()`.
- [ ] **Step 4: Run the two feature files, expect pass.**
- [ ] **Step 5: Commit** `Booking page: offers released when the tab closes or the page is left; failed accept keeps the request releasable`.

### Task 3: Locked state, template blocking from the create form, quieter toasts, seed transaction

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` (`#[Locked]` on `requestBlocking`, `offers`, `appointmentRequestId`, `expired`, `countdownExpiresAt`, `booking`; `createManualReferenceValue` passes blocking through; `sendAppointment` suppresses the trait's success toast)
- Modify: `app/Models/DatasetReferenceValue.php` (`createManual` accepts `blocking_override` in `$data` for templates and calls `setBlockingOverride`)
- Modify: `app/Traits/PSOInteractionsTrait.php` (a `protected bool $quietSuccess = false` honoured by the success branch of `sendToPSONew`; errors still notify)
- Modify: `app/Console/Commands/SeedBookingCustomers.php` (wrap the create loop in `DB::transaction`)
- Test: `tests/Feature/AppointmentBookingPageTest.php`, `tests/Feature/SeedCustomersCommandTest.php`

- [ ] **Step 1: Failing tests.** (a) creating a template through the page's create option with `blocking_override` false stores a row where `blockingDefault()` is false and `hasBlockingOverride()` is true; (b) a day request on the page does not produce a success notification (`assertNotNotified()` or check Filament's notification container); (c) seed command: when the second insert fails (mock or a unique conflict), no rows remain; (d) slug numbering continues after a kept row (`sample-<id>-3` exists, next run creates `-4`).
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.** For `#[Locked]`, every write stays server side already; if a test sets one of these via `->set()`, change the test to drive the page through its public methods instead.
- [ ] **Step 4: Run the two files, then the full suite.**
- [ ] **Step 5: Commit** `Booking page: locked request state, template blocking from the create form, no success toast per request; seed command transactional`.

### Task 4: Live PSO actions: submit disabled until the required inputs are filled (#46)

**Files:**
- Modify: `app/Traits/FormTrait.php` (add `protected function requiredForSubmit(): array { return []; }` and `public function canSubmit(): bool`)
- Modify: `app/Filament/Pages/Activity/GenerateActivities.php`, `UpdateActivityStatus.php`, `DeleteActivities.php`, `app/Filament/Pages/Resource/ResourceEvent.php`, `ResourceUnavailability.php`, `ResourceUpdateShift.php`, `ResourceUpdateUnavailability.php`, `app/Filament/Pages/Modelling/ARPRegion.php`, `ARPResource.php`
- Test: `tests/Feature/LiveActionSubmitGateTest.php`

**Interfaces:**
- `canSubmit()`: true when `environment_data.environment_id` and `environment_data.dataset_id` are filled and every dotted path in `requiredForSubmit()` (relative to the page's data form state path, read with `data_get($this->{statePath}, $path)`) is filled. For repeaters, a path like `unavailabilities.0.unavailability_id` counts as "at least one row with that field".
- Each page: `requiredForSubmit()` lists exactly the fields that carry `->required()` in its form today (read each form; do not add or drop requirements). The submit `Action` gets `->disabled(fn (): bool => ! $this->canSubmit())`. Required fields that are not already `->live()` get `->live(onBlur: true)`.
- `GenerateCustomException` keeps its own gate; `GenericDelete` stays hidden until an object is picked.

- [ ] **Step 1: Failing test file** with one `it` per page: mount, assert the submit action is disabled (`TestAction::make('<action name>')` with `assertActionDisabled`; if Filament cannot resolve a schema action by name, assert `canSubmit()` false instead and say so in the report), set environment, dataset and every required field, assert enabled.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement** across the nine pages.
- [ ] **Step 4: Run the file, then the full suite.**
- [ ] **Step 5: Commit** `Live PSO actions: submit disabled until the required inputs are filled`.
