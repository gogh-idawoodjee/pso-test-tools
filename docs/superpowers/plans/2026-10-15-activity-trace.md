# Trace an Activity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One web tool, "Trace an activity", that reads a PSO audit zip (plus an optional LOAD and IFS event log) and shows one activity's booking (appointment requests, offers, response, slot usage) and scheduling history (inputs, plan changes, checks, IFS pairing) as one page in two views.

**Architecture:** The logic is a Python module in pso-tools (`activity-trace/trace_activity.py`, standard library, streaming XML) that ports two PowerShell scripts into one reader plus two builders and writes `trace.html`, `trace.json`, `booking.csv`, `history.csv`. The web app (pso-test-tools) adds a sixth export kind for the IFS event log, a tool class that runs the script as a child process like the load audit, and a result view. The PowerShell scripts are not changed.

**Tech Stack:** Python 3 stdlib (zipfile, xml.etree.ElementTree.iterparse, csv, json, datetime, unittest); Laravel 13 / Filament v5 / Pest 4 for the web.

**Spec:** `docs/superpowers/specs/2026-10-15-activity-trace-design.md` (pso-test-tools). Behaviour reference: `docs/superpowers/notes/2026-10-15-activity-trace-inventory.md`. Source scripts: pso-tools `scripts/Build-AppointmentTimeline.ps1` (ATL), `scripts/Get-PsoTaskTimeline.ps1` (PTT), `scripts/task_timeline_template.html`.

Two repositories: Tasks 1 to 6 in pso-tools (`/Users/idawoodjee/Documents/Projects/PSO Tools`, branch `activity-trace` from main), Tasks 7 to 9 in pso-test-tools (`/Users/idawoodjee/Herd/pso-test-tools`, branch `feat/activity-trace`, already holds the spec).

## Global Constraints

- Python: standard library only; `iterparse` streaming with `elem.clear()` after extraction; strip namespaces explicitly; flat memory on 100 MB+ zips. No interactive prompts anywhere. Exit codes: 0 with outputs, 2 activity not found (one-line reason on stderr), 1 any other failure with `Stopped during: <stage>` on stderr.
- All times in PSO data are UTC. A file's time: Input_Reference `datetime` for client inputs, Plan `output_datetime` for PSO output, else the UTC stamp in the file name (`file<seq>-<TYPE>-yyyy_MM_dd_HH_mm_ss.xml` or `File_yyyy_MM_dd_HH_mm_ss.xml`), else the zip entry time with the file marked `not timed`.
- Progress: with `PSO_PROGRESS=lines`, write `PROGRESS {"stage":..,"percent":..}` lines on stderr (copy the `Progress` class pattern from `load-audit/audit_load.py` lines 236 to 310). Last stdout line `summary: <text>`; warnings as `warning: <text>` lines.
- Behaviours in inventory sections 3 and 4 are kept exactly (dedupe keys, first-seen, taken vs declined, outcome rules, expiry, slot rule placement, window and shift checks, severity rules, facts, pairing window [-2 s, +10 s], status 30 mapping, source classification). Inference notes stay in the page footer.
- Customer-facing plain English, "Allocated" never "placed", no em dashes, warnings on the page not only in tooltips. Lookup tables (status and exception names) copied verbatim from inventory section 6.
- PowerShell scripts untouched. Web: Filament v5 APIs; ids are UUID strings; export kinds via `ExportKind::getLabel()`.
- Run `vendor/bin/pint --dirty` before PHP commits. Python tests: `python3 -m unittest discover -s tests/activity_trace -t .` from the pso-tools root.

## Review Focus

1. A zip whose file names carry no sequence or timestamp (Workbench `File_...xml` or arbitrary names): ordering must fall back to zip entry order and the time rule to the entry time with `not timed`, never crash. (Task 1 test `test_unnamed_files_fall_back_to_entry_order_and_not_timed`.)
2. An activity with requests but every offer in a LOAD snapshot only: booking must still list the offers, mark them snapshot-only in stats, and not count them as measured. (Task 2 test `test_offers_only_in_load_snapshot`.)
3. An event log whose Created Date is text in a non-ISO format the reader cannot parse: those rows drop with a warning line, the run continues; a log with zero datable rows fails with the column named. (Task 5 tests `test_rows_without_date_are_dropped_with_warning`, `test_log_with_no_dates_fails_naming_column`.)
4. A LOAD given as `.zip`: the biggest `.xml` member is used; a `.json` LOAD is refused with a clear message (the scripts only read XML). (Task 2 test `test_load_zip_uses_biggest_xml_member`, Task 7 keeps `json` out of EventLog extensions and Task 8 passes the LOAD path through unchanged.)
5. A dataset with no `ifs_time_zone`: the IFS log is read in the dataset zone; a dataset with neither zone set: UTC with a warning on the page. (Task 5 test `test_ifs_zone_defaults`, Task 8 test `passes the dataset zone and the ifs zone when set`.)

