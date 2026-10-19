# Booking history, rebook, and Previous

Date: 2026-10-19. Extends `2026-10-19-appointment-booking-design.md` and `2026-10-19-agenda-grid-design.md`.

## Intent

Three gaps on the booking page after today's walkthrough: nothing shows what PSO was told for a task, a booked task
dead-ends on the booked panel, and Next has no mirror.

## 1. Booking history

### What the person sees

A collapsible "Booking history" section at the bottom of the booking page, collapsed by default, loaded when opened.
One row per services appointment request for this task's activity in this dataset, newest first:

| When | What | Outcome | Offers | Request |
|---|---|---|---|---|
| Oct 19, 4:36 PM | Survey (wide net), 21 days from Oct 19 | Released | 9 returned, 6 valid | a3054c28 |
| Oct 19, 4:41 PM | Offers, template Half Day, 21 days from Oct 20, blocking | Booked Thu Oct 22, 8:00 AM to 12:00 PM, SLA INSTALL (previous Appointment) | 7 returned, 7 valid | a3054d10 |
| Oct 19, 4:39 PM | Offers, template Half Day, from Oct 20 | Expired, offers left to PSO | 7 returned, 7 valid | a3054cf2 |

- "What" comes from the request row: survey or template, days and base day, slot usage rule set, blocking.
- "Outcome" is the row's status in plain words: Booked (with the window in the booking zone and the SLA types),
  Released (declined by the page or the survey), Expired (PSO timed the offers out), Checked (non-blocking check
  done, slot free or taken), Failed (PSO rejected the accept), Open (offers still held). A survey always reads
  Released.
- Times in the booking zone (customer, else dataset). The request id is the first eight characters, full id in a
  tooltip, and the row links nowhere yet (the services summary endpoint is the next step if wanted).
- The EditTask page gets a "History" link in its header beside Rebook / Appointment booking, which opens the
  booking page with the history section expanded.

### Services

New `GET /api/v2/appointment` (index) taking `activityId` and `datasetId` as query parameters, same auth as the
other appointment routes, returning `data.history`: a list newest first, at most 50 rows, each with
`appointmentRequestId`, `createdAt` (ISO with offset), `status` (the enum label), `survey`, `blocking`,
`appointmentTemplateId`, `appointmentTemplateDatetime`, `appointmentTemplateDuration` (days), `slotUsageRuleId`,
`offersReturned`, `validOffers`, `acceptedOfferId`, `acceptedWindowStart`, `acceptedWindowEnd` (from the stored
accepted offer), `bookingSlaTypeId`, `previousSlaTypeId`, `appointedCheck` (`unknown` / `available` /
`unavailable` / null when not checked), `acceptDeclineDatetime`, `offerExpiryDatetime`, `requiredManualCleanup`.
No gateway call: this reads services' own table, which is the record of every booking.

## 2. Rebook

### What the person sees

- Opening the booking page for a booked task: the month shows a banner above the grid, "Booked: Thu Oct 22,
  8:00 AM to 12:00 PM America/Toronto EDT, SLA INSTALL" with the booked day ringed in the grid and a small
  "Booked" tag on it. The survey still runs so other days show their dots.
- The booked panel (after an accept) gets a "Rebook" button beside Back to month, which goes to the month view in
  that state.
- On the agenda, a block whose window equals the current booking is tagged "Current" and has no Book button.
- Accepting another offer sends the task's current booking SLA type as the previous type (the page already does),
  and the booked panel then shows "Rebooked" as its heading with the previous window struck through above the new
  one.
- The task's old window is kept nowhere else; the history section is the record.

## 3. Previous

- Agenda: a "Previous" button left of Next. It asks again with the template starting at the earliest window start
  of the current reply minus the span of that reply (latest end minus earliest start), never earlier than now.
  Example: the reply covers Oct 20 8:00 AM to Oct 23 4:00 PM (3 days 8 hours); Previous asks from Oct 17 12:00 AM
  floored to now. Disabled when that start would be now and the current reply already starts at now (nothing
  earlier to show).
- Month: "Previous" left of Next, three weeks back from the window's first day, floored at today; disabled when
  the window already starts today. Back to today stays.

## Where the code goes

- Services: `AppointmentController::index`, a `BookingHistoryResource` (one row), route, request validation
  (`activityId` and `datasetId` required strings), tests in `tests/Feature/Api/V2/AppointmentHistoryTest.php`.
- Tools: `app/Support/Booking/BookingHistory.php` (fetch through `BookingClient`, map rows to the table's text
  in the booking zone), the booking page (`historyOpen`, `loadHistory`, `history` rows, `rebooking` state,
  `previousAgenda`, `previousMonth`, `currentBookingLabel`), the blade (history section, banner, tags, buttons),
  `EditTask` header link. Tests: unit for `BookingHistory` mapping, page tests for each behaviour.

## My calls

1. History lives on the booking page, not a separate task tab, so it sits next to the thing it explains.
2. Services serves history from its own table, no PSO call, capped at 50 rows.
3. A block equal to the current booking is tagged "Current" and cannot be booked again.
4. Previous floors at now and at today; there is no history stack of pages.
