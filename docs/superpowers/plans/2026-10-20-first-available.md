# First Available Date Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A scheduled loop that asks PSO, per region × task type, for the first *possible* day (survey) and the first *bookable* day (the type's template + slot usage rule set), keeps every answer, and a board that shows the grid, the six-week day strip per cell, and the lead-time trend. ClickSchedule's "first available" report, on PSO.

**Architecture:** Probes are configuration rows (`availability_probes`) that each own one probe `Task` on a probe `Customer`, so the existing booking stack (`BookingPayloads`, `BookingClient`, `OfferRelease`, `BookingSlaTypes`) is reused unchanged. `ProbeRunner` performs survey → day request → release and writes one `availability_probe_runs` row. A scheduler command dispatches due probes to `RunAvailabilityProbeJob`. A Filament resource manages probes (with a Generate-matrix action); a custom page renders the board.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest, MySQL, Chart.js (Filament `ChartWidget`).

**Spec:** `docs/superpowers/specs/2026-10-20-first-available-design.md` — read it first; it has the reasons. Mockups: `docs/superpowers/specs/2026-10-20-first-available-mockups.html`.

## Global Constraints

- A probe never holds a slot: the day request is always sent with `blocking: false` and always released in a `finally`. Surveys need no release (services declines them).
- The survey always covers the whole horizon. The day request is sent once, from the first possible day, never chained.
- Jobs never retry (`tries = 1`); the next cycle is the retry.
- The queue has no logged-in user: load `Environment` without global scopes, as `SyncDatasetReferenceValuesJob` does.
- Probe customers and probe tasks are hidden from the customer list, task list and dashboard map by default; a probe task cannot be booked.
- Words: **possible** (survey) and **bookable** (template + rule). Never "available" in UI text.
- `ProbeRunner` depends only on the `AppointmentRequests` interface (spec §13); the Rearview product port re-implements that interface over the gateway and must not need to touch the runner.
- Follow `CLAUDE.md` conventions (no abbreviations, `app()` over `new`, explicit return types, Filament v5 namespaces).
- `php artisan test` green after every task; `vendor/bin/pint --dirty` before each commit.

## Review Focus

- `ProbeRunner`: the release in `finally`, `blocking` forced false, horizon coverage, `unknown` days, lead-day maths across month boundary and DST.
- Write-through from probe to task on every save, and that the task is deleted with the probe.
- The board query picks the latest **succeeded** run per probe and overlays the latest run's failure marker.
- Nothing in the probe resource or board calls PSO synchronously in a request except Run now's dispatch.

---

### Task 1: Flags and dataset settings

**Files:**
- Create: `database/migrations/2026_10_21_000001_add_is_probe_to_customers_and_tasks.php`
- Modify: `app/Models/Customer.php` (fillable `is_probe`, cast bool, `scopeProbes`, `scopeNotProbes`)
- Modify: `app/Models/Task.php` (cast `is_probe`, `P-` prefix in the `creating` hook when `is_probe`)
- Modify: `app/Support/DatasetSettings.php` (keys, constructor, `fromArray`, `toArray`: `probe_default_horizon_days` 42, `probe_default_cadence_minutes` 120, `probe_lead_pivot` `{green_upto: 2, amber_upto: 7}`, `probe_raw_retention_days` 30, `probe_max_concurrent` 2)
- Modify: `app/Models/Dataset.php` `settingsSchema()` — new "First available" section with those five fields
- Modify: `database/factories/CustomerFactory.php`, `TaskFactory.php` — `probe()` states
- Test: `tests/Unit/DatasetSettingsProbeDefaultsTest.php`, `tests/Feature/ProbeFlagsTest.php`

- [ ] **Step 1: Failing tests** — settings round-trip through `fromArray`/`toArray` with defaults; a probe task gets a `P-` friendly id; `Customer::probes()` / `notProbes()` scopes.
- [ ] **Step 2: Migration** — `boolean('is_probe')->default(false)->index()` on both tables.
- [ ] **Step 3: Implement** models, settings DTO, form section (follow the `region_pivot` fields for the pivot inputs: two `TextInput`s, `numeric`, `minValue(0)`).
- [ ] **Step 4: Run tests, pint, commit** — "Probe flags on customers and tasks, first-available dataset settings".

### Task 2: Task type probe defaults