---

## File structure

pso-tools (create):
- `activity-trace/trace_activity.py` the tool: `main()`, `Reader`, `BookingBuilder`, `HistoryBuilder`, `EventLog`, `write_outputs()`.
- `activity-trace/trace_template.html` the page (from `scripts/task_timeline_template.html`).
- `tests/activity_trace/__init__.py`, `fixtures.py` (synthetic zip and file builders), `test_reader.py`, `test_booking.py`, `test_history.py`, `test_event_log.py`, `test_outputs.py`, `test_golden.py`.
- Modify: `scripts/INDEX.md` (one paragraph: the Python tool is the reference; the two scripts are the Windows versions), `CLAUDE.md` repository tree (add `activity-trace/`).

pso-test-tools (create):
- `app/Tools/ActivityTrace/ActivityTraceCommand.php`, `ActivityTraceTool.php`.
- `resources/views/filament/tool-runs/appointment_timeline.blade.php`.
- `tests/Feature/ActivityTrace/ActivityTraceToolTest.php`, `ActivityTraceCommandTest.php`, `EventLogExportKindTest.php`, `EventLogSummariserTest.php`.
- Modify: `app/Enums/ExportKind.php`, `app/Enums/Tool.php`, `app/Support/Exports/ExportSummariser.php`, `app/Support/DatasetSettings.php` (find the class that reads dataset settings), `app/Models/Dataset.php` (settings form), `app/Tools/LoadAudit/PsoToolsCheckout.php`, `app/Providers/AppServiceProvider.php`, `config/tools.php`, the tool-run download controller and `routes/web.php` (two CSV routes), `README.md`.

---

### Task 1: Reader, file time rule, CLI skeleton, progress, exit codes (pso-tools)

**Files:**
- Create: `activity-trace/trace_activity.py`, `tests/activity_trace/__init__.py`, `tests/activity_trace/fixtures.py`, `tests/activity_trace/test_reader.py`, `tests/activity_trace/test_outputs.py`
- Modify: `CLAUDE.md` (tree)

**Interfaces (Produces):**
```python
# trace_activity.py
STATUS_NAMES = {...}      # inventory section 6, verbatim
EXCEPTION_NAMES = {...}   # inventory section 6, verbatim

class AuditFile:          # one zip member
    name: str; seq: int|None; type: str; time: str|None; time_source: str  # 'input_reference'|'plan_output'|'file_name'|'entry_time'
    size: int; header: dict; is_input: bool; error: str|None; counts: dict

class Reader:
    def __init__(self, audits_path: str, activity_id: str|None, work_task_id: str|None, load_path: str|None, progress): ...
    def run(self) -> 'ReadResult'   # streams every file once; raises ActivityNotFound (exit 2)

class ReadResult:
    files: list[AuditFile]; activity_id: str; work_task_id: str|None; work_task_source: str|None  # 'given'|'custom_url'|'attribute'|'event_log'|None
    related_ids: list[str]
    booking_rows: dict   # 'requests','offers','responses','summaries' -> list[dict with 'file' index]
    history_rows: dict   # per file index: {'activity':[], 'status':[], 'sla':[], 'availability':[], 'group':[], 'custom_url':[], 'attribute':[], 'deletion':[], 'allocation':[], 'allocation_data':[], 'travel':[], 'exception':[], 'exception_data':[], 'exception_response':[], 'plan_deletion':[], 'route':[]}
    load_config: dict    # 'templates','template_items','template_slot_usage','rule_sets','rules' (empty without a LOAD)

def file_time(header: dict, name: str, entry_time: datetime) -> tuple[str|None, str]
def parse_name(name: str) -> tuple[int|None, str, str|None]   # seq, type, utc 'YYYY-MM-DDTHH:MM:SSZ'
def main(argv=None) -> int
```
- Row dicts hold the fields listed in inventory section 2, keyed by element name, text stripped, entities decoded. The "mention" test for history rows is the PTT regex: value equal to the id, or matching `^(?:[A-Za-z0-9_]+-)?A-<id>(?:-\d+)?$`, over fields activity_id, activity_id1, activity_id2, previous_activity_id, id, object_pk1..4, value, label_value, custom_parameter_value1. Plan_Route rows are kept when their resource_id and shift_id match any kept Allocation of the file.
- A LOAD given with `--load` is read twice: once for `load_config` (the five config tables, Slot_Usage_Rule filtered to referenced rule sets), once as an additional AuditFile of type LOAD appended after the zip's files.
- Work task resolution: if `activity_id` is None, scan Activity_Custom_URL.custom_parameter_value1 and Additional_Attribute(label == 'Work Task ID').label_value for `work_task_id` in every file; the first hit's parent activity id becomes `activity_id` with `work_task_source` 'custom_url' or 'attribute'. None found: raise ActivityNotFound("Work task <id> was not found in Activity_Custom_URL or the Work Task ID attribute of any file").
- Related ids: for every Activity_Group row mentioning a primary id, the other end.

