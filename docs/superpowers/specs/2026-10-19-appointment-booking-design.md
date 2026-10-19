# Appointment booking: design

Issues #6, #9 (Appointment Booking part) in pso-test-tools; the accept rework in pso-services. Two repos, one
feature. Drafted with Ish 2026-10-19 from his PSO practice, which overrides the IFS guide where they differ
(section 9 lists where). Approved by Ish, 2026-10-19.

## 1. Goal

A working appointment booking flow in the test tools, end to end, the way Ish books with customers: find a
customer, create a task, open Appointment booking, see which days have slots, pick a day, pick a slot, book it,
and later rebook the same task. pso-services does the PSO work and is the system of record for every booking.
pso-test-tools sends small payloads and shows the result.

Success: a booking made from the page shows up in PSO as the original activity at status 0 with its SLA set to
the offer window under the booking SLA type, the temporary activity gone, and a rebooking of the same task does
the same again without ever touching the live booking until the new offer is accepted.

## 2. The PSO flow (Ish's practice)

Terms: the **real activity** is the customer's task, id for example `100`. The **temporary activity** is a copy
under a suffixed id (`100` + short code) that exists only to obtain offers.

1. **Request.** pso-services sends, as one CHANGE: the Appointment_Request pointing at the temporary activity, the
   temporary activity in full (Activity, Activity_Status, Location, Location_Region, Activity_Skill, Activity_SLA
   of the dataset's *appointment* SLA type). The real activity is not touched. On a rebooking it stays live at
   status 0 so its slot is never released while the customer deliberates.
2. **Offers.** PSO returns one Appointment_Offer per slot. Valid means `available` true; an `offer_value` of 0 is
   guaranteed unavailable. Both tests apply.
3. **Check (non-blocking requests only).** Appointment_Offer_Response with `input_updated` false; PSO answers with an
   Appointment_Summary whose `appointed` says whether the slot still holds. Never needed for blocking requests.
4. **Accept.** Two messages, in order. First CHANGE: Appointment_Offer_Response with `input_updated` true; the real
   activity in full at **status 0**, built from the stored temporary request with every id switched to the real id;
   an Object_Deletion of the real activity's previous Activity_SLA (when it had one; composite key activity_id,
   priority, sla_type_id, start_based); the new Activity_SLA of the *booking* SLA type with the offer window.
   Second CHANGE, sent only when the first got a 2xx: an Object_Deletion of the temporary activity with cascade on.
   The delete must not travel with the offer response, because PSO may apply the deletion first and cascade away
   the Appointment_Request the response refers to (Ish, 2026-10-19). One SLA per activity, always; the booking SLA
   replaces whatever was there. No Availability records, ever.
5. **Decline.** Appointment_Offer_Response with offer id -1, then delete the temporary activity. Unchanged.
6. **Expiry.** Fixed 5 minutes in pso-services, as today. Every template sets an offer expiry window in practice.
7. **After accept.** Nothing. There is no practical way to confirm the DSE scheduled it. The guide's advice is wrong.

The accept payload is always full, for first bookings and rebookings alike. A first booking needs it because every
child row references the temporary id; a rebooking may carry changed duration, description, region or skills,
which the temporary request already holds. One code path.

## 3. pso-services changes

### 3.1 Bugs to fix in accept (`AppointmentService::acceptAppointment`, `BookAppointmentActivity::finalize`)

- `Activity_Status.status_id` on the accepted activity is -1 (IGNORE, inherited from the temporary activity).
  Must be 0. This is the bug that makes a booking look successful while the DSE never schedules it.
- The accepted activity keeps the temporary activity's `Activity_SLA` with its `sla_type_id` and only moves the
  window. It must be the booking SLA type the caller names (3.2), and the previous SLA must be deleted (3.3).
