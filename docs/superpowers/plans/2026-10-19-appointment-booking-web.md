# Appointment booking (pso-test-tools) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a task, open Appointment booking, see three weeks of days with slots, pick a day, pick a slot, book it through pso-services, and rebook later.

**Architecture:** Data lives where the app already keeps it: dataset settings (JSON DTO), reference value attributes, columns on tasks. Pure resolution and payload building sit in small classes under `app/Support/Booking` so they are unit tested without Livewire. The Filament page `AppointmentBooking` holds the state machine (month, agenda, booked) with public Livewire methods the tests call directly, and a blade view that reads the pso-services response as it comes (camelCase).

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest 3, `Http::fake` for pso-services.

**Spec:** `docs/superpowers/specs/2026-10-19-appointment-booking-design.md`, sections 4 to 6. Requires the pso-services plan (`pso-services/docs/superpowers/plans/2026-10-19-appointment-booking-accept.md`) deployed first; the page talks to the new accept contract.

## Global Constraints

- Datetimes sent to services: UTC wall time, `Y-m-d\TH:i:s`, converted from midnight in the dataset's time zone (`Dataset::timeZone()`).
- Services response keys are camelCase: `appointmentOffers.validOffers`, `invalidOffers`, `bestOffer`, `allOfferValues`; offer keys `id`, `offerValue`, `available`, `prospectiveResourceId`, `prospectiveAllocationStart`, `windowStartDatetime`, `windowEndDatetime`, `windowDayEnglish`, `windowStartTime`, `windowEndTime`, `isBestOffer`.
- Services routes: `POST appointment` (request), `POST appointment/{id}` (check), `PATCH appointment/{id}` (accept), `DELETE appointment/{id}` (decline), all through `sendToPSONew`.
- Reference values are keyed by `pso_id`; never put a row uuid in a payload.
- Copy: plain English, "Appointment booking", "Rebook", "Release offers", no em dashes.
- `php artisan test` green at the end of every task.

## Review Focus

1. A dataset with no appointment SLA type set: the page must say which setting is missing and disable the request, not send `slaTypeId` null (Task 4 test, Task 6 test "disables the request when the SLA types are not set").
2. A task whose customer has no coordinates: services requires lat and long; the page must refuse with a message (Task 5 test "refuses to build a request without coordinates").
3. Clicking Book twice while the accept is in flight, or after the countdown expired: one accept at most, and an expired agenda shows "Offers have expired, search again" with Book disabled (Task 7 tests).
4. A survey that fails (services down): the month grid still renders without dots and shows the error text (Task 6 test).
5. A manual edit of a template's blocking flag must not stop the sync updating its label (Task 2 test "keeps PSO fields fresh on a row with only local attributes edited").

---

### Task 1: Dataset booking settings

**Files:**
- Modify: `app/Support/DatasetSettings.php` (KEYS, constructor, `fromArray`, `toArray`)
- Modify: `app/Models/Dataset.php` (settings form section: add a section "Appointment booking" with two selects)
- Test: `tests/Unit/DatasetSettingsTest.php` (extend if present, else create)

**Interfaces:**
- Produces: settings keys `appointment_sla_type_id` and `default_booking_sla_type_id`, both `?string`, DTO properties `$appointmentSlaTypeId`, `$defaultBookingSlaTypeId`. Form fields `settings.appointment_sla_type_id` and `settings.default_booking_sla_type_id`, `Select` with options `DatasetReferenceValue::psoIdOptionsFor($record?->id, ReferenceEntityType::SlaType)`, nullable.

- [ ] **Step 1: Test**

```php
it('round trips the two booking SLA settings', function () {
    $s = DatasetSettings::fromArray(['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'BOOKED']);
    expect($s->appointmentSlaTypeId)->toBe('Appointment')
        ->and($s->defaultBookingSlaTypeId)->toBe('BOOKED')
        ->and($s->toArray())->toMatchArray(['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'BOOKED']);
});
```

- [ ] **Step 2: Run, expect FAIL** ("Unknown dataset setting"). - [ ] **Step 3: Implement.** - [ ] **Step 4: Run** `php artisan test tests/Unit/DatasetSettingsTest.php`, PASS.
- [ ] **Step 5: Commit** `"Datasets: appointment and default booking SLA type settings"`

---

### Task 2: Local-only attributes on task types and templates

