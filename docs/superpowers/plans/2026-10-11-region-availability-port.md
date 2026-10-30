# Region Availability on the Web Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the pso-tools region availability viewer from a dataset's Runs tab (one week per run, the dataset's settings handed to the script as an account file), show its own three-layer page on the run page, and remove the old Technician Availability page.

**Architecture:** `RegionAvailabilityTool` (pso-test-tools) writes the dataset settings to `accounts/<ACCOUNT>.json` in the run's scratch folder, builds an argument list, starts `region_availability.py` from the pso-tools checkout through a shared `ScriptRun` helper (extracted from the load audit tool, which keeps its behaviour), and stores the rendered HTML plus the gathered JSON in the encrypted `result`. A small opt-in change to `region_availability.py` (pso-tools) emits `PROGRESS` lines. The load audit's page controller becomes `ToolRunPageController`, serving any run that holds a page. The Technician Availability page, service, jobs, component, tile, test and fixture are deleted.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest; Python 3 standard library (pso-tools). PHP 8.5 locally, 8.4 on the server.

**Spec:** `docs/superpowers/specs/2026-10-11-region-availability-port-design.md` (pso-test-tools). Read it first, and the load audit spec it builds on (`2026-10-10-load-audit-port-design.md`) for the shape every tool run shares.

**Two repos.** Part A is in `pso-tools` (`/Users/idawoodjee/Documents/Projects/PSO Tools`, branch `region-availability-progress` off `main`). Part B is in `pso-test-tools` (`~/Herd/pso-test-tools`, branch `feat/region-availability` off `main`, which already holds the spec). Do Part A first; Part B's golden test needs it, and the fake script in Part B copies its progress line shape.

## Global Constraints

- pso-tools: Python standard library only. `region_availability.py`'s arguments, outputs and gathered JSON must not change; `Build-RegionAvailability.ps1` is not touched; `python3 -m unittest discover -s tests/region_availability -t .` stays green.
- pso-tools is private, pso-test-tools is public: never copy `region_availability.py`, its template or `accounts/*.json` into pso-test-tools. Only the tiny fake script in `tests/Fixtures/pso-tools/region-availability/` lives there.
- pso-test-tools: run the suite with `php artisan test --compact --exclude-filter=ExampleTest` (ExampleTest fails on main). Run `vendor/bin/pint --dirty` before each commit.
- Unit tests that touch the database go in `tests/Feature` (Unit has no `RefreshDatabase`).
- Wording: plain English, no em dashes, "allocated" never "placed", every failure message says what was missing or what to do.
- Never pass user text through a shell: the command is always an argument list.
- Commit messages end with the attribution lines the session provides.

## Review Focus

Inputs the spec implies that are easy to leave untested. Each has a test in the task named.

1. A dataset with no settings, or with an empty map such as no attribute labels, must still give the script a readable account file: `{}` and never `[]` at the top or for any map, since the script calls `.get()` on them and a list would crash it (Task 3).
2. An environment whose account id is blank or has odd characters must still produce a file name and a `--account` the script accepts (Task 3, Task 5).
3. A run of a tool other than the load audit whose result holds `html` is served by the generic controller; a running run with no `html` yet is a 404, not an error page (Task 6).
4. The Runs tab must show the region availability summary line and still show the load audit's red, amber, green counts, and the placeholder when a summary has neither (Task 6).
5. A `week_of` option that is blank, a `Y-m-d` string, or a datetime string must produce no `--week-of`, `--week-of 2026-10-07`, and `--week-of 2026-10-07` respectively (Task 4).

## File structure

Part A, pso-tools:
- Modify `region-availability/region_availability.py`: `open_xml()` also returns the byte size; `read_tables()` wraps the file in a counting reader that writes `load` progress when `PSO_PROGRESS=lines`; `main()` writes the `gather` line.
- Create `tests/region_availability/test_progress.py`.

Part B, pso-test-tools:
- Modify `config/tools.php` (+`region_availability.timeout_seconds`).
- Modify `app/Tools/LoadAudit/PsoToolsCheckout.php` (+`regionAvailabilityScript()`, "pso-tools checkout" wording), `app/Tools/LoadAudit/ProgressLine.php` (+`gather`).
- Create `app/Tools/ScriptRun.php` (runs a pso-tools script, feeds progress, plain-English failures); modify `app/Tools/LoadAudit/LoadAuditTool.php` to use it.
- Modify `app/Support/DatasetSettings.php` (+`toAccountFile()`).
- Create `app/Tools/RegionAvailability/AccountSettingsFile.php`, `RegionAvailabilityCommand.php`, `RegionAvailabilityTool.php`.
- Modify `app/Providers/AppServiceProvider.php` (register the tool).
- Rename `app/Http/Controllers/LoadAuditRunController.php` to `ToolRunPageController.php`; modify `routes/web.php`, `resources/views/filament/tool-runs/load_audit.blade.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`.
- Create `resources/views/filament/tool-runs/region_availability.blade.php`.
- Create `tests/Fixtures/pso-tools/region-availability/region_availability.py` (fake script).
- Tests: `tests/Unit/RegionAvailability/RegionAvailabilityCommandTest.php`, `tests/Unit/RegionAvailability/AccountSettingsFileTest.php`, additions to `tests/Unit/DatasetSettingsTest.php`, `tests/Unit/LoadAudit/ProgressLineTest.php`, `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`, `tests/Feature/LoadAudit/LoadAuditToolTest.php`, `tests/Feature/LoadAudit/LoadAuditPagesTest.php`; `tests/Feature/RegionAvailability/RegionAvailabilityToolTest.php`, `RegionAvailabilityPagesTest.php`, `RegionAvailabilityGoldenTest.php`; `tests/Feature/TechnicianAvailabilityRetiredTest.php`.
- Delete: `app/Filament/Pages/TechnicianAvail.php`, `app/Services/TechnicianAvailabilityService.php`, `app/Jobs/GetTechniciansListJob.php`, `app/Jobs/GetTechnicianShiftsJob.php`, `app/Jobs/CleanupTechnicianUploadJob.php`, `resources/views/filament/pages/technician-avail.blade.php`, `resources/views/components/technician-gantt.blade.php`, `tests/Feature/TechnicianAvailabilityServiceTest.php`, `tests/Fixtures/sample-technician-shifts.json`; modify `app/Filament/Widgets/QuickLaunch.php`, `README.md`.

Rulings made while planning (small departures from the spec's letter, same intent):
- `toAccountFile()` is an instance method on `DatasetSettings` (the spec wrote it static). Same result.
- The process plumbing is extracted into `ScriptRun` instead of being copied into the second tool. The load audit's existing tests prove it still behaves the same.
- The Runs tab column is relabelled "Result" because it now carries a summary line, not only findings counts.

---

## Part A: pso-tools

### Task 1: Opt-in progress lines in `region_availability.py`

**Files:**
- Modify: `region-availability/region_availability.py` (`open_xml()` line 28, `read_tables()` line 41, `main()` line 373)
- Create: `tests/region_availability/test_progress.py`

**Interfaces:**
- Produces: when the environment variable `PSO_PROGRESS` is `lines`, stderr carries `PROGRESS {"stage":"load","percent":0}`, more `load` lines while the file is read (at most two a second), `PROGRESS {"stage":"load","percent":100}`, then `PROGRESS {"stage":"gather"}` before the week is worked out. Without the variable stderr is untouched. Nothing else changes.

- [ ] **Step 1: Branch**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools" && git switch -c region-availability-progress main
```

- [ ] **Step 2: Write the failing test**

`tests/region_availability/test_progress.py`:

```python
"""PSO_PROGRESS=lines makes region_availability.py write PROGRESS lines on stderr for the web app; otherwise nothing."""
import contextlib, io, json, os, sys, tempfile, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "region-availability"))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import region_availability as ra  # noqa: E402
import fixtures as fx  # noqa: E402


class ProgressTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.load = fx.write_load(fx.base_tables(), self.tmp)
        self.argv = ["--load", str(self.load), "--account", "ACME", "--time-zone", "America/Regina",
                     "--accounts-dir", str(self.tmp / "accounts"), "--out-root", str(self.tmp / "out")]

    def run_main(self, env_value):
        old = os.environ.get("PSO_PROGRESS")
        if env_value is None:
            os.environ.pop("PSO_PROGRESS", None)
        else:
            os.environ["PSO_PROGRESS"] = env_value
        err, out = io.StringIO(), io.StringIO()
        try:
            with contextlib.redirect_stderr(err), contextlib.redirect_stdout(out):
                ra.main(self.argv)
        finally:
            if old is None:
                os.environ.pop("PSO_PROGRESS", None)
            else:
                os.environ["PSO_PROGRESS"] = old
        return err.getvalue(), out.getvalue()

    def test_lines_mode_reports_load_then_gather(self):
        err, out = self.run_main("lines")
        lines = [l for l in err.splitlines() if l]
        self.assertTrue(all(l.startswith("PROGRESS ") for l in lines), lines)
        recs = [json.loads(l[len("PROGRESS "):]) for l in lines]
        self.assertEqual(recs[0], {"stage": "load", "percent": 0})
        loads = [r for r in recs if r["stage"] == "load"]
        self.assertEqual(loads[-1], {"stage": "load", "percent": 100})
        self.assertTrue(all(0 <= r["percent"] <= 100 for r in loads))
        self.assertEqual(recs[-1], {"stage": "gather"})
        self.assertIn("technicians", out)                      # stdout is as before
        self.assertTrue((self.tmp / "out" / "ACME" / "NORTH" / "region_availability_2026-10-05.html").exists())

    def test_zip_input_reports_the_same(self):
        self.load = fx.write_load(fx.base_tables(), self.tmp, zipped=True)
        self.argv[1] = str(self.load)
        err, _ = self.run_main("lines")
        recs = [json.loads(l[len("PROGRESS "):]) for l in err.splitlines() if l]
        self.assertEqual(recs[0], {"stage": "load", "percent": 0})
        self.assertIn({"stage": "load", "percent": 100}, recs)

    def test_silent_without_the_variable(self):
        err, _ = self.run_main(None)
        self.assertEqual(err, "")


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Run it to see it fail**

```bash
python3 -m unittest tests.region_availability.test_progress -v
```
Expected: the two `lines` tests fail (`recs` is empty, `IndexError` on `recs[0]`); the silent test passes.

- [ ] **Step 4: Implement**

In `region-availability/region_availability.py`:

Add `os` to the imports on line 15:

```python
import argparse, collections, json, os, re, sys, time, zipfile
```

Replace `open_xml()` (line 28) so it also returns the byte size, and add the counting reader right after it:

```python
def open_xml(p):
    """(binary file object, xml file name, size in bytes). A zip is read in place; its largest .xml is used."""
    p = Path(p)
    if p.suffix.lower() == ".zip":
        z = zipfile.ZipFile(p)
        xmls = [i for i in z.infolist() if i.filename.lower().endswith(".xml")]
        if not xmls:
            raise SystemExit(f"No .xml file inside {p}")
        m = max(xmls, key=lambda i: i.file_size)
        return z.open(m), Path(m.filename).name, m.file_size
    return open(p, "rb"), p.name, p.stat().st_size


def progress_lines():
    """True when the web app is listening: PSO_PROGRESS=lines. The command line never sees these lines."""
    return os.environ.get("PSO_PROGRESS") == "lines"


def emit_progress(rec):
    sys.stderr.write("PROGRESS %s\n" % json.dumps(rec, separators=(",", ":")))
    sys.stderr.flush()


class CountingReader:
    """Wraps the binary file iterparse reads from and reports 'load' progress as bytes go by:
    a 0% line when opened, at most two lines a second while reading, and 100% from done()."""

    def __init__(self, f, size):
        self.f, self.size, self.read_bytes = f, size, 0
        self.next = time.time() + 0.5
        emit_progress({"stage": "load", "percent": 0})

    def read(self, n=-1):
        b = self.f.read(n)
        self.read_bytes += len(b)
        now = time.time()
        if self.size and now >= self.next:
            self.next = now + 0.5
            emit_progress({"stage": "load", "percent": min(99, int(100 * self.read_bytes / self.size))})
        return b

    def done(self):
        emit_progress({"stage": "load", "percent": 100})

    def close(self):
        self.f.close()

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()
```

In `read_tables()` change the two lines that open the file and the end of the function:

```python
    f, name, size = open_xml(path)
    counting = CountingReader(f, size) if progress_lines() else None
    f = counting or f
```

(the `with f:` loop stays exactly as it is) and after the `with` block, before `return out, name`:

```python
    if counting:
        counting.done()
```

In `main()`, immediately before `d = gather(T, settings, account, monday, Z, tz_name)`:

```python
    if progress_lines():
        emit_progress({"stage": "gather"})
```

- [ ] **Step 5: Run the new test and the whole suite**

```bash
python3 -m unittest tests.region_availability.test_progress -v
python3 -m unittest discover -s tests/region_availability -t .
```
Expected: all pass (the parity test needs `pwsh`; it is skipped without it, which is fine on this Mac only if `pwsh` is missing; normally it runs).

- [ ] **Step 6: Prove the command line output is unchanged**

```bash
python3 region-availability/region_availability.py --load ~/pso-data/ACME/sunday/load.zip --accounts-dir accounts --out-root /tmp/ra-after 2>/tmp/ra-stderr.txt
git stash && python3 region-availability/region_availability.py --load ~/pso-data/ACME/sunday/load.zip --accounts-dir accounts --out-root /tmp/ra-before && git stash pop
diff <(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));d['meta'].pop('generated');print(json.dumps(d,sort_keys=True))" /tmp/ra-before/ACME/NORTH/region_availability_*.json) \
     <(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));d['meta'].pop('generated');print(json.dumps(d,sort_keys=True))" /tmp/ra-after/ACME/NORTH/region_availability_*.json) && echo SAME
wc -c /tmp/ra-stderr.txt
```
Expected: `SAME`, and stderr is 0 bytes (no variable set). If `~/pso-data/ACME/sunday/load.zip` is missing, say so and skip this step; the unit tests still cover the change.

- [ ] **Step 7: Commit**

```bash
git add region-availability/region_availability.py tests/region_availability/test_progress.py
git commit -m "Region availability: opt-in PROGRESS lines on stderr (PSO_PROGRESS=lines) for the web app"
```

Then merge to main and push so the server checkout can pull it:

```bash
git switch main && git merge --ff-only region-availability-progress && git push origin main
```

---

## Part B: pso-test-tools

### Task 2: Checkout, config and progress stage for the second tool

**Files:**
- Modify: `app/Tools/LoadAudit/PsoToolsCheckout.php`, `config/tools.php`, `app/Tools/LoadAudit/ProgressLine.php`
- Create: `tests/Fixtures/pso-tools/region-availability/region_availability.py`
- Test: `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`, `tests/Unit/LoadAudit/ProgressLineTest.php`, `tests/Feature/LoadAudit/LoadAuditToolTest.php`

**Interfaces:**
- Produces: `PsoToolsCheckout::regionAvailabilityScript(): string`; config key `tools.region_availability.timeout_seconds` (int, default 600); `ProgressLine::parse()` knows stage `gather` with label `Working out each technician's regions`; a fake script at `tests/Fixtures/pso-tools/region-availability/region_availability.py` driven by `FAKE_RA_MODE` (`ok` | `fail` | `nopage` | `hang`) and `FAKE_RA_ARGS_FILE`.

- [ ] **Step 1: Branch**

```bash
cd ~/Herd/pso-test-tools && git switch -c feat/region-availability main
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`:

```php
it('resolves the region availability script', function () {
    config(['tools.pso_tools_path' => fakeCheckout()]);

    expect(PsoToolsCheckout::fromConfig()->regionAvailabilityScript())
        ->toBe(fakeCheckout().'/region-availability/region_availability.py');
});
```

In the same file change every expected message that starts `The load audit is not set up on this server:` to `The pso-tools checkout is not set up on this server:` (three places: the path test, the missing file test, the python test). In `tests/Feature/LoadAudit/LoadAuditToolTest.php` make the same change in `surfaces a missing python as the not-set-up message`.

In `tests/Unit/LoadAudit/ProgressLineTest.php` add a case to the `labels each stage in plain English` dataset:

```php
})->with([['load', 'Reading the LOAD'], ['plan', 'Reading the plan'], ['sys', 'Reading the SYS file'], ['checks', 'Running the checks'], ['gather', "Working out each technician's regions"]]);
```

- [ ] **Step 3: Run them to see them fail**

```bash
php artisan test --compact --filter='PsoToolsCheckoutTest|ProgressLineTest|LoadAuditToolTest'
```
Expected: the new checkout test fails (`regionAvailabilityScript` undefined), the wording tests fail, the `gather` case fails.

- [ ] **Step 4: Implement**

`app/Tools/LoadAudit/PsoToolsCheckout.php`: add after `script()`:

```php
    public function regionAvailabilityScript(): string
    {
        return $this->file('region-availability/region_availability.py');
    }
```