**Files:**
- Modify: `app/Enums/ReferenceEntityType.php` — `localAttributeKeys()` for `TaskType` adds `probe_appointment_template_id`, `probe_slot_usage_rule_id`, `probe_skills`, `probe_duration`
- Modify: `app/Filament/Resources/DatasetReferenceValueResource.php` (or the schema class it uses) — "First available probe defaults" section, visible only for task types: template `Select` from the dataset's `appointment_template` values, rule set `Select` with a "None" option, skills multi-`Select`, duration `TextInput` numeric minutes
- Test: `tests/Feature/TaskTypeProbeDefaultsTest.php`

- [ ] **Step 1: Failing test** — editing the four keys does not set `is_locally_modified`, and a PSO sync afterwards still updates `priority`/`duration` (the upserter merges local keys; verify `ReferenceValuesUpserter` already does — it does for `booking_sla_type_id`).
- [ ] **Step 2: Implement** the keys and the form section. Options come from `DatasetReferenceValue::psoIdOptionsFor($datasetId, $type)`.
- [ ] **Step 3: Run tests, pint, commit** — "Task type probe defaults".

### Task 3: Probe and run models

**Files:**
- Create: `database/migrations/2026_10_21_000002_create_availability_probes_table.php`
- Create: `database/migrations/2026_10_21_000003_create_availability_probe_runs_table.php`
- Create: `app/Enums/ProbeRunStatus.php` (queued, running, succeeded, failed; `isTerminal()`; `HasLabel`, `HasColor`)
- Create: `app/Enums/ProbeRunTrigger.php` (scheduled, manual)
- Create: `app/Enums/BookableResolution.php` (found, none_in_span, no_template, not_attempted; `getLabel()`, `explanation(): string` used by the presenter)
- Create: `app/Enums/ProbeCadence.php` (manual 0, hourly 60, every two hours 120, every four hours 240, daily 1440; label + minutes)
- Create: `app/Models/AvailabilityProbe.php`, `app/Models/AvailabilityProbeRun.php`
- Create: `database/factories/AvailabilityProbeFactory.php`, `AvailabilityProbeRunFactory.php`
- Test: `tests/Unit/AvailabilityProbeEffectiveValuesTest.php`, `tests/Feature/AvailabilityProbeTaskSyncTest.php`

**Interfaces:**
- `AvailabilityProbe` columns per spec §4.4; relations `dataset`, `task`, `region`, `taskType`, `customer`, `runs`, `latestRun` (HasOne latest), `latestSucceededRun`; `effectiveTemplateId()`, `effectiveSlotUsageRuleId()`, `effectiveSkills()`, `effectiveDuration()` each returning `['value' => mixed, 'source' => 'probe'|'task type'|'none']`; `syncTask(): Task` creates or updates the probe task (`is_probe`, `customer_id`, `task_type_id`, `regions`, `skills`, `duration`, `name` = label); `scheduleNext(): void` sets `next_due_at`; global scope `DatasetOwnedModel`.
- `AvailabilityProbeRun` columns per spec §4.5; casts for json/date/datetime/enums; `scopeSucceeded`, `scopeLatestPerProbe` (subquery on max `requested_at` among succeeded).

- [ ] **Step 1: Failing tests** — effective-value fallbacks and sources (probe override, task type attribute, none; the `-` sentinel for "no rule set"); saving a probe creates a `P-` task with the right customer/type/regions/skills/duration and updates it on change; deleting the probe deletes its task; unique pair violation.
- [ ] **Step 2: Migrations** — unique index (`dataset_id`,`region_id`,`task_type_id`,`customer_id`); run indexes (`probe_id`,`requested_at`) and (`dataset_id`,`requested_at`); `raw` as `json` nullable.
- [ ] **Step 3: Implement** models, enums, factories. `syncTask()` in `saved` observer; `deleting` deletes the task.
- [ ] **Step 4: Run tests, pint, commit** — "Availability probe and run models".

### Task 4: ProbeRunner

**Files:**
- Create: `app/Support/Probes/AppointmentRequests.php` — the port (interface) the runner talks to: `survey(Task, BookingSlaTypes, CarbonImmutable $baseDay): array{offers: array, request_id: ?string}`, `dayRequest(Task, BookingSlaTypes, CarbonImmutable $baseDay, string $templateId, ?string $slotUsageRuleId): array{offers: array, request_id: ?string}` (always non-blocking), `release(Task, string $requestId): bool`. Spec §13: this is the seam the Rearview port re-implements over the gateway, so nothing services-specific leaks past it.
- Create: `app/Support/Probes/ServicesAppointmentRequests.php` — the implementation over `BookingClient`, `BookingPayloads`, `OfferRelease`; bound in a service provider
- Create: `app/Support/Probes/ProbeRunner.php` (depends on `AppointmentRequests`, never on `BookingClient` directly)
- Create: `app/Support/Probes/HorizonSurvey.php` (loops `survey()` over the horizon → `day_states`, `survey_request_ids`, last `latestWindowEnd`)
- Create: `app/Support/Probes/ProbeRunPresenter.php` (resolution sentence, lead formatting, window text — used by the board later)
- Test: `tests/Feature/ProbeRunnerTest.php` (use `PsoServicesFake::session()` and `bookingOffersResponse()` from the booking tests; add a `survey:` reply builder that places offers on chosen days)

