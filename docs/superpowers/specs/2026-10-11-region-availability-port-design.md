# Design: region availability on the web (runs the pso-tools Python) and retiring Technician Availability

Date: 2026-10-11. Status: approved in conversation; spec for review.

Builds on `2026-10-10-load-audit-port-design.md` (the first tool on the export-sets contract that runs a
pso-tools script) and on pso-tools `docs/superpowers/specs/2026-10-07-region-availability-design.md` (the
viewer itself: its rules, layout and three layers).

## 1. Problem

Which regions each technician can work on each day of a week is answered today in two places:

- pso-tools `region-availability/region_availability.py` (and its PowerShell twin): the whole team for a
  week, in three layers. The week grid (technicians down, Monday to Sunday across, shift bar and region
  change chips per cell, filters for district, supervisor, resource type and a search on id or name),
  the one-day view (click a cell: the shift with breaks, one lane per region window, every region with
  multiplier and priority) and the region pivot (regions grouped by parent, days across, technician
  counts with red and amber thresholds, click a cell for the names). Runs on the Mac and on Windows.
- pso-test-tools `TechnicianAvail` page: a dsScheduleData JSON uploaded to R2, one technician picked
  from a list, their next seven shifts drawn as a gantt with breaks and the regions open in each. Its own
  upload path, no dataset scope, its own copy of the region rules in PHP.

The web page is one technician's slice of what the viewer does, with a second implementation of the
rules. Goal: the viewer becomes the second tool on a dataset's Runs tab, run from the dataset's uploaded
LOAD exactly as the load audit runs, and the old page is removed. Success: one upload path, one place
the region rules live (the Python, shared with Windows), and nothing the old page could show that the
new tool cannot. The old page's view is the viewer's layer two reached through the search box.

## 2. Decisions made

- **Run the Python, do not port it.** Same as the load audit: the job starts `region_availability.py`
  from the pso-tools checkout (`TOOLS_PSO_TOOLS_PATH`) as a child process. No rule is reimplemented.
- **The three layers are the drill-down.** The template already has them (section 1). No template change
  and no PHP view of the data: the page is the Python's own HTML in a sandboxed iframe.
- **Give the script the dataset's settings as an account file.** Unlike the load audit, this script reads
  the account settings for real: attribute labels (which `Additional_Attribute` labels are the district
  and the supervisor), display name, time zone, excluded resource types, resource type names and the
  pivot thresholds. The web run writes them into `accounts/<ACCOUNT>.json` in the scratch folder, in the
  pso-tools account file's own key names, and points `--accounts-dir` at it. The checkout's own
  `accounts/` folder is never read, so a dataset's settings on the web are the only source.
- **One week per run.** The script produces one page per week; `--week-of` picks it. The Run form has a
  "Week of" date, blank meaning the LOAD's own week (a Saturday or Sunday LOAD shows the next week, as
  the command line does). Several weeks are several runs. The page's previous and next week links point
  at file names that do not exist on the web; they stay as they are (they 404 inside the sandbox) and are
  not a reason to change the template.
- **Store the finished HTML and the gathered JSON**, for the same reasons as the load audit: an old run
  keeps showing what it showed, and the download is the same file the command line produces.
- **One controller for every tool page.** The load audit's controller becomes the controller for any run
  whose result holds a page. Route names change; the load audit view follows.
- **Retire Technician Availability outright.** Page, view, gantt component, service, three jobs, the
  QuickLaunch tile, its test and its fixture. No redirect: the page was reached from the QuickLaunch
  widget, not from links. Uploads it made to R2 are left alone (the cleanup job already removed the
  ones it saw; anything else is a few JSON files under its upload prefix, documented in section 9).
- **Progress lines in the Python**, opt-in through `PSO_PROGRESS=lines` as in the load audit. The
  PowerShell twin is untouched: progress goes to stderr and is not output, so the parity rule holds.

## 3. Running the viewer

### 3.1 The tool

`App\Tools\RegionAvailability\RegionAvailabilityTool` implements `App\Tools\Contracts\Tool`:

