# Booking agenda as a day grid

Date: 2026-10-19. Replaces the agenda view in section 5.4 of `2026-10-19-appointment-booking-design.md`.

## Intent

The agenda today lists offers under Morning / Midday / Afternoon / Evening headings, one day after another, with raw
ISO datetimes in detail mode. Ish wants it to read like Outlook's week view: one column per day, time running down,
each offer a block at its window, so a call centre agent sees the shape of the week at a glance.

## What the person sees

- **Columns**: one per calendar day that has at least one offer, in date order, left to right. A day request usually
  returns a week or less; the grid grows with what comes back and scrolls sideways on a narrow screen rather than
  squeezing. Each column header reads like "Thu Oct 22".
- **Time axis**: hour labels down the left ("8 AM", "9 AM", ...). The axis runs from the earliest window start to the
  latest window end across every offer shown, rounded out to whole hours, so the grid is as tall as the day needs and
  no taller.
- **Blocks**: each offer is a block in its day column, top at the window start and height proportional to the window
  length. The block shows "8:00 AM to 12:00 PM" in bold and the Best offer badge when it is the best. A valid block
  carries a Book button; the whole block is clickable to book. Two offers that overlap in the same column sit side by
  side.
- **Detail mode** adds, under the times: "Value 950" (whole number), "Resource R-17" (or "no resource"),
  "Allocated 8:42 AM" (the prospective allocation start, as a time; with the date only when it is not the column's
  day). No raw ISO strings anywhere.
- **Invalid offers** show as greyed blocks with a "Not available" badge and no Book button, in detail mode. Call centre
  mode hides them, as it does today, so the agent reads only what can be booked. (My call: Ish asked for greyed blocks
  in the columns; keeping them out of call centre mode keeps that view clean.)
- **Time zone pill** in the section header, next to the countdown: "America/Toronto (EDT)" from the dataset's zone and
  its abbreviation on the selected day. Next to it a small switch, "Show in UTC". On, the pill reads "UTC" and every
  time in the grid (axis, blocks, allocation start, the column day) is in UTC. The choice lasts for the page visit.
  Default is the dataset zone.
- The heading, description line (template, window length, blocking, summary), countdown, Call centre / Detail tabs,
  Release offers and Back to month buttons, the expired and slot-taken alerts, and the empty state stay as they are.

## Behaviour that does not change

Requests, booking, check-then-accept, expiry, release on leave: untouched. Only the presentation of the offers and
the new zone switch change.

## Where the code goes

- `app/Support/Booking/AgendaPresenter.php`: `groups()` and `periodOfDay()` are replaced by `grid(array $offers,
  string $mode, string $timeZone): array` returning the axis and the columns with blocks ready to render (times as
  text, top and height as percentages, flags). `slotLength`, `withEnglishWindow`, `formatDuration`, `utcMoment`
  stay.
- `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`: a public `bool $showUtc = false`, a
  `displayTimeZone(): string` (UTC when on, else the dataset zone), a `timeZoneLabel(): string` for the pill, and
  `agendaGrid()` delegating to the presenter. `agendaGroups()` goes.
- `resources/views/filament/resources/tasks/pages/appointment-booking.blade.php`: the agenda section renders the grid.
- Tests: unit tests for `grid` (columns by day in the display zone, axis bounds, block positions, detail fields,
  invalid hidden in call centre, UTC vs Regina), page tests for the pill text and the switch, existing booking tests
  unchanged.

## Left to the builder

Exact pixel height per hour, how overlapping blocks share a column's width, and the switch component (Filament
toggle or a plain checkbox styled as a switch).
