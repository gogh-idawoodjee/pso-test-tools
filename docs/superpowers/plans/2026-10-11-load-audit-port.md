# Load Audit on the Web Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the pso-tools Python load audit from a dataset's Runs tab, with live progress, and show its own dashboard on the run page.

**Architecture:** `LoadAuditTool` (pso-test-tools) builds an argument list, starts `audit_load.py` from a pso-tools checkout with Laravel's `Process` facade, turns the script's `PROGRESS` stderr lines into `tool_runs.progress`, and stores the rendered HTML plus the findings JSON in the encrypted `result`. A small, opt-in change to `audit_load.py` (pso-tools) emits those `PROGRESS` lines. The run page shows the stored HTML in a sandboxed iframe served by an owner-only route.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest; Python 3 standard library (pso-tools). PHP 8.5 locally, 8.4 on the server.

**Spec:** `docs/superpowers/specs/2026-10-10-load-audit-port-design.md` (pso-test-tools). Read it first; the plan argues from it.

**Two repos.** Part A is in `pso-tools` (`/Users/idawoodjee/Documents/Projects/PSO Tools`, branch `load-audit-progress` off `main`). Part B is in `pso-test-tools` (`~/Herd/pso-test-tools`, branch `feat/load-audit`, which already holds the spec). Do Part A first: Part B's golden test needs it.

## Global Constraints

- pso-tools: Python standard library only; `audit_load.py`'s findings must not change (Task 1 proves it with `Compare-LoadAudit.ps1`). `Build-LoadAudit.ps1` is not touched.
- pso-tools is private, pso-test-tools is public: never copy `audit_load.py`, the template or the `kb/` files into pso-test-tools. Only the tiny fake script in `tests/Fixtures/pso-tools/` lives there.
- pso-test-tools: run the suite with `php artisan test --compact --exclude-filter=ExampleTest` (ExampleTest fails on main). Run `vendor/bin/pint --dirty` before each commit.
- Unit tests that touch the database go in `tests/Feature` (Unit has no `RefreshDatabase`).
- Wording: plain English, no em dashes, "allocated" never "placed", every failure message says what to do or what was missing.
- Never pass user text through a shell: the command is always an argument list.
- Commit messages end with the attribution lines the session provides.

## Review Focus

Inputs the spec implies but which are easy to leave untested. Each has a test in the task named.

1. A `PROGRESS` line that is not valid JSON, or a stage the app does not know, must not fail the run; it is ignored (Task 5).
2. Python emits progress at most twice a second but the app must save at most every 2 seconds, and must save at once when the label changes (Task 3).
3. A LOAD with no plan and no SYS must still produce a complete command and a successful run (Task 4, Task 5).
4. A dataset with no display name: `--org` is omitted, never passed empty (Task 4).
5. A run of another tool, or a run with no `result.html` yet (still running), must give 404 on the dashboard route rather than an error page (Task 6).

## File structure

Part A, pso-tools:
- Modify `load-audit/audit_load.py`: `ReadProgress` gains a lines mode; `stream()` takes a `stage`; `read_load`, `read_plan`, `read_sys` pass theirs; `main()` writes the `checks` line.
- Create `tests/load_audit/__init__.py`, `tests/load_audit/fixtures.py` (minimal LOAD, plan, SYS), `tests/load_audit/test_progress.py`.

Part B, pso-test-tools:
- Create `config/tools.php`.
- Create `app/Tools/LoadAudit/PsoToolsCheckout.php` (paths into the checkout, "not set up" errors).
- Create `app/Tools/LoadAudit/LoadAuditCommand.php` (pure: builds the argument list).
- Create `app/Tools/LoadAudit/ProgressLine.php` (pure: parses one stderr line into a label).
- Create `app/Tools/LoadAudit/LoadAuditTool.php` (runs the process, reports progress, assembles the result).
- Create `app/Tools/Contracts/ToolSetupException.php` (a RuntimeException: "not set up").
- Modify `app/Tools/Contracts/Tool.php` (+`optionFields()`), `app/Tools/Contracts/ToolResult.php` (+`summary`), `app/Tools/ToolContext.php` (+`reportProgress`).
- Create migration `add_progress_and_summary_to_tool_runs_table`.
- Modify `app/Models/ToolRun.php`, `app/Jobs/RunToolJob.php`, `app/Providers/AppServiceProvider.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`.
- Create `app/Http/Controllers/LoadAuditRunController.php`; modify `routes/web.php`.
- Create `resources/views/filament/tool-runs/load_audit.blade.php`; modify `resources/views/filament/pages/tool-run.blade.php`.
- Create `tests/Fixtures/pso-tools/load-audit/audit_load.py`, `tests/Fixtures/pso-tools/kb/pso_parameters_reference.csv`, `tests/Fixtures/pso-tools/kb/28-release-notes-watchlist.md`.
- Tests: `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`, `tests/Unit/LoadAudit/LoadAuditCommandTest.php`, `tests/Unit/LoadAudit/ProgressLineTest.php`, `tests/Feature/LoadAudit/LoadAuditToolTest.php`, `tests/Feature/LoadAudit/LoadAuditPagesTest.php`, `tests/Feature/LoadAudit/LoadAuditGoldenTest.php`, `tests/Feature/ToolContextProgressTest.php`; additions to `tests/Feature/RunToolJobTest.php`, `tests/Feature/RunsTabTest.php`; `tests/Support/FakeTool.php`.
- Modify `README.md`, `.env.example`.

Ruling made while planning (also added to the spec): the red/amber/green counts are stored a second time in a plain `tool_runs.summary` json column (`ToolResult::$summary`), so the Runs tab does not decrypt a 0.5 MB `result` per row. Counts are not sensitive.

---

## Part A: pso-tools

### Task 1: Opt-in progress lines in `audit_load.py`

**Files:**
- Modify: `load-audit/audit_load.py` (`ReadProgress` at about line 228, `stream()` at 265, `read_load` 365, `read_plan` 501, `read_sys` 1601, `main()` 2332)
- Create: `tests/load_audit/__init__.py`, `tests/load_audit/fixtures.py`, `tests/load_audit/test_progress.py`

**Interfaces:**
- Produces: when the environment variable `PSO_PROGRESS` is `lines`, stderr carries lines of the form `PROGRESS {"stage":"load","percent":0}` ... `PROGRESS {"stage":"load","percent":100}` for each file read (`load`, `plan`, `sys`), and `PROGRESS {"stage":"checks"}` each time checks start. `seconds_left` (int) is present only when there is an estimate. Everything else about the script is unchanged.

- [ ] **Step 1: Branch**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools" && git switch -c load-audit-progress main
```

- [ ] **Step 2: Write the fixtures**

`tests/load_audit/__init__.py` is empty. `tests/load_audit/fixtures.py`:

```python
"""Minimal PSO exports that audit_load.py accepts end to end (verified 2026-10-11)."""
from pathlib import Path

LOAD = ('<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'
        '<Input_Reference><id>IR1</id><datetime>2026-10-04T08:06:33+00:00</datetime><dataset_id>NORTH</dataset_id>'
        '<input_type>LOAD</input_type><enacting_app_instance_id>ACME</enacting_app_instance_id></Input_Reference>'
        '<Activity><id>A1</id><activity_type_id>INSTALL</activity_type_id><duration>PT1H</duration></Activity>'
        '<Activity_Status><activity_id>A1</activity_id><status_id>0</status_id></Activity_Status>'
        '<Resources><id>R1</id></Resources></dsScheduleData>')

PLAN = ('<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'
        '<Allocation><activity_id>A1</activity_id><resource_id>R1</resource_id></Allocation>'
        '<Plan><id>1</id><dataset_id>NORTH</dataset_id><input_reference_id>IR1</input_reference_id>'
        '<input_reference_date_time>2026-10-04T08:06:33+00:00</input_reference_date_time>'
        '<output_datetime>2026-10-04T08:09:00+00:00</output_datetime>'
        '<application_instance_id>ACME</application_instance_id></Plan></dsScheduleData>')

SYS = ('<?xml version="1.0"?><DsSystemData xmlns="http://360Scheduling.com/Schema/DsSystemData.xsd">'
       '<System_Version><version_id>6.14.0.0</version_id><version_type>Upgrade</version_type>'
       '<version_datetime>2026-03-01T00:00:00+00:00</version_datetime><user_id>DBA</user_id></System_Version>'
       '<Profile><id>ORG</id></Profile>'
       '<Profile_Parameter><profile_id>ORG</profile_id><parameter_id>DatasetSizeLimit</parameter_id>'
       '<value>10000</value><application_type>DSE</application_type></Profile_Parameter></DsSystemData>')