**Interfaces:**
- `ProbeRunner::run(AvailabilityProbe $probe, AvailabilityProbeRun $run): void` — spec §5 steps 1–7 exactly.
- `HorizonSurvey::survey(Task $task, BookingSlaTypes $sla, CarbonImmutable $baseDay, int $horizonDays): array{day_states: array<string,string>, request_ids: list<string>}` — uses `BookingPayloads::survey()` and `BookingClient::send('appointment', …)`; next base from `BookingWindow::latestWindowEnd()` as the month Next does; days not covered are `unknown`.

- [ ] **Step 1: Failing tests** — (a) survey only (no template): `no_template`, `first_possible_date` set, no day request sent; (b) survey + day request found: window/value stored, `released` true, `blocking` false asserted on the sent payload even when the template's `blockingDefault()` is true; (c) day request with no valid offers → `none_in_span`, still released; (d) 42-day horizon issues exactly two surveys and the second starts at the first's latest window end; (e) survey with no slots → `not_attempted`, no day request; (f) SLA types incomplete → failed before any HTTP call; (g) day request throws → run failed, release still attempted; (h) lead days: base day Oct 31 and possible day Nov 2 = 2; a probe in `America/Regina` on a Toronto DST-change weekend gives whole-day leads.
- [ ] **Step 2: Implement** `AppointmentRequests`, `ServicesAppointmentRequests` (uses `BookingClient` with `notify: false`), `HorizonSurvey`, `ProbeRunner`, `ProbeRunPresenter`. Tests of the runner's logic may bind a fake `AppointmentRequests`; the HTTP-faked tests cover `ServicesAppointmentRequests`. Record `template_id_used` / `slot_usage_rule_id_used`. Store `raw` as `['surveys' => [...replies], 'confirm' => reply]`.
- [ ] **Step 3: Run tests, pint, commit** — "ProbeRunner: survey, confirm under rule, release".

### Task 5: Job, dispatch and purge

**Files:**
- Create: `app/Jobs/RunAvailabilityProbeJob.php`
- Create: `app/Console/Commands/DispatchAvailabilityProbes.php` (`availability-probes:dispatch`)
- Create: `app/Console/Commands/PurgeAvailabilityProbeRuns.php` (`availability-probes:purge`)
- Create: `app/Support/Probes/StartProbeRun.php` (creates the run row with a trigger and dispatches; used by the command and by Run now)
- Modify: `routes/console.php` — `Schedule::command('availability-probes:dispatch')->everyFifteenMinutes()`, `Schedule::command('availability-probes:purge')->daily()->at('02:00')`
- Modify: `config/queue.php` only if a `probes` queue needs declaring for the worker (document the worker flag in README)
- Test: `tests/Feature/DispatchAvailabilityProbesTest.php`, `tests/Feature/RunAvailabilityProbeJobTest.php`, `tests/Feature/PurgeAvailabilityProbeRunsTest.php`

