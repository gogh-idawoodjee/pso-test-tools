# First available date: design

The ClickSchedule "first available" report, rebuilt on PSO. Drafted with Ish 2026-10-20 from the existing
appointment booking flow (`docs/superpowers/specs/2026-10-19-appointment-booking-design.md`) and the
Appointment History work. pso-test-tools only; no pso-services change is required for v1 (section 9 lists two
optional ones).

Mockups: `docs/superpowers/specs/2026-10-20-first-available-mockups.html` (open in a browser; three screens).

## 1. Goal

Per region and per kind of work, show the first day a customer could be booked, and show *why* earlier days are
not available. Keep it current without anyone touching the page: a scheduled loop re-asks PSO every couple of hours
and keeps every answer, so lead time can be trended as a capacity KPI.

Success: a dispatch manager opens one page, sees a region × work-type grid of lead days for the dataset, clicks a
cell and sees the next six weeks as a strip of days (possible / bookable / none), the booked window PSO offered,
and how that lead time has moved over the last month. Nothing on the page holds a slot in PSO for longer than
the request round-trip.

## 2. The PSO mechanism (what already exists)

Everything the loop needs is in the booking stack today:

- **Survey request** (`BookingPayloads::survey()`, `data.surveyTemplate = true`): services substitutes its own
  template `PSOSVC_SURVEY_12H` (08:00–20:00, one 12 h window per day, 21 days, non-blocking, no slot usage rule),
  gets the offers, then declines and deletes the temporary activity itself. `AppointmentBooking::loadSurvey()`
  already turns the reply into `Y-m-d => 'slots' | 'none'` via `BookingWindow::offerDay()`. **A survey answers
  "could the ABE physically fit this work on that day" — it does not apply the work type's template or slot
  usage rule.**
- **Day request** (`BookingPayloads::day()`): one template + optional slot usage rule set, `blocking` overridable,
  21-day span from the base day. Valid offers come back under `appointmentOffers.validOffers`; the earliest
  `windowStartDatetime` is the first bookable instant under that rule. Must be released afterwards with
  `OfferRelease::release()`.
- **Reference values**: regions, skills, task types, slot usage rule sets, appointment templates and SLA types are
  mirrored per dataset in `dataset_reference_values` (`SyncDatasetReferenceValuesJob`). Slot usage rules are
  mirrored at the rule *set* level because that is the id `Appointment_Request` carries.
- **SLA resolution**: `BookingSlaTypes::for($task)` — appointment SLA from the dataset, booking SLA from the task
  type or dataset default. A probe needs both for the request to be accepted by services, same as a booking.

So a probe is: a survey (feasibility, wide net) followed by one day request (the real template and rule). Two
numbers per probe per cycle:

| Number | From | Meaning |
|---|---|---|
| **first possible** | survey | first day the engine could fit the work at all, ignoring slot rules |
| **first bookable** | day request | first day a customer could actually be offered under the work type's template and rule set |

The gap between them is the slot usage rule's cost in days. That gap is the explanation customers ask for most.

Terminology: **possible** and **bookable** are the words used everywhere (UI, columns, docs). Not "available",
which is ambiguous between the two.

## 3. Capacity counting (the "why" layer) — v2, not in this spec

The derived view from the saved schedule — allocated activities per region/type/day (any status: allocated is
allocated), template coverage by weekday, eligible resources by region + skill + availability — explains the
*none* days. It is a `Tool` in `app/Tools/` run against a dsScheduleData export, like Schedule review. It is out
of scope here so the probe loop ships on its own; section 8 notes the hooks to leave for it.

## 4. Data model

All scoped to the dataset through what exists (`DatasetOwnedModel`). UUID primary keys (`HasUuids`), matching
`Task`.

### 4.1 Probe locations: customers flagged as probes

`customers.is_probe` (boolean, default false, indexed). A probe customer is the representative address for one
region (a big rural region may have two). It has `region_id`, `lat`, `long`, optional `time_zone`, name like
"Probe · Regina North". Hidden from the customer list by default (a "Show probe locations" toggle filter), never
shown on the dashboard customer map. Created from the probe resource (section 6.1) or the customer form.

### 4.2 Probe tasks: tasks flagged as probes