- The temporary activity is left for the expiry job. Accept deletes it explicitly in a second message after the
  accept succeeds (section 2, step 4) and marks the row cleaned (`cleanup_datetime`, `required_manual_cleanup`
  false), so the job finds nothing to do. If the delete fails the row stays ACCEPTED with `required_manual_cleanup`
  true and the response says so.
- The row is marked ACCEPTED before the PSO send and stays ACCEPTED on a PSO error. Write ACCEPTED, the accepted
  offer and the booking SLA type only on a 2xx from the accept message; on any other outcome set FAILED (the enum
  already has it) and keep the offers so the caller can see what happened. The previous-SLA lookup (3.3) then
  never picks up a booking that did not land. (My call, from the builder check.)
- Drop the base_value multiplier (`acceptedValueMultiplier`, `accepted_value_multiplier` config,
  `BookAppointmentActivity` value raise, the two tests). Value is governed by the booking SLA type in Ish's practice,
  not by inflating base_value. Keep `base_value` as stored.

### 3.2 Accept request (`PATCH /appointment/{appointmentRequestId}`)

Payload stays small: environment block, `data.appointmentOfferId` and a new required
`data.bookingSlaTypeId` (string). Optional `data.previousSlaTypeId` for a real activity whose SLA was set outside
services (3.3). `acceptedValueMultiplier` is removed.

### 3.3 Services is the system of record

- New columns on `appointment_request`: `booking_sla_type_id` (string, nullable), `blocking` (bool, nullable: null
  when the request did not override the template), `real_activity_id` is the existing `activity_id`.
- On accept, the previous SLA to delete is found as: the caller's `previousSlaTypeId` if given; otherwise the
  `booking_sla_type_id` of the latest ACCEPTED row with the same `activity_id`, `base_url` and `dataset_id`; otherwise
  no Object_Deletion for an SLA (first booking: the real activity does not exist yet, so there is nothing to delete).
  Priority and start_based for the deletion are the values `Sla::make` writes (2, true).
- Services never queries PSO for state.

### 3.4 Blocking

`data.blocking` (bool, optional) on the request. When present it goes on the Appointment_Request as `blocking` and
is stored on the row. Check appointed on a row whose stored `blocking` is true returns 406 "Request is blocking; the
check step does not apply". When `blocking` is null, services does not know and allows the check.

### 3.5 Offer validity, time zone and repeated checks

Valid offer: `available` is true and `offer_value` is not 0. Today only the value is tested. Keep the camelCase
response shape; it is what the page will consume. Carry `available` through on every formatted offer.

Time zone (my call, from the builder check): `getAppointment` reads the caller's time zone with
`data_get($context->validated, 'timezone')`, one level too high, so it always falls back to the config default.
Read `data.timezone`. The page always sends the dataset's time zone (section 5.6). `windowDayEnglish` and the
time strings are what the month dots and the agenda grouping key on, so the zone must be the customer's.

Repeated checks (my call): a check is allowed while the row is UNACKNOWLEDGED or CHECKED, and each check
overwrites the appointed-check columns. Today the first check moves the row to CHECKED and a second check is
refused with 406, which would break "slot taken, pick another" (5.4).

### 3.6 The wide general template (month view)

A new request mode `data.surveyTemplate` (bool, default false). When true services ignores
`appointmentTemplateId` and sends its own template in the same message, with the request pointing at it:

- `Appointment_Template` id `PSOSVC_SURVEY_12H`, `day_pattern` YYYYYYY, one `Appointment_Template_Item` 08:00 to
  20:00, `appointment_window_length` PT12H, `granularity` PT15M, `minimum_delay` P0D, `start_based` true,
  `offer_expiry_window` PT5M, `blocking` false, `default_template` false. `number_of_slots` 21.
- `appointment_template_duration` P21D, `appointment_template_datetime` midnight of the caller's base day.
- Immediately after the offers come back, services declines the request (offer id -1) and deletes the temporary
  activity, so the survey never holds slots. The row is stored with status DECLINED and a `survey` flag so it is
  distinguishable in the log. The response is the normal offers block; the caller only needs one line per day.