**Interfaces:**
- `RunAvailabilityProbeJob(string $runId)`: `ShouldQueue`, `ShouldBeUnique` (unique id = probe id), `timeout` 300, `tries` 1, queue `probes`; middleware honouring `probe_max_concurrent` per dataset (builder's primitive); marks running → `ProbeRunner` → terminal; updates the probe's `last_run_at` / `next_due_at` on both outcomes.
- `StartProbeRun::start(AvailabilityProbe $probe, ProbeRunTrigger $trigger): ?AvailabilityProbeRun` — returns null when the probe already has a queued/running run.

- [ ] **Step 1: Failing tests** — dispatch picks only enabled, due, non-manual probes; skips probes with an open run; sets `next_due_at` on dispatch so a slow worker does not double-dispatch; purge nulls `raw` past retention and leaves summary columns; job marks failed with the message when the runner throws.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Smoke test against Technodrome from tinker** — create one probe by hand, `StartProbeRun::start()`, watch the row land. Note the request ids and confirm in the task's booking-page history that the survey reads "Released" and the day request reads "Released".
- [ ] **Step 4: Run tests, pint, commit** — "Probe job, dispatch and purge commands".

### Task 6: Probe resource and Generate matrix

**Files:**
- Create: `app/Filament/Resources/AvailabilityProbeResource.php` + `Pages/{ListAvailabilityProbes,CreateAvailabilityProbe,EditAvailabilityProbe}.php`
- Create: `app/Filament/Resources/AvailabilityProbeResource/Schemas/ProbeForm.php`, `Tables/ProbesTable.php`
- Create: `app/Support/Probes/GenerateMatrix.php` (`generate(Dataset, array $regionIds, array $taskTypeIds): array{created: int, skipped_regions: list<string>, survey_only_types: list<string>}`)
- Modify: `app/Filament/Navigation/LivePsoActionPages.php` — add the three pages
- Modify: `app/Filament/Resources/CustomerResource` — `is_probe` toggle on the form, "Show probe locations" filter (default off), hidden by default; same filter on `TaskResource`; `CustomerMap` widget excludes probes
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php` `mount()` — probe task: notification "Probe tasks are not booked by hand" and redirect to the task
- Test: `tests/Feature/AvailabilityProbeResourceTest.php`, `tests/Feature/GenerateMatrixTest.php`, extend `AppointmentBookingPageTest` (probe task refused), `CustomerMapWidgetTest` (probes excluded)

- [ ] **Step 1: Failing tests** — form creates probe + task; location select limited to probe customers in the chosen region; Generate creates only missing pairs and reports skipped regions and survey-only types; Run now creates a manual run and dispatches; filters hide probes by default.
- [ ] **Step 2: Implement** per spec §6.1 and mockup screen 1. Table columns: label, region, task type, template (+ source badge), rule set, location, cadence, enabled (toggle), last run (relative), last bookable lead (coloured `TextColumn` badge), next due. Row actions: Run now, Edit, Enable/Disable, Delete. Bulk: Run now, Enable, Disable, Delete. Header: Generate matrix, Probe locations (link to the customer list with the filter on).
- [ ] **Step 3: Run tests, pint, commit** — "First-available probes resource".

### Task 7: The board

**Files:**
- Create: `app/Filament/Pages/FirstAvailableBoard.php` (group `Groups::ANALYSE`, route `/first-available`, title "First available date")
- Create: `resources/views/filament/pages/first-available-board.blade.php`
- Create: `app/Support/Probes/BoardQuery.php` (latest succeeded run per probe for a dataset, plus latest run status/error; grouped `[region][taskType] => list<cell>`; stat strip numbers)
- Create: `app/Support/Probes/BoardCell.php` (DTO: lead_bookable, lead_possible, state, stale, failed, count, dates, window)
- Create: `app/Filament/Widgets/ProbeLeadTrend.php` (`ChartWidget`, two lines over 30 days for one probe)
- Create: `app/Http/Controllers/Exports/ExportFirstAvailableController.php` (CSV of the matrix as shown)
- Test: `tests/Feature/FirstAvailableBoardTest.php`, `tests/Unit/BoardQueryTest.php`

- [ ] **Step 1: Failing tests** — latest succeeded run wins; a newer failed run shows the `!` marker and keeps the old numbers; stale when older than 2 × cadence; two probes in one cell → best lead and count 2; Possible toggle swaps the big number; CSV columns and one row per probe; dataset picker remembers `last_tool_context`.
- [ ] **Step 2: Implement** per spec §6.2 and mockup screens 2–3. Colour thresholds from `probe_lead_pivot`; region display names from `region_labels`. Cell states: number (green/amber/red), `–` grey, `rule` striped, `survey` outline, `!` red outline, dimmed stale, empty. `wire:poll.60s` only while runs are open. Drill-in as a Filament slide-over action with the strip, window, resolution sentence (`ProbeRunPresenter`), request ids linking to `/tasks/{task}/book`, Run now, and the trend widget.
- [ ] **Step 3: Run tests, pint, commit** — "First available board".

### Task 8: Wrap up

- [ ] README: a "First available date" section — what it does, the two numbers, the queue worker flag (`--queue=default,probes`), the scheduler, the settings.
- [ ] `app/Filament/Widgets/ToolCatalogue.php`: add a card for the board (question: "Where can I first book each kind of work, per region?") if the catalogue lists pages, not only `Tool` enum cases; otherwise skip.
- [ ] Final full test run, pint, commit — "First available: docs and catalogue".
