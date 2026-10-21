# Design: "System day" leg of the load audit

**Date:** 2026-10-20
**Status:** Approved 2026-10-20 evening for build; every open question settled (section 8)
**Repos:** pso-tools (the engine: a new module beside `load-audit/audit_load.py`, and the audit page gains a
tab) and pso-test-tools (three export kinds, one reference table, the tool's inputs and options).
Builds on `2026-10-10-load-audit-port-design.md` (the audit runs the Python from a checkout and stores the
page it rendered) and `2026-10-10-export-sets-design.md` (export files, kinds, tool runs).
Hand-off material: `docs/system-day-handoff/` (reference indexer and renderer, no customer data).

## 1. Problem

The load audit answers "is the data and the configuration right" from a LOAD, a plan and a SYS. It has
nothing to say about what the system *did* during the day: broadcasts that IFS Cloud refused, appointment
requests the ABE never answered, DSE errors, and the inputs the integration sent in the minutes around
each of those. Today that evidence is pulled by hand from four Workbench exports and lined up on a clock
in a scratch page. On 2026-10-20 that page, built once for a client, found an 8-minute broadcast outage
(HTTP 500 then 503 on every `IncomingSchedules` POST, queue depth to 31), 49 appointment timeouts of
which 44 were one activity, and six DSE "Unexpected changes detected when merging clusters" errors in the
8 seconds after one input carried two statuses per visit for a split activity.

Success: a user adds the day's audit zip, the PSO system events export and the Appointment History
export to a load audit run, and the audit page gains a **System day** tab: every stream on one zoomable
clock, every event id labelled with its message text, every input in the audits as a bar, and a short set
of findings in the audit's own red / amber / green style. Nothing in the tab is interpretation: it shows
what was logged, when, next to what else was logged.

The leg is optional. A run without these files is the audit as it is today.

## 2. Where it lives, and why not elsewhere

In the **load audit**, as leg 5, not in the schedule review and not as a tool of its own.

- The reader is the same person as for legs 1 to 4: the consultant or the integration team, and the
  output is what gets attached to an IFS support case. The schedule review is read by operations and
  answers a different question (how the schedule came out).
- Its inputs are Workbench and Admin-panel downloads, like the audit's. None of them is reachable
  through the RESTful gateway, so a timed pipeline (the schedule review's model) can never feed it.
- It shares the audit's settings (display name, time zone) and its one-page-per-run output.

The split the two tools keep: the review surfaces that a day was bad; the audit explains why.

## 3. Inputs

| Input | Kind | Scope | Where it comes from | Format |
| --- | --- | --- | --- | --- |
| Audit messages | `audits` (exists) | dataset | Dataset audit archive, one zip per dataset per day | zip of `file<id>-<CHANGE\|PLAN_CHANGE\|LOAD\|COMPLETE>-<yyyy_MM_dd_HH_mm_ss>.xml` |
| PSO system events | `pso_events` (new) | dataset, Unfiled | Workbench, System, Events, export (one export covers every dataset and the system-wide rows) | JSON array; fields `id, loggedAt, severity, eAppType, messageId, organisationAccountId, externalTaskId, applicationInstanceId, message, exceptionDetail, parameters[], origin, userId`; `loggedAt` may carry a leading `\d` |
| Appointment history | `appointment_history` (new) | dataset, Unfiled | Workbench, Performance, Appointment History table, export | CSV: `ID, Dataset, Request ID, Input Internal ID, Plan ID, Request Time, Response Time, Success, Input Time, Output Time, Requested Slots, Offered Slots, Time Taken (seconds), Output Time Taken (seconds), Input Time Taken (seconds)` |
| Message texts | `message_texts` (new) | environment | Admin panel language export (`MessageTexts-en.xml`, `DsSystemData` with `Message_Text` rows); the SYS export does not carry these rows, so this is its own one-time upload | XML or zip; parsed into a table on upload, re-uploaded only on a PSO upgrade |

Rules:

- `pso_events` and `appointment_history` are new `ExportKind` cases, dataset-scoped (and Unfiled), with
  extensions `json, zip` and `csv, zip`. `ExportSummariser` records the row count, the first and last
  timestamp and, for events, the count per `messageId`, so the files list shows what a file holds.
- `message_texts` is environment-scoped like `sys`. `SummariseExportFileJob` parses it into
  `pso_message_texts (environment_id, language_id, message_id, text)` and the file itself is kept only
  as the source. One environment has one current set per language; a new upload replaces it.
- The tool takes `audits`, `pso_events`, `appointment_history` and `message_texts` as optional kinds.
  The run is Unfiled most of the time, so all four are files the user picks on the Inputs step like any
  other. On a dataset run, when no `message_texts` file is picked, the tool writes the environment's
  current table to `messages.json` in the scratch folder and passes that. With neither, ids show as
  numbers and an INFO finding says so.
- The leg renders with whatever subset is present, and the tab appears whenever at least one of the
  three dataset-scoped files is in the run. Each lane depends on one input only: no audit zip, no input
  bars and no inbound-per-minute lane; no appointment CSV, no appointment lane; no events, no event
  lanes. An empty lane is not drawn; the tab says which export would fill it.
- A dataset run shows that dataset's audit. The events export is system-wide: rows with
  `externalTaskId` equal to the dataset, plus rows with no dataset (gateway, Workbench, broadcast
  manager rows carry the dataset; gateway rows do not), are kept; other datasets' rows are dropped and
  the tab says how many. An Unfiled run keeps everything and labels lanes by `externalTaskId`.
  `organisationAccountId` is ignored: nothing in the run knows the account, and a multi-account export
  is not a case this tool serves.
- Clock: the tab draws in the run's time zone (the dataset's, or the Unfiled run's zone as the other
  legs use it) and offers a toggle to UTC. The axis, crosshair, windows and tables all follow the
  toggle; tooltips always carry both.
- Several datasets on one clock (the view that found the 2026-10-20 outage was both datasets side by
  side) is a second step, not part of the run: see section 4.8.

## 4. The engine (pso-tools)

A new module `load-audit/system_day.py`, imported by `audit_load.py` when any of `--audits`,
`--events`, `--appointments` is given, plus `--messages <json>` and `--noise-ids <csv of ints>`. Standard
library only, streaming, same `PROGRESS` lines as the rest of the audit (stages `audits`, `events`,
`appointments`). Nothing in legs 1 to 4 changes; `Compare-LoadAudit.ps1` parity does not apply to the new
leg (there is no PowerShell twin and there will not be one).

### 4.1 Audit index

One row per file in the zip, read with `iterparse` and `elem.clear()` (the COMPLETE file alone is
27 MB): `file_id, type, timestamp (from the file name, UTC), bytes, input_id, input_type, process_type,
schedule_data, load_status, input_datetime` (from `Input_Reference`), count of every top-level element,
`Object_Deletion` count and count per `object_type_id`, and the count of `Activity` rows. Written as
`system_day_inputs.csv` next to the page (downloadable) and kept in memory for the series below.
Reference implementation: `docs/system-day-handoff/reference/index_audit.py` (13,129 files in about
40 s with CPython 3.13, from an unzipped folder). The engine reads straight from the zip; the plan
measures that on a real day's archive before trusting the audit's 840 s budget.

### 4.2 Events

Each row keeps `t` (epoch ms), `severity`, `eAppType`, `messageId`, `externalTaskId`,
`applicationInstanceId` (the instance suffix is shown, e.g. `_0`), `message`, `exceptionDetail`,
`parameters`. The text for a `messageId` comes from `messages.json`; placeholders `{0}`.. are filled
from `parameters`, terminology tokens `{activity|u}`, `{status|p}` and the like are resolved the way the
Workbench does (`u` capitalises, `p` pluralises). Ids with no text show the number and are listed.

Known ids the leg treats specially (texts from a 6.x `MessageTexts-en.xml`; confirm on the client's):

| id | application | text |
| --- | --- | --- |
| 2217 | broadcast manager | Broadcast to {0} returned status code {1}. |
| 2493 | broadcast manager | Broadcast for plan {0} failed. ID: {1}. Time taken to process: {2} seconds |
| 2494 | broadcast manager | Broadcast queue for {0} now contains {1} unprocessed broadcasts |
| 1974 | gateway | Timeout waiting for appointment output, internal id: {0}. |
| 2302 | gateway | Operation {0} failed. Result code: {1} |
| 1754 | ABE / DSE / DSP | Activity {0} not found in appointment request with id {1}. Request ignored. |
| 2544 | ABE / DSE / DSP | Activity {0} with a completed visit has further statuses. Statuses for follow on visit ({1}) ignored. |
| 2879 | DSE | Unexpected changes detected when merging clusters. (no parameters) |
| 3041 | — | Plan {0} not found for schedule changes generation. (one per input; noise) |
| 1964 / 1965 | — | A further {0} event(s) of type "{1}" occurred but were not logged in detail. (rollup rows) |

The **noise list** is a run option on the wizard (default `3041, 1767, 2959, 1964, 1965, 24578`),
shown the same for dataset and Unfiled runs: those ids are kept in the counts table and excluded from
the lanes, the windows and the findings. There is no per-dataset setting for it.

### 4.3 Appointment history

Each CSV row keeps `t` (Request Time to UTC), `dataset`, `request_id`, `input_internal_id`, `success`,
`time_taken`, `output_time_taken`, `input_time_taken`, `requested_slots`, `offered_slots`. Two joins
give each row an activity: `request_id` to event 1754's second parameter, and `input_internal_id` to
event 1974's parameter. A failed row is one with `success=false`; a timeout is a failed row whose
`output_time_taken` is 0 and `time_taken` is at or above 30 s (the gateway limit observed on
2026-10-20). The finding names the threshold it used.

### 4.4 Series the page gets

Per-minute, for the whole day: inbound inputs by type (`CHANGE`, `PLAN_CHANGE`, ...) from the index;
events per `messageId`. Per item: every event outside the noise list, every appointment row, every input
(timestamp, bytes, activities, deletions, deletion types, element counts). The page is self-contained
like the rest of the audit, so the per-input element breakdown is embedded only for inputs that are
*flagged* (at least `flag_activities` Activity rows or `flag_deletions` Object_Deletion rows, defaults 5
and 30, run options) or that fall inside a window (4.5); other inputs carry timestamp, bytes, activity
and deletion counts only. On the 2026-10-20 client day that keeps the tab under 1 MB against 2.4 MB with
everything embedded. Full detail is in the CSV download.

### 4.5 Windows

The tab opens on the full day and offers one button per window the engine found, so the reader lands
on the minutes that matter without knowing the ids:

- every run of consecutive 2217 rows (gap under 2 minutes), padded 3 minutes each side;
- every minute in which an error-severity id outside the noise list first appears, padded 2 minutes
  (the rollup ids 1964 and 1965 are in the default noise list, so a rollup row never opens a window);
- every input at or above `flag_deletions` deletions, padded 1 minute;
- every group of 3 or more failed appointment requests within 5 minutes.

Windows that overlap are merged. Each is labelled with what defined it (`2217 x37, 18:24–18:32`).

### 4.6 Findings

Added to the audit's findings list under a new area **System day**, so they appear in the markdown, the
JSON, the counts and the verdict line like any other check. Thresholds are run options with these
defaults.

| Check | GREEN | AMBER | RED |
| --- | --- | --- | --- |
| Broadcasts refused (2217) | none | any | a run of 2217 rows longer than 2 minutes, or queue depth (2494) at or above 20 |
| Appointment timeouts (1974 / `success=false` with no output) | none | under 2% of requests | 2% or more, or 10 or more for one activity |
| DSE errors outside the noise list (severity error, `eAppType` 2) | none | any | 5 or more in one minute |
| Error-severity ids with no text | n/a | INFO: list | n/a |
| Inputs at or above `flag_deletions` deletions | n/a | INFO: count, largest, which object types | n/a |
| Message texts | n/a | INFO when the environment has none uploaded | n/a |

Each finding names the ids, the counts, the first and last time and, for appointments, the activities
with the most failures, and nothing else. Recommendations stay generic ("attach the System day tab and
the inputs CSV to the IFS case") because the leg does not know causes.

### 4.7 The tab

A new tab on the audit page, rendered from `system_day.json` by the renderer in
`docs/system-day-handoff/reference/timeline_renderer.js` (plain SVG, no library, about 8 KB): lanes
are plug-ins with `title`, `h`, `draw(g, x, laneTop, laneHeight, domain)`; `mount()` adds the Window /
pan / zoom row, drag-to-zoom, wheel zoom (one redraw per animation frame), double-click reset and the
delegated tooltip. The renderer takes the day's bounds and the display offset as data (the reference
file hard-codes a date in `minuteSeries` and labels everything UTC; both go). The tab starts hidden,
and the renderer sizes from its parent, so the first draw runs when the tab is opened, and a resize
redraws. Lanes, top to bottom:

1. Broadcast: 2217 marks coloured by HTTP status, 2494 queue depth as a stepped line, 2493 call
   seconds as dots.
2. Appointment requests: one dot per row, y = time taken, ring for failed; tooltip carries request,
   activity, slots, the 1754 / 1974 texts.
3. One lane per error-severity id outside the noise list, titled with its text.
4. Other events (1754, 1759, 2544, 24585 and whatever else is present), one row each.
5. Inputs: one bar per audit file, height = bytes with the y axis rescaling to the largest bar in view,
   flagged bars in a second colour, `Na/Nd` labels under 40 minutes; above about 900 bars in view the
   lane bins by 2 px column (count, largest input, flagged count in the tooltip).
6. Inputs per minute by type.

Then, below the chart: the 2217 table, the failed-request table with activity ids, the per-id counts
with texts, the windows as sequence lists (every event and every flagged input in order, with the offset
from the window's first error), and the inputs CSV download. The reference block (every id, its text,
its count) is collapsed at the bottom.

### 4.8 Combining datasets

A load audit run is one dataset, and stays so. The engine therefore writes its data file,
`system_day.json` (the series of 4.4, the windows of 4.5, the findings of 4.6, every row tagged with
its dataset), as a result file next to the page, and the tab is rendered *from that file* by the
renderer, not built inline. That makes a combined view cheap later:

- **Combine system days** is a later option, not in this build. It takes two or more finished load
  audit runs the user picks (`TakesRunInputs`, the pattern Travel tuning uses to read Plan travels
  runs), Unfiled or not, merges their `system_day.json` files, and renders the same tab with one row
  per dataset in each lane (the day page of 2026-10-20 had exactly that: the inbound-per-minute, inputs
  and 2879 lanes split by dataset). Rows with no dataset (gateway, Workbench) appear once. No engine
  change is needed; the merge is a JSON concatenation with the dataset tag already on every row. "Same
  day" is the LOAD's `Input_Reference` date in the run's time zone; runs whose days differ are combined
  with a warning, not refused. A combined view defaults its clock toggle to UTC, since the runs may
  come from different zones.
- An audit run that is given more than one audit zip is not supported in this design; the wizard takes
  one `audits` file per run as it does for Trace an activity.

## 5. The web (pso-test-tools)

- `ExportKind`: `PsoEvents = 'pso_events'`, `AppointmentHistory = 'appointment_history'`,
  `MessageTexts = 'message_texts'` (environment-scoped; `allows()` and `expectedRoot()` as for `sys`).
- `ExportSummariser`: counts and first / last timestamp for the two dataset kinds; `Message_Text` row
  count for `message_texts`. `SummariseExportFileJob` writes `pso_message_texts` for `message_texts`
  (migration: `environment_id, language_id, message_id, text`, unique on the first three).
- `LoadAuditTool::optionalKinds()` gains the four kinds; the wizard already lets a run pick any
  optional kind. `LoadAuditCommand::build()` adds `--audits`, `--events`, `--appointments` when present,
  `--messages` (the picked file, else `<scratch>/messages.json` from the environment's table, else
  nothing), and `--noise-ids`, `--flag-activities`, `--flag-deletions` from the options.
- Option fields on the Run form, shown for every run including Unfiled, with built-in defaults: the
  noise list, the two flag thresholds. No dataset setting.
- Result: `html`, `findings`, `counts` as today, plus `system_day` (the JSON of 4.8, needed by the
  combine option) in the encrypted result row. The inputs CSV (2 to 3 MB) is kept as a file in the
  run's storage folder, not in the row, and served by a new owner-only download route next to
  `tool-runs.download-json` (404 when the run has none).
- `tools.php`: `load_audit.timeout_seconds` stays 840; the indexer adds about 40 s for 13k files, so the
  default holds. Memory: the audit container has the 1 GB step from the port; the indexer streams.

## 6. Tests

- pso-tools: fixtures with a 3-file audit zip (CHANGE, PLAN_CHANGE, COMPLETE), a 20-row events JSON
  covering 2217 / 2494 / 2493 / 1974 / 1754 / 2879 / a noise id / an id with no text, a 6-row appointment
  CSV with one timeout, and a 10-row messages JSON. Tests: index row shape; terminology resolution
  (`{activity|u}` to `Activity`, `{status|p}` to `statuses`); the two appointment joins; every window
  rule; every finding threshold at its boundary; the leg absent when no new input is given (findings
  JSON byte-identical to a run without the flags).
- pso-test-tools: `LoadAuditCommandTest` shapes with each subset of the three kinds; summariser tests
  for the three kinds including a zip and a `loggedAt` with the `\d` prefix; `pso_message_texts` replace
  on re-upload; the CSV download route (owner only, 404 when the run has no CSV); a run with
  `message_texts` on another environment does not leak into this one.

## 7. Review focus

1. An Unfiled run with every new file and no dataset: lanes labelled by `externalTaskId`, the clock in
   the run's zone, the toggle to UTC. Test it; it is the common case.
2. `loggedAt` with the `\d` prefix, and Appointment History times in the client's offset, both end up
   on the same UTC clock as the audit file names.
3. An events export that spans two days: the leg uses the audit's day (from the LOAD's
   `Input_Reference`) and drops the rest, saying how many rows it dropped.
4. The tab must not render at all without events; the rest of the audit page is unchanged.
5. A message id present in the export but not in the texts must never break a lane title.

## 8. Open questions

Settled 2026-10-20 by the owner: the Events screen export is always the JSON shape in section 3; the
SYS export does not carry `Message_Text` rows, so `message_texts` is its own one-time upload per
environment; a system has one or more audit zips (one per dataset) and the audit stays one dataset per
run, with the combined view as the later option in 4.8.

Settled 2026-10-20 evening: the account id is not needed and is ignored; the clock is the run's zone
with a UTC toggle; the first draw waits for the tab to open; the tab renders with any one of the three
files; the CSV lives on disk, the JSON in the result row; the zipped indexer is measured in the plan;
the noise list and the flag thresholds are run options on the wizard (not dataset settings) so Unfiled
runs see them; `message_texts` is a pickable optional file; combine is a later step with the changes in
4.8.

Left as is: the `eAppType` numbers (1 and 9 loader-style rows, 2 the DSE, 4 the broadcast manager, 6
and 31 ABE and DSP, 24 the Workbench server, 25 the gateway). Lanes show the number until IFS confirms
a table.

## 9. Hand-off material

`docs/system-day-handoff/reference/index_audit.py` is the indexer as run on 2026-10-20, with the
element counting and the `Object_Deletion` breakdown; it takes the folder and the CSV path as
arguments. `docs/system-day-handoff/reference/timeline_renderer.js` is the renderer: `chart()`,
`mount()`, `mark()`, `bars()`, the delegated tooltip and the zoom handling, with no lane definitions and
no data. Neither file contains customer data. The engine's lane definitions and the data builder are
written fresh in pso-tools from section 4.
