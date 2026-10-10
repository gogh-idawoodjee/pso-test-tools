# Design: load audit on the web (runs the pso-tools Python)

**Date:** 2026-10-10
**Status:** Approved in conversation, pending spec review
**Repos:** pso-test-tools (the tool, progress, dashboard) and pso-tools (one small progress change to
`load-audit/audit_load.py`). Builds on `2026-10-10-export-sets-design.md` (export files, tool runs,
`RunToolJob`, the `Tool` contract, dataset settings).

## 1. Problem

The load and configuration health audit (`load-audit/audit_load.py` in the private `pso-tools` repo,
about 2,400 lines, with a PowerShell 5.1 twin held at full parity) is the first tool to move onto export
sets. A PHP port would add a third implementation to keep at parity until PowerShell is retired. Python
3.13 is already installed in the app container, so the web runs the Python reference itself.

Success: a user picks a dataset's LOAD (plus an optional plan and the environment's SYS), the run shows
live progress, and the finished run shows the same dashboard and the same findings the command line
would produce from the same files and settings.

## 2. Decisions made

- **Run the Python, do not port it.** The job starts `audit_load.py` as a child process. No check is
  reimplemented in PHP.
- **Point at a checkout, do not copy.** `pso-tools` is private and `pso-test-tools` is public, and a copy
  would publish the audit, its template, the parameter reference and the release-notes watchlist. The
  app reads the script and the `kb/` files from a pso-tools checkout named by `TOOLS_PSO_TOOLS_PATH`
  (`/var/www/pso-tools` on the server, the repo itself on the Mac). Updating the audit is a `git pull`
  there.
- **No change to the audit's logic or output.** The audit takes only `display_name`, `time_zone` and
  `geo_bbox` from an account file, and neither account file sets `time_zone` or `geo_bbox`. The web
  passes `--org` and `--tz` from the dataset settings and an empty `--accounts-dir`, so the account files
  in the checkout are never read. The only Python change is an opt-in progress output (section 4), which
  does not touch the parity rule.
