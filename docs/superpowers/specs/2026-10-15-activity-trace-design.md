# Trace an activity: design

Date: 2026-10-15. Status: approved in conversation, awaiting written review. Replaces issue #30's scope;
closes the #25 question for this tool (Python first).

Reference: `docs/superpowers/notes/2026-10-15-activity-trace-inventory.md` (behaviour inventory of the two
PowerShell scripts this tool replaces). Where this spec is silent, the inventory's sections 3 and 4 are the
behaviour to keep.

## Problem

Two PowerShell scripts in pso-tools answer two questions about one PSO activity from the same audit zip:
`Build-AppointmentTimeline.ps1` (how was the appointment booked: requests, offers, response, slot usage rules)
and `Get-PsoTaskTimeline.ps1` (what happened to the activity in PSO over time: inputs, plan changes, window and
shift checks, IFS event log pairing). Both are Windows-only. Consultants need them from the web app, and one
activity should be one story.

## Decisions

- One web tool, "Trace an activity", with one report in two views: Booking and History.
- Logic in Python in pso-tools (`activity-trace/trace_activity.py`), run by the web as a child process like the
  load audit. Python is the reference implementation. The two PowerShell scripts stay unchanged until the web
  version replaces them on Windows; they are listed in INDEX.md as the Windows versions.
- The IFS event log is part of the first version, as a new optional export kind.
- Rejected: two straight ports with tabbed results (audit read twice, two templates, no single story);
  appointment timeline alone first.

## 1. Inputs and the web contract

- Tool identity: the existing enum case `Tool::AppointmentTimeline` is relabelled "Trace an activity", question
  "What happened to one activity: how it was booked and how PSO scheduled it?". Dataset scope. The existing
  hidden entry page is unchanged; it appears once the tool registers.
- Export kinds. Required: `audits` (the zip). Optional: `load` (slot usage rules, appointment templates, shift
  routes). Optional, new: `event_log`, label "IFS event log", dataset scope, extensions `xlsx` and `csv`, no XML
  root check; summary line is the row count and the date range covered. It behaves like any other kind in the
  Exports tab and the wizard Inputs step (drop target, purge after `exports.ttl_hours`).
- Options step: `options.activity_id` (text, required unless `options.work_task_id` is given) and
  `options.work_task_id` (text, optional), with helper text saying one of the two is needed and the activity id
  is the usual one. Time zone comes from the dataset settings (`settings.time_zone`); a new optional setting
  `settings.ifs_time_zone` gives the zone the IFS event log times are written in (default: the dataset zone).
- Run: `App\Tools\ActivityTrace\ActivityTraceTool` runs the script from the pso-tools checkout
  (`TOOLS_PSO_TOOLS_PATH`) as a child process with `PSO_PROGRESS=lines`, timeout from
  `config('tools.activity_trace.timeout_seconds', 840)`. Inputs are the scratch copies of the files. Outputs:
  `trace.html`, `trace.json`, `booking.csv`, `history.csv` in the run's scratch folder; the tool stores all
  four in the run result (`html`, `data`, `booking_csv`, `history_csv`).
- Result page: the sandboxed iframe used by the load audit, plus download buttons for HTML, JSON, booking CSV
  and history CSV. Summary line built from the JSON, for example "Booked 2026-10-03 after 2 offers; 14
  scheduling events, 1 check failed" (booking outcome; history counts; red findings count).

## 2. The Python tool

### Shape

`activity-trace/trace_activity.py`, Python 3 standard library only, streaming XML (`iterparse`, clear after
use, explicit namespace stripping), flat memory. Three parts in one module:

1. Reader: walks the audit zip once, in file order (file-name sequence when the name matches
   `file<seq>-<TYPE>-yyyy_MM_dd_HH_mm_ss.xml`, else zip entry order), and keeps for the chosen activity and its
   related activities: Input_Reference and Plan headers per file; Appointment_Request, Appointment_Offer,
   Appointment_Offer_Response, Appointment_Summary rows linked to the activity's requests; Activity,
   Activity_Status, Activity_SLA, Availability, Activity_Group, Activity_Custom_URL, Additional_Attribute,
   Object_Deletion rows mentioning the activity; Allocation, Allocation_Data, Plan_Travel, Schedule_Exception,
   Schedule_Exception_Data, Schedule_Exception_Response, Plan_Deletion rows mentioning it; Plan_Route rows for
   the resource and shift of each of its allocations. The field lists are the inventory's section 2.
2. Booking builder: the appointment script's logic (inventory sections 3 and 4, ATL).
3. History builder: the task timeline's logic (inventory sections 3 and 4, PTT).