- `id()`: `Tool::RegionAvailability` (the enum case exists; label "Region availability").
- `requiredKinds()`: `[ExportKind::Load]`. `optionalKinds()`: `[]`.
- `optionFields()`: one `DatePicker::make('options.week_of')`, label "Week of", helper text "Any date in
  the week to show. Blank: the week of the LOAD (a weekend LOAD shows the next week).", nullable, native
  false, no minimum or maximum (the LOAD's horizon is unknown when the form is shown).
- `run()`: writes the account file (3.3), builds the command (3.4), runs it like the load audit does
  (same `Process` call, `PSO_PROGRESS=lines`, `LANG=C.UTF-8`, cwd the scratch folder, stderr parsed
  line by line through `ProgressLine`, stdout kept), finds the outputs (3.5) and returns a `ToolResult`.

Registered in `AppServiceProvider` next to the load audit:
`$registry->register(ToolId::RegionAvailability, RegionAvailabilityTool::class);`

### 3.2 Configuration

- `config/tools.php` gains `'region_availability' => ['timeout_seconds' => (int) env('TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS', 600)]`.
  The script reads one LOAD and does no checks; SaskTel's LOAD takes well under a minute.
- `PsoToolsCheckout` (stays in `App\Tools\LoadAudit`, moving it is churn) gains
  `regionAvailabilityScript()` returning `<root>/region-availability/region_availability.py`. Its error
  messages drop "The load audit" for "The pso-tools checkout" so they read right from either tool:
  "The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set." The existing
  checkout test is updated for the wording.

### 3.3 The account file

`App\Tools\RegionAvailability\AccountSettingsFile` is pure apart from one `file_put_contents`:

```php
/** Writes <dir>/<ACCOUNT>.json in the key names the pso-tools scripts read; returns the path. */
public static function write(?DatasetSettings $settings, string $dir, string $account): string
```

- `$account` is the dataset's environment `account_id`, upper-cased, with anything outside
  `[A-Za-z0-9]` removed; `ACCOUNT` when that leaves nothing. The same string is passed as `--account`,
  so the script never has to infer the account from the LOAD and the file name always matches.
- The JSON is `DatasetSettings::toArray()` with the aliases reversed (`attribute_labels` to
  `attributes`, `dataset_display_name` to `dataset_name`, `region_labels` to `regions`), null values
  dropped, pretty printed, unescaped slashes and unicode. A null `$settings` writes `{}` so the script's
  "no settings file" warning never fires on the web (the defaults are the script's own, which is what
  the command line gives a dataset without an account file).
- Written to `<scratch>/accounts/`, created `0770` if missing.

`DatasetSettings` gains `public static function toAccountFile(DatasetSettings $s): array` next to
`fromAccountFile()`, so the two aliases live in one class and round trip:
`fromAccountFile(toAccountFile($s))` equals `$s` for every key.

### 3.4 The command

`App\Tools\RegionAvailability\RegionAvailabilityCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, string $account): list<string>`,
pure like `LoadAuditCommand`:

```
<python> -I <checkout>/region-availability/region_availability.py
  --load <inputPaths['load']>
  --account <account>
  --time-zone <timeZone>
  --accounts-dir <workDir>/accounts
  --out-root <workDir>/out
  [--week-of YYYY-MM-DD]        only when options.week_of is filled; the form value is normalised to a date string
```

No `--template`: the script's default is the template next to it in the checkout, which is the one
Windows uses. `--time-zone` is the run's zone (`ToolContext::$timeZone`, the dataset's setting), which
beats the account file and the LOAD's own templates, as it does on the command line.

### 3.5 What the run stores

The script writes `<out-root>/<ACCOUNT>/<DATASET>/region_availability_<Monday>.html` and `.json`,
where `<DATASET>` comes from the LOAD's `Input_Reference.dataset_id`. The tool does not predict the
folder: it globs `<workDir>/out/*/*/region_availability_*.html`, expects exactly one, and reads the
`.json` beside it. None or several is "The viewer finished but wrote no page." (a `RuntimeException`,
so the run fails with that message).

```
result  (encrypted)   html: the page as rendered
                      data: the gathered JSON, decoded (format, meta, regions, groups, types, techs)
summary (plain)       week: "2026-10-05"            meta.week, the Monday
                      counts: {techs, shifts, techs_with_changes}   from meta.counts
                      line: "118 technicians, 9 with changes, week of 2026-10-05"
warnings              every stdout line starting "WARNING: " with the prefix removed
                      (e.g. "No shifts in this week.", "No Availability rows: ...")
```

The Runs tab's summary column shows `summary['line']` when a summary has one, otherwise the existing
red/amber/green formatting for the load audit, otherwise its placeholder. That is the only change to
`ToolRunsRelationManager`.