`tasks.is_probe` (boolean, default false, indexed). One task per probe, created by the probe, never by hand.
Reuses the whole booking stack unchanged: `BookingPayloads`, `BookingClient`, `OfferRelease`, `BookingSlaTypes`
all take a `Task`. The task's `friendly_id` is prefixed `P-` instead of `T-` so the temporary activities are
recognisable in PSO and in services' history (`Task::booted()` creating hook: prefix from `is_probe`). Hidden
from the task list by default (same toggle filter). Never bookable: the Appointment booking page refuses a probe
task with a notice.

### 4.3 Task type defaults (local attribute keys)

Template and rule set are a function of the work type, not the region, so they live on the task type and the
probe only overrides. On `ReferenceEntityType::TaskType`, add local attribute keys (the mechanism
`localAttributeKeys()` already provides for `booking_sla_type_id`):

- `probe_appointment_template_id` — PSO id of an `appointment_template` reference value in the dataset
- `probe_slot_usage_rule_id` — PSO id of a `slot_usage_rule` reference value, nullable (no rule set)
- `probe_skills` — list of skill PSO ids the probe activity carries, nullable (none)
- `probe_duration` — minutes, nullable (falls back to the task type's PSO `duration`, then 60)

Edited where task type attributes are edited today (`DatasetReferenceValueResource`), in a "First available
probe defaults" section shown only for task types.

### 4.4 `availability_probes`

| column | type | notes |
|---|---|---|
| id | uuid | |
| dataset_id | uuid FK | |
| task_id | uuid FK tasks | the probe task, created with the probe, deleted with it |
| region_id | uuid FK dataset_reference_values | the matrix row |
| task_type_id | uuid FK dataset_reference_values | the matrix column |
| customer_id | uuid FK customers | the probe location; must be `is_probe` and in `region_id` |
| label | string | "Regina North · Install", generated, editable |
| appointment_template_id | string nullable | PSO id override; null = task type default |
| slot_usage_rule_id | string nullable | PSO id override; null = task type default. Sentinel `-` = "no rule set, even if the type has one" |
| skills | json nullable | override; null = task type default |
| duration | int nullable | minutes override |
| horizon_days | smallint | default from dataset settings (42) |
| cadence_minutes | smallint | default from dataset settings (120); 0 = manual only |
| enabled | bool | default true |
| last_run_at | datetime nullable | |
| next_due_at | datetime nullable | set on save and after each run; null when disabled or manual-only |
| notes | text nullable | |
| timestamps | | |

Unique on (`dataset_id`, `region_id`, `task_type_id`, `customer_id`). Model `AvailabilityProbe` with
`effectiveTemplateId()`, `effectiveSlotUsageRuleId()`, `effectiveSkills()`, `effectiveDuration()` resolving
override → task type attribute → fallback, each returning `['value' => …, 'source' => 'probe' | 'task type' | 'none']`
so the UI can say where a value came from (same pattern as `BookingSlaTypes`).

On create/update the probe writes through to its task: `customer_id`, `task_type_id`, `regions = [region pso id]`,
`skills = effectiveSkills()`, `duration = effectiveDuration()`. The task is the request; the probe is the config.

### 4.5 `availability_probe_runs`

One row per probe per cycle. Small rows kept forever; the raw replies are purged.

| column | type | notes |
|---|---|---|
| id | uuid | |
| probe_id | uuid FK | cascade delete |
| dataset_id | uuid FK | denormalised for the board query |
| status | enum `ProbeRunStatus` | queued, running, succeeded, failed |
| trigger | enum | scheduled, manual |
| base_day | date | today in the task's zone when the run started |
| horizon_days | smallint | copied from the probe |
| requested_at | datetime | |
| finished_at | datetime nullable | |
| day_states | json | `{ "2026-10-21": "slots", … }` for every day in the horizon, from the surveys |
| first_possible_date | date nullable | first `slots` day |
| first_bookable_date | date nullable | from the day request |
| first_bookable_window_start | datetime nullable | UTC, the offer's `windowStartDatetime` |
| first_bookable_window_end | datetime nullable | UTC |
| first_bookable_offer_value | decimal nullable | the offer's value, for later cost analysis |
| bookable_resolution | enum | `found`, `none_in_span` (day request returned no valid offer across its 21 days), `no_template` (probe has no effective template; survey only), `not_attempted` (survey found no possible day) |
| lead_days_possible | smallint nullable | `first_possible_date - base_day` |
| lead_days_bookable | smallint nullable | |
| survey_request_ids | json | services `appointmentRequestId`s of the surveys, in order |
| confirm_request_id | string nullable | the day request's id |
| released | bool | the day request's offers were released; false means a release failed and PSO will time them out |
| template_id_used | string nullable | the effective template at run time (defaults can change) |
| slot_usage_rule_id_used | string nullable | |
| error | text nullable | |
| raw | json nullable | survey replies and the day reply, as received; nulled by the purge after `probe_raw_retention_days` |
| timestamps | | |

Indexes: (`probe_id`, `requested_at`), (`dataset_id`, `requested_at`).

### 4.6 Dataset settings

Add to `DatasetSettings` (keys, constructor, `fromArray`, `toArray`) and the dataset form under a new
"First available" section:

- `probe_default_horizon_days` int, default 42 (two surveys)
- `probe_default_cadence_minutes` int, default 120
- `probe_lead_pivot` `{ green_upto: 2, amber_upto: 7 }` — board colouring; red above amber
- `probe_raw_retention_days` int, default 30
- `probe_max_concurrent` int, default 2 — simultaneous probe jobs per dataset (environment courtesy)

## 5. The run (`App\Support\Probes\ProbeRunner`)

A plain class, `run(AvailabilityProbe $probe, AvailabilityProbeRun $run): void`, called from
`RunAvailabilityProbeJob`. Every step records into `$run`; a failure anywhere marks the run failed with the
message and still attempts the release.

1. **Guard.** Probe enabled (or trigger manual). Task loaded with dataset and environment (without the user
   scope — the queue has no user; see `SyncDatasetReferenceValuesJob` for the pattern). `BookingSlaTypes::for()`
   complete, else fail with `missingMessage()`. Customer has coordinates, else fail.
2. **Base day.** Today at 00:00 in `$task->timeZone()`; `base_day` stored as the date.
3. **Survey the whole horizon.** `survey($baseDay)` → merge day states for its 21 days. If `horizon_days > 21`,
   survey again from the previous reply's `latestWindowEnd` (as `BookingWindow::nextMonthStart()` does for the
   month view), until the horizon is covered. Always the whole horizon, not "stop at the first slot": the day
   strip is the product, and the gap between possible and bookable is only visible with the full strip. Days
   the replies did not cover (a short last reply) are `unknown`.