CLI: `trace_activity.py --audits <zip> (--activity <id> | --work-task <id>) [--load <xml|zip>]
[--event-log <xlsx|csv>] [--tz <iana>] [--ifs-tz <iana>] [--out-dir <dir>] [--template <html>]`. No prompts, ever.
Exit 0 with outputs; exit 2 with a one-line reason on stderr when the activity cannot be found; exit 1 on any
other failure with "Stopped during: <stage>" on stderr.

### One rule for a file's time

A file's time is its Input_Reference `datetime` for client inputs and the Plan `output_datetime` for PSO
output. If neither exists, the UTC timestamp in the file name. If that fails, the zip entry time, and the file
is marked "not timed" in the report and in Files read. All times in the data are UTC. (The appointment script's
order with the task timeline's file-name fallback; the PowerShell scripts' own rules are not changed.)

### LOAD

Only the optional LOAD input. No search next to the input, no nested zip, no prompt. The LOAD supplies
Appointment_Template, Appointment_Template_Item, Appt_Template_Slot_Usage, Slot_Usage_Rule_Set,
Slot_Usage_Rule (booking) and is also read as one more audit file for the activity's rows (history). Without a
LOAD: the Booking view says slot usage rules were not checked; the History view skips the shift-fit check and
says so.

### Work task id and related activities

`--activity` is primary. With only `--work-task`, the reader resolves the activity from
Activity_Custom_URL.custom_parameter_value1 or the Additional_Attribute labelled "Work Task ID"; if nothing
matches, exit 2 naming the id and the two places searched. Activities linked through Activity_Group to a primary
activity are traced as "related", as today. The work task id shown on the page comes from `--work-task`, else
the activity's own Custom URL or attribute, else the first TASK_SEQ in the event log (marked "not verified").

### Event log

`.xlsx` read with the standard library (zipfile; `xl/sharedStrings.xml` and the first worksheet; inline,
shared and numeric cells; first row is the header) or `.csv`. Columns as in the inventory (Created Date, Key
Ref, Event, Event Description, Source, Created By, Event Text, Transaction Note, Previous Value, Event Log
Sequence, Generated). Created Date as OADate (seconds truncated) or parseable text; rows with no date are
dropped. Times interpreted in `--ifs-tz`, else `--tz`, and converted to UTC. Pairing to PSO inputs: gap in
[-2 s, +10 s], smallest absolute gap wins, only ASSIGNED, APPOINTMENT, UNASSIGNED, CANCELLED, REASSIGNED count
as matched; assignment-to-activity mapping only from an ASSIGNED event whose matched input carries status 30.

### Behaviours carried over unchanged

