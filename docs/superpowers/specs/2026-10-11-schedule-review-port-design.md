# Design: daily schedule review on the web (runs the pso-tools Python)

Date: 2026-10-11. Status: approved in conversation; spec for review.

Third tool on the export-sets contract, after the load audit (`2026-10-10-load-audit-port-design.md`) and
region availability (`2026-10-11-region-availability-port-design.md`). Read those first: the run mechanics
(`ScriptRun`, `PsoToolsCheckout`, `AccountSettingsFile`, `ToolRunPageController`, progress lines) are
reused as they are and are not re-described here. The weekly and monthly roll-up is a separate, later spec
(section 9).

## 1. Problem

The daily schedule review (`pso-tools/schedule-review/schedule_review.py`, PowerShell twin
`Build-ScheduleReview.ps1`) compares the morning LOAD and plan with the end-of-day export and shows how the
day went: booked work and its fates, on-time performance, planned versus actual per technician,
utilisation, duration estimates versus actuals, reassignments, overtime, and with the day's audit messages
an appointments section. It is the tool used every morning, and the one the load audit links to. Today it
runs on the laptop in PowerShell (about two minutes) or on the Mac in Python.

Goal: run it from a dataset's Runs tab against the uploaded LOAD, plan, EOD and optional audits zip, with
live progress, its own dashboard on the run page, and the day-over-day trend lines working, which means
the 60-day history the script keeps between runs has to live in the app.

Success: a run on the web produces the same data file as the command line given the same inputs and the
same history; trend lines on the dashboard grow as days are run; a weekday run fits in the box's memory.

## 2. Findings that shape the design

- **Speed is fine.** The Python runs the SaskTel Sunday set (load 2.7 MB, plan 1.2 MB, EOD 3.8 MB zipped)
  in 6.5 seconds. The two minutes is PowerShell 5.1 on the laptop.
- **Memory is not.** Peak resident set was 554 MB on that Sunday set: 145 MB after the LOAD, 250 MB after
  the plan, 493 MB after the EOD, before the engine ran. `read_tables()` keeps every field of every row of
  every wanted table as a dict of strings (77,000 `Additional_Attribute` rows twice, 24,000 `Plan_Break`
  rows, the whole EOD). A weekday is plausibly twice that. LXC 199 has 1 GB with MariaDB, Apache and the
  PHP worker resident (about 160 MB used, 860 MB available at idle); the load audit peaks at 146 MB.
- **State between runs.** The script reads and writes `output/<ACCOUNT>/<DATASET>/history/history.json`
  (headline rows per view, used for the trend lines) and `dur_history.json` (duration visits for the
  estimate-versus-actual history), both trimmed to 60 days and with same-day rows replaced. Each web run
  gets a fresh scratch folder, so the app must keep this state per dataset and hand it to the script.
- **Settings.** The script reads `attributes`, `coverage_km`, `customer_activity_types`, `dataset_name`,
  `display_name`, `eod_cutoff_hour`, `exclude_resource_types`, `ignore_skills`, `notes`, `regions`,
  `resource_type_names`, `supervisor_label`, `supervisor_label_plural`, `time_zone`. Three are not dataset
  settings yet: `customer_activity_types`, `ignore_skills`, and the roll-up's `week_start`.
- **Export sets already know the inputs.** `ExportKind` has `load`, `plan`, `eod`, `audits`; the picker
  preselects one day's set; the audits summariser drops contact fields.

## 3. Decisions made

- **Run the Python, do not port it.** As before. One pso-tools change in two parts: a memory diet in the
  reader and opt-in progress lines, both with byte-identical output so the PowerShell twin is untouched.
- **Two specs.** The daily review here; the roll-up later, once stored runs exist to design against. Each
  run keeps its full data JSON so the roll-up can read it (section 5).
- **History is one blob per dataset, every successful run reads it before and writes it after, last
  writer wins.** A second schedule review is refused while one is queued or running on the same dataset.
  A failed run leaves the stored history untouched. Re-running a day is allowed; the script replaces that
  day's rows itself.
- **No run options.** The day is whatever the chosen files are. The run date is the local day the script
  works out from the LOAD.