**Files:**
- Modify: `app/Enums/ReferenceEntityType.php` (`attributeKeys()`: AppointmentTemplate `['blocking']`; new `localAttributeKeys(): array`: TaskType `['booking_sla_type_id']`, others `[]`)
- Modify: `app/Support/ReferenceValues/ReferenceValuesUpserter.php` (`attributesFor` casts `blocking` to `?bool`; on update, merge: PSO keys overwrite, local-only keys kept from the current row; the `is_locally_modified` branch keeps all attributes but still refreshes `label` only when the row was not renamed on purpose. Simplest rule that satisfies the tests: a row counts as locally modified only when `pso_id` or `label` changed, or a PSO-sourced attribute key changed; `DatasetReferenceValue::updating` must therefore ignore local-only keys when deciding `is_locally_modified`)
- Modify: `app/Models/DatasetReferenceValue.php` (`updating` hook as above; `blockingDefault(): bool` on templates: `attribute('blocking') ?? true`)
- Modify: `app/Filament/Resources/DatasetReferenceValueResource.php::formSchema` (TaskType: `Select` `attributes.booking_sla_type_id` from the dataset's sla types, nullable, helper "Blank uses the dataset default"; AppointmentTemplate: `Toggle` `attributes.blocking` default true, helper "From PSO when synced; change it here if PSO is wrong"), and `table` columns for both
- Test: `tests/Feature/ReferenceValuesUpserterTest.php`, `tests/Unit/ReferenceEntityTypeTest.php`

**Interfaces:**
- Produces: `ReferenceEntityType::AppointmentTemplate->attributeKeys() === ['blocking']`, `ReferenceEntityType::TaskType->localAttributeKeys() === ['booking_sla_type_id']`; `DatasetReferenceValue::attribute('blocking')` bool|null, `attribute('booking_sla_type_id')` string|null.

- [ ] **Step 1: Tests**

```php
it('stores blocking from PSO on appointment templates', ...)        // row ['id'=>'STANDARD','description'=>'Std','blocking'=>false] -> attribute('blocking') false; true/absent handled
it('keeps booking_sla_type_id when PSO updates a task type', ...)   // local key survives an update of priority/base_value
it('keeps PSO fields fresh on a row with only local attributes edited', ...) // set booking_sla_type_id via update(), then sync changes label -> label updated, is_locally_modified false
it('still freezes a row whose PSO attribute was edited', ...)       // existing behaviour kept
```

- [ ] **Step 2 to 4** as before. - [ ] **Step 5: Commit** `"Reference values: template blocking from PSO, task type booking SLA kept locally"`

---

### Task 3: Regions, skills and booking state on tasks

**Files:**
- Create: `database/migrations/2026_10_20_000001_add_booking_columns_to_tasks_table.php`
- Modify: `app/Models/Task.php` (casts `regions` and `skills` array, `booked_at` datetime; `getForm` adds two multi `Select`s; `creating` defaults `regions` to `[customer->region->pso_id]` when empty and the customer has a region; `isBooked(): bool` = `appointment_request_id` filled)
- Test: `tests/Feature/TaskBookingColumnsTest.php`

**Interfaces:**
- Produces: tasks columns `regions` json nullable, `skills` json nullable, `appointment_request_id` string nullable, `booking_sla_type_id` string nullable, `booked_at` datetime nullable. Form fields `regions` and `skills` (options from `DatasetReferenceValue::psoIdOptionsFor($datasetId, Region|Skill)`, values are pso ids).

- [ ] **Step 1: Tests**

```php
it('defaults the task regions to the customer region pso id', ...)
it('keeps regions given on create', ...)
it('stores skills as pso ids', ...)
it('is booked once it has an appointment request id', ...)
```

- [ ] **Step 2 to 4** as before. - [ ] **Step 5: Commit** `"Tasks: regions, skills and booking state"`

---

### Task 4: SLA type resolution

**Files:**
- Create: `app/Support/Booking/BookingSlaTypes.php`
- Test: `tests/Unit/Booking/BookingSlaTypesTest.php`

**Interfaces:**
- Produces: `final readonly class BookingSlaTypes` with `public static function for(Task $task): self` and properties `?string $appointmentSlaTypeId`, `?string $bookingSlaTypeId`, `string $appointmentSource` ('dataset' or 'missing'), `string $bookingSource` ('task type', 'dataset' or 'missing'), `public function complete(): bool`, `public function missingMessage(): ?string` ("Set the appointment SLA type on the dataset." / "Set a booking SLA type on the task type or the dataset." / both joined with a space).

- [ ] **Step 1: Tests**: task type override wins; dataset default used when the task type has none; both missing yields `complete()` false and the combined message.
- [ ] **Step 2 to 4** as before. - [ ] **Step 5: Commit** `"Booking: SLA type resolution"`

---

### Task 5: Request payloads (fixes #6)

**Files:**
- Create: `app/Support/Booking/BookingPayloads.php`
- Test: `tests/Unit/Booking/BookingPayloadsTest.php`

**Interfaces:**
- Consumes: Task 4 `BookingSlaTypes`; `Task` with `customer` (lat, long), `taskType` (pso_id, attribute priority), `regions`, `skills`, `duration`, `base_value`, `friendly_id`; `Dataset::timeZone()`.
- Produces: `final class BookingPayloads` with
  - `__construct(Task $task, BookingSlaTypes $sla)`
  - `survey(CarbonImmutable $baseDay): array` and `day(CarbonImmutable $baseDay, string $templateId, ?string $slotUsageRuleId, ?bool $blocking, ?string $inputDatetime): array`, both returning `['data' => [...]]` with keys `activityId`, `activityTypeId`, `duration`, `baseValue` (int), `priority`, `lat`, `long`, `regions`, `skills`, `slaTypeId`, `slaStart`, `slaEnd`, `appointmentTemplateDuration` 21, `appointmentBaseDateTime`, `appointmentTemplateDatetime`, `timezone`; survey adds `surveyTemplate` true and no `appointmentTemplateId`; day adds `appointmentTemplateId`, `slotUsageRuleId` only when given, `blocking` only when not null, `inputDatetime` only when given.
  - `accept(int $offerId, ?string $previousSlaTypeId): array` → `['data' => ['appointmentOfferId' => $offerId, 'bookingSlaTypeId' => ..., 'previousSlaTypeId' => ...]]` (previous key only when given).
  - `check(int $offerId): array` → `['data' => ['appointmentOfferId' => $offerId]]`.
  - Throws `RuntimeException('The customer has no coordinates.')` when lat or long is null.
  - Date rule: `$baseDay` is a date in the dataset zone; `appointmentBaseDateTime` = `$baseDay->startOfDay()->utc()->format('Y-m-d\TH:i:s')`; `slaEnd` = `$baseDay->addDays(21)->startOfDay()->utc()` formatted the same.

- [ ] **Step 1: Tests** (dataset time zone `America/Toronto`, base day 2026-10-22)

```php
it('sends appointmentBaseDateTime as UTC midnight of the base day without an offset', ...) // '2026-10-22T04:00:00'
it('sends the sla window as 21 days from the base day', ...)                                 // slaStart '2026-10-22T04:00:00', slaEnd '2026-11-12T05:00:00' (DST ends 1 Nov)
it('builds a survey request without a template id', ...)
it('builds a day request with template, rule set, blocking and input datetime only when given', ...)
it('never contains a reference value uuid', ...)
it('refuses to build a request without coordinates', ...)
it('builds accept and check payloads', ...)
```

- [ ] **Step 2 to 4** as before. - [ ] **Step 5: Commit** `"Booking: request payloads in UTC with regions, skills and resolved SLA types"`

---

### Task 6: The page, month view

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`
- Modify: `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php`
- Modify: `app/Filament/Resources/TaskResource/Pages/EditTask.php` (header action "Appointment booking" or "Rebook" by `$record->isBooked()`, linking to `TaskResource::getUrl('bookAppointment', ['record' => $record])`)
- Test: `tests/Feature/AppointmentBookingPageTest.php` (replaces `AppointmentBookingPayloadTest.php`; keep its three dropdown tests)

**Interfaces:**
- Consumes: Tasks 4 and 5; `sendToPSONew('appointment', $payload)` returns a JSON string; `prepareTokenizedPayload`.
- Produces page state and methods:
  - `public string $view = 'month'|'agenda'|'booked'`; `public array $dayStates = []` keyed `Y-m-d` → `'slots'|'none'|'unknown'`; `public ?string $selectedDay`; `public ?array $offers` (the `appointmentOffers` block); `public ?string $appointmentRequestId`; `public ?string $countdownExpiresAt`; `public ?string $pageError`; `public ?bool $blockingOverride = null`.
  - `public function mount(int|string $record): void` locks `environment_data` to the task's dataset and environment (`selectedEnvironment`, `selectedDataset` set from `$record->dataset`), marks the two selects disabled, then calls `loadSurvey()` when `BookingSlaTypes::for($record)->complete()`, else sets `pageError` to the missing message.
  - `public function loadSurvey(): void`: survey payload for today in the dataset zone; on success fills `dayStates` for 21 days from `validOffers` (`windowDayEnglish` parsed in the dataset zone); on failure `pageError` = the services error text and every day `'unknown'`.
  - `public function selectDay(string $day): void` → declines any open request first (DELETE), then the day request, `view = 'agenda'`, `offers`, `appointmentRequestId`, `countdownExpiresAt` = now + 5 minutes.
  - `public function backToMonth(): void` declines the open request (if any) and shows the month.
  - `public function blockingForRequest(): bool` = `$blockingOverride ?? template attribute blocking ?? true`.

- [ ] **Step 1: Tests** (`Http::fake` on `*/api/v2/appointment*`)

```php
it('locks the environment and dataset to the task', ...)
it('loads the survey on open and marks the days that have slots', ...)   // fake validOffers on 22 and 23 Oct -> dayStates['2026-10-22'] 'slots', '2026-10-24' 'none'
it('shows the services error and no dots when the survey fails', ...)
it('disables the request when the SLA types are not set', ...)           // pageError contains "Set the appointment SLA type"
it('requests the chosen day with the real template and opens the agenda', ...) // Http::assertSent with data.appointmentTemplateDatetime '2026-10-22T04:00:00' and data.appointmentTemplateId 'STANDARD'
it('releases the previous day\'s offers before requesting another day', ...)  // selectDay twice -> one DELETE between the two POSTs
it('shows the Rebook action on a booked task', ...)
```

- [ ] **Step 2: Run, expect FAIL.** - [ ] **Step 3: Implement** page and view. Month grid: 7 columns, weeks starting Monday, 21 days from today, a filled dot for `'slots'`, grey cell for `'none'`, plain for `'unknown'`.
- [ ] **Step 4: Run** `php artisan test tests/Feature/AppointmentBookingPageTest.php`, PASS. - [ ] **Step 5: Commit** `"Appointment booking: month view from a survey request"`

---

### Task 7: Agenda, check, book, release

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`
- Modify: `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php`
- Test: `tests/Feature/AppointmentBookingPageTest.php`

**Interfaces:**
- Produces: `public string $mode = 'callcentre'|'detail'`; `public ?array $booking` (the `acceptedAppointmentSummary`); `public bool $expired = false`; `public ?string $slotMessage`.
  - `public function book(int $offerId): void`: refuse when `expired` or `view !== 'agenda'`; when `blockingForRequest()` is false, POST check first and on `appointment_summary.isSlotAvailable` false set `slotMessage` "That slot was taken, pick another" and return; then PATCH accept with `BookingPayloads::accept($offerId, $this->record->booking_sla_type_id)`; on success write `appointment_request_id`, `booking_sla_type_id`, `booked_at` now, `appt_window_start`/`finish` from the chosen offer's window, set `booking`, `view = 'booked'`.
  - `public function releaseOffers(): void`: DELETE decline, back to month.
  - `public function markExpired(): void` (called by the countdown) sets `expired` true.
  - View: agenda grouped by `windowDayEnglish` then period of day from `windowStartTime`; call centre shows `validOffers` with Book; detail shows `allOfferValues` merged with `validOffers`, greyed when not valid, with `offerValue`, `prospectiveResourceId`, `prospectiveAllocationStart`; best marked by `isBestOffer`; a countdown that calls `markExpired`; "Release offers" and "Back to month" buttons; a booked panel.

- [ ] **Step 1: Tests**

```php
it('accepts straight away on a blocking request', ...)                 // one PATCH, no POST check; task updated; view 'booked'
it('checks first on a non-blocking request and books when appointed', ...) // POST check then PATCH; bookingSlaTypeId in the PATCH body
it('does not book when the check says the slot is taken', ...)          // no PATCH; slotMessage set; view still 'agenda'
it('sends previousSlaTypeId on a rebooking', ...)                       // task booking_sla_type_id 'OLD' -> PATCH body data.previousSlaTypeId 'OLD'
it('refuses to book after the offers expired', ...)                     // markExpired then book -> no HTTP
it('books at most once', ...)                                           // second book call after 'booked' -> no second PATCH
it('releases the offers and returns to the month', ...)                // DELETE sent, view 'month'
it('renders unavailable offers greyed in detail mode', ...)
```

- [ ] **Step 2 to 4** as before. - [ ] **Step 5: Commit** `"Appointment booking: agenda, check, book and release"`

---

### Task 8: Wrap up

- [ ] **Step 1:** Delete `tests/Feature/AppointmentBookingPayloadTest.php` if Task 6 did not already fold it in. Run `php artisan test`. PASS.
- [ ] **Step 2:** Browser check list for Ish, written into the final PR description: dataset settings section, task type booking SLA, template blocking toggle, task regions and skills, month dots, agenda both modes, book on blocking and non-blocking, rebook.
- [ ] **Step 3:** Commit, then on Ish's word merge and push. Close #6, close #9, update #47.