def write_all(folder: Path) -> dict:
    paths = {}
    for name, body in (('load', LOAD), ('plan', PLAN), ('sys', SYS)):
        p = folder / f'{name}.xml'
        p.write_text(body, encoding='utf-8')
        paths[name] = p
    (folder / 'accounts').mkdir()
    return paths
```

- [ ] **Step 3: Write the failing tests**

`tests/load_audit/test_progress.py`:

```python
import json, os, subprocess, sys, tempfile, unittest
from pathlib import Path

import fixtures as fx  # noqa: E402  (tests/load_audit is on sys.path when run with -s tests/load_audit)

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'load-audit' / 'audit_load.py'


def run_audit(folder: Path, env_extra: dict, with_sys: bool = True) -> subprocess.CompletedProcess:
    p = fx.write_all(folder)
    cmd = [sys.executable, '-I', str(SCRIPT), '--load', str(p['load']), '--plan', str(p['plan']),
           '--tz', 'America/Regina', '--org', 'Acme', '--accounts-dir', str(folder / 'accounts'),
           '--params', str(ROOT / 'kb' / 'pso_parameters_reference.csv'),
           '--watchlist', str(ROOT / 'kb' / '28-release-notes-watchlist.md'),
           '--out', str(folder / 'r.md'), '--json', str(folder / 'r.json'), '--html', str(folder / 'r.html')]
    if with_sys:
        cmd += ['--sys', str(p['sys'])]
    env = {'PATH': os.environ.get('PATH', ''), 'LANG': 'C.UTF-8'}
    env.update(env_extra)
    return subprocess.run(cmd, capture_output=True, text=True, env=env, cwd=str(folder))


class ProgressLinesTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())

    def progress(self, stderr: str) -> list:
        return [json.loads(line[len('PROGRESS '):]) for line in stderr.splitlines() if line.startswith('PROGRESS ')]

    def test_lines_mode_reports_every_stage_in_order(self):
        r = run_audit(self.tmp, {'PSO_PROGRESS': 'lines'})
        self.assertEqual(r.returncode, 0, r.stderr)
        stages = [p['stage'] for p in self.progress(r.stderr)]
        # Dedupe consecutive repeats: a stage may report several percentages.
        order = [s for i, s in enumerate(stages) if i == 0 or s != stages[i - 1]]
        self.assertEqual(order, ['load', 'plan', 'checks', 'sys', 'checks'])

    def test_each_file_starts_at_zero_and_ends_at_one_hundred(self):
        r = run_audit(self.tmp, {'PSO_PROGRESS': 'lines'})
        for stage in ('load', 'plan', 'sys'):
            pcts = [p['percent'] for p in self.progress(r.stderr) if p['stage'] == stage]
            self.assertEqual(pcts[0], 0, stage)
            self.assertEqual(pcts[-1], 100, stage)

    def test_checks_line_has_no_percent(self):
        r = run_audit(self.tmp, {'PSO_PROGRESS': 'lines'})
        for p in self.progress(r.stderr):
            if p['stage'] == 'checks':
                self.assertNotIn('percent', p)

    def test_no_sys_means_no_sys_stage(self):
        r = run_audit(self.tmp, {'PSO_PROGRESS': 'lines'}, with_sys=False)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertNotIn('sys', [p['stage'] for p in self.progress(r.stderr)])

    def test_without_the_variable_stderr_carries_no_progress(self):
        r = run_audit(self.tmp, {})
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertNotIn('PROGRESS', r.stderr)
        self.assertEqual(r.stderr, '')  # stderr is not a terminal here, so the old mode prints nothing

    def test_findings_identical_with_and_without_progress(self):
        a = run_audit(self.tmp, {'PSO_PROGRESS': 'lines'})
        first = (self.tmp / 'r.json').read_bytes()
        b = run_audit(self.tmp, {})
        self.assertEqual(a.returncode, 0); self.assertEqual(b.returncode, 0)
        self.assertEqual(first, (self.tmp / 'r.json').read_bytes())


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 4: Run the tests to see them fail**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools" && python3 -m unittest discover -s tests/load_audit -t . -v
```

Expected: `test_lines_mode_reports_every_stage_in_order`, `test_each_file_starts...`, `test_checks_line...` FAIL (no `PROGRESS` lines yet); the other three PASS.

- [ ] **Step 5: Implement the lines mode**

Replace `ReadProgress` and `stream()` in `load-audit/audit_load.py` with:

```python
class ReadProgress:
    """Progress while a big export is read, at most twice a second.

    On a terminal: one rewritten line on stderr. With PSO_PROGRESS=lines (how the web app runs this
    script): one machine-readable line per update, 'PROGRESS {"stage":..,"percent":..}', plus a 0% line
    when the file is opened and a 100% line when it is finished. Otherwise silent.
    """

    def __init__(self, fobj, stage):
        self.f, self.size, self.stage = fobj, getattr(fobj, 'pso_size', 0), stage
        self.name = os.path.basename(str(getattr(fobj, 'name', '')))
        self.lines = os.environ.get('PSO_PROGRESS') == 'lines'
        self.on = self.size > 0 and (self.lines or sys.stderr.isatty())
        self.rows, self.t0, self.shown = 0, time.time(), False
        self.next = self.t0 + 1.0
        if self.lines:
            self.emit(0)

    def emit(self, pct, left=None):
        rec = {'stage': self.stage, 'percent': pct}
        if left is not None:
            rec['seconds_left'] = left
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec, separators=(',', ':')))
        sys.stderr.flush()

    def tick(self):
        self.rows += 1
        if not self.on or self.rows % 2000:
            return
        now = time.time()
        if now < self.next:
            return
        self.next = now + 0.5
        try:
            pos = self.f.tell()
        except (OSError, ValueError):
            return
        el = now - self.t0
        pct = min(100, int(100.0 * pos / self.size))
        left = int(el * (self.size - pos) / pos) if pos and el >= 2 else None
        if self.lines:
            self.emit(pct, left)
            return
        tail = ', about %d s left' % left if left is not None else ''
        sys.stderr.write('\r  reading %s: %d%% (%s of %s MB, %s rows, %d s so far%s)\033[K'
                         % (self.name, pct, format(pos // 1048576, ','), format(self.size // 1048576, ','),
                            format(self.rows, ','), el, tail))
        sys.stderr.flush()
        self.shown = True

    def done(self):
        if self.lines:
            self.emit(100)
        elif self.shown:
            sys.stderr.write('\r\033[K')
            sys.stderr.flush()


def stream(fobj, stage='load'):
    """Yield (entity_tag, {child_tag: text}) for each top-level element, O(1) memory."""
    prog = ReadProgress(fobj, stage)
    try:
        for row in _stream(fobj, prog):
            yield row
    finally:
        prog.done()


def checks_progress():
    """Tell a PSO_PROGRESS=lines listener that the checks are running (no percentage for this stage)."""
    if os.environ.get('PSO_PROGRESS') == 'lines':
        sys.stderr.write('PROGRESS {"stage":"checks"}\n')
        sys.stderr.flush()
```

Then pass the stage at the three call sites:

- in `read_load` (line about 401): `for tag, d in stream(f):` becomes `for tag, d in stream(f, 'load'):`
- in `read_plan` (about 506): `stream(f, 'plan')`
- in `read_sys` (about 1608): `stream(f, 'sys')`

And in `main()`, add `checks_progress()` immediately before `leg1(D, F, ctx, P)` and again immediately before `leg3(read_sys(a.sys), ...)` is reached, that is inside `if a.sys:` as the first statement is `read_sys`, so put the second `checks_progress()` right after that `leg3(...)` call and before `leg4(D, P, F, ctx)`:

```python
    checks_progress()
    leg1(D, F, ctx, P)
    leg2(D, F, ctx)
    commit_rules(D, F, ctx)
    appointment_templates(D, F, ctx)
    if not a.sys:
        a.sys = find_sys(os.path.dirname(os.path.abspath(a.load)))
        if a.sys:
            print('SYS file: %s (found next to the LOAD)' % os.path.basename(a.sys))
    if a.sys:
        leg3(read_sys(a.sys), F, ctx, read_param_ref(a.params), read_watchlist(a.watchlist), a.ifs_release, P)
        checks_progress()
    leg4(D, P, F, ctx)
```

(The `read_sys` inside the `leg3(...)` call emits the `sys` 0..100 lines; the `checks_progress()` after it gives the second `checks`. Without a SYS the sequence is `load`, `plan`, `checks`.)

- [ ] **Step 6: Run the tests**

```bash
python3 -m unittest discover -s tests/load_audit -t . -v
```

Expected: all 6 PASS.

- [ ] **Step 7: Prove the findings did not change on real data**

The customer exports at the repo root are swapped: `plan.zip` holds the LOAD and `load.zip` holds the plan (checked 2026-10-10). Run the audit from `main` and from this branch into two folders and compare with `Compare-LoadAudit.ps1` (it compares result, count, denominator, records and recommendation of every check):

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools"
S=/private/tmp/claude-501/-Users-idawoodjee-Documents-Projects-PSO-Tools/parity; mkdir -p $S/before $S/after
git stash -u -q 2>/dev/null; git switch -q main
python3 load-audit/audit_load.py --load plan.zip --plan load.zip --accounts-dir accounts \
  --params kb/pso_parameters_reference.csv --watchlist kb/28-release-notes-watchlist.md \
  --out $S/before/r.md --json $S/before/r.json --html $S/before/r.html
git switch -q load-audit-progress; git stash pop -q 2>/dev/null
PSO_PROGRESS=lines python3 load-audit/audit_load.py --load plan.zip --plan load.zip --accounts-dir accounts \
  --params kb/pso_parameters_reference.csv --watchlist kb/28-release-notes-watchlist.md \
  --out $S/after/r.md --json $S/after/r.json --html $S/after/r.html 2>$S/after/progress.txt
pwsh -File load-audit/Compare-LoadAudit.ps1 -Python $S/before/r.json -PowerShell $S/after/r.json; echo "exit=$?"
grep -c PROGRESS $S/after/progress.txt
```

Expected: `exit=0` and a PROGRESS count of at least 4 (0% and 100% for the LOAD and the plan). If `git stash` has nothing to stash the `pop` fails harmlessly.

- [ ] **Step 8: Commit**

```bash
git add load-audit/audit_load.py tests/load_audit
git commit -m "Load audit: opt-in PROGRESS lines on stderr (PSO_PROGRESS=lines) for the web app"
```

Do not push; Ish pushes pso-tools. Tell Ish in the final report that `git pull` in `/var/www/pso-tools` is needed after the push.

---

## Part B: pso-test-tools

All commands run from `~/Herd/pso-test-tools` on branch `feat/load-audit`.

### Task 2: Config and the checkout locator

**Files:**
- Create: `config/tools.php`
- Create: `app/Tools/Contracts/ToolSetupException.php`
- Create: `app/Tools/LoadAudit/PsoToolsCheckout.php`
- Create: `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: `PsoToolsCheckout::fromConfig(): self`; `->script(): string`, `->paramsFile(): string`, `->watchlistFile(): string`, `->python(): string`. Each path method throws `ToolSetupException` with the message "The load audit is not set up on this server: <what is missing>." when the thing is missing.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`:

```php
<?php

use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;

function fakeCheckout(): string
{
    return base_path('tests/Fixtures/pso-tools');
}

it('resolves the script, kb files and python from the checkout', function () {
    config(['tools.pso_tools_path' => fakeCheckout(), 'tools.python' => 'python3']);

    $checkout = PsoToolsCheckout::fromConfig();

    expect($checkout->script())->toBe(fakeCheckout().'/load-audit/audit_load.py')
        ->and($checkout->paramsFile())->toBe(fakeCheckout().'/kb/pso_parameters_reference.csv')
        ->and($checkout->watchlistFile())->toBe(fakeCheckout().'/kb/28-release-notes-watchlist.md')
        ->and($checkout->python())->toBe('python3');
});

it('says the path is not configured', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => PsoToolsCheckout::fromConfig()->script())
        ->toThrow(ToolSetupException::class, 'The load audit is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});

it('names the missing file', function () {
    $empty = sys_get_temp_dir().'/checkout-'.uniqid();
    mkdir($empty);
    config(['tools.pso_tools_path' => $empty]);

    expect(fn () => PsoToolsCheckout::fromConfig()->script())
        ->toThrow(ToolSetupException::class, "The load audit is not set up on this server: {$empty}/load-audit/audit_load.py is missing.");
    expect(fn () => PsoToolsCheckout::fromConfig()->paramsFile())
        ->toThrow(ToolSetupException::class, 'kb/pso_parameters_reference.csv is missing.');
});
```

- [ ] **Step 2: Create the fixture folder the test points at**

```bash
mkdir -p tests/Fixtures/pso-tools/load-audit tests/Fixtures/pso-tools/kb
printf 'application,parameter_id,access,data_type,group,class,default_value,description\n' > tests/Fixtures/pso-tools/kb/pso_parameters_reference.csv
printf '# Release Notes Watchlist (test fixture)\n' > tests/Fixtures/pso-tools/kb/28-release-notes-watchlist.md
printf '# replaced in Task 5\n' > tests/Fixtures/pso-tools/load-audit/audit_load.py
```

- [ ] **Step 3: Run the tests to see them fail**

```bash
php artisan test --compact tests/Feature/LoadAudit/PsoToolsCheckoutTest.php
```

Expected: FAIL, class `PsoToolsCheckout` not found.

- [ ] **Step 4: Implement**

`config/tools.php`:

```php
<?php

return [
    // Python interpreter used to run the pso-tools scripts.
    'python' => env('TOOLS_PYTHON', 'python3'),
    // A checkout of the private pso-tools repo (git clone, kept current with git pull). Never copied into this repo.
    'pso_tools_path' => env('TOOLS_PSO_TOOLS_PATH'),
    'load_audit' => [
        // Under RunToolJob's 900 s so the job reports the timeout in plain words.
        'timeout_seconds' => (int) env('TOOLS_LOAD_AUDIT_TIMEOUT_SECONDS', 840),
    ],
];
```

`app/Tools/Contracts/ToolSetupException.php`:

```php
<?php

namespace App\Tools\Contracts;

use RuntimeException;

/** The server lacks something a tool needs (a path, a binary). The message is shown to the user as the run's failure. */
class ToolSetupException extends RuntimeException {}
```

`app/Tools/LoadAudit/PsoToolsCheckout.php`:

```php
<?php

namespace App\Tools\LoadAudit;

use App\Tools\Contracts\ToolSetupException;

/** Where the pso-tools scripts and reference files live on this server. */
class PsoToolsCheckout
{
    public function __construct(private ?string $root, private string $python) {}

    public static function fromConfig(): self
    {
        $root = config('tools.pso_tools_path');

        return new self(filled($root) ? rtrim((string) $root, '/') : null, (string) config('tools.python', 'python3'));
    }

    public function python(): string
    {
        return $this->python;
    }

    public function script(): string
    {
        return $this->file('load-audit/audit_load.py');
    }

    public function paramsFile(): string
    {
        return $this->file('kb/pso_parameters_reference.csv');
    }

    public function watchlistFile(): string
    {
        return $this->file('kb/28-release-notes-watchlist.md');
    }

    private function file(string $relative): string
    {
        if ($this->root === null) {
            throw new ToolSetupException('The load audit is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
        }

        $path = $this->root.'/'.$relative;
        if (! is_file($path)) {
            throw new ToolSetupException("The load audit is not set up on this server: {$path} is missing.");
        }

        return $path;
    }
}
```

Append to `.env.example`:

```
# pso-tools checkout (private repo) and the Python that runs its scripts
TOOLS_PSO_TOOLS_PATH=
TOOLS_PYTHON=python3
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Feature/LoadAudit/PsoToolsCheckoutTest.php
```

Expected: 3 PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty -q
git add config/tools.php app/Tools tests/Feature/LoadAudit tests/Fixtures .env.example
git commit -m "feat: tools config and the pso-tools checkout locator"
```

### Task 3: Progress and summary on a run

**Files:**
- Create: `database/migrations/2026_10_11_000004_add_progress_and_summary_to_tool_runs_table.php`
- Modify: `app/Models/ToolRun.php`, `app/Tools/ToolContext.php`, `app/Tools/Contracts/ToolResult.php`, `app/Jobs/RunToolJob.php`
- Create: `tests/Feature/ToolContextProgressTest.php`
- Modify: `tests/Feature/RunToolJobTest.php`, `tests/Support/FakeTool.php`

**Interfaces:**
- Produces: `ToolContext::reportProgress(string $label, ?int $percent = null, ?int $secondsLeft = null): void`; `tool_runs.progress` json `{label, percent, seconds_left}` (nullable) and `tool_runs.summary` json (nullable); `ToolResult::__construct(array $result = [], array $warnings = [], array $summary = [])`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ToolContextProgressTest.php`:

```php
<?php

use App\Models\Dataset;
use App\Models\ToolRun;
use App\Tools\ToolContext;
use Illuminate\Support\Carbon;

function contextFor(ToolRun $run): ToolContext
{
    return new ToolContext($run, $run->scope, null, 'UTC');
}

it('saves the first report at once and throttles repeats of the same label to one every two seconds', function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();
    $context = contextFor($run);

    $context->reportProgress('Reading the LOAD', 10, 30);
    expect($run->fresh()->progress)->toBe(['label' => 'Reading the LOAD', 'percent' => 10, 'seconds_left' => 30]);

    Carbon::setTestNow('2026-10-11 10:00:01');
    $context->reportProgress('Reading the LOAD', 20, 20);
    expect($run->fresh()->progress['percent'])->toBe(10);

    Carbon::setTestNow('2026-10-11 10:00:02');
    $context->reportProgress('Reading the LOAD', 30, 10);
    expect($run->fresh()->progress['percent'])->toBe(30);
    Carbon::setTestNow();
});

it('saves at once when the label changes', function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();
    $context = contextFor($run);

    $context->reportProgress('Reading the LOAD', 99);
    $context->reportProgress('Reading the plan');
    expect($run->fresh()->progress)->toBe(['label' => 'Reading the plan', 'percent' => null, 'seconds_left' => null]);
    Carbon::setTestNow();
});
```

Add to `tests/Feature/RunToolJobTest.php` (after the existing tests):

```php
it('clears progress when the run succeeds and stores the summary', function () {
    $run = runFor($this->dataset, $this->load);
    $run->forceFill(['progress' => ['label' => 'Old', 'percent' => 5, 'seconds_left' => null]])->save();

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->progress)->toBeNull()
        ->and($run->summary)->toBe(['counts' => ['red' => 1]]);
});

it('clears progress when the run fails', function () {
    FakeTool::$throw = new RuntimeException('boom');
    $run = runFor($this->dataset, $this->load);
    $run->forceFill(['progress' => ['label' => 'Old', 'percent' => 5, 'seconds_left' => null]])->save();

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    FakeTool::$throw = null;

    expect($run->refresh()->progress)->toBeNull();
});
```

In `tests/Support/FakeTool.php`, make `run()` return the summary too:

```php
        return new ToolResult(
            result: [
                'seen_kinds' => array_keys($inputPaths),
                'bytes' => filesize($inputPaths['load']),
                'display_name' => $context->settings?->displayName,
                'time_zone' => $context->timeZone,
                'options' => $options,
            ],
            warnings: ['Fake warning'],
            summary: ['counts' => ['red' => 1]],
        );
```

- [ ] **Step 2: Run the tests to see them fail**

```bash
php artisan test --compact tests/Feature/ToolContextProgressTest.php tests/Feature/RunToolJobTest.php
```

Expected: FAIL (`reportProgress` undefined, unknown named argument `summary`, no `progress` column).

- [ ] **Step 3: Implement**

Migration `database/migrations/2026_10_11_000004_add_progress_and_summary_to_tool_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            $table->json('progress')->nullable()->after('status');   // {label, percent, seconds_left} while running
            $table->json('summary')->nullable()->after('result');    // small plain facts for lists (e.g. counts), never customer text
        });
    }

    public function down(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            $table->dropColumn(['progress', 'summary']);
        });
    }
};
```

`app/Models/ToolRun.php`: add `'progress', 'summary'` to `$fillable` and to `casts()`:

```php
            'progress' => 'array',
            'summary' => 'array',
```

`app/Tools/Contracts/ToolResult.php`:

```php
<?php

namespace App\Tools\Contracts;

/** What a tool hands back: its data file, the plain-English warnings the dashboard shows, and small facts for lists. */
class ToolResult
{
    /**
     * @param  array<string, mixed>  $result  stored encrypted
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $summary  stored in plain json for tables (counts only, never customer text)
     */
    public function __construct(
        public array $result = [],
        public array $warnings = [],
        public array $summary = [],
    ) {}
}
```

`app/Tools/ToolContext.php`:

```php
<?php

namespace App\Tools;

use App\Models\ToolRun;
use App\Support\DatasetSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ToolContext
{
    private ?string $lastLabel = null;

    private ?Carbon $lastSavedAt = null;

    public function __construct(
        public ToolRun $run,
        public Model $scope,
        public ?DatasetSettings $settings,
        public string $timeZone,
    ) {}

    /**
     * Show the user where a run is. Saved at once when the label changes, otherwise at most every
     * 2 seconds, so a chatty tool does not hammer the database. RunToolJob clears it when the run ends.
     */
    public function reportProgress(string $label, ?int $percent = null, ?int $secondsLeft = null): void
    {
        $now = Carbon::now();
        $sameLabel = $label === $this->lastLabel;
        if ($sameLabel && $this->lastSavedAt !== null && $now->diffInSeconds($this->lastSavedAt, true) < 2) {
            return;
        }

        $this->run->forceFill(['progress' => ['label' => $label, 'percent' => $percent, 'seconds_left' => $secondsLeft]])->save();
        $this->lastLabel = $label;
        $this->lastSavedAt = $now;
    }
}
```

`app/Jobs/RunToolJob.php`: in `handle()`, the success `forceFill` gains two keys and `markFailed` clears progress:

```php
            $run->forceFill([
                'result' => $output->result,
                'warnings' => $output->warnings,
                'summary' => $output->summary ?: null,
                'progress' => null,
                'run_date' => $run->run_date ?? $this->exportDateOf($files),
                'status' => ToolRunStatus::Succeeded,
                'error_message' => null,
                'finished_at' => now(),
            ])->save();
```

```php
    private function markFailed(ToolRun $run, string $message): void
    {
        $run->forceFill(['status' => ToolRunStatus::Failed, 'progress' => null, 'error_message' => $message, 'finished_at' => now()])->save();
    }
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Feature/ToolContextProgressTest.php tests/Feature/RunToolJobTest.php
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty -q
git add -A database/migrations app/Models/ToolRun.php app/Tools app/Jobs/RunToolJob.php tests
git commit -m "feat: live progress and a plain summary on tool runs"
```

### Task 4: Option fields on the contract, and the command builder

**Files:**
- Modify: `app/Tools/Contracts/Tool.php`, `tests/Support/FakeTool.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`
- Create: `app/Tools/LoadAudit/LoadAuditCommand.php`
- Create: `tests/Unit/LoadAudit/LoadAuditCommandTest.php`
- Modify: `tests/Feature/RunsTabTest.php`

**Interfaces:**
- Consumes: `PsoToolsCheckout` (Task 2).
- Produces: `Tool::optionFields(): array` (list of Filament form components named `options.<key>`); `LoadAuditCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $displayName, string $reviewUrl): array` returning the argument list (`list<string>`), and the constants `LoadAuditCommand::OUT_JSON = 'load_audit.json'`, `OUT_HTML = 'load_audit.html'`, `OUT_MD = 'load_audit.md'`, `ACCOUNTS_DIR = 'accounts'`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/LoadAudit/LoadAuditCommandTest.php`:

```php
<?php

use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;

function checkoutForCommand(): PsoToolsCheckout
{
    return new PsoToolsCheckout(dirname(__DIR__, 2).'/Fixtures/pso-tools', 'python3');
}

it('builds the full command for a LOAD, plan and SYS', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/scratch/run1',
        ['load' => '/scratch/run1/a.zip', 'plan' => '/scratch/run1/b.xml', 'sys' => '/scratch/run1/c.xml'],
        ['ifs_release' => '25.2.7', 'complete_grace_hours' => 12],
        'America/Regina', 'Acme', 'https://app/datasets/1?relation=toolRuns');

    $root = dirname(__DIR__, 2).'/Fixtures/pso-tools';
    expect($argv)->toBe([
        'python3', '-I', "{$root}/load-audit/audit_load.py",
        '--load', '/scratch/run1/a.zip',
        '--plan', '/scratch/run1/b.xml',
        '--sys', '/scratch/run1/c.xml',
        '--tz', 'America/Regina',
        '--org', 'Acme',
        '--accounts-dir', '/scratch/run1/accounts',
        '--params', "{$root}/kb/pso_parameters_reference.csv",
        '--watchlist', "{$root}/kb/28-release-notes-watchlist.md",
        '--ifs-release', '25.2.7',
        '--complete-grace-hours', '12',
        '--review', 'https://app/datasets/1?relation=toolRuns',
        '--out', '/scratch/run1/load_audit.md',
        '--json', '/scratch/run1/load_audit.json',
        '--html', '/scratch/run1/load_audit.html',
    ]);
});