- [ ] **Step 1: Fixtures**

`tests/activity_trace/fixtures.py` builds in-memory zips:
```python
import io, zipfile, datetime

def ds(rows_xml: str, header: str = '') -> bytes:
    return ('<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/schema/dsScheduleData.xsd">'
            + header + rows_xml + '</dsScheduleData>').encode()

def input_ref(dt='2026-10-04T08:00:00Z', typ='CHANGE', ref='r1', user=None, app=None, enacting=None):
    extra = ''.join(f'<{k}>{v}</{k}>' for k, v in [('user_id', user), ('application_type_id', app), ('enacting_app_type_id', enacting)] if v)
    return f'<Input_Reference><id>{ref}</id><datetime>{dt}</datetime><input_type>{typ}</input_type>{extra}</Input_Reference>'

def plan(dt='2026-10-04T08:00:30Z', pid='p1', ref='r1', taken='PT2.5S'):
    return f'<Plan><id>{pid}</id><plan_type>PLAN_CHANGE</plan_type><output_datetime>{dt}</output_datetime><time_taken>{taken}</time_taken><input_reference_id>{ref}</input_reference_id></Plan>'

def row(tag, **fields):
    return f'<{tag}>' + ''.join(f'<{k}>{v}</{k}>' for k, v in fields.items() if v is not None) + f'</{tag}>'

def make_zip(members: dict[str, bytes], times: dict[str, datetime.datetime] | None = None) -> str:
    """Write a temp zip; members keyed by name. Returns the path."""
```
plus `make_zip` writing to `tempfile.mkdtemp()`, honouring `times` through `ZipInfo.date_time`.

- [ ] **Step 2: Failing reader tests**

`tests/activity_trace/test_reader.py` (unittest):
- `test_file_time_rule`: four members: input with Input_Reference datetime; plan with output_datetime; `file3-CHANGE-2026_10_04_09_00_00.xml` with no header; `random.xml` with no header and a ZipInfo time of 2026-10-04 10:00. Assert `(time, time_source)` are `('2026-10-04T08:00:00Z','input_reference')`, `('2026-10-04T08:00:30Z','plan_output')`, `('2026-10-04T09:00:00Z','file_name')`, `('2026-10-04T10:00:00Z','entry_time')`, and the last file has `not_timed` True.
- `test_order_by_sequence_then_entry_order`: `file10-...`, `file2-...`, `File_2026_10_04_07_00_00.xml`, `zzz.xml`: order is seq 2, seq 10, then the two unsequenced in zip entry order.
- `test_unnamed_files_fall_back_to_entry_order_and_not_timed` (Review Focus 1).
- `test_keeps_only_rows_for_the_activity`: a CHANGE with Activity 1001 and 1002, Activity_Status for both, Appointment_Request for 1001 and 1002, offers for both requests; assert booking_rows hold only 1001's request/offers and history_rows only 1001's rows; `counts` per file.
- `test_mention_regex_matches_prefixed_ids`: `<activity_id>SATE-A-1001-2</activity_id>` is a hit for 1001; `<activity_id>11001</activity_id>` is not.
- `test_related_through_activity_group`: Activity_Group activity_id1=1001 activity_id2=1002 makes 1002 related; its rows are kept with role data available.
- `test_work_task_resolution_custom_url_then_attribute` and `test_work_task_not_found_exit_2` (via `main([...])` returning 2 and stderr containing both place names).
- `test_load_config_and_load_as_file`: `--load` with Appointment_Template, Slot_Usage_Rule_Set, Slot_Usage_Rule rows and an Activity 1001 row; assert `load_config` populated and the LOAD appears as the last AuditFile of type LOAD.
- `test_load_zip_uses_biggest_xml_member` and `test_load_json_refused` (message contains "XML").
- `test_unreadable_member_is_skipped_and_listed`: a member with broken XML gets `error` set and the run continues.