Inventory sections 3 and 4: dedupe keys and first-seen rule; taken versus declined; outcome rules and expiry;
slot rule placement (base + minimum_start_offset, a rule applies until the next rule's offset); timing stats;
appointment window and shift checks with their inclusive bounds; moves versus the previous plan; exception and
clearance handling with the 30-minute neighbour window; severity rules (CHECK warn = red, "Changed since" =
info, other warn = amber, CHECK ok = green); pinned-by, fixed-start, shift-fit and actually-worked facts;
planned versus actual per assignment instance; input source classification (PSW / SIM / USER / UNKNOWN).
Every inference the scripts label as unconfirmed stays labelled in the page footer.

### Outputs

- `trace.html`: one page, two views (section 3).
- `trace.json`: `{meta, booking, history, findings, files}`. `meta`: activity id, work task id and how it was
  found, related ids, dataset zone, IFS zone, generated time, inputs used. `booking`: timing, requests[] (the
  ATL model). `history`: steps, bars, actuals, hero, facts, notes (the PTT model). `findings`: severity, time,
  activity, text. `files`: name, type, time, time source, sizes, counts, error.
- `booking.csv`: the ATL column order. `history.csv`: the PTT column order.
- Summary line on stdout as the last line, `summary: <text>`; warnings as `warning: <text>` lines, as the load
  audit does.

### Not carried over

Console colours, `-OpenReport`, the Markdown report, and the appointment page's "Show JSON / Copy JSON / Copy
XML" buttons that rebuilt an input message from a LOAD for resending. That last feature is a gateway-upload job
and belongs with issue #23 if wanted.

## 3. The report page

- `activity-trace/trace_template.html`, started from pso-tools `scripts/task_timeline_template.html` (theme,
  zone switcher, KPI tiles, findings table, timeline list) with `__TITLE__` and `__DATA__` placeholders.
- Header: activity id, work task id when known, dataset name, a view switch (Booking | History), the zone
  switcher (dataset zone, UTC, viewer's zone; choice stored under one key `pso-trace-tz`), theme toggle.
- Booking view: timing cards; per request the outcome pill, the flow (requested, offers returned, response),
  the calendar of offered slots with the allowed-utilisation row per day (absent, with a note, without a LOAD),
  the offers table with the "available and taken only" toggle, the responses table, "Check this" warnings.
- History view: hero bar, facts, KPI tiles, findings with the needs-attention filter, the day strip, planned
  versus actual (only with an event log), the timeline with source filters and the context-lines toggle.
- Files read: once, at the bottom, shared by both views, with time source per file and the "not timed" marker.
- Wording: plain English, "Allocated" never "placed", no em dashes, warnings on the page not only in tooltips,
  inference notes in the footer.

## 4. Web side

- `ExportKind::EventLog = 'event_log'`: label "IFS event log", `allowedScope()` Dataset, `extensions()`
  `['xlsx', 'csv']`, `expectedRoot()` null. `ExportSummariser` gains an event-log branch: row count and
  first/last Created Date (xlsx via PHP's ZipArchive and SimpleXML on the first sheet, csv via fgetcsv);
  the Exports tab "Contents" column shows "N events, <first> to <last>".
- `Tool::AppointmentTimeline`: label "Trace an activity", question as in section 1, icon unchanged
  (OutlinedCalendarDays).
- `App\Tools\ActivityTrace\ActivityTraceTool` (registered in AppServiceProvider): `requiredKinds()` [Audits],
  `optionalKinds()` [Load, EventLog], `optionFields()` the two text inputs with validation that at least one is
  filled, `run()` builds the argv through `ActivityTraceCommand::build(...)`, runs `ScriptRun::execute`, reads
  the four outputs, derives the summary line and `summary.counts` (red, amber, green findings) and returns the
  ToolResult. `PsoToolsCheckout` gains `activityTraceScript()` and `activityTraceTemplate()`.
- Dataset settings: `ifs_time_zone` added to the settings form (optional, searchable zone select) and to
  `DatasetSettings`.
- Result view `resources/views/filament/tool-runs/appointment_timeline.blade.php`: counts badges, four download
  buttons (existing HTML and JSON download routes; two new CSV routes alongside them in the same controller),
  the sandboxed iframe.
- Windows bundle: `make-windows-bundle.sh` in pso-tools is not changed (the PowerShell scripts stay).

## 5. Errors

- Activity not found (exit 2): the run fails with the script's message, shown on the run page.
- Unreadable file inside the zip: skipped, counted, listed under Files read with its error.
- Event log with no usable Created Date column or no rows with dates: the run fails naming the column.
- Missing required audits file, expired file, unowned scope: handled by the wizard and StartToolRun as today.
- Timeout: the existing message from ScriptRun.

## 6. Testing

Python (`tests/activity_trace/` in pso-tools, unittest, synthetic zips built in the tests):
- Booking: request with offers and a taken response; declined; expired with no response; no slot available;
  offers only in a LOAD snapshot; slot usage rules placed from base datetime; template fallback for the rule
  set; the seven booking warnings.
- History: allocation inside and outside the appointment window (start-based and not); shift overshoot with
  the latest start that fits; a move between plans; a Schedule_Exception and its clearance through
  Plan_Deletion; related activity through Activity_Group; work task resolution from Custom URL and from the
  attribute; input source classification.
- File time rule: each of the four fallbacks; "not timed" marker.
- Event log: xlsx and csv readers; OADate; pairing within the window; assignment mapping on status 30.
- Outputs: JSON shape, CSV column orders, exit codes 0, 1, 2; `PSO_PROGRESS=lines` output.
- Golden test on the real SaskTel audits when `~/pso-data/SATE/<date>/audits.zip` exists, skipped otherwise.

PHP (Pest): ExportKind EventLog (scope, extensions, label); summariser for xlsx and csv; ActivityTraceCommand
argv for every input combination; ActivityTraceTool end to end with a fake script writing the four outputs;
result view and the two CSV download routes; dataset settings form with `ifs_time_zone`; the wizard showing
three rows (Audit messages required, LOAD and IFS event log optional) and the two option fields.

## 7. Execution order

Python first, in two stages inside the one module so a usable result arrives early: reader + booking builder +
page with the Booking view; then history builder + History view + event log. Web side after the Python's CLI is
stable. A Sonnet or Opus session executes from the plan; this session reviews.