it('omits plan, sys, org and ifs release when absent and defaults the grace hours', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], [], 'UTC', null, 'https://app/r');

    expect($argv)->not->toContain('--plan')->not->toContain('--sys')->not->toContain('--org')->not->toContain('--ifs-release')
        ->and(array_slice($argv, array_search('--complete-grace-hours', $argv, true), 2))->toBe(['--complete-grace-hours', '24']);
});

it('treats a blank display name or ifs release as absent', function () {
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml'], ['ifs_release' => '  '], 'UTC', '', 'https://app/r');

    expect($argv)->not->toContain('--org')->not->toContain('--ifs-release');
});
```

Add to `tests/Feature/RunsTabTest.php`:

```php
it('shows a tool option field on the Run form and stores the option', function () {
    Queue::fake();
    FakeTool::$optionFields = [\Filament\Forms\Components\TextInput::make('options.ifs_release')->label('IFS release')];
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->assertHasNoTableActionErrors();
    FakeTool::$optionFields = [];

    expect(ToolRun::first()->inputs['options'])->toBe(['ifs_release' => '25.2.7']);
});
```

- [ ] **Step 2: Run the tests to see them fail**

```bash
php artisan test --compact tests/Unit/LoadAudit tests/Feature/RunsTabTest.php
```

Expected: FAIL (`LoadAuditCommand` missing; `FakeTool::$optionFields` undefined).

- [ ] **Step 3: Implement**

`app/Tools/Contracts/Tool.php`: add after `optionalKinds()`:

```php
    /** @return list<\Filament\Schemas\Components\Component> form fields for the Run action, named options.<key>; [] when the tool has none */
    public function optionFields(): array;