Builder's call: whether the template definition lives in config or a stub class, as long as the id is stable and
the template is re-sent on every survey request (PSO treats a re-send with the same key as an update).

### 3.7 Known bug to fix while in there

`DeleteTempActivity` reads `baseUrl`, `datasetId`, credentials from the top of `service_api_input`, but the stored
JSON nests them under `environment`. Read with the `environment.` prefix. Cover with a test.

### 3.8 Out of scope in pso-services

Linked activities, displacement, utilisation, non-template (Availability based) requests, the gateway v2 URL.

## 4. pso-test-tools data model

All scoped to the dataset through what exists. No new SLA type table: `dataset_reference_values` with entity type
`sla_type` is the SLA type table.

- **Dataset settings** (`settings` JSON, the `DatasetSettings` DTO): `appointment_sla_type_id` (used on the
  temporary activity) and `default_booking_sla_type_id`. Both pick from the dataset's `sla_type` reference values.
  Shown on the dataset edit form under a new "Appointment booking" section.
- **Task type** (`task_type` reference value `attributes`): optional `booking_sla_type_id`. Falls back to the dataset
  default. Edited where task type attributes are edited today.
- **Appointment template** (`appointment_template` reference value `attributes`): `blocking`. The reference value
  sync reads it from PSO's Appointment_Template when the row is there (registry attribute in services, attribute key
  here), a manual edit overrides it, and when neither says anything the page treats the template as blocking,
  PSO's own default. (My call, from the builder check.) The upserter must merge local-only attribute keys
  (`blocking`, `booking_sla_type_id`) instead of freezing the whole row on `is_locally_modified`, or editing one
  of them stops the PSO-synced attributes updating.
- **Task**: `regions` and `skills`, arrays of PSO ids chosen from the dataset's `region` and `skill` reference
  values on the task form; `regions` defaults to the customer's region when the customer has one (Ish,
  2026-10-19). `appointment_request_id` (last accepted request, from services), `booking_sla_type_id` (what was
  booked with), `booked_at`. `appt_window_start` / `appt_window_finish` already exist and receive the accepted
  window. These drive the Rebook button and the "Booked" state on the task screen; services remains the record
  of truth.

Resolution shown on the page, with its origin: appointment SLA from the dataset; booking SLA from the task type,
else the dataset default. If either is unset the page says so and Get appointments is disabled.

## 5. The page (`TaskResource` page `AppointmentBooking`, route `/{record}/book`)

### 5.1 Entry

From the task view: **Appointment booking** when the task has no booking, **Rebook** when it has one. Identical
page; Rebook shows the current booking (window, SLA type, request id) in the task summary.

### 5.2 Settings row