and change the three messages: in `python()` to `"The pso-tools checkout is not set up on this server: Python ({$this->python}) was not found."`, in `file()` to `'The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.'` and `"The pso-tools checkout is not set up on this server: {$path} is missing."`. Update the class docblock to "Where the pso-tools scripts and reference files live on this server. Shared by every tool that runs one."

`config/tools.php`: add after the `load_audit` block:

```php
    'region_availability' => [
        // The viewer reads one LOAD and does no checks; Acme's takes well under a minute.
        'timeout_seconds' => (int) env('TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS', 600),
    ],
```

`app/Tools/LoadAudit/ProgressLine.php`: add to `LABELS`:

```php
        'gather' => "Working out each technician's regions",
```

and change the class docblock to "One stderr line from a pso-tools script run with PSO_PROGRESS=lines, turned into words for the run page."

Create `tests/Fixtures/pso-tools/region-availability/region_availability.py` (make it the fake the tool tests drive; it mirrors the real script's arguments and output layout):

```python
"""Stand-in for pso-tools/region-availability/region_availability.py in tests. Same arguments, same output
layout (<out-root>/<ACCOUNT>/<DATASET>/region_availability_<Monday>.html + .json), no rules.

FAKE_RA_MODE: ok (default) | fail | nopage | hang.
FAKE_RA_ARGS_FILE: where to write the received argv, cwd, env and the account file it read, as JSON (optional).
"""
import json, os, sys, time


def arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None


mode = os.environ.get('FAKE_RA_MODE', 'ok')
account = arg('--account') or 'ACME'
settings = {}
sp = os.path.join(arg('--accounts-dir') or '.', account + '.json')
if os.path.exists(sp):
    with open(sp, encoding='utf-8') as fh:
        settings = json.load(fh)

if os.environ.get('FAKE_RA_ARGS_FILE'):
    with open(os.environ['FAKE_RA_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS'),
                   'settings': settings, 'settings_file': sp}, fh)

if mode == 'hang':
    time.sleep(30)

lines = os.environ.get('PSO_PROGRESS') == 'lines'
def progress(rec):
    if lines:
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec)); sys.stderr.flush()

print('Reading load.xml ...', flush=True)
progress({'stage': 'load', 'percent': 0})
progress({'stage': 'load', 'percent': 100})
progress({'stage': 'gather'})

if mode == 'fail':
    sys.stderr.write('Traceback (most recent call last):\n  File "region_availability.py", line 1\n')
    sys.stderr.write('SystemExit: The LOAD has no Input_Reference. Is it a dsScheduleData export?\n')
    sys.exit(1)

print('WARNING: No shifts in this week.')
if mode == 'nopage':
    sys.exit(0)

week = arg('--week-of') or '2026-10-05'
out_dir = os.path.join(arg('--out-root'), account, 'NORTH')
os.makedirs(out_dir, exist_ok=True)
data = {
    'format': 1,
    'meta': {'account': account, 'dataset': 'NORTH', 'org': settings.get('display_name', account),
             'tz': arg('--time-zone'), 'week': week,
             'labels': {'district': settings.get('attributes', {}).get('district', 'District')},
             'counts': {'techs': 118, 'shifts': 500, 'techs_with_changes': 9, 'missing_availability': 0, 'ties': 0, 'unknown_regions': 0},
             'warnings': ['No shifts in this week.']},
    'regions': [], 'groups': [], 'types': [], 'techs': [],
}
base = os.path.join(out_dir, 'region_availability_' + week)
with open(base + '.json', 'w') as fh:
    json.dump(data, fh)
with open(base + '.html', 'w') as fh:
    fh.write('<!doctype html><html><body><h1>%s: regions, week of %s</h1><script>const DATA=%s</script></body></html>'
             % (data['meta']['org'], week, json.dumps(data)))
print('load.xml: 118 technicians, 500 shifts in the week of %s, 9 with changes.' % week)
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact --filter='PsoToolsCheckoutTest|ProgressLineTest|LoadAuditToolTest'
```
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/LoadAudit/PsoToolsCheckout.php config/tools.php app/Tools/LoadAudit/ProgressLine.php tests/Fixtures/pso-tools/region-availability tests/Feature/LoadAudit tests/Unit/LoadAudit
git commit -m "Tools: checkout knows the region availability script, gather progress stage, fake viewer script"
```

---

### Task 3: The account file from the dataset settings

**Files:**
- Modify: `app/Support/DatasetSettings.php`
- Create: `app/Tools/RegionAvailability/AccountSettingsFile.php`
- Test: `tests/Unit/DatasetSettingsTest.php`, `tests/Unit/RegionAvailability/AccountSettingsFileTest.php`

**Interfaces:**
- Produces: `DatasetSettings::toAccountFile(): array` (pso-tools key names, nulls dropped); `AccountSettingsFile::DIR = 'accounts'`; `AccountSettingsFile::accountName(?string $accountId): string`; `AccountSettingsFile::write(?DatasetSettings $settings, string $accountsDir, string $account): string` (returns the file path).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/DatasetSettingsTest.php`:

```php
it('writes the pso-tools account file keys and round trips through fromAccountFile', function () {
    $settings = DatasetSettings::fromArray([
        'display_name' => 'Acme',
        'dataset_display_name' => 'North',
        'exclude_resource_types' => ['LONGCYCLE'],
        'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email'],
        'region_labels' => ['district_label' => 'Territory'],
        'time_zone' => 'America/Regina',
    ]);

    $file = $settings->toAccountFile();

    expect($file['attributes'])->toBe(['district' => 'District', 'supervisor_email' => 'Supervisor Email'])
        ->and($file['dataset_name'])->toBe('North')
        ->and($file['regions'])->toBe(['district_label' => 'Territory'])
        ->and($file)->not->toHaveKeys(['attribute_labels', 'dataset_display_name', 'region_labels', 'eod_cutoff_hour', 'supervisor_label', 'windows_time_zone', 'resource_type_names', 'notes'])
        ->and($file['region_pivot'])->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and(DatasetSettings::fromAccountFile($file)->toArray())->toBe($settings->toArray());
});
```

Create `tests/Unit/RegionAvailability/AccountSettingsFileTest.php`:

```php
<?php

use App\Support\DatasetSettings;
use App\Tools\RegionAvailability\AccountSettingsFile;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/accounts-'.uniqid();
});

afterEach(function () {
    if (is_dir($this->dir)) {
        File::deleteDirectory($this->dir);
    }
});

it('writes the settings under the pso-tools key names and creates the folder', function () {
    $settings = DatasetSettings::fromArray([
        'display_name' => 'Acme',
        'exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'],
        'resource_type_names' => ['DISTRICTTECH' => 'district tech'],
        'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email'],
        'time_zone' => 'America/Regina',
    ]);

    $path = AccountSettingsFile::write($settings, $this->dir, 'ACME');

    expect($path)->toBe($this->dir.'/ACME.json');
    $json = json_decode(file_get_contents($path), true);
    expect($json['display_name'])->toBe('Acme')
        ->and($json['attributes'])->toBe(['district' => 'District', 'supervisor_email' => 'Supervisor Email'])
        ->and($json['exclude_resource_types'])->toBe(['LONGCYCLE', 'SHORTCYCLE'])
        ->and($json['resource_type_names'])->toBe(['DISTRICTTECH' => 'district tech'])
        ->and($json['region_pivot'])->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($json['time_zone'])->toBe('America/Regina')
        ->and($json)->not->toHaveKeys(['attribute_labels', 'eod_cutoff_hour', 'dataset_display_name']);
});

it('writes an empty object, never a list, when there are no settings', function () {
    AccountSettingsFile::write(null, $this->dir, 'ACME');

    expect(trim(file_get_contents($this->dir.'/ACME.json')))->toBe('{}');
});

it('leaves empty maps out so the script never meets a JSON list where it expects a map', function () {
    AccountSettingsFile::write(DatasetSettings::fromArray(['display_name' => 'Acme']), $this->dir, 'ACME');

    $json = json_decode(file_get_contents($this->dir.'/ACME.json'), true);
    expect($json)->toHaveKeys(['display_name', 'coverage_km', 'region_pivot'])
        ->and($json)->not->toHaveKeys(['attributes', 'exclude_resource_types', 'resource_type_names', 'notes', 'regions']);
});

it('cleans the account name', function (?string $raw, string $expected) {
    expect(AccountSettingsFile::accountName($raw))->toBe($expected);
})->with([
    ['acme', 'ACME'],
    ['GLBX', 'GLBX'],
    ['ac me-1', 'ACME1'],
    ['   ', 'ACCOUNT'],
    [null, 'ACCOUNT'],
]);
```

- [ ] **Step 2: Run them to see them fail**

```bash
php artisan test --compact --filter='DatasetSettingsTest|AccountSettingsFileTest'
```
Expected: FAIL, `toAccountFile` undefined and class `AccountSettingsFile` not found.

- [ ] **Step 3: Implement**

`app/Support/DatasetSettings.php`: add after `fromAccountFile()`:

```php
    /**
     * These settings as the pso-tools scripts read them from accounts/<ACCOUNT>.json: their key names,
     * nulls and empty maps left out (an empty PHP array encodes as a JSON list, and the scripts call
     * .get() on these, so an empty one must be absent rather than []). fromAccountFile(toAccountFile())
     * gives the same settings back.
     *
     * @return array<string, mixed>
     */
    public function toAccountFile(): array
    {
        $out = [];
        foreach ($this->toArray() as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            $fileKey = array_search($key, self::ACCOUNT_FILE_ALIASES, true);
            $out[$fileKey === false ? $key : $fileKey] = $value;
        }

        return $out;
    }
```

Create `app/Tools/RegionAvailability/AccountSettingsFile.php`:

```php
<?php

namespace App\Tools\RegionAvailability;

use App\Support\DatasetSettings;
use stdClass;

/**
 * The dataset's settings written as the account file region_availability.py reads
 * (accounts/<ACCOUNT>.json under --accounts-dir). The checkout's own accounts folder is never used on
 * the web: what the dataset's edit form says is the only source.
 */
class AccountSettingsFile
{
    public const DIR = 'accounts';

    /** The name the file is written under and passed as --account: letters and digits only, upper case; ACCOUNT when nothing is left. */
    public static function accountName(?string $accountId): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $accountId));

        return $clean === '' ? 'ACCOUNT' : $clean;
    }

    /** Writes <accountsDir>/<account>.json (creating the folder) and returns its path. No settings writes {}. */
    public static function write(?DatasetSettings $settings, string $accountsDir, string $account): string
    {
        $accountsDir = rtrim($accountsDir, '/');
        if (! is_dir($accountsDir)) {
            mkdir($accountsDir, 0770, true);
        }

        $data = $settings?->toAccountFile() ?? [];
        $path = "{$accountsDir}/{$account}.json";
        file_put_contents($path, json_encode($data === [] ? new stdClass : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact --filter='DatasetSettingsTest|AccountSettingsFileTest'
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/DatasetSettings.php app/Tools/RegionAvailability/AccountSettingsFile.php tests/Unit/DatasetSettingsTest.php tests/Unit/RegionAvailability/AccountSettingsFileTest.php
git commit -m "Region availability: dataset settings written as the pso-tools account file"
```

---

### Task 4: The command

**Files:**
- Create: `app/Tools/RegionAvailability/RegionAvailabilityCommand.php`
- Test: `tests/Unit/RegionAvailability/RegionAvailabilityCommandTest.php`

**Interfaces:**
- Consumes: `PsoToolsCheckout::python()`, `PsoToolsCheckout::regionAvailabilityScript()` (Task 2); `AccountSettingsFile::DIR` (Task 3).
- Produces: `RegionAvailabilityCommand::OUT_DIR = 'out'`; `RegionAvailabilityCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, string $account): list<string>`; `RegionAvailabilityCommand::weekOf(mixed $value): ?string`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/RegionAvailability/RegionAvailabilityCommandTest.php`:

```php
<?php

use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\RegionAvailabilityCommand;
use Carbon\Carbon;

function raCheckout(): PsoToolsCheckout
{
    return new PsoToolsCheckout(base_path('tests/Fixtures/pso-tools'), 'python3');
}

it('builds the full command without a week', function () {
    $argv = RegionAvailabilityCommand::build(raCheckout(), '/scratch/run-1/', ['load' => '/scratch/run-1/load.zip'], [], 'America/Regina', 'ACME');

    expect($argv)->toBe([
        'python3', '-I', base_path('tests/Fixtures/pso-tools').'/region-availability/region_availability.py',
        '--load', '/scratch/run-1/load.zip',
        '--account', 'ACME',
        '--time-zone', 'America/Regina',
        '--accounts-dir', '/scratch/run-1/accounts',
        '--out-root', '/scratch/run-1/out',
    ]);
});

it('adds the week when one is given', function () {
    $argv = RegionAvailabilityCommand::build(raCheckout(), '/scratch/run-1', ['load' => '/scratch/run-1/load.xml'], ['week_of' => '2026-10-07'], 'America/Regina', 'ACME');

    expect(array_slice($argv, -2))->toBe(['--week-of', '2026-10-07']);
});

it('normalises the week option', function (mixed $value, ?string $expected) {
    expect(RegionAvailabilityCommand::weekOf($value))->toBe($expected);
})->with([
    'blank' => ['', null],
    'null' => [null, null],
    'date string' => ['2026-10-07', '2026-10-07'],
    'datetime string' => ['2026-10-07T00:00:00', '2026-10-07'],
    'carbon' => [fn () => Carbon::parse('2026-10-07'), '2026-10-07'],
]);
```

- [ ] **Step 2: Run it to see it fail**

```bash
php artisan test --compact --filter=RegionAvailabilityCommandTest
```
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Tools/RegionAvailability/RegionAvailabilityCommand.php`:

```php
<?php

namespace App\Tools\RegionAvailability;

use App\Tools\LoadAudit\PsoToolsCheckout;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * The argument list for region_availability.py. Pure: no process, no filesystem, so every shape is unit
 * tested. --account names the file AccountSettingsFile wrote, so the script never has to work the
 * account out from the LOAD; --time-zone is the run's zone and beats the account file, as on the command line.
 */
class RegionAvailabilityCommand
{
    public const OUT_DIR = 'out';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path; only 'load' is used
     * @param  array<string, mixed>  $options  week_of (Y-m-d string from the form, or blank)
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, string $account): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [
            $checkout->python(), '-I', $checkout->regionAvailabilityScript(),
            '--load', $inputPaths['load'],
            '--account', $account,
            '--time-zone', $timeZone,
            '--accounts-dir', $workDir.'/'.AccountSettingsFile::DIR,
            '--out-root', $workDir.'/'.self::OUT_DIR,
        ];

        $week = self::weekOf($options['week_of'] ?? null);
        if ($week !== null) {
            array_push($argv, '--week-of', $week);
        }

        return $argv;
    }

    /** The "Week of" form value as YYYY-MM-DD, or null when blank. */
    public static function weekOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
```

- [ ] **Step 4: Run the test**

```bash
php artisan test --compact --filter=RegionAvailabilityCommandTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/RegionAvailability/RegionAvailabilityCommand.php tests/Unit/RegionAvailability/RegionAvailabilityCommandTest.php
git commit -m "Region availability: command builder"
```

---

### Task 5: `ScriptRun` (shared process plumbing) and `RegionAvailabilityTool`

**Files:**
- Create: `app/Tools/ScriptRun.php`
- Modify: `app/Tools/LoadAudit/LoadAuditTool.php`, `app/Providers/AppServiceProvider.php`
- Create: `app/Tools/RegionAvailability/RegionAvailabilityTool.php`
- Test: `tests/Feature/RegionAvailability/RegionAvailabilityToolTest.php`; the existing `tests/Feature/LoadAudit/LoadAuditToolTest.php` must stay green

**Interfaces:**
- Consumes: `ProgressLine::parse()`, `PsoToolsCheckout` (Task 2), `AccountSettingsFile` (Task 3), `RegionAvailabilityCommand` (Task 4), `ToolContext::reportProgress()`, `ToolResult`.
- Produces: `ScriptRun::execute(ToolContext $context, string $workDir, array $argv, int $timeout, string $noun): ScriptRun` with public `string $stdout`, `list<string> $stderr`, `string $lastLabel`; `RegionAvailabilityTool` registered for `Tool::RegionAvailability`; result keys `html`, `data`; summary keys `week`, `counts {techs, shifts, techs_with_changes}`, `line`.

- [ ] **Step 1: Extract `ScriptRun` from the load audit tool (refactor, no behaviour change)**

Create `app/Tools/ScriptRun.php`:

```php
<?php

namespace App\Tools;

use App\Tools\LoadAudit\ProgressLine;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One run of a pso-tools script as a child process, in the run's scratch folder, with PSO_PROGRESS=lines.
 * PROGRESS stderr lines become the run's progress; the rest of stderr is kept (last 200 lines) for the
 * failure message. Shared by every tool that runs a script; $noun ("The audit", "The viewer") names the
 * tool in the messages a user sees.
 */
final class ScriptRun
{
    /** @param list<string> $stderr */
    private function __construct(public string $stdout, public array $stderr, public string $lastLabel) {}

    /**
     * @param  list<string>  $argv
     *
     * @throws RuntimeException when the script times out or exits non-zero, in plain words
     */
    public static function execute(ToolContext $context, string $workDir, array $argv, int $timeout, string $noun): self
    {
        $lastLabel = 'starting';
        $stderr = [];
        $stdout = '';
        $pending = '';

        $handleLine = static function (string $line) use (&$lastLabel, &$stderr, $context): void {
            if ($line === '') {
                return;
            }
            $progress = ProgressLine::parse($line);
            if ($progress !== null) {
                $lastLabel = $progress['label'];
                $context->reportProgress($progress['label'], $progress['percent'], $progress['seconds_left'], $progress['stage']);
            } elseif (! str_starts_with($line, 'PROGRESS ')) {
                $stderr[] = $line;
                $stderr = array_slice($stderr, -200);
            }
        };

        try {
            $process = Process::path($workDir)
                ->timeout($timeout)
                ->env(['PSO_PROGRESS' => 'lines', 'LANG' => 'C.UTF-8'])
                ->run($argv, static function (string $type, string $buffer) use (&$stdout, &$pending, $handleLine): void {
                    if ($type === 'out') {
                        $stdout .= $buffer;

                        return;
                    }
                    // A chunk can end mid-line: only complete lines are handled, the fragment waits for the next chunk.
                    $lines = preg_split('/\r?\n/', $pending.$buffer);
                    $pending = (string) array_pop($lines);
                    foreach ($lines as $line) {
                        $handleLine($line);
                    }
                });
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("{$noun} took longer than ".intdiv($timeout, 60).' minutes and was stopped.');
        }

        $handleLine($pending);

        if (! $process->successful()) {
            Log::warning('pso-tools script failed', ['tool_run_id' => $context->run->id, 'script' => $argv[2] ?? null, 'exit' => $process->exitCode(), 'stderr' => implode("\n", $stderr)]);
            $stage = strtolower(preg_replace('/:.*$/', '', $lastLabel));
            $lastLine = collect($stderr)->last(static fn (string $line): bool => trim($line) !== '') ?? 'no error text';

            throw new RuntimeException("{$noun} stopped while {$stage}: ".Str::limit(trim($lastLine), 300));
        }

        return new self($stdout, $stderr, $lastLabel);
    }
}
```

Then in `app/Tools/LoadAudit/LoadAuditTool.php` replace everything in `run()` from `$lastLabel = 'starting';` down to and including the `throw new RuntimeException("The audit stopped while ...` block with:

```php
        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The audit');
```

and change the later `preg_split('/\r?\n/', $stdout)` to `preg_split('/\r?\n/', $script->stdout)`. Remove the now unused imports (`ProcessTimedOutException`, `Log`, `Process`, `Str`) and add `use App\Tools\ScriptRun;`.

- [ ] **Step 2: Prove the load audit still behaves the same**

```bash
php artisan test --compact --filter=LoadAuditToolTest
```
Expected: all pass, including "names the stage and the last error line", "stops a script that runs too long", "joins a progress line that arrives in two chunks", "throttles repeated percent updates", "limits the last error line kept".

- [ ] **Step 3: Commit the refactor**

```bash
vendor/bin/pint --dirty
git add app/Tools/ScriptRun.php app/Tools/LoadAudit/LoadAuditTool.php
git commit -m "Tools: ScriptRun shares the process plumbing between script-running tools"
```

- [ ] **Step 4: Write the failing tool tests**

`tests/Feature/RegionAvailability/RegionAvailabilityToolTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\RegionAvailability\RegionAvailabilityTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;

/** Symfony Process only forwards variables from $_SERVER/$_ENV, not ones set with putenv(), so set them there. */
function fakeViewerEnv(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

beforeEach(function () {
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.region_availability.timeout_seconds' => 600]);
    fakeViewerEnv('FAKE_RA_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/ra-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/load.xml', '<dsScheduleData/>');
    $this->dataset = Dataset::factory()
        ->for(Environment::factory()->state(['account_id' => 'acme']))
        ->create(['settings' => ['display_name' => 'Acme', 'time_zone' => 'America/Regina', 'attribute_labels' => ['district' => 'District']]]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    if (isset($this->scratch) && is_dir($this->scratch)) {
        File::deleteDirectory($this->scratch);
    }
    fakeViewerEnv('FAKE_RA_MODE', null);
    fakeViewerEnv('FAKE_RA_ARGS_FILE', null);
});

it('is registered for region availability', function () {
    expect(app(ToolRegistry::class)->for(ToolId::RegionAvailability))->toBeInstanceOf(RegionAvailabilityTool::class);
});

it('writes the account file, runs the script in the scratch folder and stores page, data, summary and warnings', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeViewerEnv('FAKE_RA_ARGS_FILE', $argsFile);

    $result = (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], ['week_of' => '2026-10-07']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['cwd'])->toBe(realpath($this->scratch))
        ->and($args['progress_env'])->toBe('lines')
        ->and($args['argv'])->toContain('--account', 'ACME', '--time-zone', 'America/Regina', '--week-of', '2026-10-07')
        ->and($args['settings_file'])->toBe($this->scratch.'/accounts/ACME.json')
        ->and($args['settings']['display_name'])->toBe('Acme')
        ->and($args['settings']['attributes'])->toBe(['district' => 'District'])
        ->and($result->result['html'])->toContain('Acme: regions, week of 2026-10-07')
        ->and($result->result['data']['meta']['week'])->toBe('2026-10-07')
        ->and($result->result['data']['meta']['labels']['district'])->toBe('District')
        ->and($result->summary)->toBe([
            'week' => '2026-10-07',
            'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9],
            'line' => '118 technicians, 9 with changes, week of 2026-10-07',
        ])
        ->and($result->warnings)->toBe(['No shifts in this week.']);
});

it('passes no week when the option is blank and uses ACCOUNT when the environment has no account id', function () {
    $this->dataset->environment->update(['account_id' => '']);
    $this->dataset->refresh();
    $context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
    $argsFile = $this->scratch.'/args.json';
    fakeViewerEnv('FAKE_RA_ARGS_FILE', $argsFile);

    (new RegionAvailabilityTool)->run($context, ['load' => $this->scratch.'/load.xml'], ['week_of' => '']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['argv'])->not->toContain('--week-of')
        ->and($args['argv'])->toContain('--account', 'ACCOUNT')
        ->and(is_file($this->scratch.'/accounts/ACCOUNT.json'))->toBeTrue();
});

it('leaves the last progress label at the gather stage', function () {
    (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []);

    expect($this->run->fresh()->progress['label'])->toBe("Working out each technician's regions");
});

it('names the stage and the last error line when the script fails', function () {
    fakeViewerEnv('FAKE_RA_MODE', 'fail');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, "The viewer stopped while working out each technician's regions: SystemExit: The LOAD has no Input_Reference. Is it a dsScheduleData export?");
});

it('fails when the script exits cleanly but wrote no page', function () {
    fakeViewerEnv('FAKE_RA_MODE', 'nopage');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The viewer finished but wrote no page.');
});

it('stops a script that runs too long', function () {
    config(['tools.region_availability.timeout_seconds' => 1]);
    fakeViewerEnv('FAKE_RA_MODE', 'hang');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The viewer took longer than 0 minutes and was stopped.');
});

it('fails plainly when the server is not set up', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});

it('offers only the week as a run option and needs only a LOAD', function () {
    $tool = new RegionAvailabilityTool;

    expect(array_map(static fn ($field) => $field->getName(), $tool->optionFields()))->toBe(['options.week_of'])
        ->and($tool->requiredKinds())->toBe([\App\Enums\ExportKind::Load])
        ->and($tool->optionalKinds())->toBe([]);
});
```

- [ ] **Step 5: Run them to see them fail**

```bash
php artisan test --compact --filter=RegionAvailabilityToolTest
```
Expected: FAIL, class not found.

- [ ] **Step 6: Implement the tool and register it**

`app/Tools/RegionAvailability/RegionAvailabilityTool.php`:

```php
<?php

namespace App\Tools\RegionAvailability;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use Filament\Forms\Components\DatePicker;
use RuntimeException;

/**
 * Runs pso-tools/region-availability/region_availability.py (the reference implementation, never ported)
 * against the run's LOAD for one week, with the dataset's settings handed over as the account file, and
 * keeps the page it rendered plus the gathered JSON. The page's three layers (week grid, one-day view,
 * region pivot) are the drill-down; nothing is rebuilt in PHP.
 */
class RegionAvailabilityTool implements Tool
{
    public static function id(): ToolId
    {
        return ToolId::RegionAvailability;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function optionFields(): array
    {
        return [
            DatePicker::make('options.week_of')
                ->label('Week of')
                ->helperText('Any date in the week to show. Blank: the week of the LOAD (a weekend LOAD shows the next week).')
                ->native(false)
                ->rule('date')
                ->nullable(),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['load']);
        $accountId = $context->scope instanceof Dataset ? $context->scope->environment?->account_id : null;
        $account = AccountSettingsFile::accountName($accountId);
        AccountSettingsFile::write($context->settings, $workDir.'/'.AccountSettingsFile::DIR, $account);

        $argv = RegionAvailabilityCommand::build($checkout, $workDir, $inputPaths, $options, $context->timeZone, $account);
        $timeout = (int) config('tools.region_availability.timeout_seconds', 600);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The viewer');

        // The script names the folder after the LOAD's account and dataset id; find the one page it wrote.
        $pages = glob($workDir.'/'.RegionAvailabilityCommand::OUT_DIR.'/*/*/region_availability_*.html') ?: [];
        if (count($pages) !== 1) {
            throw new RuntimeException('The viewer finished but wrote no page.');
        }
        $html = file_get_contents($pages[0]);
        $json = @file_get_contents(substr($pages[0], 0, -strlen('.html')).'.json');
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || $html === false || $html === '') {
            throw new RuntimeException('The viewer finished but wrote no page.');
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $raw = is_array($meta['counts'] ?? null) ? $meta['counts'] : [];
        $counts = [
            'techs' => (int) ($raw['techs'] ?? 0),
            'shifts' => (int) ($raw['shifts'] ?? 0),
            'techs_with_changes' => (int) ($raw['techs_with_changes'] ?? 0),
        ];
        $week = (string) ($meta['week'] ?? '');

        $warnings = [];
        foreach (preg_split('/\r?\n/', $script->stdout) as $line) {
            if (str_starts_with($line, 'WARNING: ')) {
                $warnings[] = trim(substr($line, strlen('WARNING: ')));
            }
        }

        return new ToolResult(
            result: ['html' => $html, 'data' => $data],
            warnings: $warnings,
            summary: ['week' => $week, 'counts' => $counts, 'line' => self::summaryLine($counts, $week)],
        );
    }

    /** @param array{techs: int, shifts: int, techs_with_changes: int} $counts */
    public static function summaryLine(array $counts, string $week): string
    {
        return sprintf('%d technicians, %d with changes, week of %s', $counts['techs'], $counts['techs_with_changes'], $week === '' ? 'the LOAD' : $week);
    }
}
```

`app/Providers/AppServiceProvider.php`: add `use App\Tools\RegionAvailability\RegionAvailabilityTool;` and, inside the `afterResolving` closure after the load audit line:

```php
            $registry->register(ToolId::RegionAvailability, RegionAvailabilityTool::class);
```

- [ ] **Step 7: Run the tests**

```bash
php artisan test --compact --filter='RegionAvailabilityToolTest|LoadAuditToolTest'
```
Expected: PASS. If the "is registered" test fails because `ToolRegistry::has()` or the Runs tab now offers a second Run action that an existing test counts, read that test and update its expectation to include "Run Region availability".

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/RegionAvailability/RegionAvailabilityTool.php app/Providers/AppServiceProvider.php tests/Feature/RegionAvailability/RegionAvailabilityToolTest.php
git commit -m "Region availability: tool on the export-sets contract, runs the pso-tools viewer"
```

---

### Task 6: One controller for every tool page; summary line on the Runs tab

**Files:**
- Rename: `app/Http/Controllers/LoadAuditRunController.php` to `app/Http/Controllers/ToolRunPageController.php`
- Modify: `routes/web.php`, `resources/views/filament/tool-runs/load_audit.blade.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`
- Test: `tests/Feature/LoadAudit/LoadAuditPagesTest.php` (update), `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php` (create, controller and Runs tab parts)

**Interfaces:**
- Produces: routes `tool-runs.page`, `tool-runs.download-html`, `tool-runs.download-json` under `datasets/{dataset}/runs/{run}/`; `ToolRunPageController::fileName(Dataset, ToolRun, string $extension)`; the Runs tab "Result" column shows `summary['line']`, else the red/amber/green counts, else the placeholder.

- [ ] **Step 1: Update the load audit page tests to the new routes and rules**

In `tests/Feature/LoadAudit/LoadAuditPagesTest.php`:

```bash
sed -i '' "s/'load-audit\.dashboard'/'tool-runs.page'/g; s/'load-audit\.download-html'/'tool-runs.download-html'/g; s/'load-audit\.download-findings'/'tool-runs.download-json'/g" tests/Feature/LoadAudit/LoadAuditPagesTest.php
```

Then change the test `gives 404 to another user, to a run of another tool, and to a run without a page yet` to:

```php
it('gives 404 to another user and to a run without a page yet, and serves another tool that has a page', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::LoadAudit]);
    $this->get(route('tool-runs.page', [$other->scope_id, $other]))->assertNotFound();

    $review = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'result' => ['html' => '<p>Review page</p>']]);
    $this->get(route('tool-runs.page', [$this->dataset, $review]))->assertOk()->assertSee('Review page', false);

    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('tool-runs.page', [$this->dataset, $running]))->assertNotFound();
});
```

The test `gives 404 for a run that belongs to another dataset of the same user` loops over route names; make its list `['tool-runs.page', 'tool-runs.download-html', 'tool-runs.download-json']`. The test `shows counts, download buttons and the sandboxed iframe on the run page` keeps `Download findings JSON` (the load audit view keeps that label).

- [ ] **Step 2: Write the new controller and Runs tab tests**

`tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php` (the run page and Run form parts are added in Task 7; start with these):

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
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
        'tool' => ToolId::RegionAvailability, 'run_date' => '2026-10-04',
        'result' => [
            'html' => '<!doctype html><html><body>Regions page <script>1</script></body></html>',
            'data' => ['format' => 1, 'meta' => ['week' => '2026-10-05', 'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9]], 'techs' => []],
        ],
        'summary' => ['week' => '2026-10-05', 'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9], 'line' => '118 technicians, 9 with changes, week of 2026-10-05'],
    ]);
    $this->actingAs($this->user);
});

it('serves the stored page to its owner inside a sandbox', function () {
    $this->get(route('tool-runs.page', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertSee('Regions page', false);
});

it('downloads the page and the data under the tool, account, dataset and week', function () {
    $this->get(route('tool-runs.download-html', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('region_availability_ACME_NORTH_2026-10-05.html');

    $this->get(route('tool-runs.download-json', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('region_availability_ACME_NORTH_2026-10-05.json')
        ->assertJsonPath('meta.week', '2026-10-05');
});

it('gives 404 to another user and to a run without a page yet', function () {
    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('tool-runs.page', [$this->dataset, $running]))->assertNotFound();

    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']));
    $this->get(route('tool-runs.page', [$this->dataset, $this->run]))->assertNotFound();
});

it('shows the summary line on the Runs tab next to the load audit counts', function () {
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]]]);
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'summary' => ['other' => 1]]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('118 technicians, 9 with changes, week of 2026-10-05')
        ->assertSee('2 red, 1 amber, 9 green')
        ->assertSee('Result');
});
```