On a non-zero exit the tool fails like the load audit: "The viewer stopped while <stage>: <last stderr
line>", where stage is the last progress label lower-cased ("reading the load", "working out regions").
A `ProcessTimedOutException` becomes "The viewer took longer than 10 minutes and was stopped."

## 4. Live progress

### 4.1 pso-tools: opt-in progress lines in `region_availability.py`

Same convention as `audit_load.py`, kept small because this script has one input:

- `read_tables()` wraps the file object in a byte counter when `PSO_PROGRESS=lines`, knowing the size
  from the file (or the zip member's `file_size`), and writes `PROGRESS {"stage":"load","percent":N}`
  at 0, at most twice a second as it reads, and at 100. Otherwise it writes nothing (the command line
  stays as it is; no terminal progress is added).
- Before `gather()` it writes `PROGRESS {"stage":"gather"}` (no percentage).
- `main()` behaviour, arguments and outputs are unchanged. Build-RegionAvailability.ps1 is unchanged.
  `python3 -m unittest discover -s tests/region_availability -t .` must still pass, and one new test
  runs `main()` with `PSO_PROGRESS=lines` on the synthetic LOAD and asserts the stderr lines
  (0, 100 for load, then gather) and that without the variable stderr is empty.

### 4.2 pso-test-tools

`ProgressLine::STAGES` gains `'gather' => 'Working out each technician\'s regions'`. Everything else
(the 2 s throttle, the run page's bar, the Runs tab label) already works for any tool.

## 5. Pages, routes and screens

### 5.1 One controller

`LoadAuditRunController` is renamed `ToolRunPageController` (`app/Http/Controllers/ToolRunPageController.php`)
and serves any finished dataset run whose result holds `html`:

```
GET datasets/{dataset}/runs/{run}/page            tool-runs.page            the HTML under the CSP sandbox
GET datasets/{dataset}/runs/{run}/download/html   tool-runs.download-html
GET datasets/{dataset}/runs/{run}/download/json   tool-runs.download-json   result['data'] ?? result['findings']
```

- `find()` loses the `where('tool', LoadAudit)` filter; the 404 rules otherwise stay (another user's
  dataset, a run of another dataset, no `html` yet). A run of a tool without a page (none today) 404s
  through the `html` check.
- The download name is `<tool id>_<account>_<dataset>_<date>.<ext>`; `<date>` is `summary['week']`
  when present (the week Monday, which is what the command line names the file after), else the run
  date. Same cleaning as today.
- The load audit view switches to the new route names; its tests follow. The old `load-audit.*` route
  names are gone (nothing outside the app links to them).

### 5.2 The run page

`resources/views/filament/tool-runs/region_availability.blade.php`, the load audit view's shape: a
section with the counts as badges (technicians, shifts, with changes) and the week, the two download
buttons (HTML, JSON), then the iframe with the same `sandbox` attribute and height. Without `html`
in the result it shows "The page is not available for this run."

### 5.3 Retirement

Deleted:

```
app/Filament/Pages/TechnicianAvail.php
app/Services/TechnicianAvailabilityService.php
app/Jobs/GetTechniciansListJob.php
app/Jobs/GetTechnicianShiftsJob.php
app/Jobs/CleanupTechnicianUploadJob.php
resources/views/filament/pages/technician-avail.blade.php
resources/views/components/technician-gantt.blade.php
tests/Feature/TechnicianAvailabilityServiceTest.php
tests/Fixtures/sample-technician-shifts.json       only the deleted page read it
```

`QuickLaunch` loses the "Technician Availability" tile and its import. `TechnicianDetails` (live API,
`PSOResourceBasePage`) is a different page and stays. README: the Technician Availability line (if any)
goes; the Exports section gains "### Region availability" beside the load audit's entry, saying what
a run shows and that the per-technician view is the search box plus a click on a day.

## 6. Errors and limits

- Setup errors (`ToolSetupException`: no checkout path, script missing, Python missing) surface as
  today, before the process starts.
- Script exits non-zero (no `Input_Reference`, unreadable zip, bad `--week-of`): section 3.5 wording.
- A week with no shifts is not an error: the page renders with "No shifts in this week." and the
  warning is kept on the run.
- Input is the run's own scratch copy of the LOAD; the script reads nothing else but its template and
  the generated account file. `python3 -I`, as for the load audit.
- The page can be 1 to 2 MB for SaskTel (the gathered JSON is inlined in the HTML, and stored a second
  time decoded in `data`). Within the 16 MB `result` column; the load audit's deferred minor about the
  Runs tab loading `result` on every poll gets heavier and is still a follow-up, not part of this.

## 7. Testing

### 7.1 pso-test-tools (Pest)

- `tests/Unit/RegionAvailability/RegionAvailabilityCommandTest.php`: the argv with and without
  `week_of`; `--account` and `--time-zone` present; `--accounts-dir` and `--out-root` under the work dir;
  a form value `2026-10-07` and a Carbon instance both become `--week-of 2026-10-07`.
- `tests/Unit/RegionAvailability/AccountSettingsFileTest.php`: writes SaskTel-like settings and reads
  the file back: `attributes.district`, `display_name`, `exclude_resource_types`, `region_pivot`,
  `resource_type_names`, `time_zone` under the pso-tools key names, no `attribute_labels` key, nulls
  dropped; null settings write `{}`; account cleaning (`sate` to `SATE`, `' '` to `ACCOUNT`).
- `tests/Unit/DatasetSettingsTest.php` (exists): `fromAccountFile(toAccountFile($s))` round trip.
- `tests/Feature/RegionAvailability/RegionAvailabilityToolTest.php` with a fake
  `tests/Fixtures/pso-tools/region-availability/region_availability.py` (same pattern as the fake audit:
  `FAKE_RA_MODE` ok | fail | nopage, `FAKE_RA_ARGS_FILE`; writes `out/SATE/NORTH/region_availability_2026-10-05.html`
  and `.json` with a small `meta`, prints a `WARNING:` line, emits load 0/100 and gather progress):
  registered in the registry; argv, cwd and env recorded; the account file exists next to the run and
  holds the dataset's labels; `result.html`, `result.data.meta.week`, `summary` and `warnings` as in
  3.5; the stage label after the run is "Working out each technician's regions"; `fail` gives the
  "stopped while" message; `nopage` gives "wrote no page"; a `week_of` option reaches the script.
- `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php`: the generic controller serves the
  stored page under the sandbox for a region availability run and still for a load audit run; JSON
  download returns `data` for one and `findings` for the other; file names
  `region_availability_SATE_NORTH_2026-10-05.json` (from `summary.week`) and
  `load_audit_..._<run date>.html`; the run page shows the badges, the two buttons and the iframe; the
  Runs tab shows the summary line; the Run form offers "Week of" and rejects `not a date`.
- `tests/Feature/RegionAvailability/RegionAvailabilityGoldenTest.php`, skipped unless
  `TOOLS_PSO_TOOLS_PATH` and `REGION_AVAILABILITY_SAMPLE_DIR` are set: runs the job on a real LOAD and
  the script directly with the same account file and week, and compares the two gathered JSONs apart
  from `meta.generated`.
- Retirement: `tests/Feature/TechnicianAvailabilityRetiredTest.php` asserts the page class no longer
  exists, `/admin/technician-avail` is 404 for a logged-in user, and the QuickLaunch widget's groups
  carry no "Technician Availability" label. The existing load audit page tests pass after the route
  rename. Full suite green (the pre-existing `ExampleTest` failure aside).

### 7.2 pso-tools (unittest)

`tests/region_availability/test_engine.py` gains the progress test from 4.1. The existing engine and
parity tests pass unchanged.

## 8. Deploy

1. pso-tools: commit the progress lines to `main` and push. The server checkout at `/var/www/pso-tools`
   updates through the pull deploy (or `git pull` by hand until issue #18 is done).
2. pso-test-tools: merge to `main` and push. Deploy sequence as the load audit's (no migration this
   time). Then open a dataset, Runs tab, "Region availability", pick a week, run.

## 9. Out of scope

- A per-technician view beyond the template's three layers (the old page's single-technician gantt).
  Revisit only if it is missed once the tool is in use.
- Linking consecutive weeks on the web (the template's previous and next links), or running several
  weeks in one go.
- Deleting the old page's uploads from R2 (the `uploads/` folder on the `r2` disk, a handful of JSON files;
  remove by hand from the bucket if wanted).
- The Runs tab loading the encrypted `result` on every poll (a load audit deferred minor in `docs/superpowers/notes/2026-10-11-load-audit-execution-notes.md`, still a
  follow-up).
- Rogers: `ROGE.json` has no `attributes.district`, so its district filter is empty on the command line
  and will be on the web too until the Rogers dataset's settings name the attribute. A settings
  question, not a code change.