- **Store the finished HTML.** This departs from export-sets section 2 ("dashboards are rendered from
  the result on request, not stored") on purpose: an old run must keep showing what it showed on the day,
  even after the template or the data shape changes in pso-tools, and the download must be the same file
  a customer receives today. The rendered page is about 0.5 MB for SaskTel; it sits in the encrypted
  `result` with the findings.
- **Reuse the template as-is.** The dashboard is the Python's own page in a sandboxed iframe, not a Blade
  rebuild.
- **Live progress is required**, and the mechanism is generic so every later port can use it.

## 3. Running the audit

### 3.1 The tool

`App\Tools\LoadAudit\LoadAuditTool` implements `App\Tools\Contracts\Tool` and is registered for
`Tool::LoadAudit` in `AppServiceProvider`.

- `requiredKinds()`: `load`. `optionalKinds()`: `plan`, `sys` (the SYS comes from the dataset's
  environment, which `RunToolJob` already allows).
- Run options, shown in the Run action form: **IFS release** (optional text, e.g. `25.2.7`, validated
  against `^\d+(\.\d+){1,3}$`) and **Finished work kept for (hours)** (integer 0 to 168, default 24).
  The `Tool` contract gains `optionFields(): array` (Filament form components named `options.*`);
  `ToolRunsRelationManager` appends them to the Run form. The `Tool` interface has no default, so every
  tool implements it (returning `[]` when it has none).

### 3.2 Configuration

`config/tools.php`:

| Key | Env | Default |
| --- | --- | --- |
| `python` | `TOOLS_PYTHON` | `python3` |
| `pso_tools_path` | `TOOLS_PSO_TOOLS_PATH` | null |
| `load_audit.timeout_seconds` | | 840 (under the job's 900) |

Paths derived from `pso_tools_path`: `load-audit/audit_load.py`, `kb/pso_parameters_reference.csv`,
`kb/28-release-notes-watchlist.md`.

### 3.3 The command

Built as an argument list for `Symfony\Component\Process\Process` (never through a shell):

```
<python> -I <pso-tools>/load-audit/audit_load.py
  --load <scratch>/<load file>
  [--plan <scratch>/<plan file>]
  [--sys <scratch>/<sys file>]
  --tz <dataset time zone>
  [--org <settings.display_name>]
  --accounts-dir <scratch>/accounts          (created empty)
  --params <pso-tools>/kb/pso_parameters_reference.csv
  --watchlist <pso-tools>/kb/28-release-notes-watchlist.md
  [--ifs-release <option>]
  --complete-grace-hours <option, default 24>
  --review <dataset Runs tab url, ?relation=toolRuns>
  --out <scratch>/load_audit.md --json <scratch>/load_audit.json --html <scratch>/load_audit.html
```

- `--org` is omitted when the dataset has no display name; the script then titles the page from the
  dataset id, as it does today.
- `--sys` is always explicit when a SYS was chosen. When none was chosen, the scratch folder holds no
  other DsSystemData file, so the script's "find a SYS next to the LOAD" search finds nothing.
- Working directory: the run's scratch folder. Environment: only `PATH`, `LANG=C.UTF-8` and
  `PSO_PROGRESS=lines`.

### 3.4 What the run stores

`ToolResult::$result`, stored through the existing `encrypted:array` cast:

| Key | Content |
| --- | --- |
| `html` | the contents of `load_audit.html` |
| `findings` | the decoded `load_audit.json` (the list of findings) |
| `counts` | `{red, amber, green}`, counted from `findings` by `severity` |

`ToolResult::$warnings`: every stdout line that starts with `warning:`, with the prefix removed (for
example a red or amber check with no recommendation). The markdown report is written to scratch and
discarded.

## 4. Live progress

### 4.1 pso-tools: opt-in progress lines

- When the environment variable `PSO_PROGRESS` equals `lines`, `ReadProgress` reports even when stderr
  is not a terminal, and writes one line per update instead of rewriting a single line:

  ```
  PROGRESS {"stage":"load","percent":45,"seconds_left":6}
  ```

  At most twice a second, as now. `seconds_left` is omitted until there is an estimate.
- Stages: `load`, `plan`, `sys` (with `percent` while that file is read) and `checks` (no percent),
  written each time checks start. `main()` reads the SYS after the first checks, so a full run reports
  `load`, `plan`, `checks`, `sys`, `checks`. Each `read_*` function passes its stage to `ReadProgress` instead of
  the stage being guessed from the file name.
- Without the variable, stdout and stderr are byte for byte what they are today. `Build-LoadAudit.ps1` is
  not changed; it has its own progress output.

### 4.2 pso-test-tools: generic progress on a run

- Migration: `tool_runs.progress` json, nullable: `{label, percent, seconds_left}`.
- `ToolContext::reportProgress(string $label, ?int $percent = null, ?int $secondsLeft = null): void`
  saves the column at most once every 2 seconds, and at once when the label changes. `RunToolJob` sets
  it to null when the run succeeds or fails.
- `LoadAuditTool` reads stderr through the Process output callback, parses each `PROGRESS` line and
  reports plain English:

  | Stage | Label |
  | --- | --- |
  | load | Reading the LOAD (with "45%, about 6 s left") |
  | plan | Reading the plan |
  | sys | Reading the SYS file |
  | checks | Running the checks |

  Other stderr text is kept (last 50 lines) for the failure message.
- The run page polls every 2 seconds (was 5) while the run is queued or running and shows the label
  with a progress bar when there is a percent. The Runs tab shows the label under the Running badge.

## 5. Dashboard and screens

- **Run page.** The existing header stays (tool, status with progress, run date, started by, warnings,
  inputs). The load audit's view `filament.tool-runs.load_audit` adds:
  - a strip with the red, amber and green counts;
  - **Download HTML** (`load_audit_<environment account_id>_<dataset name>_<run date>.html`, the stored page, with
    anything outside `A-Za-z0-9_-` replaced by `_`) and
    **Download findings JSON**;
  - the dashboard in a full-height iframe.
- **Dashboard route.** `GET /datasets/{dataset}/runs/{run}/dashboard` (auth, panel access, run on that
  dataset and owned by the user, else 404) returns `result.html`.
- **Containing the page.** The HTML carries text from customer files and builds the page with
  JavaScript, so it never runs with the app's origin:
  - iframe `sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"`
    (no `allow-same-origin`);
  - the route sends `Content-Security-Policy: sandbox allow-scripts allow-downloads allow-popups
    allow-top-navigation-by-user-activation`, so the same holds if the URL is opened directly.

  The in-page job ID CSV download, the theme toggle and the Google Fonts keep working.
- **Schedule review link.** The dashboard's "Open the schedule review" button goes to the dataset's Runs
  tab (the `--review` value) until the schedule review is ported.
- **Runs tab.** A load audit row shows the red, amber and green counts next to its status.
- **Additional Tools.** `LoadAuditEntry` already exists; no change.

## 6. Errors and limits

| Situation | Run result |
| --- | --- |
| `TOOLS_PSO_TOOLS_PATH` unset, script or `kb/` file missing, Python will not start | Failed: "The load audit is not set up on this server: <what is missing>." |
| Script exits non-zero | Failed: "The audit stopped while <last stage label, lower case>: <last non-blank stderr line>." Full stderr to the log (server side only). |
| Exit 0 but `load_audit.json` or `load_audit.html` missing or unreadable | Failed: "The audit finished but wrote no results." |
| Timeout (840 s) | Process stopped. Failed: "The audit took longer than 14 minutes and was stopped." |

- The Run action stays visible when the tool is not set up; the failure says what is missing.
- Memory: the Python peaked at about 146 MB on the SaskTel LOAD and plan (Mac, 2026-10-10, about 10 s).
  The container goes from 512 MB to 1 GB before this ships. No cap is set; a process killed for memory
  shows as a non-zero exit.
- Swapped files cannot reach the script: export sets already rejects a plan uploaded as a LOAD and a
  file without a Plan block uploaded as a plan. (The command line crashes in `render()` when given a LOAD
  as `--plan`; that is a separate small fix in pso-tools, not part of this spec.)
- One queue worker per app runs runs one at a time; no locking.

## 7. Testing

### 7.1 pso-test-tools

- **Fake script (runs everywhere, including CI).** `tests/Fixtures/pso-tools/` holds a fake
  `load-audit/audit_load.py` and empty `kb/` files; tests point `tools.pso_tools_path` at it. The fake
  writes its arguments to a file, prints `PROGRESS` lines and a `warning:` line, and writes the JSON and
  HTML outputs. An environment variable chosen by the test makes it exit non-zero, sleep past a short
  timeout, or write nothing. Covered:
  - the argument list from settings and options (with and without plan, SYS, display name, IFS release);
  - progress labels, the 2-second throttle (frozen time), and progress cleared on success and failure;
  - `result` keys and counts, `warning:` lines becoming warnings;
  - each error in section 6.
- **Feature.** Dashboard route: owner gets the HTML with the CSP sandbox header, anyone else 404, a run
  of another tool 404. Both downloads return the stored content and file name. The run page renders the
  iframe and counts; the Runs tab shows counts and the progress label. The Run form shows the two option
  fields and validates the IFS release.
- **Golden (skipped unless `TOOLS_PSO_TOOLS_PATH` and `LOAD_AUDIT_SAMPLE_DIR` are set).** Runs the real
  script through `RunToolJob` on the sample's LOAD, plan and SYS, then runs the command line directly
  with the same arguments, and requires identical findings JSON.

### 7.2 pso-tools

- `tests/load_audit/test_progress.py` (unittest, synthetic LOAD and plan): with `PSO_PROGRESS=lines`
  every `PROGRESS` line parses and the stages appear as `load`, `plan`, `checks`, `sys`, `checks`; without it stderr carries no `PROGRESS`
  text.
- On the SaskTel files: the findings JSON is identical before and after the change, and
  `Compare-LoadAudit.ps1` exits 0 against the PowerShell run (`pwsh` on the Mac).

## 8. Deploy

1. Raise LXC 199 to 1 GB of RAM (Ish).
2. Clone `outofjam/pso-tools` read-only to `/var/www/pso-tools` as `deploy`, with a read-only deploy key
   added in GitHub (Ish).
3. In the app's `.env`: `TOOLS_PSO_TOOLS_PATH=/var/www/pso-tools` (and `TOOLS_PYTHON` if `python3` is not
   on the worker's `PATH`).
4. `php artisan migrate --force --path=<the progress migration>` (the prod `.env` placeholder issue still
   applies), `optimize:clear`, `optimize`, restart the queue worker.
5. Updating the audit later: `git pull` in `/var/www/pso-tools`; the app needs no redeploy.

The README gets these steps.

## 9. Out of scope

- Porting the audit to PHP, or any change to its checks.
- A date filter on the Runs tab for the schedule review link (comes with the schedule review port).
- Automatic deploys (the paused pipeline work).
- The `render()` crash on a LOAD passed as `--plan` (separate pso-tools fix, both Python and PowerShell
  checked).