Environment and dataset are locked to the task's dataset and its environment, shown but not selectable; the
send-to-PSO switch and credentials stay. (My call, from the builder check: the templates, SLA types, regions and
time zone all come from the task's dataset, and services keys the previous-SLA lookup by dataset, so sending a
task elsewhere would make a booking Rebook cannot find.) Template (the dataset's `appointment_template` values, keyed by pso id), with its
stored blocking flag shown and a per-request override toggle (null means "as the template"). Slot usage rule set.
Appointment SLA and booking SLA, read-only, each with where it came from. Input datetime override as today.

### 5.3 Month view (opens automatically)

On open the page sends a survey request (3.6) with the base day = today in the dataset's time zone, and renders
three weeks as a month grid starting on the current week. A day gets a dot when at least one of its offers is
valid; days with offers but none valid are greyed; days outside the 21 are blank. Loading state while the request
runs. If the survey fails the grid still renders, without dots, with the error shown on the page.

Clicking a day sets `appointmentTemplateDatetime` to that day at 00:00 in the dataset time zone, sends a real
request with the chosen template, and opens the agenda. The dots stay; a "back to month" control returns to them
without a new survey.

### 5.4 Agenda view

Heading: the day, template, slot length, countdown to expiry (from the services row's expiry, 5 minutes). Toggle:

- **Call centre**: valid offers only, grouped by period of day, best offer marked, a Book button on each.
- **Detail**: every offer, invalid ones greyed and unclickable, with value, prospective resource id, prospective
  allocation start, and the raw window. Best marked.

Book: on a blocking request (template flag, or override true) calls accept with the resolved booking SLA type. On a
non-blocking request calls check first; if `appointed` is true, calls accept; if false, shows "slot taken, pick
another" and leaves the other offers clickable. Decline is a button on the agenda ("Release offers"), and the page
declines on leaving the agenda for a new day or closing, so offers are not left to time out.

After accept: the task gets the window, SLA type, request id and booked time (section 4), a success panel shows the
summary from services, and the Book buttons disappear.

### 5.5 Response contract

The page reads the services response as it is (camelCase: `appointmentOffers.validOffers`, `invalidOffers`,
`bestOffer`, `windowDayEnglish`, `windowStartTime`, …). Today's blade reads snake_case keys that never existed;
replace it.

### 5.6 Request payload (fixes #6)

All datetimes sent to services are UTC wall time without an offset, format `Y-m-d\TH:i:s`, because PSO reads
offsetless times as UTC: take midnight of the base day in the dataset's time zone and convert it to UTC before
formatting (Toronto midnight is `05:00:00` or `04:00:00` UTC). `appointmentBaseDateTime` (not
`appointmentBaseDate`) and `appointmentTemplateDatetime` = that converted midnight. SLA start = the same, SLA end
= base day + 21 days converted the same way. `appointmentTemplateDuration` 21 (days, as services interprets it).
`slaTypeId` = the resolved appointment SLA type. `timezone` = the dataset's time zone, always. `regions` and
`skills` from the task. `blocking` only when the override is set. `surveyTemplate` true for the month request.
`inputDatetime` as today.

## 6. Tests

pso-services: accept builds status 0, the booking SLA, the two deletions and no multiplier; previous SLA lookup
(caller, latest accepted row, none); blocking stored and the check refused on a blocking row; survey request sends
the template, declines and cleans up; offer validity uses both fields; `DeleteTempActivity` reads the nested
environment; the time zone is read from `data.timezone`; a second check is allowed after the first; the temporary
delete goes in a second message only after a 2xx; ACCEPTED is written only on a 2xx and FAILED otherwise. The
existing 20 appointment tests stay green except the multiplier ones, which go.

pso-test-tools (closes the Appointment Booking part of #9): payload keys and formats for the survey request and
the day request; SLA resolution with and without a task type override; blocking from template and override; the
page shows dots from a faked survey; check-then-accept on non-blocking, accept-only on blocking; the task is
updated after accept; `inputDatetime` present only when set.

## 7. Delivery order

1. pso-services: 3.1 to 3.7, tests, deploy. Nothing in the page works without 3.1.
2. pso-test-tools data model (section 4), the task form (regions, skills), the dataset and task type forms, the
   template sync attribute.
3. The page, month view then agenda, then tests.
3. Status issue #47 and pso-services issue #37 (Activity SLA delete) updated; #6 closed; #9 closed.

## 8. Left to the builder

Component layout inside Filament, the month grid implementation, where the survey template definition lives in
services, exact copy, how the task summary presents the current booking.

## 9. Where this differs from the IFS guide, on purpose

- No Availability record of type ACCEPTED_APPOINTMENT; the Activity_SLA is the constraint.
- No activity type switch or value raise on accept; the booking SLA type carries the value.
- The accepted activity is the real one, not the one the request was made for; the temporary one is deleted.
- No post-accept DSE check.
- Offer value 0 counts as unavailable in addition to the `available` flag.