- [ ] **Step 3: Run both files to see the failures**

```bash
php artisan test --compact --filter='LoadAuditPagesTest|RegionAvailabilityPagesTest'
```
Expected: FAIL with "Route [tool-runs.page] not defined".

- [ ] **Step 4: Implement**

```bash
git mv app/Http/Controllers/LoadAuditRunController.php app/Http/Controllers/ToolRunPageController.php
```

Rewrite `app/Http/Controllers/ToolRunPageController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\ToolRun;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The page a tool run rendered (the pso-tools script's own HTML) and its downloads, for any run whose
 * result holds `html`. The page carries text from customer files and builds itself with JavaScript, so
 * it is served under a CSP sandbox and never with the app's origin.
 */
class ToolRunPageController extends Controller
{
    public const SANDBOX = 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation';

    public function page(string $dataset, string $run): Response
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
        }, self::fileName($datasetModel, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The run's data file: the viewer's gathered JSON (`data`) or the audit's findings (`findings`). */
    public function downloadJson(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);
        $payload = $toolRun->result['data'] ?? $toolRun->result['findings'] ?? [];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, self::fileName($datasetModel, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    /** @return array{0: Dataset, 1: ToolRun} the user's dataset and a finished run on it that holds a page, else 404 */
    private function find(string $dataset, string $run): array
    {
        $datasetModel = Dataset::query()->ownedByCurrentUser()->findOrFail($dataset);
        $toolRun = ToolRun::query()
            ->where('scope_type', $datasetModel->getMorphClass())
            ->where('scope_id', $datasetModel->getKey())
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$datasetModel, $toolRun];
    }

    /** <tool>_<account>_<dataset>_<week Monday or run date>.<ext>, cleaned to letters, digits, _ and -. */
    public static function fileName(Dataset $dataset, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);
        $date = $run->summary['week'] ?? $run->run_date?->toDateString() ?? 'undated';

        return $run->tool->value.'_'.$clean($dataset->environment?->account_id).'_'.$clean($dataset->name).'_'.$clean($date).'.'.$extension;
    }
}
```