- [ ] **Step 3: Run to see them fail**, then implement `Reader`, `file_time`, `parse_name`, `main` with argparse (`--audits`, `--activity`, `--work-task`, `--load`, `--event-log`, `--tz`, `--ifs-tz`, `--out-dir`, `--template`), the `Progress` class copied from audit_load.py, the `Stopped during:` trap, and a stub `write_outputs` that writes `trace.json` with `meta` and `files` only (Task 2 onward fills the rest). `main` returns 0/1/2.

- [ ] **Step 4: Outputs tests** (`test_outputs.py`): `test_exit_codes` (0 on a good run, 2 on unknown activity, 1 on a missing zip with "Stopped during: " on stderr); `test_progress_lines_when_env_set` (stderr has `PROGRESS {` lines with stage `reading` 0 and 100); `test_summary_line_is_last_stdout_line` (`summary: ` prefix; content checked in later tasks).

- [ ] **Step 5: Run `python3 -m unittest discover -s tests/activity_trace -t .`** Expected: all pass. Add `activity-trace/` to the CLAUDE.md tree. Commit: "Activity trace: reader, file time rule and CLI".

---

### Task 2: Booking builder and booking.csv (pso-tools)

**Files:** modify `activity-trace/trace_activity.py`; create `tests/activity_trace/test_booking.py`.

**Interfaces (Produces):**
```python
class BookingBuilder:
    def __init__(self, read: ReadResult, latest_file_time: str|None): ...
    def build(self) -> dict   # {'timing': {...}, 'requests': [...], 'warnings': [...], 'rules_checked': bool}
BOOKING_CSV_COLUMNS = ['Timestamp','TimestampSource','Event','RequestId','OfferId','Available','OfferStart','OfferEnd','WindowStart','WindowEnd','OfferValue','ReasonTypeId','ProspectiveResource','ProspectiveStart','PlanId','TemplateId','TemplateDatetime','WindowLength','OfferExpiry','InputUpdated','AuditFile','InputType','InputReferenceId','SeenInFiles']
def booking_csv_rows(booking: dict) -> list[list[str]]
def booking_summary(booking: dict) -> str   # 'Booked 2026-10-03 after 2 offers' | 'Declined after 3 offers' | 'No slot available' | 'Not booked, offers expired' | 'No response yet' | 'No appointment request'
```
Each request dict: id, activity_id, request_time, request_time_measured, template_id, template_datetime, window_length, base, expiry, rule_set_id, rule_set_source ('request'|'template'|'template_slot_usage'|None), first_file, offers[] (id, available, start, end, window_start, window_end, value, reason_type_id, prospective_resource, prospective_start, plan_id, time, time_source, seen, files[], taken: bool), responses[] (offer_id, taken, input_updated, time, time_source, file, gap_seconds), summary (booked: {time,start,end,value,file}|None, closed: {...}|None), outcome: {text, kind: 'good'|'bad'|'warn'}, warnings[], rules[] (from, until, max_utilisation, min_free_time), events[] (the timeline in order: Timestamp, rank, kind, detail, file).