- **Memory: diet and headroom.** The diet (section 7) targets under 250 MB peak on the Sunday set; the LXC
  goes to 2 GB (`pct set 199 --memory 2048` on the Proxmox host, Ish's step, no restart). Both, because
  a weekday set has not been measured.

## 4. Running the review

### 4.1 The tool

`App\Tools\ScheduleReview\ScheduleReviewTool` implements `Tool`:

- `id()`: `Tool::ScheduleReview`. `requiredKinds()`: `[Load, Plan, Eod]`. `optionalKinds()`: `[Audits]`.
  `optionFields()`: `[]`.
- `run()`:
  1. Account name and account file exactly as region availability (`AccountSettingsFile`).
  2. `ScheduleReviewHistory::seed($context, $workDir)` writes the dataset's stored `history` and
     `dur_history` blobs into `<workDir>/out/<ACCOUNT>/<DATASET>/history/history.json` and
     `dur_history.json` when they exist (section 4.3 says how `<DATASET>` is known before the run).
  3. `ScheduleReviewCommand::build(...)` (4.2), `ScriptRun::execute(..., 'The review')`, timeout from
     `tools.schedule_review.timeout_seconds` (default 600).
  4. Finds the one `<workDir>/out/*/*/schedule_review_<day>.html`, reads the `.json` beside it
     (`schedule_review_data_<day>.json`) and both history files; none or several is "The review finished
     but wrote no page."
  5. `ScheduleReviewHistory::store($context, ...)` saves the two history files back.
  6. Returns the `ToolResult` (4.4).

Registered in `AppServiceProvider` beside the other two.

### 4.2 The command

`ScheduleReviewCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, string $timeZone, string $account): list<string>`:

```
<python> -I <checkout>/schedule-review/schedule_review.py
  --load <load> --plan <plan> --eod <eod>
  [--audits <audits>]            only when the run has one
  --account <account>
  --time-zone <timeZone>
  --accounts-dir <workDir>/accounts
  --out-root <workDir>/out
```

No `--template` (the checkout's), no `--org-label` (the account file's `display_name` and `dataset_name`
give the title). `PsoToolsCheckout` gains `scheduleReviewScript()`.

### 4.3 History

New table `tool_states`:

| column | type | notes |
|---|---|---|
| id | ulid | |
| scope_type, scope_id | morph | the dataset |
| tool | string | `schedule_review` |
| key | string | `history` or `dur_history` |
| state | longtext, `encrypted:array` | the file's JSON, decoded; the duration visits carry technician ids |
| updated_at | timestamp | |

Unique on (scope_type, scope_id, tool, key). Model `ToolState` (`ScopeOwnedModel`). The roll-up spec
will use the same table.

`App\Tools\ScheduleReview\ScheduleReviewHistory`:

- `seed(ToolContext $context, string $workDir, string $account, string $dataset): void` writes each
  stored blob to `<workDir>/out/<account>/<dataset>/history/<key>.json` (compact JSON, folder created).
  Nothing is written when nothing is stored: the script then starts an empty history, as on a first run.
- `store(ToolContext $context, string $historyDir): void` reads `history.json` and `dur_history.json`
  from the folder the script wrote and upserts both rows.

`<dataset>` is the script's `safe()` of the LOAD's `Input_Reference.dataset_id`. The run knows it before
the script starts from the LOAD export's stored summary (`ExportSummary` already records `dataset_id`; if
it is missing from an old row, the tool reads the first `Input_Reference` from the scratch LOAD with
`XMLReader` the way the summariser does). After the run the tool uses the folder the script actually
wrote, so a mismatch would show up as a seeded folder the script ignored and a history that restarts;
the tool warns "History was not found for dataset X; trend lines start today." in that case.

**Refusing a concurrent run.** The Run action's `->action()` in the shared `ToolRunsRelationManager`
gains a hook: a tool may implement `App\Tools\Contracts\RefusesConcurrentRuns` (marker interface). When
it does, and a run of the same tool on the same scope is `queued` or `running`, the action sends a
warning notification "A schedule review is already running on this dataset. Wait for it to finish." and
creates nothing. The check is one query; the small race between two users clicking at once is
accepted, because the script replaces same-day rows and the last writer wins anyway.

### 4.4 What the run stores

```
result  (encrypted)  html: the dashboard as rendered
                     data: schedule_review_data_<day>.json decoded (meta, scope_list, scopes, shared, appointments)
summary (plain)      day: "2026-10-04"                        meta.day_iso
                     counts: {booked, completed_pct, ontime_pct, views}
                     line: "7,620 jobs, 91% completed, 84% on time, 4 views"
warnings             every stdout line starting "WARNING: " with the prefix removed
```

`counts` come from the `all` scope's history row the script just wrote (`booked`, `completed_pct`,
`ontime_pct`) and `len(scope_list)`. `run_date` on the row is set to `meta.day_iso`. The data JSON is
0.4 MB and the page 0.6 MB on the Sunday set; the Runs tab deferred minor (loading `result` per poll) is
still a follow-up.

### 4.5 Settings

`DatasetSettings` gains three keys, with form fields on the dataset edit form and pass-through in
`toAccountFile()` / `fromAccountFile()` (no aliases):

| key | type | form | used by |
|---|---|---|---|
| `customer_activity_types` | list of strings | TagsInput "Customer job types" (blank: inferred) | review |
| `ignore_skills` | list of strings | TagsInput "Skills to ignore" | review |
| `week_start` | `monday` or `sunday` | Select "Week starts on" (default monday) | roll-up (later) |

`datasets:seed-settings` accepts them from the account file as it does the others.

## 5. Page, progress and links

- **Run page view** `filament/tool-runs/schedule_review.blade.php`: badges for jobs booked, completed
  percentage, on-time percentage and views, the day, the two download buttons (HTML, data JSON), the
  warnings list, then the sandboxed iframe. Same controller and routes as the other tools; download name
  `schedule_review_<account>_<dataset>_<day>.<ext>` (the controller uses `summary['day']` when present,
  alongside the existing `week` rule).