`routes/web.php`: replace the `use App\Http\Controllers\LoadAuditRunController;` line with `use App\Http\Controllers\ToolRunPageController;` and the route group with:

```php
Route::middleware(['web', Authenticate::class])
    ->prefix('datasets/{dataset}/runs/{run}')
    ->controller(ToolRunPageController::class)
    ->group(static function (): void {
        Route::get('page', 'page')->name('tool-runs.page');
        Route::get('download/html', 'downloadHtml')->name('tool-runs.download-html');
        Route::get('download/json', 'downloadJson')->name('tool-runs.download-json');
    });
```

`resources/views/filament/tool-runs/load_audit.blade.php`: change the three route names: `load-audit.download-html` to `tool-runs.download-html`, `load-audit.download-findings` to `tool-runs.download-json`, `load-audit.dashboard` to `tool-runs.page`. Labels stay ("Download HTML", "Download findings JSON").

`app/Filament/RelationManagers/ToolRunsRelationManager.php`: replace the `findings` column with:

```php
                Tables\Columns\TextColumn::make('result_summary')
                    ->label('Result')
                    ->placeholder('-')
                    ->getStateUsing(static function (ToolRun $record): ?string {
                        $summary = $record->summary ?? [];
                        if (is_string($summary['line'] ?? null) && $summary['line'] !== '') {
                            return $summary['line'];
                        }
                        $counts = $summary['counts'] ?? null;
                        if (! is_array($counts) || ! array_key_exists('red', $counts)) {
                            return null;
                        }

                        return ((int) ($counts['red'] ?? 0)).' red, '.((int) ($counts['amber'] ?? 0)).' amber, '.((int) ($counts['green'] ?? 0)).' green';
                    }),
```