Port exactly: ATL functions for dedupe (Add-Unique), timing maps (Note-Time), outcome, stats (n, avg, median, p90 with index floor(0.9*n) capped, min, max), the seven warnings, expiry rule, slot rule placement (ISO duration `minimum_start_offset`; drop non-ISO with warning; until = next rule's from). Inventory sections 3 and 4 (ATL) are the checklist.

- [ ] **Step 1: Failing tests** in `test_booking.py`, each on a synthetic zip from the fixtures: `test_request_offers_taken` (two offers, response with offer id 1: outcome 'Booked (offer 1)', kind good, offer 1 taken, gap seconds computed from first response time minus offers time); `test_declined` (response without offer id); `test_expired_no_response` (expiry earlier than the latest file time: 'Not booked, offers expired', kind bad); `test_no_slot_available` (all offers available=false); `test_no_response_yet`; `test_offers_only_in_load_snapshot` (Review Focus 2: offers only in the LOAD file: listed, `snapshot_only` count 1, not measured); `test_dedupe_first_seen_with_seen_count` (same offer in two files: one entry, seen 2, time from the first file); `test_offer_ids_unique_within_request` (two requests both with offer id 0 stay separate); `test_summary_booked_from_appointment_summary`; `test_slot_rules_placed_from_base` (rule set with PT0S and P1D offsets: from/until computed; max utilisation shown); `test_rule_set_fallback_request_then_template_then_slot_usage`; `test_seven_warnings` (one assertion per warning text from inventory 4 ATL); `test_booking_csv_columns_and_events` (column order exact; events Request / Offer / Offer (not available) / Response: offer taken / Response: declined); `test_booking_summary_lines`.
- [ ] **Step 2: Implement `BookingBuilder`, `booking_csv_rows`, `booking_summary`**; wire into `write_outputs` (`booking.csv`, `trace.json['booking']`, summary line).
- [ ] **Step 3: Run the suite; commit** "Activity trace: booking builder and booking.csv".

---

### Task 3: Page template and the Booking view (pso-tools)

**Files:** create `activity-trace/trace_template.html` (copy `scripts/task_timeline_template.html`, then change); modify `trace_activity.py` (`write_outputs` renders `trace.html`); create `tests/activity_trace/test_golden.py`.

- Template contract: placeholders `__TITLE__` and `__DATA__` (the whole trace.json as a JSON literal). Header: activity id, work task id (and its source when inferred), dataset name (from `--dataset-name`, add this CLI option), view switch `Booking | History` (buttons with `aria-pressed`; the active view stored under localStorage key `pso-trace-view`), zone switcher (dataset zone from `--tz`, UTC, viewer zone; key `pso-trace-tz`), theme toggle (key `pso-audit-theme`, as the template already does).
- Booking view (JS renders from `data.booking`): timing cards (request to offers with engine average; offers to taken; offers to declined; bookings %; outcome of the latest request; offers available; booked slot); per request: outcome pill, meta line (template, template date, window length, base, rule set and source, first-seen file), "Check this" warnings list, flow (Requested, Offers returned, Response) with times in the chosen zone, calendar of offered slots (one column per day; cells available / not available / taken; per-day "Allowed utilisation" row only when `rules_checked`; a sentence "Slot usage rules were not checked because no LOAD was given" otherwise), offers table (Offer, Slot, Length, Status, Allowed utilisation when rules, Offer value, Prospective resource, Plan, Files) with the "Available and taken only" checkbox, responses table (File time, Response, After the offers, input_updated, File).
- History view: a placeholder section "History is added in Task 6" (replaced then). Files read: table at the bottom (#, File, Type, File time, Time source, MB, Requests, Offers, Responses, Error) with `not timed` shown as text.
- Wording per Global Constraints; footer carries the inference notes from inventory section 7 (ATL ones now, PTT ones in Task 6).
- Time tokens: reuse the template's `{t:yyyyMMddTHHmmssZ:mode}` renderer for every time so the zone switcher works on both views.

- [ ] **Step 1: Failing tests**: in `test_outputs.py` add `test_html_written_with_data_and_title` (trace.html contains `const DATA =` with the JSON and the activity id in `<title>`; no `__DATA__` left); `test_html_has_both_views_and_files_table` (strings "Booking", "History", "Files read", "pso-trace-view").
- [ ] **Step 2: Build the template and renderer.** Open the resulting file in a browser locally (`python3 activity-trace/trace_activity.py --audits <fixture zip> --activity 1001 --out-dir /tmp/x` then open) and check light and dark, zone switch, calendar and tables.
- [ ] **Step 3: Golden test** `test_golden.py`: skip unless `~/pso-data/SATE/2026-10-04/audits.zip` exists; run `main` with activity id from env `TRACE_GOLDEN_ACTIVITY` (skip if unset) and assert exit 0, four outputs exist, `trace.json['booking']['requests']` non-empty. Document the env var in the test docstring.
- [ ] **Step 4: Run the suite; commit** "Activity trace: page template and Booking view".

---

### Task 4: History builder and history.csv (pso-tools)

**Files:** modify `trace_activity.py`; create `tests/activity_trace/test_history.py`.

**Interfaces (Produces):**
```python
class HistoryBuilder:
    def __init__(self, read: ReadResult, tz: str, events: list[dict] | None): ...   # events from Task 5; None for now
    def build(self) -> dict   # {'steps': [...], 'findings': [...], 'bars': [...], 'actuals': [...], 'hero': {...}, 'facts': {...}, 'notes': [...], 'counts': {'steps','inputs','plans','red','amber','green'}}
HISTORY_CSV_COLUMNS = ['UtcTime','LocalTime','Source','Activity','Role','Step','Level','Detail','File']
def history_csv_rows(history: dict, tz: str) -> list[list[str]]
def history_summary(history: dict) -> str   # '14 scheduling events, 1 check failed'
```
Step dict: utc, source ('PSO input'|'PSO plan'|'PSO baseline'|'IFS'), activity, role ('Primary'|'Related'), title, lines[] (level 'warn'|'ok'|'key'|'match'|'ctx', text), file, seq, meta (status_stamp, status, user, app, plan_id). Port exactly from PTT: per-file step construction, carried state (SLA, allocation, duration, shift, fixed), "Changed since plan X" / "Same placement" / "First allocation", window check with inclusive bounds and start_based rule, shift check with latest-start-that-fits, fixed-start fact, exception text and knock-ons keyed by exception id, clearance through Plan_Deletion, neighbour removal within 30 minutes, input source classification, severity rules, hero and facts, final placement per activity (bars). Inventory sections 3 and 4 (PTT).

- [ ] **Step 1: Failing tests** in `test_history.py`: `test_window_inside_and_outside_start_based`, `test_window_end_checked_when_not_start_based`, `test_shift_overshoot_names_latest_start_that_fits`, `test_move_between_plans_changed_since`, `test_same_placement`, `test_first_allocation`, `test_exception_and_clearance` (type 210 text from EXCEPTION_NAMES; cleared by Plan_Deletion), `test_knock_on_neighbour_removed_within_30_minutes`, `test_related_activity_steps_have_role_related`, `test_link_only_mentions_dropped`, `test_input_source_classification` (PSW, SIM, USER, UNKNOWN), `test_severity_rules` (CHECK warn red, Changed since info, other warn amber, CHECK ok green; dedupe by sev|activity|text), `test_facts_pinned_by_and_fixed_start`, `test_history_csv_columns`, `test_history_summary_line`.
- [ ] **Step 2: Implement; wire into `write_outputs`** (`history.csv`, `trace.json['history']`, `trace.json['findings']`, combined summary line "<booking>; <history>").
- [ ] **Step 3: Run the suite; commit** "Activity trace: history builder and history.csv".

---

### Task 5: IFS event log (pso-tools)

**Files:** modify `trace_activity.py`; create `tests/activity_trace/test_event_log.py`.

**Interfaces (Produces):**
```python
class EventLog:
    @staticmethod
    def read(path: str, ifs_tz: str, warn) -> list[dict]   # rows: utc, event, desc, source, user, text, note, prev, instance, task, seq, generated
    # raises EventLogError('The IFS event log has no usable Created Date column') -> exit 1 with that message
def pair_events(events, steps, tolerance=10) -> None     # marks steps' 'match' lines and events' matched step; assignMap from ASSIGNED + status 30
def planned_vs_actual(events, assign_map) -> list[dict]  # per instance: technician, activity, planned, on_route, started, completed
```
- xlsx: zipfile; `xl/sharedStrings.xml`; the first worksheet named in `xl/workbook.xml` (fall back to `xl/worksheets/sheet1.xml`); cells inline (`t="inlineStr"`), shared (`t="s"`), numeric; first row headers. OADate to datetime with seconds truncated. csv: `csv.DictReader`.
- Time zone conversion with `zoneinfo` (stdlib, Python 3.9+); if the zone is unknown, warn and use UTC.
- Work task from the log: first TASK_SEQ when nothing else gave one (`work_task_source` 'event_log'); warn when events carry a different TASK_SEQ.

- [ ] **Step 1: Failing tests**: `test_reads_xlsx_shared_and_inline_strings_and_oadate` (build a minimal xlsx with zipfile in the test), `test_reads_csv`, `test_rows_without_date_are_dropped_with_warning` (Review Focus 3), `test_log_with_no_dates_fails_naming_column`, `test_ifs_zone_defaults` (Review Focus 5: `--ifs-tz` absent uses `--tz`; both absent uses UTC with a warning), `test_pairing_window_and_best_match`, `test_only_listed_event_types_are_matched`, `test_assign_map_from_status_30`, `test_planned_vs_actual`, `test_work_task_from_event_log_marked_not_verified`, `test_missing_ifs_counterpart_warning`.
- [ ] **Step 2: Implement; pass `events` into `HistoryBuilder`; add the `--event-log` and `--ifs-tz` handling to `main`.**
- [ ] **Step 3: Run the suite; commit** "Activity trace: IFS event log reading and pairing".

---

### Task 6: History view, files read, summary, docs (pso-tools)

**Files:** modify `activity-trace/trace_template.html`, `trace_activity.py`, `scripts/INDEX.md`, `tests/activity_trace/test_outputs.py`.

- History view (JS from `data.history`): hero bar (elapsed and proportional pipe), facts, KPI tiles (steps, IFS events, PSO inputs, PSO plans, red, amber; click filters), findings table (Severity, Time, Activity, Finding; filter Needs attention / Information / All; click scrolls to the step), day strip SVG (appointment window row, shift row, one row per plan allocation with the past-shift-end segment, on route and on site rows when actuals exist; tooltips; legend), planned versus actual table (hidden without actuals), timeline (filters All / IFS / PSO inputs / PSO plans; Context lines toggle; status pills coloured from STATUS_NAMES codes; "+ N later" gaps). Keep the template's existing implementations where they exist; adapt data paths.
- Footer: add the PTT inference notes. Files read table complete.
- `summary:` line: "<booking_summary>; <history_summary>" (or just one when the other has nothing).
- INDEX.md: a paragraph under the appointments section saying `activity-trace/trace_activity.py` is now the reference implementation run by the web app, and the two scripts are the Windows versions kept until the web replaces them. CLAUDE.md parity note: the Python is reference; PowerShell unchanged for this tool.

- [ ] **Step 1: Failing tests**: `test_html_history_view_sections` (strings for findings filter labels, "Planned versus actual", "Context lines"), `test_summary_line_combined`.
- [ ] **Step 2: Build; open in a browser with the golden data if available; check both views, zone switch, filters, dark mode.**
- [ ] **Step 3: Run the suite; commit** "Activity trace: History view, files read and summary line".

---

### Task 7: IFS event log export kind and dataset setting (pso-test-tools)

**Files:** modify `app/Enums/ExportKind.php`, `app/Support/Exports/ExportSummariser.php`, the dataset settings class and `Dataset::getForm()`, `resources/views/livewire/export-drop-zone.blade.php` only if the hint builder needs the new kind (it reads `extensions()`, so probably not). Create `tests/Feature/ActivityTrace/EventLogExportKindTest.php`, `EventLogSummariserTest.php`.

- `ExportKind::EventLog = 'event_log'`; `allowedScope()` Dataset; `extensions()` `['xlsx', 'csv']`; `expectedRoot()` null; `getLabel()` 'IFS event log'. Check every `match ($this)` over ExportKind in app/ (summariser, Exports tab contents column, RunInputPicker labels, controllers) and add the new case so nothing throws `UnhandledMatchError`.
- Summariser: `summariseEventLog(string $path, string $extension)`: xlsx via `ZipArchive` + `SimpleXMLElement` (shared strings, first sheet, header row, Created Date column, OADate or text) and csv via `fgetcsv`; summary `['event_count' => n, 'first_event' => iso, 'last_event' => iso]`; a log with no datable rows is a failed summary with message "No usable Created Date column". Exports tab "Contents": "N events, <first> to <last>" (dates in the dataset zone, format `j M H:i`).
- Dataset settings: `ifs_time_zone` (optional, searchable zone select, helper "Zone the IFS event log times are written in. Blank means the dataset time zone.") on the form and the settings class.
- Upload validation: the slot/complete controllers validate extension per kind through `extensions()`; confirm a `.xlsx` passes and `.xml` is refused for this kind.

- [ ] **Step 1: Failing tests**: kind scope/extensions/label; summariser xlsx and csv (build a small xlsx with ZipArchive in the test; sample csv); failure message; Exports tab contents string; settings form accepts `ifs_time_zone`; upload slot refuses `.xml` for event_log and accepts `.xlsx`.
- [ ] **Step 2: Implement.** - [ ] **Step 3: `php artisan test --compact` (known ExampleTest failure only); pint; commit** "IFS event log export kind, summary and dataset time zone".

---

### Task 8: ActivityTraceTool, command, registry, result view (pso-test-tools)

**Files:** create `app/Tools/ActivityTrace/ActivityTraceCommand.php`, `ActivityTraceTool.php`, `resources/views/filament/tool-runs/appointment_timeline.blade.php`, tests `ActivityTraceCommandTest.php`, `ActivityTraceToolTest.php`. Modify `app/Enums/Tool.php` (label 'Trace an activity', question 'What happened to one activity: how it was booked and how PSO scheduled it?'), `app/Tools/LoadAudit/PsoToolsCheckout.php` (`activityTraceScript()` = `activity-trace/trace_activity.py`, `activityTraceTemplate()`), `app/Providers/AppServiceProvider.php` (register), `config/tools.php` (`activity_trace.timeout_seconds` 840), the tool-run download controller + `routes/web.php` (routes `tool-runs.download-booking-csv`, `tool-runs.download-history-csv` serving `result['booking_csv']` / `result['history_csv']` as text/csv with sensible file names), `app/Filament/Widgets/ToolCatalogue.php` `needs()` already derives from kinds.

- `ActivityTraceCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $ifsTimeZone, ?string $datasetName): array` returns argv: python, script, `--audits`, `--activity`/`--work-task` (whichever given; both when both), `--load` when present, `--event-log` when present, `--tz`, `--ifs-tz` when set, `--dataset-name`, `--out-dir $workDir`, `--template <checkout template>`.
- `ActivityTraceTool`: `requiredKinds()` [Audits]; `optionalKinds()` [Load, EventLog]; `optionFields()`: `TextInput options.activity_id` (label 'Activity id', helper 'The PSO activity id. Give this or the work task id.', `requiredWithout('options.work_task_id')`), `TextInput options.work_task_id` (label 'Work task id', helper 'The IFS work task id, when you do not know the activity id.'). `run()`: build argv, `ScriptRun::execute($context, $workDir, $argv, $timeout, 'The trace')`, read the four outputs, `result = ['html','data','booking_csv','history_csv']`, warnings from `warning:` lines, summary `['line' => <summary: text>, 'counts' => findings counts]`. A missing output: RuntimeException 'The trace finished but wrote no results.' Exit 2 from the script surfaces as the script's stderr line (check how ScriptRun reports non-zero exits and keep the message plain).
- Result view: badges for red/amber/green findings, summary line, four download buttons, the sandboxed iframe (copy load_audit.blade.php and adjust titles and routes).

- [ ] **Step 1: Failing tests**: command argv for audits only; with load; with event log; with work task only; with both ids; with ifs zone (Review Focus 5); tool end to end with a fake script (a tiny Python file written by the test that writes the four outputs and prints `summary: Booked...`) using `TOOLS_PSO_TOOLS_PATH` pointed at a temp dir; result page shows badges, summary and four buttons; CSV routes serve the stored text with 404 for another user; the wizard for `Tool::AppointmentTimeline` shows exactly three input rows and the two option fields; `canAccess()` true once registered; the catalogue tile text "Needs a Audit messages export; LOAD, IFS event log optional" (fix the article in `needs()` to use `aExport()`-style logic if the output reads badly: "Needs an Audit messages export").
- [ ] **Step 2: Implement.** - [ ] **Step 3: Full suite; pint; commit** "Trace an activity: tool, command, result view and CSV downloads".

---

### Task 9: Docs, notes, handoff (pso-test-tools)

- README: one paragraph in the tools list for Trace an activity (inputs, two views, downloads).
- `docs/superpowers/notes/2026-10-15-activity-trace-execution-notes.md`: per-task summary, deviations, deferred items, after-merge steps (pso-tools: pull on the box happens via the deploy cron; pso-test-tools: deploy cron; no migration unless Task 7 adds one, which it should not since settings are JSON), browser check list (Exports tab accepts an xlsx; wizard three rows; run on the SaskTel audits with a known activity; both views; zone switch; downloads; exit-2 message for a wrong id).
- Commit "Trace an activity: docs and execution notes".

---

## Self-review

Spec coverage: section 1 in Tasks 7 and 8; section 2 in Tasks 1, 2, 4, 5 (file time rule Task 1; LOAD Task 1 and 2; work task Task 1 and 5; event log Task 5; outputs Tasks 2 to 6); section 3 in Tasks 3 and 6; section 4 in Tasks 7 and 8; section 5 errors in Tasks 1, 5, 8; section 6 tests across all tasks; section 7 order is the task order. Placeholders: none ("History is added in Task 6" is a visible stand-in replaced in Task 6). Type consistency: `ReadResult` fields used by both builders; `BookingBuilder(read, latest_file_time)`; `HistoryBuilder(read, tz, events)`; `EventLog.read(path, ifs_tz, warn)`; CSV column constants; result keys `html`, `data`, `booking_csv`, `history_csv` used in Task 8's view and routes. Review Focus items each name their test.