```

`tests/Support/FakeTool.php`: add

```php
    /** @var list<\Filament\Schemas\Components\Component> */
    public static array $optionFields = [];

    public function optionFields(): array
    {
        return self::$optionFields;
    }
```

`app/Filament/RelationManagers/ToolRunsRelationManager.php`, in `runActions()`, after the `foreach` that builds the Select fields and before `$actions[] = Action::make(...)`:

```php
            foreach ($tool->optionFields() as $field) {
                $fields[] = $field;
            }
```

`app/Tools/LoadAudit/LoadAuditCommand.php`:

```php
<?php

namespace App\Tools\LoadAudit;

/**
 * The argument list for audit_load.py. Pure: no process, no filesystem, so every shape is unit tested.
 * An empty --accounts-dir keeps the script from reading the account files in the checkout; everything
 * per customer comes from the dataset settings instead.
 */
class LoadAuditCommand
{
    public const OUT_JSON = 'load_audit.json';

    public const OUT_HTML = 'load_audit.html';

    public const OUT_MD = 'load_audit.md';

    public const ACCOUNTS_DIR = 'accounts';

    public const DEFAULT_GRACE_HOURS = 24;

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path
     * @param  array<string, mixed>  $options  ifs_release, complete_grace_hours
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $displayName, string $reviewUrl): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [$checkout->python(), '-I', $checkout->script(), '--load', $inputPaths['load']];

        foreach (['plan', 'sys'] as $kind) {
            if (filled($inputPaths[$kind] ?? null)) {
                $argv[] = "--{$kind}";
                $argv[] = $inputPaths[$kind];
            }
        }

        $argv[] = '--tz';
        $argv[] = $timeZone;

        if (filled(trim((string) $displayName))) {
            $argv[] = '--org';
            $argv[] = trim((string) $displayName);
        }

        array_push($argv, '--accounts-dir', $workDir.'/'.self::ACCOUNTS_DIR,
            '--params', $checkout->paramsFile(),
            '--watchlist', $checkout->watchlistFile());

        $release = trim((string) ($options['ifs_release'] ?? ''));
        if ($release !== '') {
            $argv[] = '--ifs-release';
            $argv[] = $release;
        }

        $grace = $options['complete_grace_hours'] ?? null;
        array_push($argv, '--complete-grace-hours', (string) (is_numeric($grace) ? (int) $grace : self::DEFAULT_GRACE_HOURS));

        array_push($argv, '--review', $reviewUrl,
            '--out', $workDir.'/'.self::OUT_MD,
            '--json', $workDir.'/'.self::OUT_JSON,
            '--html', $workDir.'/'.self::OUT_HTML);

        return $argv;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Unit/LoadAudit tests/Feature/RunsTabTest.php
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty -q
git add app tests
git commit -m "feat: tool option fields on the Run form; load audit command builder"
```

### Task 5: The tool: run the script, report progress, map errors

**Files:**
- Create: `app/Tools/LoadAudit/ProgressLine.php`, `app/Tools/LoadAudit/LoadAuditTool.php`
- Create: `tests/Fixtures/pso-tools/load-audit/audit_load.py` (replace the placeholder)
- Create: `tests/Unit/LoadAudit/ProgressLineTest.php`, `tests/Feature/LoadAudit/LoadAuditToolTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `PsoToolsCheckout`, `LoadAuditCommand`, `ToolContext::reportProgress`, `ToolResult(summary:)`, `ToolSetupException`.
- Produces: `ProgressLine::parse(string $line): ?array{label: string, percent: ?int, seconds_left: ?int}`; `LoadAuditTool implements Tool` registered for `ToolId::LoadAudit`; `result` keys `html` (string), `findings` (list), `counts` (`{red, amber, green}`); `summary` = `['counts' => ...]`.

- [ ] **Step 1: Write the fake script**

`tests/Fixtures/pso-tools/load-audit/audit_load.py` (this is the only Python in pso-test-tools; it imitates the interface, not the audit):

```python
"""Stand-in for pso-tools/load-audit/audit_load.py in tests. Same arguments, same outputs, no checks.

FAKE_AUDIT_MODE: ok (default) | fail | hang | silent.
FAKE_AUDIT_ARGS_FILE: where to write the received argv as JSON (optional).
"""
import json, os, sys, time


def arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None


mode = os.environ.get('FAKE_AUDIT_MODE', 'ok')
if os.environ.get('FAKE_AUDIT_ARGS_FILE'):
    with open(os.environ['FAKE_AUDIT_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, fh)

if mode == 'hang':
    time.sleep(30)

lines = os.environ.get('PSO_PROGRESS') == 'lines'
def progress(rec):
    if lines:
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec)); sys.stderr.flush()

progress({'stage': 'load', 'percent': 0})
progress({'stage': 'load', 'percent': 45, 'seconds_left': 6})
sys.stderr.write('not progress, just noise\n')
progress({'stage': 'load', 'percent': 100})
progress({'stage': 'mystery'})            # unknown stage: must be ignored
sys.stderr.write('PROGRESS {not json\n')  # broken line: must be ignored
if arg('--plan'):
    progress({'stage': 'plan', 'percent': 0}); progress({'stage': 'plan', 'percent': 100})
progress({'stage': 'checks'})
if arg('--sys'):
    progress({'stage': 'sys', 'percent': 0}); progress({'stage': 'sys', 'percent': 100}); progress({'stage': 'checks'})

if mode == 'fail':
    sys.stderr.write('Traceback (most recent call last):\n  File "audit_load.py", line 1\nKeyError: \'dataset_id\'\n')
    sys.exit(1)

print('warning: no recommendation for red/amber check: Resources with no shifts')
if mode == 'silent':
    sys.exit(0)

findings = [
    {'leg': '1', 'check': 'Open activities already past their SLA', 'severity': 'AMBER', 'count': 3, 'of': 10, 'samples': ['A1'], 'note': 'n', 'recommendation': 'r'},
    {'leg': '2', 'check': 'Resources with no shifts', 'severity': 'RED', 'count': 1, 'of': 5, 'samples': ['R1'], 'note': 'n', 'recommendation': None},
    {'leg': '4', 'check': 'Plan output within the DSE window', 'severity': 'GREEN', 'count': None, 'of': None, 'samples': [], 'note': 'n', 'recommendation': None},
]
with open(arg('--json'), 'w') as fh:
    json.dump(findings, fh)
with open(arg('--html'), 'w') as fh:
    fh.write('<!doctype html><html><body><h1>%s</h1><script>const DATA={}</script></body></html>' % (arg('--org') or 'Dataset'))
with open(arg('--out'), 'w') as fh:
    fh.write('# report\n')
print('wrote', arg('--out'), '(3 findings)')
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/LoadAudit/ProgressLineTest.php`:

```php
<?php

use App\Tools\LoadAudit\ProgressLine;

it('turns a load line into a label with percent and estimate', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"load","percent":45,"seconds_left":6}'))
        ->toBe(['label' => 'Reading the LOAD: 45%, about 6 s left', 'percent' => 45, 'seconds_left' => 6]);
});

it('labels each stage in plain English', function (string $stage, string $label) {
    expect(ProgressLine::parse('PROGRESS {"stage":"'.$stage.'"}')['label'])->toBe($label);
})->with([['load', 'Reading the LOAD'], ['plan', 'Reading the plan'], ['sys', 'Reading the SYS file'], ['checks', 'Running the checks']]);

it('ignores noise, broken json and unknown stages', function (string $line) {
    expect(ProgressLine::parse($line))->toBeNull();
})->with(['just noise', 'PROGRESS {not json', 'PROGRESS {"stage":"mystery"}', 'PROGRESS []', '']);

it('rounds the percent into the label without an estimate when there is none', function () {
    expect(ProgressLine::parse('PROGRESS {"stage":"plan","percent":100}')['label'])->toBe('Reading the plan: 100%');
});
```