Search for other users of the old names and fix them:

```bash
grep -rn "load-audit\.\|LoadAuditRunController" app resources routes tests
```
Expected: no matches left.

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact --filter='LoadAuditPagesTest|RegionAvailabilityPagesTest|RunsTabTest'
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/Http/Controllers routes/web.php resources/views/filament/tool-runs/load_audit.blade.php app/Filament/RelationManagers/ToolRunsRelationManager.php tests/Feature/LoadAudit/LoadAuditPagesTest.php tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php
git commit -m "Tool runs: one page controller for every tool, summary line on the Runs tab"
```

---

### Task 7: The run page view, the Run form, and the golden test

**Files:**
- Create: `resources/views/filament/tool-runs/region_availability.blade.php`
- Test: `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php` (append), `tests/Feature/RegionAvailability/RegionAvailabilityGoldenTest.php` (create)

**Interfaces:**
- Consumes: `tool-runs.*` routes (Task 6); `summary` keys (Task 5); `ToolRunPage::resultView()` picks `filament.tool-runs.<tool id>` when it exists.

- [ ] **Step 1: Write the failing view and form tests**

Append to `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php` (add `use App\Enums\ExportKind; use App\Filament\Pages\ToolRunPage; use App\Models\ExportFile; use Illuminate\Support\Facades\Queue;` to the imports):

```php
it('shows the counts, the week, the download buttons and the sandboxed iframe on the run page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('118 technicians')->assertSee('500 shifts')->assertSee('9 with changes')
        ->assertSee('Week of 2026-10-05')
        ->assertSee('Download HTML')->assertSee('Download JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', [$this->dataset, $this->run])));
});