4. **First possible** = first `slots` day, else null and `bookable_resolution = not_attempted`.
5. **Confirm under the rule.** If the probe has an effective template: `day(firstPossibleDay, template, rule,
   blocking: false, inputDatetime: null)`. The reply spans 21 days from that base, so the earliest valid offer's
   day is `first_bookable_date`; record its window and value, `found`. No valid offers at all: `none_in_span` and
   `first_bookable_date` null — do not chain further (it is already 21 days past the first possible day; that is
   the finding). No template: `no_template`, board shows the possible number only.
   `blocking` is always sent as `false` whatever the template says. A probe must never hold a slot.
6. **Release.** `OfferRelease::release()` on the day request's id, always, in a `finally`. Record `released`.
   A survey needs no release (services declines it).
7. **Lead days** from `base_day`. Save. Update the probe's `last_run_at`, `next_due_at = now + cadence`.

Services calls per probe per cycle: 2 surveys (42-day horizon) + 1 day request + 1 release. 300 probes every two
hours is ~1,200 calls a day per dataset; the concurrency cap keeps it polite.

Timeouts: `BookingClient::appointmentTimeout()` per call; job `timeout` 300 s, `tries` 1.

## 6. The pages

Three places. Mockups in the HTML file, screens 1–3.

### 6.1 Probe setup — `AvailabilityProbeResource` (sidebar group **Live PSO actions**, label "First-available probes")

List: label, region, task type, template (with source badge: probe / type), rule set, location, cadence, enabled,
last run, last bookable lead (coloured chip), next due. Filters: region, task type, enabled. Row actions: Run now
(creates a manual run and dispatches), Edit, Enable/Disable, Delete (deletes the task). Bulk: Run now, Enable,
Disable, Delete.