`tests/Feature/LoadAudit/LoadAuditToolTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.load_audit.timeout_seconds' => 840]);
    putenv('FAKE_AUDIT_MODE=ok');
    $this->scratch = sys_get_temp_dir().'/la-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/load.xml', '<dsScheduleData/>');
    $this->dataset = Dataset::factory()->create(['settings' => ['display_name' => 'Acme', 'time_zone' => 'America/Regina']]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    putenv('FAKE_AUDIT_MODE');
    putenv('FAKE_AUDIT_ARGS_FILE');
});

it('is registered for the load audit', function () {
    expect(app(ToolRegistry::class)->for(ToolId::LoadAudit))->toBeInstanceOf(LoadAuditTool::class);
});

it('runs the script in the scratch folder and stores html, findings, counts and warnings', function () {
    $argsFile = $this->scratch.'/args.json';
    putenv("FAKE_AUDIT_ARGS_FILE={$argsFile}");

    $result = (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], ['ifs_release' => '25.2.7']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['cwd'])->toBe(realpath($this->scratch))
        ->and($args['progress_env'])->toBe('lines')
        ->and($args['argv'])->toContain('--org', 'Acme', '--tz', 'America/Regina', '--ifs-release', '25.2.7')
        ->and(is_dir($this->scratch.'/accounts'))->toBeTrue()
        ->and($result->result['html'])->toContain('<h1>Acme</h1>')
        ->and($result->result['findings'])->toHaveCount(3)
        ->and($result->result['counts'])->toBe(['red' => 1, 'amber' => 1, 'green' => 1])
        ->and($result->summary)->toBe(['counts' => ['red' => 1, 'amber' => 1, 'green' => 1]])
        ->and($result->warnings)->toBe(['no recommendation for red/amber check: Resources with no shifts']);
});

it('reports progress in plain English as the script runs', function () {
    (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml', 'sys' => $this->scratch.'/load.xml'], []);

    // The last label saved before the job clears it (the tool itself does not clear progress).
    expect($this->run->fresh()->progress['label'])->toBe('Running the checks');
});

it('fails plainly when the server is not set up', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(ToolSetupException::class, 'not set up on this server');
});

it('names the stage and the last error line when the script fails', function () {
    putenv('FAKE_AUDIT_MODE=fail');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, "The audit stopped while running the checks: KeyError: 'dataset_id'");
});

it('fails when the script exits cleanly but wrote nothing', function () {
    putenv('FAKE_AUDIT_MODE=silent');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The audit finished but wrote no results.');
});

it('stops a script that runs too long', function () {
    config(['tools.load_audit.timeout_seconds' => 1]);
    putenv('FAKE_AUDIT_MODE=hang');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The audit took longer than 0 minutes and was stopped.');
});

it('offers the ifs release and grace hours as run options', function () {
    $names = array_map(static fn ($field) => $field->getName(), (new LoadAuditTool)->optionFields());

    expect($names)->toBe(['options.ifs_release', 'options.complete_grace_hours']);
});
```

(The timeout message says "0 minutes" for a 1-second test timeout; with the default 840 s it reads "14 minutes". `intdiv($seconds, 60)`.)

- [ ] **Step 3: Run the tests to see them fail**

```bash
php artisan test --compact tests/Unit/LoadAudit/ProgressLineTest.php tests/Feature/LoadAudit/LoadAuditToolTest.php
```

Expected: FAIL (classes missing).

- [ ] **Step 4: Implement `ProgressLine`**

`app/Tools/LoadAudit/ProgressLine.php`:

```php
<?php

namespace App\Tools\LoadAudit;

/** One stderr line from audit_load.py run with PSO_PROGRESS=lines, turned into words for the run page. */
class ProgressLine
{
    private const LABELS = [
        'load' => 'Reading the LOAD',
        'plan' => 'Reading the plan',
        'sys' => 'Reading the SYS file',
        'checks' => 'Running the checks',
    ];

    /** @return array{label: string, percent: ?int, seconds_left: ?int}|null null for anything that is not a progress line we know */
    public static function parse(string $line): ?array
    {
        if (! str_starts_with($line, 'PROGRESS ')) {
            return null;
        }

        $record = json_decode(substr($line, strlen('PROGRESS ')), true);
        if (! is_array($record) || ! isset(self::LABELS[$record['stage'] ?? null])) {
            return null;
        }

        $percent = isset($record['percent']) && is_numeric($record['percent']) ? max(0, min(100, (int) $record['percent'])) : null;
        $left = isset($record['seconds_left']) && is_numeric($record['seconds_left']) ? max(0, (int) $record['seconds_left']) : null;

        $label = self::LABELS[$record['stage']];
        if ($percent !== null) {
            $label .= ": {$percent}%";
            if ($left !== null) {
                $label .= ", about {$left} s left";
            }
        }

        return ['label' => $label, 'percent' => $percent, 'seconds_left' => $left];
    }
}
```

- [ ] **Step 5: Implement `LoadAuditTool`**

`app/Tools/LoadAudit/LoadAuditTool.php`:

```php
<?php

namespace App\Tools\LoadAudit;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Resources\DatasetResource;
use App\Models\Dataset;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use Filament\Forms\Components\TextInput;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs pso-tools/load-audit/audit_load.py (the reference implementation, never ported) against the run's
 * inputs, in the run's scratch folder, and keeps the page it rendered plus its findings.
 */
class LoadAuditTool implements Tool
{
    public static function id(): ToolId
    {
        return ToolId::LoadAudit;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Plan, ExportKind::Sys];
    }

    public function optionFields(): array
    {
        return [
            TextInput::make('options.ifs_release')
                ->label('IFS Cloud release')
                ->placeholder('e.g. 25.2.7')
                ->helperText('Not in any PSO file; used to match release-note fixes.')
                ->regex('/^\d+(\.\d+){1,3}$/')
                ->maxLength(16),
            TextInput::make('options.complete_grace_hours')
                ->label('Finished work kept for (hours)')
                ->helperText('Finished activities younger than this are expected in a LOAD. IFS Cloud keeps them 24 hours.')
                ->integer()
                ->minValue(0)
                ->maxValue(168)
                ->default(LoadAuditCommand::DEFAULT_GRACE_HOURS),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['load']);
        $accounts = $workDir.'/'.LoadAuditCommand::ACCOUNTS_DIR;
        if (! is_dir($accounts)) {
            mkdir($accounts, 0770, true);
        }

        $argv = LoadAuditCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZone,
            $context->settings?->displayName, $this->reviewUrl($context->scope),
        );
        $timeout = (int) config('tools.load_audit.timeout_seconds', 840);

        $lastLabel = 'starting';
        $stderr = [];
        $stdout = '';

        try {
            $process = Process::path($workDir)
                ->timeout($timeout)
                ->env(['PSO_PROGRESS' => 'lines', 'LANG' => 'C.UTF-8'])
                ->run($argv, function (string $type, string $buffer) use (&$lastLabel, &$stderr, &$stdout, $context): void {
                    if ($type === 'out') {
                        $stdout .= $buffer;

                        return;
                    }
                    foreach (preg_split('/\r?\n/', $buffer) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $progress = ProgressLine::parse($line);
                        if ($progress !== null) {
                            $lastLabel = $progress['label'];
                            $context->reportProgress($progress['label'], $progress['percent'], $progress['seconds_left']);
                        } elseif (! str_starts_with($line, 'PROGRESS ')) {
                            $stderr[] = $line;
                            $stderr = array_slice($stderr, -50);
                        }
                    }
                });
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('The audit took longer than '.intdiv($timeout, 60).' minutes and was stopped.');
        }

        if (! $process->successful()) {
            Log::warning('Load audit script failed', ['tool_run_id' => $context->run->id, 'exit' => $process->exitCode(), 'stderr' => implode("\n", $stderr)]);
            $stage = strtolower(preg_replace('/:.*$/', '', $lastLabel));
            $lastLine = collect($stderr)->last(static fn (string $line): bool => trim($line) !== '') ?? 'no error text';

            throw new RuntimeException("The audit stopped while {$stage}: ".trim($lastLine));
        }

        $json = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_JSON);
        $html = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_HTML);
        $findings = $json === false ? null : json_decode($json, true);
        if (! is_array($findings) || $html === false || $html === '') {
            throw new RuntimeException('The audit finished but wrote no results.');
        }

        $counts = ['red' => 0, 'amber' => 0, 'green' => 0];
        foreach ($findings as $finding) {
            $key = strtolower((string) ($finding['severity'] ?? ''));
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        $warnings = [];
        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            if (str_starts_with($line, 'warning:')) {
                $warnings[] = trim(substr($line, strlen('warning:')));
            }
        }

        return new ToolResult(
            result: ['html' => $html, 'findings' => $findings, 'counts' => $counts],
            warnings: $warnings,
            summary: ['counts' => $counts],
        );
    }

    /** Where the dashboard's "Open the schedule review" button goes until that tool is ported: the dataset's Runs tab. */
    private function reviewUrl(mixed $scope): string
    {
        return $scope instanceof Dataset
            ? DatasetResource::getUrl('view', ['record' => $scope]).'?relation=toolRuns'
            : url('/');
    }
}
```