it('renders a run without a page without an iframe or download buttons', function () {
    $noPage = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'result' => ['data' => []], 'summary' => []]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $noPage->id])
        ->assertOk()
        ->assertDontSeeHtml('<iframe')
        ->assertDontSee('Download HTML')
        ->assertSee('page is not available');
});

it('offers Week of on the Run form and validates it', function (array $options, ?string $invalidField) {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    $component = Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_region_availability', data: ['files' => ['load' => $load->id], 'options' => $options]);

    if ($invalidField === null) {
        $component->assertHasNoTableActionErrors();
        expect(ToolRun::query()->where('tool', ToolId::RegionAvailability)->latest('id')->first()->inputs['options'])->toBe($options);
    } else {
        $component->assertHasTableActionErrors([$invalidField]);
    }
})->with([
    'blank week' => [['week_of' => null], null],
    'a date' => [['week_of' => '2026-10-07'], null],
    'not a date' => [['week_of' => 'not a date'], 'options.week_of'],
]);
```

- [ ] **Step 2: Run them to see them fail**

```bash
php artisan test --compact --filter=RegionAvailabilityPagesTest
```
Expected: the run page test fails (the generic view shows no badges); the form test may already pass for the valid cases.

- [ ] **Step 3: Create the view**

`resources/views/filament/tool-runs/region_availability.blade.php`:

```blade
@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? [];
    $week = $run->summary['week'] ?? null;
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
            <x-filament::badge color="gray">{{ (int) ($counts['techs'] ?? 0) }} technicians</x-filament::badge>
            <x-filament::badge color="gray">{{ (int) ($counts['shifts'] ?? 0) }} shifts</x-filament::badge>
            <x-filament::badge color="warning">{{ (int) ($counts['techs_with_changes'] ?? 0) }} with changes</x-filament::badge>
            @if ($week)
                <span class="text-gray-600 dark:text-gray-400">Week of {{ $week }}</span>
            @endif
        </div>
        @if ($hasPage)
        <div class="flex gap-2">
            <x-filament::button tag="a" href="{{ route('tool-runs.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download JSON</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The viewer's own page, as region_availability.py rendered it: week grid, one-day view, region pivot.
     Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', [$dataset, $run]) }}"
    title="Region availability"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 14rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The page is not available for this run.</p>
@endif
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact --filter=RegionAvailabilityPagesTest
```
Expected: PASS. If `not a date` is not rejected, the `->rule('date')` on the DatePicker in `RegionAvailabilityTool::optionFields()` is missing; add it there (Task 5 includes it).

- [ ] **Step 5: Write the golden test (skipped without real data)**

`tests/Feature/RegionAvailability/RegionAvailabilityGoldenTest.php`:

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
use App\Support\DatasetSettings;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\AccountSettingsFile;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Proves the web run hands the real script what the command line does. Needs a real pso-tools checkout and
 * a folder holding one LOAD export named load.(xml|zip). Keep that folder outside this repo (customer data):
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools REGION_AVAILABILITY_SAMPLE_DIR=/path/to/folder php artisan test --filter=RegionAvailabilityGolden
 * Skipped otherwise.
 */
it('gathers the same JSON through the web as the command line', function () {
    $checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    $sample = env('REGION_AVAILABILITY_SAMPLE_DIR');
    if (blank($checkoutPath) || blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH and REGION_AVAILABILITY_SAMPLE_DIR to run the golden test.');
    }
    config(['tools.pso_tools_path' => $checkoutPath, 'tools.region_availability.timeout_seconds' => 600]);

    $load = null;
    foreach (['xml', 'zip'] as $ext) {
        if (is_file("{$sample}/load.{$ext}")) {
            $load = "{$sample}/load.{$ext}";
        }
    }
    expect($load)->not->toBeNull();

    Storage::fake('r2');
    $scratchPath = sys_get_temp_dir().'/exports-golden-ra-'.uniqid();
    $work = sys_get_temp_dir().'/golden-ra-cli-'.uniqid();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => $scratchPath]);
    $removeDirectory = static fn (string $directory) => is_dir($directory) && File::deleteDirectory($directory);
    $settings = ['display_name' => 'Acme', 'time_zone' => 'America/Regina', 'exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'], 'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email']];

    try {
        $dataset = Dataset::factory()->for(Environment::factory()->state(['account_id' => 'ACME']))->create(['name' => 'NORTH', 'settings' => $settings]);
        $storage = app(ExportStorage::class);
        $file = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => pathinfo($load, PATHINFO_EXTENSION)]);
        Storage::disk('r2')->put($storage->objectKey($file), file_get_contents($load));
        $run = ToolRun::factory()->for($dataset, 'scope')->create([
            'tool' => ToolId::RegionAvailability, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null,
            'inputs' => ['files' => ['load' => $file->id], 'options' => ['week_of' => '2026-10-07']],
        ]);

        (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));
        $run->refresh();
        expect($run->status)->toBe(ToolRunStatus::Succeeded, $run->error_message ?? '');

        // The command line, with the same account file and week.
        mkdir($work, 0770, true);
        AccountSettingsFile::write(DatasetSettings::fromArray($settings), "{$work}/accounts", 'ACME');
        $checkout = PsoToolsCheckout::fromConfig();
        $cli = Process::path($work)->timeout(600)->run([
            $checkout->python(), '-I', $checkout->regionAvailabilityScript(), '--load', $load, '--account', 'ACME',
            '--time-zone', 'America/Regina', '--accounts-dir', "{$work}/accounts", '--out-root', "{$work}/out", '--week-of', '2026-10-07',
        ]);
        expect($cli->successful())->toBeTrue($cli->errorOutput());
        $cliJson = json_decode(file_get_contents(glob("{$work}/out/*/*/region_availability_*.json")[0]), true);

        $webData = $run->result['data'];
        unset($cliJson['meta']['generated'], $webData['meta']['generated']);
        expect($webData)->toEqual($cliJson)
            ->and($run->summary['week'])->toBe($cliJson['meta']['week'])
            ->and($run->summary['counts']['techs'])->toBe($cliJson['meta']['counts']['techs']);
    } finally {
        $removeDirectory($scratchPath);
        $removeDirectory($work);
    }
});
```