Header actions:

- **Generate matrix** — modal: dataset (locked to the current context), regions multi-select, task types
  multi-select. For every pair with no probe yet, create one using the region's probe customer and the type's
  defaults. Regions with no probe customer are listed in the result notice and skipped ("No probe location for:
  Yorkton, Estevan"). Types with no default template are created anyway (survey-only) and flagged in the notice.
- **Probe locations** — opens the customer list pre-filtered to `is_probe`, with a Create that pre-ticks the flag.
  Uses the existing map picker (`cheesegrits/filament-google-maps`) for lat/long.

Form (create/edit): Region → Location (probe customers in that region; "Add location" inline creates one) → Task
type → Overrides section (template, rule set with the "none" choice, skills, duration — each with helper text
showing the current effective value and its source) → Horizon, Cadence (select: manual only, hourly, 2 h, 4 h,
daily), Enabled, Notes. The "Live PSO actions" banner shows on this resource's pages since Run now talks to PSO.

### 6.2 The board — `FirstAvailableBoard` page (sidebar group **Analysis tools**, label "First available date")

Route `/first-available`. Dataset picker at the top like the tool wizard's Where step (user's last dataset
remembered via `last_tool_context`). Below it:

**Stat strip**: probes (enabled / total), last cycle finished (relative), median bookable lead, worst cell
(region · type, lead), probes failing.

**Matrix**: regions down (dataset `region_labels` for display names, PSO id as the fallback), task types across.
Cell = latest succeeded run per probe:

- big number: bookable lead in days ("0" = today, "3", "12"); colour from `probe_lead_pivot`
  (green ≤ green_upto, amber ≤ amber_upto, red above); hover shows the date and the window
- small number under it, only when it differs: possible lead ("possible 1") — the slot rule's cost at a glance
- states: **–** grey when the horizon had no possible day (nothing in 42 days); **rule** grey-striped when
  `none_in_span`; **survey** outline when `no_template` (shows the possible lead instead); **!** red outline when
  the latest run failed (hover shows the error, the cell keeps the previous success underneath); dimmed when the
  latest run is older than 2 × cadence (stale); empty when no probe exists for the pair
- toggle above the matrix: **Bookable** (default) / **Possible** — switches which number is big
- two probes for one region × type (two locations): the cell shows the better lead and a "2" badge; the
  drill-in lists both

Filters: region, task type, hide disabled probes. **Export CSV** of the matrix as shown (region, type, bookable
date, bookable lead, possible date, possible lead, resolution, run time).