Register it in `app/Providers/AppServiceProvider.php`, `register()`, right after the singleton:

```php
        $this->app->afterResolving(ToolRegistry::class, static function (ToolRegistry $registry): void {
            $registry->register(\App\Enums\Tool::LoadAudit, \App\Tools\LoadAudit\LoadAuditTool::class);
        });
```

Then check the two tests that previously registered `FakeTool` for `LoadAudit` still pass (they call `register()` again in `beforeEach`, which overwrites, so they do). `ToolEntryPageTest` "stays out of navigation until its tool is registered" will now fail because the tool is always registered: change that test to use a tool with no implementation. Replace it with:

```php
it('stays out of navigation until its tool is registered', function () {
    $registry = new ToolRegistry;   // fresh, nothing registered
    expect($registry->has(ToolId::LoadAudit))->toBeFalse();
    $registry->register(ToolId::LoadAudit, FakeTool::class);
    expect($registry->has(ToolId::LoadAudit))->toBeTrue();
});
```

- [ ] **Step 6: Run the tests**

```bash
php artisan test --compact tests/Unit/LoadAudit tests/Feature/LoadAudit tests/Feature/ToolEntryPageTest.php tests/Feature/RunsTabTest.php tests/Feature/RunToolJobTest.php
```

Expected: all PASS. If `python3` is not on PATH the tool tests fail at the process start; that is the machine, not the code.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty -q
git add app tests
git commit -m "feat: load audit tool runs the pso-tools script with live progress"
```

### Task 6: Dashboard route, downloads, run page and Runs tab

**Files:**
- Create: `app/Http/Controllers/LoadAuditRunController.php`
- Modify: `routes/web.php`
- Create: `resources/views/filament/tool-runs/load_audit.blade.php`
- Modify: `resources/views/filament/pages/tool-run.blade.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`
- Create: `tests/Feature/LoadAudit/LoadAuditPagesTest.php`

**Interfaces:**
- Consumes: `result.html`, `result.findings`, `summary.counts`, `progress` (Tasks 3 and 5).
- Produces: named routes `load-audit.dashboard`, `load-audit.download-html`, `load-audit.download-findings`, all `GET /datasets/{dataset}/runs/{run}/...`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/LoadAudit/LoadAuditPagesTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'ACME']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'run_date' => '2026-10-04',
        'result' => ['html' => '<!doctype html><html><body>Audit page <script>1</script></body></html>', 'findings' => [['check' => 'x']], 'counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
        'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
    ]);
    $this->actingAs($this->user);
});

it('serves the stored dashboard to its owner inside a sandbox', function () {
    $this->get(route('load-audit.dashboard', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Audit page');
});

it('gives 404 to another user, to a run of another tool, and to a run without a page yet', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::LoadAudit]);
    $this->get(route('load-audit.dashboard', [$other->scope_id, $other]))->assertNotFound();

    $review = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'result' => ['html' => 'x']]);
    $this->get(route('load-audit.dashboard', [$this->dataset, $review]))->assertNotFound();

    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('load-audit.dashboard', [$this->dataset, $running]))->assertNotFound();
});

it('requires a login', function () {
    auth()->logout();
    $this->get(route('load-audit.dashboard', [$this->dataset, $this->run]))->assertRedirect();
});

it('downloads the page and the findings under a name built from account, dataset and date', function () {
    $this->get(route('load-audit.download-html', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_ACME_NORTH_2026-10-04.html');

    $this->get(route('load-audit.download-findings', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_ACME_NORTH_2026-10-04.json')
        ->assertJson([['check' => 'x']]);
});

it('cleans the download name', function () {
    $this->dataset->update(['name' => 'North / West']);

    $this->get(route('load-audit.download-html', [$this->dataset, $this->run]))->assertDownload('load_audit_ACME_North___West_2026-10-04.html');
});

it('shows counts, download buttons and the sandboxed iframe on the run page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('2 red')->assertSee('1 amber')->assertSee('9 green')
        ->assertSee('Download HTML')->assertSee('Download findings JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('load-audit.dashboard', [$this->dataset, $this->run])));
});

it('shows the progress label and bar while a run is going, polling every two seconds', function () {
    $running = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null,
        'progress' => ['label' => 'Reading the LOAD: 45%, about 6 s left', 'percent' => 45, 'seconds_left' => 6],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $running->id])
        ->assertOk()
        ->assertSee('Reading the LOAD: 45%, about 6 s left')
        ->assertSeeHtml('wire:poll.2s')
        ->assertSeeHtml('width: 45%');
});

it('shows the progress label and the counts on the Runs tab', function () {
    ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null,
        'progress' => ['label' => 'Running the checks', 'percent' => null, 'seconds_left' => null],
    ]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('Running the checks')
        ->assertSee('2 red, 1 amber, 9 green');
});
```

- [ ] **Step 2: Run the tests to see them fail**

```bash
php artisan test --compact tests/Feature/LoadAudit/LoadAuditPagesTest.php
```

Expected: FAIL (routes not defined).

- [ ] **Step 3: Implement the controller and routes**

`app/Http/Controllers/LoadAuditRunController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The load audit's rendered page and downloads. The page carries text from customer files and
 * builds itself with JavaScript, so it is served under a CSP sandbox and never with the app's origin.
 */
class LoadAuditRunController extends Controller
{
    public const SANDBOX = 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation';

    public function dashboard(string $dataset, string $run): Response
    {
        [, $toolRun] = $this->find($dataset, $run);

        return response($toolRun->result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::SANDBOX,
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadHtml(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo $toolRun->result['html'];
        }, $this->fileName($datasetModel, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function downloadFindings(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo json_encode($toolRun->result['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $this->fileName($datasetModel, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    /** @return array{0: Dataset, 1: ToolRun} the user's dataset and a finished load audit run on it, else 404 */
    private function find(string $dataset, string $run): array
    {
        $datasetModel = Dataset::query()->ownedByCurrentUser()->findOrFail($dataset);
        $toolRun = ToolRun::query()
            ->where('scope_type', $datasetModel->getMorphClass())
            ->where('scope_id', $datasetModel->getKey())
            ->where('tool', ToolId::LoadAudit)
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$datasetModel, $toolRun];
    }

    public static function fileName(Dataset $dataset, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);

        return 'load_audit_'.$clean($dataset->environment?->account_id).'_'.$clean($dataset->name).'_'.($run->run_date?->toDateString() ?? 'undated').'.'.$extension;
    }
}
```

Append to `routes/web.php`:

```php
Route::middleware(['web', 'auth'])
    ->prefix('datasets/{dataset}/runs/{run}')
    ->controller(\App\Http\Controllers\LoadAuditRunController::class)
    ->group(static function (): void {
        Route::get('dashboard', 'dashboard')->name('load-audit.dashboard');
        Route::get('download/html', 'downloadHtml')->name('load-audit.download-html');
        Route::get('download/findings', 'downloadFindings')->name('load-audit.download-findings');
    });
```

(If `routes/web.php` is already wrapped by the `web` middleware group by the framework, the explicit `'web'` is harmless.)

- [ ] **Step 4: Implement the views**

`resources/views/filament/tool-runs/load_audit.blade.php`:

```blade
@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? $run->result['counts'] ?? ['red' => 0, 'amber' => 0, 'green' => 0];
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex gap-2 text-sm font-medium">
            <x-filament::badge color="danger">{{ $counts['red'] }} red</x-filament::badge>
            <x-filament::badge color="warning">{{ $counts['amber'] }} amber</x-filament::badge>
            <x-filament::badge color="success">{{ $counts['green'] }} green</x-filament::badge>
        </div>
        <div class="flex gap-2">
            <x-filament::button tag="a" href="{{ route('load-audit.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('load-audit.download-findings', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download findings JSON</x-filament::button>
        </div>
    </div>
</x-filament::section>

{{-- The audit's own page, as audit_load.py rendered it. Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
<iframe
    src="{{ route('load-audit.dashboard', [$dataset, $run]) }}"
    title="Load and configuration health audit"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 14rem); min-height: 600px;"
></iframe>
```

`resources/views/filament/pages/tool-run.blade.php`: change the poll and add the progress block. Replace the opening `<div ...>` line with:

```blade
    <div @if ($this->isRunning()) wire:poll.2s @endif class="space-y-6">
```

and insert this right after the first `</x-filament::section>` (the header grid):

```blade
        @if ($this->isRunning() && filled($run->progress['label'] ?? null))
            <x-filament::section>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $run->progress['label'] }}</p>
                @if (($run->progress['percent'] ?? null) !== null)
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run->progress['percent'] }}">
                        <div class="h-2 rounded-full bg-primary-600 transition-all" style="width: {{ (int) $run->progress['percent'] }}%"></div>
                    </div>
                @endif
            </x-filament::section>
        @endif
```

`app/Filament/RelationManagers/ToolRunsRelationManager.php`: the status column gets the progress label as its description, and a counts column follows it:

```php
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(static fn (ToolRun $record): ?string => $record->progress['label'] ?? null)
                    ->tooltip(static fn (ToolRun $record): ?string => $record->error_message),
                Tables\Columns\TextColumn::make('summary.counts')
                    ->label('Findings')
                    ->placeholder('-')
                    ->formatStateUsing(static function (mixed $state, ToolRun $record): ?string {
                        $counts = $record->summary['counts'] ?? null;
                        if (! is_array($counts)) {
                            return null;
                        }

                        return "{$counts['red']} red, {$counts['amber']} amber, {$counts['green']} green";
                    }),
```

Also shorten the Runs tab poll from `'10s'` to `'5s'` while a run is going.

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Feature/LoadAudit tests/Feature/RunsTabTest.php
```

Expected: all PASS. If `assertDownload` complains about the streamed response, use `->assertHeader('Content-Disposition', 'attachment; filename=load_audit_ACME_NORTH_2026-10-04.html')` instead.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty -q
git add app routes resources tests
git commit -m "feat: load audit dashboard in a sandboxed iframe, downloads, progress on the run page and Runs tab"
```

### Task 7: Golden test, README, full suite

**Files:**
- Create: `tests/Feature/LoadAudit/LoadAuditGoldenTest.php`
- Modify: `README.md`

- [ ] **Step 1: Write the golden test**

`tests/Feature/LoadAudit/LoadAuditGoldenTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Storage;

/*
 * Proves the web run hands the real script exactly what the command line does. Needs a real pso-tools
 * checkout and a folder with load.(xml|zip), plan.(xml|zip), sys.(xml|zip):
 *   TOOLS_PSO_TOOLS_PATH=/Users/idawoodjee/Documents/Projects/PSO\ Tools LOAD_AUDIT_SAMPLE_DIR=~/pso-data/ACME/1005 php artisan test --filter=Golden
 * Skipped otherwise. Mind that some sample sets have load and plan swapped; check Input_Reference first.
 */
it('produces the same findings through the web as the command line', function () {
    $checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    $sample = env('LOAD_AUDIT_SAMPLE_DIR');
    if (blank($checkoutPath) || blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH and LOAD_AUDIT_SAMPLE_DIR to run the golden test.');
    }
    config(['tools.pso_tools_path' => $checkoutPath, 'tools.load_audit.timeout_seconds' => 840]);

    $find = static function (string $name) use ($sample): ?string {
        foreach (['xml', 'zip'] as $ext) {
            if (is_file("{$sample}/{$name}.{$ext}")) {
                return "{$sample}/{$name}.{$ext}";
            }
        }

        return null;
    };
    $paths = array_filter(['load' => $find('load'), 'plan' => $find('plan'), 'sys' => $find('sys')]);
    expect($paths)->toHaveKey('load');

    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-golden-'.uniqid()]);
    $dataset = Dataset::factory()->for(Environment::factory()->state(['account_id' => 'ACME']))->create(['name' => 'NORTH', 'settings' => ['display_name' => 'Acme', 'time_zone' => 'America/Regina']]);
    $storage = app(ExportStorage::class);
    $files = [];
    foreach ($paths as $kind => $path) {
        $scope = $kind === 'sys' ? $dataset->environment : $dataset;
        $file = ExportFile::factory()->for($scope, 'scope')->create(['kind' => ExportKind::from($kind), 'content_type' => pathinfo($path, PATHINFO_EXTENSION)]);
        Storage::disk('r2')->put($storage->objectKey($file), file_get_contents($path));
        $files[$kind] = $file->id;
    }
    $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null, 'inputs' => ['files' => $files, 'options' => []]]);

    (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));
    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message);

    // The command line, with the same arguments the tool used, into a folder of its own.
    $work = sys_get_temp_dir().'/golden-cli-'.uniqid();
    mkdir($work.'/'.LoadAuditCommand::ACCOUNTS_DIR, 0770, true);
    $argv = LoadAuditCommand::build(PsoToolsCheckout::fromConfig(), $work, $paths, [], 'America/Regina', 'Acme', 'about:blank');
    $process = \Illuminate\Support\Facades\Process::path($work)->timeout(840)->run($argv);
    expect($process->successful())->toBeTrue($process->errorOutput());

    $cli = json_decode(file_get_contents($work.'/'.LoadAuditCommand::OUT_JSON), true);
    expect($run->result['findings'])->toEqual($cli)
        ->and($run->result['counts']['red'] + $run->result['counts']['amber'] + $run->result['counts']['green'])->toBe(count($cli));
});
```

- [ ] **Step 2: Run it both ways**

```bash
php artisan test --compact --filter=Golden
```

Expected: 1 skipped. Then with a real sample (the customer set under `~/pso-data/ACME/1005` if it holds load, plan and sys; otherwise copy the three files there, with the correct names after checking `Input_Reference`):

```bash
TOOLS_PSO_TOOLS_PATH="/Users/idawoodjee/Documents/Projects/PSO Tools" LOAD_AUDIT_SAMPLE_DIR=$HOME/pso-data/ACME/1005 php artisan test --compact --filter=Golden
```

Expected: PASS in under a minute. This needs Task 1 merged or checked out in that path (the branch `load-audit-progress` is fine).

- [ ] **Step 3: README**

Add under "## Exports and tool runs" in `README.md`:

```markdown
### Load and configuration health audit

The audit is the Python script in the private `pso-tools` repo, run as a child process; nothing is ported.
The server needs Python 3 and a checkout of that repo:

1. As `deploy`: `git clone github-pso-tools:outofjam/pso-tools.git /var/www/pso-tools` (the read-only deploy
   key `pso-tools-deploy` and the `github-pso-tools` SSH alias are already on the server).
2. In `.env`: `TOOLS_PSO_TOOLS_PATH=/var/www/pso-tools` (and `TOOLS_PYTHON` if `python3` is not on the worker's PATH).
3. `php artisan migrate --force`, `php artisan optimize:clear`, `php artisan optimize`, restart the queue worker.
4. To update the audit later: `git pull` in `/var/www/pso-tools`. The app needs no redeploy.

A run shows live progress (reading the LOAD, the plan, the SYS file, running the checks) and finishes with the
audit's own page in a sandboxed frame, plus downloads of that page and the findings JSON. Run options: the IFS
Cloud release (not in any PSO file) and how long finished work is kept (24 hours by default). The SYS file comes
from the dataset's environment. If the server is not set up, a run fails with a message naming what is missing.
```

- [ ] **Step 4: Full suite and commit**

```bash
vendor/bin/pint --dirty -q
php artisan test --compact --exclude-filter=ExampleTest
git add tests README.md
git commit -m "test: load audit golden test against the real script; README deploy steps"
```

Expected: everything passes, 1 skipped (the golden test) plus the pre-existing skip.

- [ ] **Step 5: Hand back**

Do not merge or push `feat/load-audit`; report to Ish with: the test counts, whether the golden test ran on real data, the pso-tools branch to push, and the Windows note (none: `Build-LoadAudit.ps1` is untouched).

---

## Self-review notes

- Spec 3.1 to 3.4: Tasks 2, 4, 5. Spec 4.1: Task 1. Spec 4.2: Tasks 3, 5, 6. Spec 5: Task 6. Spec 6: Tasks 2, 5. Spec 7.1: Tasks 2 to 7; 7.2: Task 1. Spec 8: Task 7 README (server steps already done by Ish on 2026-10-11: 1 GB, clone, `.env`).
- Spec amendments made with this plan: the `summary` column (counts for the Runs tab); 0% and 100% progress lines per file; the process inherits the worker's environment plus `PSO_PROGRESS` and `LANG` (`-I` already makes Python ignore `PYTHON*` variables).
- Names used across tasks: `PsoToolsCheckout::fromConfig/script/paramsFile/watchlistFile/python`, `LoadAuditCommand::build` and its constants, `ProgressLine::parse`, `ToolContext::reportProgress`, `ToolResult(summary:)`, routes `load-audit.dashboard|download-html|download-findings`, `LoadAuditRunController::SANDBOX|fileName`.