- **Progress.** `ProgressLine` gains stages `eod` ("Reading the end-of-day export"), `audits` ("Reading
  the appointment audits"), `engine` ("Calculating the views") and `write` ("Writing the dashboard").
  The script's existing stamped stdout log is unchanged and is kept as the failure tail.
- **Cross-links.** The dashboard's "load audit" button and the audit's "schedule review" button already
  point at the dataset's Runs tab; unchanged.
- **Runs tab** shows `summary.line` through the existing rule.

## 6. Errors and limits

- Setup errors as before. Script exits (no `Input_Reference`, plan not from the load, EOD without
  statuses, unknown time zone) surface as "The review stopped while <stage>: <last line>".
- Mismatched files (plan built from another input reference, EOD from another day, EOD after the cut-off
  hour) are the script's `WARNING:` lines and are kept on the run, not errors.
- Memory: if the kernel kills the Python the run fails "stopped while <stage>: " with no text. The README
  names this symptom and the 2 GB setting.
- Timeout 600 s; the script takes under 10 s on the Sunday set.

## 7. pso-tools changes (Part A of the plan)

Both in `schedule-review/schedule_review.py`, both output-neutral:

1. **Field whitelist in `read_tables()`.** A `FIELDS` map, table to the set of field names the engine
   reads (derived by reading `run_engine()` and the helpers; `Additional_Attribute` keeps
   `activity_id`, `resource_id`, `label`, `label_value`; `Plan_Break` only what the break logic uses;
   and so on). Rows keep only those fields. Tables not in the map keep everything. Proof: run the
   Sunday set before and after with the same history and compare `schedule_review_data_<day>.json`
   field by field with lists sorted (list order varies run to run, per `CLAUDE.md`); identical. Peak
   memory measured with `/usr/bin/time -l`; target under 250 MB. `Build-ScheduleReview.ps1` is not
   touched, because its output does not change; the plan may note the same diet as a later Windows
   speed-up.
2. **Progress lines** on `PSO_PROGRESS=lines`: the `CountingReader` pattern from region availability
   around each `read_tables()` with stages `load`, `plan`, `eod`, `audits` (0, throttled, 100), then
   `engine` before `run_engine()` and `write` before the files are written. Silent otherwise.

Tests: `tests/schedule_review/test_progress.py` (lines mode emits the stages in order; silent without),
`tests/schedule_review/test_fields.py` (every field named in `FIELDS` is read somewhere in the module, so
the map cannot silently drift; and a synthetic three-file set produces identical data JSON with and
without the whitelist, by temporarily disabling it).

## 8. Testing (pso-test-tools)

- Unit: `ScheduleReviewCommandTest` (with and without audits; argument order), `DatasetSettingsTest`
  additions (the three keys round trip; `week_start` rejects anything but monday or sunday).
- Feature `ScheduleReviewToolTest` with a fake `tests/Fixtures/pso-tools/schedule-review/schedule_review.py`
  (`FAKE_SR_MODE` ok | fail | nopage | hang; writes the page, the data file and both history files, reading
  any history it was given and appending today's row; prints one `WARNING:` line): registered; argv, cwd
  and env; account file present; first run stores two `tool_states` rows; second run seeds them into the
  folder and the fake appends (stored history has two days); failed run leaves the stored rows unchanged;
  `nopage`; timeout; summary and warnings as 4.4; `run_date` set from the data.
- Feature `ScheduleReviewPagesTest`: run page badges, buttons, iframe; download names with `day`; Runs tab
  line; the Run action is refused with the notification while a review is running on the dataset, and
  allowed when the running one belongs to another dataset or another tool.
- Feature `ToolStateTest`: owned-by-user scoping, unique upsert.
- `ScheduleReviewGoldenTest`, skipped unless `TOOLS_PSO_TOOLS_PATH` and `SCHEDULE_REVIEW_SAMPLE_DIR` are
  set: web run from an empty history versus the command line from an empty history, data JSON compared
  with sorted lists apart from `meta` fields that carry generation time; then a second web run on the same
  set and the stored history has one row per view for that day.

## 9. Out of scope

- The weekly and monthly roll-up: its own spec. It will read `result.data` of the dataset's succeeded
  schedule review runs for the period plus `tool_states` history, and needs the Tool contract to allow a
  tool with no export kinds and run-derived inputs.
- Any template change, the appointments timeline links in the dashboard (they name a PowerShell command),
  and the Windows side beyond the untouched PowerShell.
- Memory work beyond the whitelist (for example streaming the EOD twice) unless the weekday measurement
  says the 2 GB box still cannot hold it.
- Removing `tool_states` rows when a dataset is deleted. Nothing removes a deleted dataset's export files
  or runs today either (morph relations, no cascade), so `tool_states` follows the same rule for now and
  the three go into one later housekeeping change together.