**Drill-in** (slide-over, click a cell): probe label and effective config with sources; the horizon strip —
one chip per day, weekday letter and date, filled = possible, ring = bookable day, grey = none, hatched = unknown,
today marked; the first bookable window in the booking zone ("Thu Oct 29, 8:00 AM – 12:00 PM America/Regina");
resolution text in words ("No valid offer in the 21 days from Oct 23 under rule set STD_AM — the template or rule
set is closing every possible day"); request ids with a link to the probe task's booking page, whose history
section shows services' own record of the survey and day requests; **Run now**; and the **trend**: bookable and
possible lead over the last 30 days (two lines, Chart.js via a Filament chart widget), with the run count.

Refresh: `wire:poll.60s` on the matrix while any run for the dataset is queued or running; otherwise static with
a Refresh button.

### 6.3 Task type defaults — on `DatasetReferenceValueResource` (task types only)

Section "First available probe defaults" with template (select from the dataset's templates), rule set (select
plus "None"), skills (multi-select), duration (minutes). Helper text: "Used by every probe of this type unless the
probe overrides it."

## 7. Scheduling

- `availability-probes:dispatch` artisan command, scheduled `everyFifteenMinutes()` in `routes/console.php`.
  Selects enabled probes with `next_due_at <= now` (and `cadence_minutes > 0`), creates a queued run for each,
  dispatches `RunAvailabilityProbeJob` on queue `probes`. Idempotent: a probe with a queued or running run is
  skipped.
- `RunAvailabilityProbeJob(string $runId)`: `ShouldQueue`, `ShouldBeUnique` on the probe id, middleware
  `WithoutOverlapping` keyed by dataset with `probe_max_concurrent` as the lock count (Laravel's
  `RateLimited` / a `Redis::funnel` if the queue is Redis; builder's call, as long as the setting is honoured).
  Marks the run running → calls `ProbeRunner` → terminal status. Never retries (a retry would send another
  survey; the next cycle is the retry).
- `availability-probes:purge` daily: null `raw` on runs older than `probe_raw_retention_days`; delete runs of
  deleted probes (cascade handles it) — the summary rows themselves are never deleted.
- Run now from the UI creates a `manual` run and dispatches the same job; the board polls until it lands.

## 8. Hooks for v2 (capacity counting)

Leave these in place so the derived view can be dropped in without touching the loop:

- `day_states` keyed by ISO date, values `slots | none | unknown` — the derived tool adds a parallel map with
  reasons (`no_template_day`, `slot_full`, `no_resources`, `engine_declined`) keyed the same way, and the strip
  renders both.
- The drill-in's resolution text is produced by one presenter class (`ProbeRunPresenter::resolution()`), so the
  derived reasons slot into the same sentence.
- `first_bookable_offer_value` is stored even though v1 does not display it.

## 9. Optional pso-services changes (not required for v1)

- A survey request currently answers 21 days per call. A `surveyDays` parameter (up to 63) would make a 42-day
  horizon one call instead of two. Nice to have.
- The day request's reply does not say *why* a day has no valid offer. If services ever exposes
  `allOfferValues` with per-offer reasons from the ABE, the resolution text can use them.

## 10. Tests (Pest)

- `ProbeRunnerTest` with `Http::fake` on `*/api/v2/appointment*` using `bookingOffersResponse()` and
  `PsoServicesFake::session()` from the booking tests: survey only; survey + confirm found; confirm none in span;
  two-survey horizon; no template; SLA incomplete fails before any call; release runs when confirm throws;
  `blocking` is false on the day request whatever the template says; lead days cross a month boundary and a DST
  change in `America/Regina` vs `America/Toronto`.
- `AvailabilityProbeTest`: effective-value resolution and sources; write-through to the task; `P-` friendly id;
  unique pair; deleting the probe deletes the task.
- `GenerateMatrixActionTest`: creates only the missing pairs; reports regions without a probe location.
- `FirstAvailableBoardTest`: matrix picks the latest succeeded run and shows a failed run's marker on top of it;
  stale dimming; the Possible toggle; CSV export columns.
- `DispatchCommandTest`: due selection, skips probes with an open run, manual-only probes never dispatched.
- Existing `AppointmentBookingPageTest`: a probe task is refused.

## 11. Delivery order

1. Flags, settings, task type defaults (small, unblocks everything)
2. `availability_probes` + `availability_probe_runs`, models, factories
3. `ProbeRunner` + job + commands, with tests, runnable from tinker against Technodrome
4. Probe resource with Generate matrix
5. Board page: matrix, drill-in, trend, export
6. Hide probes from customer/task lists and the map; refuse booking a probe task

## 12. Left to the builder

- Whether the matrix is a Filament table with custom columns or a Blade grid. The mockup is a grid; a table is
  acceptable if the cell content (two numbers, state marks, badges) survives.
- The concurrency primitive for `probe_max_concurrent`.
- Chart widget wiring for the trend (Filament's `ChartWidget` is in the stack; `saade/filament-fullcalendar` is
  not needed here).

## 13. Decisions taken, with the reason

- **Probes reuse Task and Customer** rather than their own request model: `BookingPayloads`, `BookingClient`,
  `OfferRelease` and `BookingSlaTypes` all take a `Task`, and services records history by activity id, so the
  booking page's history section works for probes for free.
- **Survey always covers the whole horizon**, never stops early: the strip and the possible/bookable gap need it.
- **One day request, never chained**: 21 days of no valid offers past the first possible day is the answer, and
  chaining would double the cost for a number nobody acts on.
- **`blocking: false` forced**: a probe that holds slots would distort the very thing it measures.
- **Allocated, any status, is what consumes capacity** (for v2 counting): committed vs not only governs whether the
  DSE may still move the activity.