`RunToolJob::handle(ExportStorage $storage, ToolRegistry $registry)` is the job's signature; the load audit golden test calls it the same way.

- [ ] **Step 6: Run the golden test against real data (on this Mac the checkout is the repo itself)**

```bash
TOOLS_PSO_TOOLS_PATH="/Users/idawoodjee/Documents/Projects/PSO Tools" REGION_AVAILABILITY_SAMPLE_DIR=~/pso-data/ACME/sunday php artisan test --compact --filter=RegionAvailabilityGolden
```
Expected: PASS (or skipped with the reason if the sample folder is missing; say which in the notes).

- [ ] **Step 7: Full suite and commit**

```bash
php artisan test --compact --exclude-filter=ExampleTest
vendor/bin/pint --dirty
git add resources/views/filament/tool-runs/region_availability.blade.php tests/Feature/RegionAvailability
git commit -m "Region availability: run page view, Run form week option, golden test"
```

---

### Task 8: Retire Technician Availability; README

**Files:**
- Delete: `app/Filament/Pages/TechnicianAvail.php`, `app/Services/TechnicianAvailabilityService.php`, `app/Jobs/GetTechniciansListJob.php`, `app/Jobs/GetTechnicianShiftsJob.php`, `app/Jobs/CleanupTechnicianUploadJob.php`, `resources/views/filament/pages/technician-avail.blade.php`, `resources/views/components/technician-gantt.blade.php`, `tests/Feature/TechnicianAvailabilityServiceTest.php`, `tests/Fixtures/sample-technician-shifts.json`
- Modify: `app/Filament/Widgets/QuickLaunch.php` (line 15 import, line 101 tile), `README.md`
- Create: `tests/Feature/TechnicianAvailabilityRetiredTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/TechnicianAvailabilityRetiredTest.php`:

```php
<?php

use App\Filament\Widgets\QuickLaunch;
use App\Models\User;
use Livewire\Livewire;

/** The old page is replaced by the region availability tool on a dataset's Runs tab (spec 2026-10-11). */
it('no longer has the Technician Availability page, tile or service', function () {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']));

    expect(class_exists(\App\Filament\Pages\TechnicianAvail::class))->toBeFalse()
        ->and(class_exists(\App\Services\TechnicianAvailabilityService::class))->toBeFalse()
        ->and(class_exists(\App\Jobs\GetTechnicianShiftsJob::class))->toBeFalse()
        ->and(view()->exists('components.technician-gantt'))->toBeFalse()
        ->and(is_file(base_path('tests/Fixtures/sample-technician-shifts.json')))->toBeFalse();

    $this->get('/technician-avail')->assertNotFound();

    Livewire::test(QuickLaunch::class)
        ->assertSee('Technician Details')
        ->assertDontSee('Technician Availability');
});
```

- [ ] **Step 2: Run it to see it fail**

```bash
php artisan test --compact --filter=TechnicianAvailabilityRetiredTest
```
Expected: FAIL (the class exists).

- [ ] **Step 3: Delete and unhook**

```bash
git rm app/Filament/Pages/TechnicianAvail.php app/Services/TechnicianAvailabilityService.php \
  app/Jobs/GetTechniciansListJob.php app/Jobs/GetTechnicianShiftsJob.php app/Jobs/CleanupTechnicianUploadJob.php \
  resources/views/filament/pages/technician-avail.blade.php resources/views/components/technician-gantt.blade.php \
  tests/Feature/TechnicianAvailabilityServiceTest.php tests/Fixtures/sample-technician-shifts.json
```

In `app/Filament/Widgets/QuickLaunch.php` remove the line `use App\Filament\Pages\TechnicianAvail;` and the line
`['label' => 'Technician Availability', 'icon' => Heroicon::OutlinedCalendar, 'class' => TechnicianAvail::class],`.

Then make sure nothing else references them:

```bash
grep -rn "TechnicianAvail\b\|TechnicianAvailabilityService\|GetTechnician\|CleanupTechnicianUpload\|technician-gantt\|technician-avail\|sample-technician-shifts" app resources routes config tests database README.md
```
Expected: only `tests/Feature/TechnicianAvailabilityRetiredTest.php`. Fix anything else that shows (for example a `config/filesystems.php` comment or a scheduled job in `routes/console.php`).

- [ ] **Step 4: Update the README**

In `README.md`, after the "### Load and configuration health audit" section and before "## Deploying", add:

```markdown
### Region availability

Which regions each technician can work on each day of a week, from a LOAD, run as a child process from the
same pso-tools checkout (nothing is ported). Start it on a dataset's Runs tab with "Run Region availability";
"Week of" is any date in the week to show (blank: the LOAD's own week; a weekend LOAD shows the next week).
One run is one week. The page has three layers: the week grid (technicians down, days across, shift bar and
region change chips, filters for district, supervisor and resource type, search by id or name), the one-day
view (click a cell: the shift with breaks and every region window with multiplier and priority) and the
region pivot (regions by parent, technician counts per day with thresholds, click for names). The
per-technician view is the search box plus a click on a day.

The dataset's settings are handed to the script as its account file, so the district and supervisor labels,
excluded resource types, resource type names and pivot thresholds come from the dataset's edit form, not from
the checkout. Downloads: the page and the gathered JSON. Timeout: `TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS`
(600 by default).

This replaces the Technician Availability page (JSON upload, one technician's shifts), removed 2026-10-11.
```

If the README mentions Technician Availability anywhere else, remove that line.

- [ ] **Step 5: Run the whole suite**

```bash
php artisan test --compact --exclude-filter=ExampleTest
```
Expected: PASS, with the retired test green and the old service test gone.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/Filament/Widgets/QuickLaunch.php README.md tests/Feature/TechnicianAvailabilityRetiredTest.php
git status --short
git commit -m "Retire Technician Availability: replaced by the region availability tool"
```

---

### Task 9: Execution notes and handback

**Files:**
- Create: `docs/superpowers/notes/2026-10-11-region-availability-execution-notes.md`

- [ ] **Step 1: Write the notes**

Follow `docs/superpowers/notes/2026-10-11-load-audit-execution-notes.md` for the shape: what was built per task, the fix rounds, what was not done on purpose (no browser check of the run page and iframe), whether the golden test ran and on which data, and a "Deferred minors" list (anything the reviewers raised that was not fixed). Do not record anything already in the spec or the plan.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/notes/2026-10-11-region-availability-execution-notes.md
git commit -m "Notes: region availability execution"
```

Then hand back: the branch `feat/region-availability` is ready for the whole-branch review and merge. The deploy is section 8 of the spec: pso-tools main first (already pushed in Task 1; the server pulls it through the pull deploy or `git pull` in `/var/www/pso-tools`), then this branch merged to main and pushed. No migration this time.
