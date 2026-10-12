# Schedule Review on the Web Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the pso-tools daily schedule review from a dataset's Runs tab against the uploaded LOAD, plan, EOD and optional audits, with the 60-day history kept per dataset so the trend lines work, and halve the script's memory so a weekday run fits the box.

**Architecture:** `ScheduleReviewTool` (pso-test-tools) seeds the dataset's stored history into the scratch folder, writes the account file, runs `schedule_review.py` through the existing `ScriptRun`, then stores the page, the data JSON and the two history files back. History lives in a new encrypted `tool_states` table. The Run action refuses a second review while one is running on the dataset. In pso-tools, `read_tables()` keeps only the fields the module names (proven output-identical) and emits `PROGRESS` lines.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest; Python 3 standard library (pso-tools). PHP 8.5 locally, 8.4 on the server.

**Spec:** `docs/superpowers/specs/2026-10-11-schedule-review-port-design.md` (pso-test-tools). Read it first, then the load audit and region availability specs it builds on. The plan argues from the spec.

**Two repos.** Part A (Tasks 1 and 2) is in `pso-tools` (`/Users/idawoodjee/Documents/Projects/PSO Tools`, branch `schedule-review-web` off `main`). Part B (Tasks 3 to 9) is in `pso-test-tools` (`~/Herd/pso-test-tools`, branch `feat/schedule-review` off `main`, in a worktree under `.worktrees/`). Do Part A first and push it: Part B's golden test runs the real script.

## Global Constraints

- pso-tools: Python standard library only. `schedule_review.py`'s arguments, outputs and data JSON must not change (Task 1 proves it on real data); `Build-ScheduleReview.ps1` is not touched. `python3 -m unittest discover -s tests -t .` stays green.
- pso-tools is private, pso-test-tools is public: never copy `schedule_review.py`, its template or `accounts/*.json` into pso-test-tools. Only the tiny fake script in `tests/Fixtures/pso-tools/schedule-review/` lives there.
- Real data for Part A and the golden test: `~/pso-data/SATE/sunday/` (load.zip, plan.zip, eod.zip; no audits). Customer data: never copied into either repo, never committed (`*.xml`, `*.zip`, `output/`).
- pso-test-tools: run the suite with `php artisan test --compact --exclude-filter=ExampleTest`. Run `vendor/bin/pint --dirty` before each commit.
- Unit tests that touch the database go in `tests/Feature`.
- Wording: plain English, no em dashes, "allocated" never "placed"; every failure message says what was missing or what to do.
- Never pass user text through a shell: the command is always an argument list.
- Commit messages end with the attribution lines the session provides.

## Review Focus

Inputs the spec implies that are easy to leave untested. Each has a test in the task named.

1. A first run on a dataset has no stored history: nothing is seeded, the script starts empty, and two `tool_states` rows exist afterwards (Task 6).
2. A failed run (script exit, no page) must leave the stored history exactly as it was (Task 6).
3. A LOAD whose upload summary has no `dataset_id` (old row) must still run; the history is not seeded and the run carries a warning that trend lines start today (Task 6).
4. The concurrency refusal must not block a review on another dataset, a different tool on the same dataset, or a review whose previous run finished or failed (Task 7).
5. The field whitelist must not change the data JSON, including on an input the Sunday set does not exercise; the drift test pins the constant to the source so a new field access cannot be silently dropped (Task 1).

## File structure

Part A, pso-tools:
- Modify `schedule-review/schedule_review.py`: `KEEP_FIELDS` constant and its use in `read_tables()`; `open_xml()` returns the size; `CountingReader`, `progress_lines()`, `emit_progress()`; stages in `main()` and `read_appointments()`.
- Create `tests/schedule_review/__init__.py`, `tests/schedule_review/test_fields.py`, `tests/schedule_review/test_progress.py`.

Part B, pso-test-tools:
- Modify `app/Support/DatasetSettings.php`, `app/Models/Dataset.php` (form), `app/Tools/Contracts/ToolResult.php` (+`runDate`), `app/Jobs/RunToolJob.php` (prefer it), `app/Tools/LoadAudit/ProgressLine.php` (+4 stages), `app/Tools/LoadAudit/PsoToolsCheckout.php` (+`scheduleReviewScript()`), `config/tools.php`.
- Create migration `create_tool_states_table`, `app/Models/ToolState.php`, `database/factories/ToolStateFactory.php`.
- Create `app/Tools/ScheduleReview/ScheduleReviewHistory.php`, `ScheduleReviewCommand.php`, `ScheduleReviewTool.php`; `app/Tools/Contracts/RefusesConcurrentRuns.php`.
- Modify `app/Providers/AppServiceProvider.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php` (refusal), `app/Http/Controllers/ToolRunPageController.php` (`day` in the file name).
- Create `resources/views/filament/tool-runs/schedule_review.blade.php`.
- Create `tests/Fixtures/pso-tools/schedule-review/schedule_review.py` (fake).
- Tests: `tests/Unit/DatasetSettingsTest.php` (+), `tests/Unit/LoadAudit/ProgressLineTest.php` (+), `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php` (+), `tests/Feature/ToolStateTest.php`, `tests/Unit/ScheduleReview/ScheduleReviewCommandTest.php`, `tests/Feature/ScheduleReview/ScheduleReviewHistoryTest.php`, `ScheduleReviewToolTest.php`, `ScheduleReviewPagesTest.php`, `ScheduleReviewGoldenTest.php`, `tests/Feature/RunsTabTest.php` (+).
- Modify `README.md`; create `docs/superpowers/notes/2026-10-11-schedule-review-execution-notes.md`.

Rulings made while planning (departures from the spec's letter, same intent):
- The whitelist is one module-wide set of field names (every quoted field name the module mentions), not a per-table map. Measured on the Sunday set: 493 MB to 245 MB after the three reads, data JSON identical. Simpler to keep honest with a drift test.
- The dataset folder name comes only from the LOAD's upload summary (`summary.dataset_id`). The spec's XMLReader fallback is dropped; a missing id skips the seed with a warning (Review Focus 3).
- `ToolResult` gains `runDate` so the tool can set the run's date to the reviewed day; the job prefers it over the LOAD's export date.

---

## Part A: pso-tools

### Task 1: Field whitelist in `read_tables()` with drift and parity tests

**Files:**
- Modify: `schedule-review/schedule_review.py` (`read_tables()` at line 80; a new constant above it)
- Create: `tests/schedule_review/__init__.py` (empty), `tests/schedule_review/test_fields.py`

**Interfaces:**
- Produces: `schedule_review.KEEP_FIELDS: frozenset[str]`; `read_tables()` keeps only those fields per row. Output of the script is unchanged.

- [ ] **Step 1: Branch**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools" && git switch -c schedule-review-web main && mkdir -p tests/schedule_review && touch tests/schedule_review/__init__.py
```

- [ ] **Step 2: Write the failing tests**

`tests/schedule_review/test_fields.py`:

```python
"""read_tables() keeps only the fields the module names (KEEP_FIELDS). The constant is pinned to the source so
a new field access cannot be dropped by accident, and the data JSON is proven identical on real data."""
import contextlib, io, json, os, re, shutil, sys, tempfile, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "schedule-review" / "schedule_review.py"
sys.path.insert(0, str(SRC.parent))
import schedule_review as sr  # noqa: E402

SAMPLE = Path(os.environ.get("PSO_SAMPLE_DIR", Path.home() / "pso-data" / "SATE" / "sunday"))


def scan_field_names(source: str) -> frozenset:
    """Every quoted lower-case identifier used as a record key: x["field"], x.get("field"), dict(("field", ...))."""
    names = set(re.findall(r"""[\[(,]\s*["']([a-z][a-z0-9_]*)["']""", source))
    names |= set(re.findall(r"""\.get\(\s*["']([a-z][a-z0-9_]*)["']""", source))
    return frozenset(names)


def normalised(path: Path):
    """The data JSON with list order removed (the script's lists vary run to run) and generation time dropped."""
    def norm(o):
        if isinstance(o, dict):
            return {k: norm(v) for k, v in o.items() if k != "generated"}
        if isinstance(o, list):
            return sorted((norm(x) for x in o), key=lambda x: json.dumps(x, sort_keys=True))
        return o
    return norm(json.loads(path.read_text(encoding="utf-8")))


class KeepFieldsTests(unittest.TestCase):
    def test_constant_matches_the_source(self):
        self.assertEqual(sr.KEEP_FIELDS, scan_field_names(SRC.read_text(encoding="utf-8")),
                         "KEEP_FIELDS has drifted from the field names the module uses; regenerate it (see the comment above it)")

    def test_essential_fields_are_kept(self):
        for name in ("id", "datetime", "dataset_id", "activity_id", "resource_id", "label", "label_value",
                     "start_datetime", "end_datetime", "visit_status", "input_reference_id", "output_datetime"):
            self.assertIn(name, sr.KEEP_FIELDS, name)

    def test_reader_drops_unlisted_fields(self):
        tmp = Path(tempfile.mkdtemp())
        try:
            xml = ('<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'
                   '<Activity><id>A1</id><memo>drop me</memo><activity_type_id>T</activity_type_id></Activity></dsScheduleData>')
            p = tmp / "load.xml"
            p.write_text(xml, encoding="utf-8")
            out, _ = sr.read_tables(p, ("Activity",))
            self.assertEqual(out["Activity"], [{"id": "A1", "activity_type_id": "T"}])
            self.assertNotIn("memo", sr.KEEP_FIELDS)
        finally:
            shutil.rmtree(tmp)

    @unittest.skipUnless((SAMPLE / "load.zip").exists() or (SAMPLE / "load.xml").exists(), "no sample data")
    def test_data_json_identical_with_and_without_the_whitelist(self):
        tmp = Path(tempfile.mkdtemp())
        try:
            results = {}
            for label, keep in (("all", None), ("kept", sr.KEEP_FIELDS)):
                sr.KEEP_FIELDS_ACTIVE = keep
                with contextlib.redirect_stdout(io.StringIO()):
                    sr.main(["--data-dir", str(SAMPLE), "--accounts-dir", str(ROOT / "accounts"), "--out-root", str(tmp / label)])
                results[label] = normalised(next((tmp / label).glob("*/*/schedule_review_data_*.json")))
            self.assertEqual(results["all"], results["kept"])
        finally:
            sr.KEEP_FIELDS_ACTIVE = sr.KEEP_FIELDS
            shutil.rmtree(tmp)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Run them to see them fail**

```bash
python3 -m unittest tests.schedule_review.test_fields -v
```
Expected: `AttributeError: module has no attribute KEEP_FIELDS`.

- [ ] **Step 4: Generate the constant and implement**

Print the constant:

```bash
python3 - <<'EOF'
import re
from pathlib import Path
src = Path("schedule-review/schedule_review.py").read_text(encoding="utf-8")
names = set(re.findall(r"""[\[(,]\s*["']([a-z][a-z0-9_]*)["']""", src)) | set(re.findall(r"""\.get\(\s*["']([a-z][a-z0-9_]*)["']""", src))
names = sorted(names)
print("KEEP_FIELDS = frozenset((")
for i in range(0, len(names), 8):
    print("    " + ", ".join(repr(n) for n in names[i:i+8]) + ",")
print("))")
EOF
```

Paste the output into `schedule-review/schedule_review.py` immediately above `def read_tables`, preceded by this comment, and add the `_ACTIVE` line after it:

```python
# Field names this module reads from any export row. read_tables() keeps only these, which halves the memory
# a day's exports take (a dsScheduleData row carries many fields nobody here looks at). The set is every quoted
# field name in this file; tests/schedule_review/test_fields.py fails if it drifts. Regenerate with the
# one-liner in that test's docstring, or in docs: python3 - <<'EOF' ... (see plan 2026-10-11-schedule-review-port).
KEEP_FIELDS = frozenset((
    ...
))
KEEP_FIELDS_ACTIVE = KEEP_FIELDS   # the parity test sets this to None to read everything
```

Then in `read_tables()` change the record build to:

```python
                if tag in want:
                    keep = KEEP_FIELDS_ACTIVE
                    rec = {}
                    for c in el:
                        if c.text is not None and c.text != "":
                            k = c.tag.rsplit("}", 1)[-1]
                            if keep is None or k in keep:
                                rec[k] = c.text
                    out[tag].append(rec)
```

Note the scan regex also matches the names inside the `KEEP_FIELDS` literal itself; that adds nothing new, so the drift test is stable. If the drift test still fails after pasting, re-run the one-liner (the regex and the generator must be the same; copy from the test).

- [ ] **Step 5: Run the tests and measure**

```bash
python3 -m unittest tests.schedule_review.test_fields -v
/usr/bin/time -l python3 schedule-review/schedule_review.py --data-dir ~/pso-data/SATE/sunday --accounts-dir accounts --out-root /tmp/sr-diet 2>&1 | grep -E "Done in|maximum resident"
rm -rf /tmp/sr-diet
```
Expected: 4 tests pass (the parity one runs because the sample exists); maximum resident set size under 300,000,000 bytes (it was 554 MB before). Record the number for the notes.

- [ ] **Step 6: Commit**

```bash
git add schedule-review/schedule_review.py tests/schedule_review/__init__.py tests/schedule_review/test_fields.py
git commit -m "Schedule review: keep only the fields the module reads (half the memory, same output)"
```

---

### Task 2: Opt-in progress lines in `schedule_review.py`

**Files:**
- Modify: `schedule-review/schedule_review.py` (`open_xml()` line 70, `read_tables()`, `read_appointments()` line 125, `main()` line 1125 onwards)
- Create: `tests/schedule_review/test_progress.py`

**Interfaces:**
- Produces: with `PSO_PROGRESS=lines`, stderr carries `PROGRESS {"stage":"load","percent":N}` lines (0, throttled, 100) for each of `load`, `plan`, `eod`, then `audits` (0, per file, 100) when an audits zip is read, then `PROGRESS {"stage":"engine"}` before the views are calculated and `PROGRESS {"stage":"write"}` before the files are written. Silent otherwise.

- [ ] **Step 1: Write the failing test**

`tests/schedule_review/test_progress.py`:

```python
"""PSO_PROGRESS=lines makes schedule_review.py write PROGRESS lines on stderr for the web app; otherwise nothing."""
import contextlib, io, json, os, shutil, sys, tempfile, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "schedule-review"))
import schedule_review as sr  # noqa: E402

SAMPLE = Path(os.environ.get("PSO_SAMPLE_DIR", Path.home() / "pso-data" / "SATE" / "sunday"))


@unittest.skipUnless((SAMPLE / "load.zip").exists() or (SAMPLE / "load.xml").exists(), "no sample data")
class ProgressTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.argv = ["--data-dir", str(SAMPLE), "--accounts-dir", str(ROOT / "accounts"), "--out-root", str(self.tmp)]

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def run_main(self, env_value):
        old = os.environ.get("PSO_PROGRESS")
        if env_value is None:
            os.environ.pop("PSO_PROGRESS", None)
        else:
            os.environ["PSO_PROGRESS"] = env_value
        err, out = io.StringIO(), io.StringIO()
        try:
            with contextlib.redirect_stderr(err), contextlib.redirect_stdout(out):
                sr.main(self.argv)
        finally:
            if old is None:
                os.environ.pop("PSO_PROGRESS", None)
            else:
                os.environ["PSO_PROGRESS"] = old
        return err.getvalue(), out.getvalue()

    def test_lines_mode_reports_each_stage_in_order(self):
        err, out = self.run_main("lines")
        lines = [l for l in err.splitlines() if l]
        self.assertTrue(lines and all(l.startswith("PROGRESS ") for l in lines), lines)
        recs = [json.loads(l[len("PROGRESS "):]) for l in lines]
        stages = []
        for r in recs:
            if not stages or stages[-1] != r["stage"]:
                stages.append(r["stage"])
        self.assertEqual(stages, ["load", "plan", "eod", "engine", "write"])     # no audits in the sample
        for stage in ("load", "plan", "eod"):
            pcts = [r["percent"] for r in recs if r["stage"] == stage]
            self.assertEqual((pcts[0], pcts[-1]), (0, 100), stage)
            self.assertTrue(all(0 <= p <= 100 for p in pcts), stage)
        self.assertNotIn("percent", recs[-1])
        self.assertIn("Done in", out)                                             # stdout log unchanged

    def test_silent_without_the_variable(self):
        err, _ = self.run_main(None)
        self.assertEqual(err, "")


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run it to see it fail**

```bash
python3 -m unittest tests.schedule_review.test_progress -v
```
Expected: the lines test fails (`lines` is empty); the silent test passes.

- [ ] **Step 3: Implement**

In `schedule-review/schedule_review.py`:

Replace `open_xml()` and add the helpers after it (same shape as `region-availability/region_availability.py`, but the stage is a parameter):

```python
def open_xml(p):
    """Return (file object, display name, size in bytes). Zips are read in place; the largest .xml inside is used."""
    if p.suffix.lower() == ".zip":
        z = zipfile.ZipFile(p)
        xmls = [i for i in z.infolist() if i.filename.lower().endswith(".xml")]
        if not xmls: raise SystemExit(f"No .xml file inside {p}")
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
    """Wraps the binary file iterparse reads from and reports progress for one stage as bytes go by:
    a 0% line when opened, at most two lines a second while reading, and 100% from done()."""

    def __init__(self, f, size, stage):
        self.f, self.size, self.stage, self.read_bytes = f, size, stage, 0
        self.next = time.monotonic() + 0.5
        emit_progress({"stage": stage, "percent": 0})

    def read(self, n=-1):
        b = self.f.read(n)
        self.read_bytes += len(b)
        now = time.monotonic()
        if self.size and now >= self.next:
            self.next = now + 0.5
            emit_progress({"stage": self.stage, "percent": min(99, int(100 * self.read_bytes / self.size))})
        return b

    def done(self):
        emit_progress({"stage": self.stage, "percent": 100})

    def close(self):
        self.f.close()

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()
```

`read_tables()` takes a stage:

```python
def read_tables(path, tables, stage=None):
    """Stream through a dsScheduleData export, keeping only the wanted tables and, per row, only KEEP_FIELDS.
    With PSO_PROGRESS=lines and a stage name, reports progress on stderr."""
    want = set(tables); out = {t: [] for t in tables}
    f, name, size = open_xml(path)
    counting = CountingReader(f, size, stage) if stage and progress_lines() else None
    f = counting or f
    depth = 0; root = None
    with f:
        ...unchanged loop...
    if counting: counting.done()
    return out, name
```

In `main()` pass the stages: `read_tables(load_p, LOAD_TABLES, "load")`, `read_tables(plan_p, PLAN_TABLES, "plan")`, `read_tables(eod_p, EOD_TABLES, "eod")`.

In `read_appointments()`, after `names = sorted(...)`:

```python
    lines = progress_lines()
    if lines: emit_progress({"stage": "audits", "percent": 0})
    ...
    for idx, name in enumerate(names):
        if lines and idx and idx % 25 == 0: emit_progress({"stage": "audits", "percent": min(99, int(100 * idx / len(names)))})
        data = z.read(name)
```

and before the function returns: `if lines: emit_progress({"stage": "audits", "percent": 100})`. (Find the function's `return` and add the line above it; it has one.)

In `main()`:
- immediately before `final = run_engine(...)`: `if progress_lines(): emit_progress({"stage": "engine"})`
- immediately before `log.head("Writing files")`: `if progress_lines(): emit_progress({"stage": "write"})`

`os` and `time` are already imported at the top of the module; nothing to add.

- [ ] **Step 4: Run all pso-tools tests and the output check**

```bash
python3 -m unittest discover -s tests -t .
python3 schedule-review/schedule_review.py --data-dir ~/pso-data/SATE/sunday --accounts-dir accounts --out-root /tmp/sr-check 2>/tmp/sr-stderr.txt >/dev/null; wc -c /tmp/sr-stderr.txt; rm -rf /tmp/sr-check /tmp/sr-stderr.txt
```
Expected: all pass (load audit, region availability and the two new schedule review modules); stderr 0 bytes without the variable.

- [ ] **Step 5: Commit, merge, push**

```bash
git add schedule-review/schedule_review.py tests/schedule_review/test_progress.py
git commit -m "Schedule review: opt-in PROGRESS lines on stderr (PSO_PROGRESS=lines) for the web app"
git switch main && git merge --ff-only schedule-review-web && git push origin main
```

---

## Part B: pso-test-tools

### Task 3: Settings keys, run date on the result, progress stages, checkout, config, fake script

**Files:**
- Modify: `app/Support/DatasetSettings.php`, `app/Models/Dataset.php` (Settings section of the form), `app/Tools/Contracts/ToolResult.php`, `app/Jobs/RunToolJob.php` (the `run_date` line), `app/Tools/LoadAudit/ProgressLine.php`, `app/Tools/LoadAudit/PsoToolsCheckout.php`, `config/tools.php`, `tests/Support/FakeTool.php`
- Create: `tests/Fixtures/pso-tools/schedule-review/schedule_review.py`
- Test: `tests/Unit/DatasetSettingsTest.php`, `tests/Unit/LoadAudit/ProgressLineTest.php`, `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`, `tests/Feature/RunToolJobTest.php`

**Interfaces:**
- Produces: `DatasetSettings` properties `customerActivityTypes: array`, `ignoreSkills: array`, `weekStart: ?string` and keys `customer_activity_types`, `ignore_skills`, `week_start`; `ToolResult::$runDate` (`?string`, `Y-m-d`); `PsoToolsCheckout::scheduleReviewScript()`; config `tools.schedule_review.timeout_seconds` (600); `ProgressLine` stages `eod`, `audits`, `engine`, `write`; the fake script driven by `FAKE_SR_MODE` (`ok` | `fail` | `nopage` | `hang`) and `FAKE_SR_ARGS_FILE`.

- [ ] **Step 1: Branch in a worktree**

```bash
cd ~/Herd/pso-test-tools && git worktree add .worktrees/schedule-review -b feat/schedule-review main && cd .worktrees/schedule-review && composer install --quiet
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/DatasetSettingsTest.php`:

```php
it('carries the schedule review keys and validates week_start', function () {
    $settings = DatasetSettings::fromArray([
        'customer_activity_types' => ['INSTALL', 'REPAIR'],
        'ignore_skills' => ['DRIVING'],
        'week_start' => 'sunday',
    ]);

    expect($settings->customerActivityTypes)->toBe(['INSTALL', 'REPAIR'])
        ->and($settings->ignoreSkills)->toBe(['DRIVING'])
        ->and($settings->weekStart)->toBe('sunday')
        ->and($settings->toAccountFile())->toMatchArray(['customer_activity_types' => ['INSTALL', 'REPAIR'], 'ignore_skills' => ['DRIVING'], 'week_start' => 'sunday'])
        ->and(DatasetSettings::fromAccountFile($settings->toAccountFile())->toArray())->toBe($settings->toArray());

    expect(fn () => DatasetSettings::fromArray(['week_start' => 'tuesday']))->toThrow(InvalidArgumentException::class, 'week_start');
    expect(DatasetSettings::fromArray(['week_start' => 'Monday'])->weekStart)->toBe('monday');
});
```

In `tests/Unit/LoadAudit/ProgressLineTest.php` extend the stage dataset:

```php
})->with([['load', 'Reading the LOAD'], ['plan', 'Reading the plan'], ['sys', 'Reading the SYS file'], ['checks', 'Running the checks'], ['gather', "Working out each technician's regions"], ['eod', 'Reading the end-of-day export'], ['audits', 'Reading the appointment audits'], ['engine', 'Calculating the views'], ['write', 'Writing the dashboard']]);
```

Append to `tests/Feature/LoadAudit/PsoToolsCheckoutTest.php`:

```php
it('resolves the schedule review script', function () {
    config(['tools.pso_tools_path' => fakeCheckout()]);

    expect(PsoToolsCheckout::fromConfig()->scheduleReviewScript())->toBe(fakeCheckout().'/schedule-review/schedule_review.py');
});
```

`tests/Support/FakeTool.php` gains a static the test can set, next to `$throw`:

```php
    /** When set, the fake's result carries this run date (Y-m-d). */
    public static ?string $runDate = null;
```

and its `run()` passes it: `runDate: self::$runDate,` after `summary:`. Reset it in the test file's `afterEach` (add `FakeTool::$runDate = null;` beside the existing `FakeTool::$throw = null;` reset, or add an `afterEach` if there is none).

Append to `tests/Feature/RunToolJobTest.php` (the file's `beforeEach` already registers the FakeTool for `LoadAudit`, fakes the `r2` disk and makes `$this->dataset` and `$this->load` whose summary says `export_date` 2026-10-10; `runFor()` makes a queued run):

```php
it('uses the run date the tool reports over the export date', function () {
    FakeTool::$runDate = '2026-10-04';
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->fresh()->run_date->toDateString())->toBe('2026-10-04');
});
```

- [ ] **Step 3: Run them to see them fail**

```bash
php artisan test --compact --filter='DatasetSettingsTest|ProgressLineTest|PsoToolsCheckoutTest|RunToolJobTest'
```
Expected: FAIL on the new cases (unknown key `customer_activity_types`, missing stage labels, missing method, unknown named argument `runDate`).

- [ ] **Step 4: Implement**

`app/Support/DatasetSettings.php`:
- Add to `KEYS`: `'customer_activity_types', 'ignore_skills', 'week_start'`.
- Constructor gains `public array $customerActivityTypes = [], public array $ignoreSkills = [], public ?string $weekStart = null,` (after `$timeZone`).
- In `fromArray()`, after the time zone check:

```php
        $weekStart = self::stringOrNull($values['week_start'] ?? null);
        if ($weekStart !== null) {
            $weekStart = strtolower($weekStart);
            if (! in_array($weekStart, ['monday', 'sunday'], true)) {
                throw new InvalidArgumentException("week_start must be monday or sunday, got {$weekStart}.");
            }
        }
```

and pass `customerActivityTypes: $list($values['customer_activity_types'] ?? []), ignoreSkills: $list($values['ignore_skills'] ?? []), weekStart: $weekStart,` to the constructor.
- `toArray()` gains `'customer_activity_types' => $this->customerActivityTypes, 'ignore_skills' => $this->ignoreSkills, 'week_start' => $this->weekStart,`.
- `toAccountFile()` needs no change (no aliases; nulls and empty lists already dropped).

`app/Models/Dataset.php`, inside the Settings section schema after the `exclude_resource_types` TagsInput:

```php
                    Forms\Components\TagsInput::make('settings.customer_activity_types')
                        ->label('Customer job types')
                        ->helperText('Activity types that count as customer work in the schedule review. Blank: worked out from the data.')
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('settings.ignore_skills')
                        ->label('Skills to ignore')
                        ->helperText('Skills the schedule review leaves out of its skill checks.')
                        ->columnSpanFull(),
                    Forms\Components\Select::make('settings.week_start')
                        ->label('Week starts on')
                        ->options(['monday' => 'Monday', 'sunday' => 'Sunday'])
                        ->placeholder('Monday'),
```

`app/Tools/Contracts/ToolResult.php`: add a fourth constructor property with its docblock line:

```php
     * @param  ?string  $runDate  the day the run is about (Y-m-d) when the tool knows it better than the export date
...
        public ?string $runDate = null,
```

`app/Jobs/RunToolJob.php`: change the `run_date` line to
`'run_date' => $output->runDate ?? $run->run_date ?? $this->exportDateOf($files),`.

`app/Tools/LoadAudit/ProgressLine.php` `LABELS` gains:

```php
        'eod' => 'Reading the end-of-day export',
        'audits' => 'Reading the appointment audits',
        'engine' => 'Calculating the views',
        'write' => 'Writing the dashboard',
```

`app/Tools/LoadAudit/PsoToolsCheckout.php`:

```php
    public function scheduleReviewScript(): string
    {
        return $this->file('schedule-review/schedule_review.py');
    }
```

`config/tools.php`:

```php
    'schedule_review' => [
        // The review reads three exports and calculates the views; the SaskTel Sunday set takes under 10 s.
        'timeout_seconds' => (int) env('TOOLS_SCHEDULE_REVIEW_TIMEOUT_SECONDS', 600),
    ],
```

Create `tests/Fixtures/pso-tools/schedule-review/schedule_review.py`:

```python
"""Stand-in for pso-tools/schedule-review/schedule_review.py in tests. Same arguments, same output layout
(<out-root>/<ACCOUNT>/<DATASET>/schedule_review_<day>.html, schedule_review_data_<day>.json and
history/history.json, history/dur_history.json), no engine. Reads any history it is given and appends today.

FAKE_SR_MODE: ok (default) | fail | nopage | hang.
FAKE_SR_ARGS_FILE: where to write the received argv, cwd, env, the account file and the history it found (optional).
"""
import json, os, sys, time


def arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None


mode = os.environ.get('FAKE_SR_MODE', 'ok')
account = (arg('--account') or 'SATE').upper()
dataset = 'NORTH'
day = os.environ.get('FAKE_SR_DAY', '2026-10-04')

settings = {}
sp = os.path.join(arg('--accounts-dir') or '.', account + '.json')
if os.path.exists(sp):
    with open(sp, encoding='utf-8') as fh:
        settings = json.load(fh)

out_dir = os.path.join(arg('--out-root'), account, dataset)
hist_dir = os.path.join(out_dir, 'history')
hist_p, dur_p = os.path.join(hist_dir, 'history.json'), os.path.join(hist_dir, 'dur_history.json')
HIST = json.load(open(hist_p)) if os.path.exists(hist_p) else {}
DURH = json.load(open(dur_p)) if os.path.exists(dur_p) else []

if os.environ.get('FAKE_SR_ARGS_FILE'):
    with open(os.environ['FAKE_SR_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS'),
                   'settings': settings, 'history_in': HIST, 'dur_history_in': DURH}, fh)

if mode == 'hang':
    time.sleep(30)

lines = os.environ.get('PSO_PROGRESS') == 'lines'
def progress(rec):
    if lines:
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec)); sys.stderr.flush()

print('  PSO schedule review', flush=True)
for stage in ('load', 'plan', 'eod'):
    progress({'stage': stage, 'percent': 0}); progress({'stage': stage, 'percent': 100})
if arg('--audits'):
    progress({'stage': 'audits', 'percent': 0}); progress({'stage': 'audits', 'percent': 100})
progress({'stage': 'engine'})

if mode == 'fail':
    sys.stderr.write('Traceback (most recent call last):\n  File "schedule_review.py", line 1\n')
    sys.stderr.write('SystemExit: The morning plan file has no Plan record. Export the plan output that followed the morning load.\n')
    sys.exit(1)

print('WARNING: The end-of-day file is not from %s local time.' % day)
if mode == 'nopage':
    sys.exit(0)

progress({'stage': 'write'})
os.makedirs(hist_dir, exist_ok=True)
today = {'date': day, 'booked': 7620, 'completed_pct': 91.2, 'incomplete_pct': 4.1, 'ontime_pct': 84.0, 'orphaned': 3,
         'cancelled': 120, 'unplanned': 44, 'investigate': 9, 'reassigned_pct': 6.5, 'util_median': 71.0, 'idle_h': 12.5,
         'late_logons': 4, 'overtime_h': 3.2, 'inpriv_h': 1.1}
HIST['all'] = [h for h in HIST.get('all', []) if h['date'] != day] + [today]
HIST['d:1 - Regina'] = [h for h in HIST.get('d:1 - Regina', []) if h['date'] != day] + [dict(today, booked=1200)]
DURH = [x for x in DURH if x['date'] != day] + [{'date': day, 'task': 'INSTALL', 'single': True, 'est': 60, 'act': 75, 'district': '1 - Regina', 'tech': 'T1'}]
data = {'meta': {'org': settings.get('display_name', account), 'account': account, 'dataset': dataset, 'day_iso': day, 'ref_tz': arg('--time-zone')},
        'scope_list': [{'key': 'all', 'label': 'All of ' + settings.get('display_name', account), 'group': ''},
                       {'key': 'd:1 - Regina', 'label': 'Regina', 'group': 'District'}],
        'scopes': {}, 'shared': {'timelines': {}, 'techmeta': {}}, 'appointments': None}
with open(os.path.join(out_dir, 'schedule_review_data_%s.json' % day), 'w') as fh:
    json.dump(data, fh)
with open(os.path.join(out_dir, 'schedule_review_%s.html' % day), 'w') as fh:
    fh.write('<!doctype html><html><body><h1>%s %s</h1><script>const DATA=%s</script></body></html>' % (data['meta']['org'], day, json.dumps(data)))
with open(hist_p, 'w') as fh:
    json.dump(HIST, fh)
with open(dur_p, 'w') as fh:
    json.dump(DURH, fh)
print('Done in 0s. %d views.' % len(data['scope_list']))
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact --filter='DatasetSettingsTest|ProgressLineTest|PsoToolsCheckoutTest|RunToolJobTest|DatasetSettingsSeed|SeedDatasetSettings'
```
Expected: PASS. (The seed command needs no change: it goes through `fromAccountFile()`.)

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/DatasetSettings.php app/Models/Dataset.php app/Tools/Contracts/ToolResult.php app/Jobs/RunToolJob.php app/Tools/LoadAudit/ProgressLine.php app/Tools/LoadAudit/PsoToolsCheckout.php config/tools.php tests/Fixtures/pso-tools/schedule-review tests/Unit tests/Feature/LoadAudit tests/Feature/RunToolJobTest.php
git commit -m "Tools: schedule review settings keys, run date on the result, progress stages, fake review script"
```

---

### Task 4: `tool_states` table, model and `ScheduleReviewHistory`

**Files:**
- Create: migration `database/migrations/2026_10_12_000001_create_tool_states_table.php`, `app/Models/ToolState.php`, `database/factories/ToolStateFactory.php`, `app/Tools/ScheduleReview/ScheduleReviewHistory.php`
- Test: `tests/Feature/ToolStateTest.php`, `tests/Feature/ScheduleReview/ScheduleReviewHistoryTest.php`

**Interfaces:**
- Produces: `ToolState` model (`scope` morph, `tool` enum, `key`, `state` encrypted array), scoped by `ScopeOwnedModel` like `ToolRun`; `ScheduleReviewHistory::DIR = 'history'`, `::FILES = ['history', 'dur_history']`; `ScheduleReviewHistory::seed(ToolContext $context, string $workDir, string $account, string $dataset): int` (files written); `ScheduleReviewHistory::store(ToolContext $context, string $historyDir): void`; `ScheduleReviewHistory::folder(string $workDir, string $account, string $dataset): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ToolStateTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolState;
use App\Models\User;

it('stores one encrypted blob per scope, tool and key and hides other users rows', function () {
    $user = User::factory()->create();
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create();
    $this->actingAs($user);

    ToolState::query()->updateOrCreate(
        ['scope_type' => $dataset->getMorphClass(), 'scope_id' => $dataset->getKey(), 'tool' => ToolId::ScheduleReview, 'key' => 'history'],
        ['state' => ['all' => [['date' => '2026-10-04', 'booked' => 1]]]],
    );
    ToolState::query()->updateOrCreate(
        ['scope_type' => $dataset->getMorphClass(), 'scope_id' => $dataset->getKey(), 'tool' => ToolId::ScheduleReview, 'key' => 'history'],
        ['state' => ['all' => [['date' => '2026-10-05', 'booked' => 2]]]],
    );

    expect(ToolState::count())->toBe(1)
        ->and(ToolState::first()->state['all'][0]['date'])->toBe('2026-10-05')
        ->and(DB::table('tool_states')->value('state'))->not->toContain('2026-10-05');

    $this->actingAs(User::factory()->create());
    expect(ToolState::count())->toBe(0)
        ->and(ToolState::withoutGlobalScopes()->count())->toBe(1);
});
```

(add `use Illuminate\Support\Facades\DB;`.)

`tests/Feature/ScheduleReview/ScheduleReviewHistoryTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Models\ToolState;
use App\Tools\ScheduleReview\ScheduleReviewHistory;
use App\Tools\ToolContext;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/srh-'.uniqid();
    mkdir($this->work);
    $this->dataset = Dataset::factory()->create();
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('writes nothing on a first run', function () {
    expect(ScheduleReviewHistory::seed($this->context, $this->work, 'SATE', 'NORTH'))->toBe(0)
        ->and(is_dir($this->work.'/out/SATE/NORTH/history'))->toBeFalse();
});

it('seeds both stored blobs into the folder the script reads', function () {
    foreach (['history' => ['all' => [['date' => '2026-10-03', 'booked' => 5]]], 'dur_history' => [['date' => '2026-10-03', 'task' => 'X']]] as $key => $state) {
        ToolState::withoutGlobalScopes()->create(['scope_type' => $this->dataset->getMorphClass(), 'scope_id' => $this->dataset->getKey(), 'tool' => ToolId::ScheduleReview, 'key' => $key, 'state' => $state]);
    }

    expect(ScheduleReviewHistory::seed($this->context, $this->work, 'SATE', 'NORTH'))->toBe(2);
    $folder = ScheduleReviewHistory::folder($this->work, 'SATE', 'NORTH');
    expect($folder)->toBe($this->work.'/out/SATE/NORTH/history')
        ->and(json_decode(file_get_contents($folder.'/history.json'), true))->toBe(['all' => [['date' => '2026-10-03', 'booked' => 5]]])
        ->and(json_decode(file_get_contents($folder.'/dur_history.json'), true))->toBe([['date' => '2026-10-03', 'task' => 'X']]);
});

it('stores what the script wrote, replacing the previous blobs', function () {
    $folder = $this->work.'/out/SATE/NORTH/history';
    mkdir($folder, 0770, true);
    file_put_contents($folder.'/history.json', json_encode(['all' => [['date' => '2026-10-04', 'booked' => 7]]]));
    file_put_contents($folder.'/dur_history.json', json_encode([['date' => '2026-10-04', 'task' => 'Y']]));

    ScheduleReviewHistory::store($this->context, $folder);
    ScheduleReviewHistory::store($this->context, $folder);

    $rows = ToolState::withoutGlobalScopes()->where('scope_id', $this->dataset->getKey())->get()->keyBy('key');
    expect($rows)->toHaveCount(2)
        ->and($rows['history']->state)->toBe(['all' => [['date' => '2026-10-04', 'booked' => 7]]])
        ->and($rows['dur_history']->state)->toBe([['date' => '2026-10-04', 'task' => 'Y']]);
});

it('ignores a missing or unreadable file when storing', function () {
    $folder = $this->work.'/out/SATE/NORTH/history';
    mkdir($folder, 0770, true);
    file_put_contents($folder.'/history.json', '{not json');

    ScheduleReviewHistory::store($this->context, $folder);

    expect(ToolState::withoutGlobalScopes()->count())->toBe(0);
});
```

- [ ] **Step 2: Run them to see them fail**

```bash
php artisan test --compact --filter='ToolStateTest|ScheduleReviewHistoryTest'
```
Expected: FAIL, class `ToolState` not found.

- [ ] **Step 3: Implement**

Migration `database/migrations/2026_10_12_000001_create_tool_states_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** State a tool keeps between runs on one scope (the schedule review's 60-day history). Encrypted: it carries technician ids. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_states', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('scope');
            $table->string('tool', 32);
            $table->string('key', 32);
            $table->longText('state');   // encrypted JSON, so longText not json
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'tool', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_states');
    }
};
```

`app/Models/ToolState.php`:

```php
<?php

namespace App\Models;

use App\Enums\Tool;
use App\Models\Scopes\ScopeOwnedModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One blob a tool keeps between runs on a scope, by key (the schedule review keeps `history` and `dur_history`). */
class ToolState extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['scope_type', 'scope_id', 'tool', 'key', 'state'];

    protected function casts(): array
    {
        return [
            'tool' => Tool::class,
            'state' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeOwnedModel);
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }
}
```

`database/factories/ToolStateFactory.php` (copy `ToolRunFactory`'s `configure()` that fills a dataset scope when none is given):

```php
<?php

namespace Database\Factories;

use App\Enums\Tool;
use App\Models\Dataset;
use App\Models\ToolState;
use Illuminate\Database\Eloquent\Factories\Factory;

class ToolStateFactory extends Factory
{
    protected $model = ToolState::class;

    public function definition(): array
    {
        return ['tool' => Tool::ScheduleReview, 'key' => 'history', 'state' => []];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (ToolState $state): void {
            if ($state->scope_type === null || $state->scope_id === null) {
                $dataset = Dataset::factory()->create();
                $state->scope_type = $dataset->getMorphClass();
                $state->scope_id = $dataset->getKey();
            }
        });
    }
}
```

`app/Tools/ScheduleReview/ScheduleReviewHistory.php`:

```php
<?php

namespace App\Tools\ScheduleReview;

use App\Enums\Tool as ToolId;
use App\Models\ToolState;
use App\Tools\ToolContext;
use Illuminate\Support\Facades\Log;

/**
 * The schedule review's state between runs: history.json (headline rows per view, for the trend lines) and
 * dur_history.json (duration visits), both kept 60 days by the script. Each web run gets an empty scratch
 * folder, so the dataset's copy is written where the script looks before the run and read back after.
 * The job runs with nobody logged in, so every query here skips the user-owned scope.
 */
class ScheduleReviewHistory
{
    public const string DIR = 'history';

    public const array FILES = ['history', 'dur_history'];

    /** Where the script reads and writes its history for this account and dataset: <workDir>/out/<ACCOUNT>/<DATASET>/history. */
    public static function folder(string $workDir, string $account, string $dataset): string
    {
        return rtrim($workDir, '/').'/'.ScheduleReviewCommand::OUT_DIR.'/'.$account.'/'.$dataset.'/'.self::DIR;
    }

    /** Writes the stored blobs into the folder; returns how many files were written (0 on a first run). */
    public static function seed(ToolContext $context, string $workDir, string $account, string $dataset): int
    {
        $rows = self::query($context)->get()->keyBy('key');
        if ($rows->isEmpty()) {
            return 0;
        }

        $folder = self::folder($workDir, $account, $dataset);
        if (! is_dir($folder)) {
            mkdir($folder, 0770, true);
        }

        $written = 0;
        foreach (self::FILES as $key) {
            if ($rows->has($key)) {
                file_put_contents("{$folder}/{$key}.json", json_encode($rows[$key]->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $written++;
            }
        }

        return $written;
    }

    /** Reads the files the script wrote in $historyDir and replaces the stored blobs. A missing or unreadable file is skipped. */
    public static function store(ToolContext $context, string $historyDir): void
    {
        foreach (self::FILES as $key) {
            $json = @file_get_contents(rtrim($historyDir, '/')."/{$key}.json");
            $state = $json === false ? null : json_decode($json, true);
            if (! is_array($state)) {
                Log::warning('Schedule review history file missing or unreadable', ['tool_run_id' => $context->run->id, 'key' => $key]);

                continue;
            }

            ToolState::withoutGlobalScopes()->updateOrCreate(
                ['scope_type' => $context->scope->getMorphClass(), 'scope_id' => $context->scope->getKey(), 'tool' => ToolId::ScheduleReview, 'key' => $key],
                ['state' => $state],
            );
        }
    }

    private static function query(ToolContext $context)
    {
        return ToolState::withoutGlobalScopes()
            ->where('scope_type', $context->scope->getMorphClass())
            ->where('scope_id', $context->scope->getKey())
            ->where('tool', ToolId::ScheduleReview);
    }
}
```

`ScheduleReviewCommand::OUT_DIR` is created in Task 5; for this task to compile, create `app/Tools/ScheduleReview/ScheduleReviewCommand.php` now with only the constant (`public const string OUT_DIR = 'out';`) and the class docblock; Task 5 fills it in.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact --filter='ToolStateTest|ScheduleReviewHistoryTest'
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_10_12_000001_create_tool_states_table.php app/Models/ToolState.php database/factories/ToolStateFactory.php app/Tools/ScheduleReview tests/Feature/ToolStateTest.php tests/Feature/ScheduleReview/ScheduleReviewHistoryTest.php
git commit -m "Tool states: encrypted per-scope blobs; schedule review history seed and store"
```

---

### Task 5: The command

**Files:**
- Modify: `app/Tools/ScheduleReview/ScheduleReviewCommand.php` (created with the constant in Task 4)
- Test: `tests/Unit/ScheduleReview/ScheduleReviewCommandTest.php`

**Interfaces:**
- Consumes: `PsoToolsCheckout::python()`, `::scheduleReviewScript()` (Task 3); `AccountSettingsFile::DIR`.
- Produces: `ScheduleReviewCommand::build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, string $timeZone, string $account): list<string>`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/ScheduleReview/ScheduleReviewCommandTest.php`:

```php
<?php

use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScheduleReview\ScheduleReviewCommand;

function srCheckout(): PsoToolsCheckout
{
    return new PsoToolsCheckout(base_path('tests/Fixtures/pso-tools'), 'python3');
}

it('builds the command for the three exports', function () {
    $argv = ScheduleReviewCommand::build(srCheckout(), '/scratch/run-1/', ['load' => '/scratch/run-1/load.zip', 'plan' => '/scratch/run-1/plan.zip', 'eod' => '/scratch/run-1/eod.xml'], 'America/Regina', 'SATE');

    expect($argv)->toBe([
        'python3', '-I', base_path('tests/Fixtures/pso-tools').'/schedule-review/schedule_review.py',
        '--load', '/scratch/run-1/load.zip',
        '--plan', '/scratch/run-1/plan.zip',
        '--eod', '/scratch/run-1/eod.xml',
        '--account', 'SATE',
        '--time-zone', 'America/Regina',
        '--accounts-dir', '/scratch/run-1/accounts',
        '--out-root', '/scratch/run-1/out',
    ]);
});

it('adds the audits zip after the eod when the run has one', function () {
    $argv = ScheduleReviewCommand::build(srCheckout(), '/scratch/run-1', ['load' => '/l', 'plan' => '/p', 'eod' => '/e', 'audits' => '/scratch/run-1/audits.zip'], 'America/Regina', 'SATE');

    expect(array_slice($argv, 9, 2))->toBe(['--audits', '/scratch/run-1/audits.zip'])
        ->and($argv)->toContain('--out-root', '/scratch/run-1/out');
});
```

- [ ] **Step 2: Run it to see it fail**

```bash
php artisan test --compact --filter=ScheduleReviewCommandTest
```
Expected: FAIL, `build` undefined.

- [ ] **Step 3: Implement**

`app/Tools/ScheduleReview/ScheduleReviewCommand.php`:

```php
<?php

namespace App\Tools\ScheduleReview;

use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\AccountSettingsFile;

/**
 * The argument list for schedule_review.py. Pure: no process, no filesystem. --account names the account file
 * AccountSettingsFile wrote; --time-zone is the run's zone and beats the account file, as on the command line.
 * No --template (the checkout's) and no --org-label (the account file's display_name and dataset_name title the page).
 */
class ScheduleReviewCommand
{
    public const string OUT_DIR = 'out';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path: load, plan, eod and optionally audits
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, string $timeZone, string $account): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [
            $checkout->python(), '-I', $checkout->scheduleReviewScript(),
            '--load', $inputPaths['load'],
            '--plan', $inputPaths['plan'],
            '--eod', $inputPaths['eod'],
        ];

        if (filled($inputPaths['audits'] ?? null)) {
            array_push($argv, '--audits', $inputPaths['audits']);
        }

        array_push($argv,
            '--account', $account,
            '--time-zone', $timeZone,
            '--accounts-dir', $workDir.'/'.AccountSettingsFile::DIR,
            '--out-root', $workDir.'/'.self::OUT_DIR,
        );

        return $argv;
    }
}
```

- [ ] **Step 4: Run the test, commit**

```bash
php artisan test --compact --filter=ScheduleReviewCommandTest
vendor/bin/pint --dirty
git add app/Tools/ScheduleReview/ScheduleReviewCommand.php tests/Unit/ScheduleReview/ScheduleReviewCommandTest.php
git commit -m "Schedule review: command builder"
```

---

### Task 6: `ScheduleReviewTool`

**Files:**
- Create: `app/Tools/ScheduleReview/ScheduleReviewTool.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ScheduleReview/ScheduleReviewToolTest.php`

**Interfaces:**
- Consumes: `ScriptRun::execute()`, `AccountSettingsFile`, `ScheduleReviewCommand`, `ScheduleReviewHistory`, `ToolResult::$runDate` (Task 3), `ExportFile` summary `dataset_id`.
- Produces: `ScheduleReviewTool` registered for `Tool::ScheduleReview`; result `html`, `data`; summary `day`, `counts {booked, completed_pct, ontime_pct, views}`, `line`; `runDate` = `meta.day_iso`; implements `RefusesConcurrentRuns` (Task 7 adds the interface; this task creates it as an empty marker so the class compiles, Task 7 fills in its method).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ScheduleReview/ScheduleReviewToolTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\ToolState;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\ScheduleReview\ScheduleReviewTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;

/** Symfony Process only forwards variables from $_SERVER/$_ENV, not ones set with putenv(), so set them there. */
function fakeReviewEnv(string $name, ?string $value): void
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
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.schedule_review.timeout_seconds' => 600]);
    fakeReviewEnv('FAKE_SR_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/sr-'.uniqid();
    mkdir($this->scratch);
    foreach (['load', 'plan', 'eod'] as $kind) {
        file_put_contents("{$this->scratch}/{$kind}.xml", '<dsScheduleData/>');
    }
    $this->paths = ['load' => "{$this->scratch}/load.xml", 'plan' => "{$this->scratch}/plan.xml", 'eod' => "{$this->scratch}/eod.xml"];
    $this->dataset = Dataset::factory()
        ->for(Environment::factory()->state(['account_id' => 'sate']))
        ->create(['settings' => ['display_name' => 'SaskTel', 'time_zone' => 'America/Regina', 'customer_activity_types' => ['INSTALL']]]);
    $this->load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-04', 'dataset_id' => 'NORTH']]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'result' => null, 'progress' => null, 'run_date' => null, 'inputs' => ['files' => ['load' => $this->load->id], 'options' => []]]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    if (isset($this->scratch) && is_dir($this->scratch)) {
        File::deleteDirectory($this->scratch);
    }
    foreach (['FAKE_SR_MODE', 'FAKE_SR_ARGS_FILE', 'FAKE_SR_DAY'] as $name) {
        fakeReviewEnv($name, null);
    }
});

function storedHistory(Dataset $dataset): array
{
    return ToolState::withoutGlobalScopes()->where('scope_id', $dataset->getKey())->get()->keyBy('key')->map->state->all();
}

it('is registered for the schedule review and needs load, plan and eod', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::ScheduleReview);

    expect($tool)->toBeInstanceOf(ScheduleReviewTool::class)
        ->and($tool->requiredKinds())->toBe([ExportKind::Load, ExportKind::Plan, ExportKind::Eod])
        ->and($tool->optionalKinds())->toBe([ExportKind::Audits])
        ->and($tool->optionFields())->toBe([]);
});

it('runs the script with the account file, stores page, data, summary, warnings, the run date and two history rows', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeReviewEnv('FAKE_SR_ARGS_FILE', $argsFile);

    $result = (new ScheduleReviewTool)->run($this->context, $this->paths, []);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['cwd'])->toBe(realpath($this->scratch))
        ->and($args['progress_env'])->toBe('lines')
        ->and($args['argv'])->toContain('--account', 'SATE', '--time-zone', 'America/Regina')
        ->and($args['argv'])->not->toContain('--audits')
        ->and($args['settings']['customer_activity_types'])->toBe(['INSTALL'])
        ->and($args['history_in'])->toBe([])
        ->and($result->result['html'])->toContain('SaskTel 2026-10-04')
        ->and($result->result['data']['meta']['day_iso'])->toBe('2026-10-04')
        ->and($result->runDate)->toBe('2026-10-04')
        ->and($result->summary)->toBe([
            'day' => '2026-10-04',
            'counts' => ['booked' => 7620, 'completed_pct' => 91.2, 'ontime_pct' => 84.0, 'views' => 2],
            'line' => '7,620 jobs, 91% completed, 84% on time, 2 views',
        ])
        ->and($result->warnings)->toBe(['The end-of-day file is not from 2026-10-04 local time.']);

    $stored = storedHistory($this->dataset);
    expect(array_keys($stored))->toEqualCanonicalizing(['history', 'dur_history'])
        ->and($stored['history']['all'])->toHaveCount(1)
        ->and($stored['dur_history'][0]['tech'])->toBe('T1');
});

it('seeds the stored history into the next run and the script carries it forward', function () {
    (new ScheduleReviewTool)->run($this->context, $this->paths, []);

    fakeReviewEnv('FAKE_SR_DAY', '2026-10-05');
    $argsFile = $this->scratch.'/args.json';
    fakeReviewEnv('FAKE_SR_ARGS_FILE', $argsFile);
    (new ScheduleReviewTool)->run($this->context, $this->paths, []);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['history_in']['all'][0]['date'])->toBe('2026-10-04');
    expect(array_column(storedHistory($this->dataset)['history']['all'], 'date'))->toBe(['2026-10-04', '2026-10-05']);
});

it('passes the audits zip when the run has one', function () {
    file_put_contents("{$this->scratch}/audits.zip", 'x');
    $argsFile = $this->scratch.'/args.json';
    fakeReviewEnv('FAKE_SR_ARGS_FILE', $argsFile);

    (new ScheduleReviewTool)->run($this->context, [...$this->paths, 'audits' => "{$this->scratch}/audits.zip"], []);

    expect(json_decode(file_get_contents($argsFile), true)['argv'])->toContain('--audits', "{$this->scratch}/audits.zip");
});

it('leaves the stored history alone when the script fails', function () {
    (new ScheduleReviewTool)->run($this->context, $this->paths, []);
    $before = storedHistory($this->dataset);
    fakeReviewEnv('FAKE_SR_MODE', 'fail');

    expect(fn () => (new ScheduleReviewTool)->run($this->context, $this->paths, []))
        ->toThrow(RuntimeException::class, 'The review stopped while calculating the views: SystemExit: The morning plan file has no Plan record. Export the plan output that followed the morning load.');
    expect(storedHistory($this->dataset))->toBe($before);
});

it('fails when the script exits cleanly but wrote no page, leaving history alone', function () {
    fakeReviewEnv('FAKE_SR_MODE', 'nopage');

    expect(fn () => (new ScheduleReviewTool)->run($this->context, $this->paths, []))
        ->toThrow(RuntimeException::class, 'The review finished but wrote no page.');
    expect(ToolState::withoutGlobalScopes()->count())->toBe(0);
});

it('skips the seed with a warning when the LOAD summary has no dataset id', function () {
    ToolState::withoutGlobalScopes()->create(['scope_type' => $this->dataset->getMorphClass(), 'scope_id' => $this->dataset->getKey(), 'tool' => ToolId::ScheduleReview, 'key' => 'history', 'state' => ['all' => [['date' => '2026-10-03']]]]);
    $this->load->update(['summary' => ['export_date' => '2026-10-04']]);
    $argsFile = $this->scratch.'/args.json';
    fakeReviewEnv('FAKE_SR_ARGS_FILE', $argsFile);

    $result = (new ScheduleReviewTool)->run($this->context, $this->paths, []);

    expect(json_decode(file_get_contents($argsFile), true)['history_in'])->toBe([])
        ->and($result->warnings)->toContain('The LOAD upload does not say which dataset it is for, so the stored history was not used; trend lines start today.');
});

it('leaves the last progress label at the write stage', function () {
    (new ScheduleReviewTool)->run($this->context, $this->paths, []);

    expect($this->run->fresh()->progress['label'])->toBe('Writing the dashboard');
});

it('stops a script that runs too long', function () {
    config(['tools.schedule_review.timeout_seconds' => 1]);
    fakeReviewEnv('FAKE_SR_MODE', 'hang');

    expect(fn () => (new ScheduleReviewTool)->run($this->context, $this->paths, []))
        ->toThrow(RuntimeException::class, 'The review took longer than 0 minutes and was stopped.');
});

it('fails plainly when the server is not set up', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => (new ScheduleReviewTool)->run($this->context, $this->paths, []))
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});
```

- [ ] **Step 2: Run them to see them fail**

```bash
php artisan test --compact --filter=ScheduleReviewToolTest
```
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

Create the marker `app/Tools/Contracts/RefusesConcurrentRuns.php` (Task 7 wires it up):

```php
<?php

namespace App\Tools\Contracts;

/** A tool whose runs on one scope must not overlap (it keeps state between runs). The Run action refuses a second one. */
interface RefusesConcurrentRuns
{
    /** The notification shown when a run is refused. */
    public function concurrentRunMessage(): string;
}
```

`app/Tools/ScheduleReview/ScheduleReviewTool.php`:

```php
<?php

namespace App\Tools\ScheduleReview;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Tools\Contracts\RefusesConcurrentRuns;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\AccountSettingsFile;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Runs pso-tools/schedule-review/schedule_review.py (the reference implementation, never ported) against the
 * run's LOAD, plan, end-of-day export and optional audits, with the dataset's settings handed over as the
 * account file and the dataset's stored 60-day history seeded into the scratch folder, and keeps the page,
 * the data JSON and the history the script wrote back.
 */
class ScheduleReviewTool implements RefusesConcurrentRuns, Tool
{
    public const string NO_DATASET_ID_WARNING = 'The LOAD upload does not say which dataset it is for, so the stored history was not used; trend lines start today.';

    public static function id(): ToolId
    {
        return ToolId::ScheduleReview;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Audits];
    }

    public function optionFields(): array
    {
        return [];
    }

    public function concurrentRunMessage(): string
    {
        return 'A schedule review is already running on this dataset. Wait for it to finish, then run again.';
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['load']);
        // The job runs with nobody logged in, so the user-owned scope would hide the environment from the relation.
        $accountId = $context->scope instanceof Dataset
            ? Environment::query()->withoutGlobalScopes()->find($context->scope->environment_id)?->account_id
            : null;
        $account = AccountSettingsFile::accountName($accountId);
        AccountSettingsFile::write($context->settings, $workDir.'/'.AccountSettingsFile::DIR, $account);

        $warnings = [];
        $datasetFolder = $this->datasetFolder($context);
        if ($datasetFolder === null) {
            $warnings[] = self::NO_DATASET_ID_WARNING;
        } else {
            ScheduleReviewHistory::seed($context, $workDir, $account, $datasetFolder);
        }

        $argv = ScheduleReviewCommand::build($checkout, $workDir, $inputPaths, $context->timeZone, $account);
        $timeout = (int) config('tools.schedule_review.timeout_seconds', 600);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The review');

        $pages = glob($workDir.'/'.ScheduleReviewCommand::OUT_DIR.'/*/*/schedule_review_????-??-??.html') ?: [];
        if (count($pages) !== 1) {
            throw new RuntimeException('The review finished but wrote no page.');
        }
        $page = $pages[0];
        $day = substr(basename($page, '.html'), strlen('schedule_review_'));
        $html = file_get_contents($page);
        $json = @file_get_contents(dirname($page)."/schedule_review_data_{$day}.json");
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || $html === false || $html === '') {
            throw new RuntimeException('The review finished but wrote no page.');
        }

        $historyDir = dirname($page).'/'.ScheduleReviewHistory::DIR;
        ScheduleReviewHistory::store($context, $historyDir);

        $today = $this->todayRow($historyDir, $day);
        $counts = [
            'booked' => (int) ($today['booked'] ?? 0),
            'completed_pct' => isset($today['completed_pct']) && is_numeric($today['completed_pct']) ? (float) $today['completed_pct'] : null,
            'ontime_pct' => isset($today['ontime_pct']) && is_numeric($today['ontime_pct']) ? (float) $today['ontime_pct'] : null,
            'views' => is_array($data['scope_list'] ?? null) ? count($data['scope_list']) : 0,
        ];

        foreach (preg_split('/\r?\n/', $script->stdout) as $line) {
            if (str_starts_with($line, 'WARNING: ')) {
                $warnings[] = trim(substr($line, strlen('WARNING: ')));
            }
        }

        return new ToolResult(
            result: ['html' => $html, 'data' => $data],
            warnings: $warnings,
            summary: ['day' => $day, 'counts' => $counts, 'line' => self::summaryLine($counts)],
            runDate: $day,
        );
    }

    /** @param array{booked: int, completed_pct: ?float, ontime_pct: ?float, views: int} $counts */
    public static function summaryLine(array $counts): string
    {
        $pct = static fn (?float $value): string => $value === null ? 'n/a' : round($value).'%';

        return sprintf('%s jobs, %s completed, %s on time, %d views', number_format($counts['booked']), $pct($counts['completed_pct']), $pct($counts['ontime_pct']), $counts['views']);
    }

    /** The script's folder name for the dataset (its safe() of the LOAD's dataset_id), from the LOAD upload's summary; null when unknown. */
    private function datasetFolder(ToolContext $context): ?string
    {
        $loadId = $context->run->inputs['files'][ExportKind::Load->value] ?? null;
        $datasetId = $loadId ? ExportFile::withoutGlobalScopes()->find($loadId)?->summary['dataset_id'] ?? null : null;
        if (! is_string($datasetId) || trim($datasetId) === '') {
            return null;
        }

        return preg_replace('/[^A-Za-z0-9_-]/', '_', $datasetId);
    }

    /** The whole-dataset ("all") history row the script wrote for the reviewed day; [] when missing. */
    private function todayRow(string $historyDir, string $day): array
    {
        $json = @file_get_contents("{$historyDir}/history.json");
        $history = $json === false ? null : json_decode($json, true);
        foreach ((array) ($history['all'] ?? []) as $row) {
            if (is_array($row) && ($row['date'] ?? null) === $day) {
                return $row;
            }
        }

        return [];
    }
}
```

`app/Providers/AppServiceProvider.php`: add `use App\Tools\ScheduleReview\ScheduleReviewTool;` and
`$registry->register(ToolId::ScheduleReview, ScheduleReviewTool::class);` after the region availability line.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact --filter='ScheduleReviewToolTest|RunsTabTest|LoadAuditPagesTest'
```
Expected: PASS. If a Runs tab test counts header actions, update its expectation for the third Run action.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/ScheduleReview/ScheduleReviewTool.php app/Tools/Contracts/RefusesConcurrentRuns.php app/Providers/AppServiceProvider.php tests/Feature/ScheduleReview/ScheduleReviewToolTest.php
git commit -m "Schedule review: tool on the export-sets contract with seeded and stored history"
```

---

### Task 7: Refuse a concurrent run; run page view; file name by day

**Files:**
- Modify: `app/Filament/RelationManagers/ToolRunsRelationManager.php` (the `->action()` of each Run action), `app/Http/Controllers/ToolRunPageController.php` (`fileName()`)
- Create: `resources/views/filament/tool-runs/schedule_review.blade.php`
- Test: `tests/Feature/ScheduleReview/ScheduleReviewPagesTest.php`

**Interfaces:**
- Consumes: `RefusesConcurrentRuns` (Task 6), Filament's `assertNotified()` / `assertNotNotified()` Livewire test helpers, `summary` keys (Task 6), `tool-runs.*` routes.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ScheduleReview/ScheduleReviewPagesTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::ScheduleReview, 'run_date' => '2026-10-04',
        'result' => ['html' => '<!doctype html><html><body>Review page <script>1</script></body></html>', 'data' => ['meta' => ['day_iso' => '2026-10-04'], 'scope_list' => [['key' => 'all']]]],
        'summary' => ['day' => '2026-10-04', 'counts' => ['booked' => 7620, 'completed_pct' => 91.2, 'ontime_pct' => 84.0, 'views' => 4], 'line' => '7,620 jobs, 91% completed, 84% on time, 4 views'],
        'warnings' => ['The end-of-day file was taken after 22:00, when this environment starts removing finished jobs. Outcomes could be incomplete.'],
    ]);
    $this->actingAs($this->user);
});

it('shows the headline badges, the day, the warnings, the download buttons and the sandboxed iframe', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('7,620 jobs')->assertSee('91% completed')->assertSee('84% on time')->assertSee('4 views')
        ->assertSee('Saturday 4 October 2026')
        ->assertSee('removing finished jobs')
        ->assertSee('Download HTML')->assertSee('Download data JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', [$this->dataset, $this->run])));
});

it('serves the page under the sandbox and names downloads by the reviewed day', function () {
    $this->get(route('tool-runs.page', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertSee('Review page', false);

    $this->run->update(['run_date' => '2026-10-09']);   // the day in the summary wins over the run date
    $this->get(route('tool-runs.download-json', [$this->dataset, $this->run]))
        ->assertDownload('schedule_review_SATE_NORTH_2026-10-04.json')
        ->assertJsonPath('meta.day_iso', '2026-10-04');
});

it('shows the summary line on the Runs tab', function () {
    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('7,620 jobs, 91% completed, 84% on time, 4 views');
});

function reviewInputs(Dataset $dataset): array
{
    $files = [];
    foreach (['load' => ExportKind::Load, 'plan' => ExportKind::Plan, 'eod' => ExportKind::Eod] as $key => $kind) {
        $files[$key] = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => $kind, 'summary' => ['export_date' => '2026-10-10', 'dataset_id' => 'NORTH']])->id;
    }

    return $files;
}

it('refuses a second review while one is queued or running on the dataset', function (ToolRunStatus $status) {
    Queue::fake();
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => $status, 'result' => null]);
    $before = ToolRun::count();

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_schedule_review', data: ['files' => reviewInputs($this->dataset)])
        ->assertNotified('A schedule review is already running on this dataset. Wait for it to finish, then run again.');

    expect(ToolRun::count())->toBe($before);
    Queue::assertNothingPushed();
})->with([ToolRunStatus::Queued, ToolRunStatus::Running]);

it('allows a review when the other run finished, is another tool, or is on another dataset', function () {
    Queue::fake();
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => ToolRunStatus::Failed]);
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running]);
    ToolRun::factory()->for(Dataset::factory()->for(Environment::factory()->for($this->user)), 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => ToolRunStatus::Running]);
    $before = ToolRun::count();

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_schedule_review', data: ['files' => reviewInputs($this->dataset)])
        ->assertHasNoTableActionErrors()
        ->assertNotNotified('A schedule review is already running on this dataset. Wait for it to finish, then run again.');

    expect(ToolRun::count())->toBe($before + 1);
    Queue::assertPushed(RunToolJob::class);
});

it('still starts a load audit while a review runs (no marker on that tool)', function () {
    Queue::fake();
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => ToolRunStatus::Running, 'result' => null]);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id]])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(RunToolJob::class);
});
```

- [ ] **Step 2: Run them to see them fail**

```bash
php artisan test --compact --filter=ScheduleReviewPagesTest
```
Expected: the view test fails (generic view, no badges), the refusal test fails (a run is created), the download name test fails (uses the run date).

- [ ] **Step 3: Implement**

`app/Filament/RelationManagers/ToolRunsRelationManager.php`: add `use App\Tools\Contracts\RefusesConcurrentRuns;` and `use Filament\Notifications\Notification;`. In `runActions()`, change the action closure to capture the tool and check first:

```php
                ->action(function (array $data) use ($toolId, $tool): void {
                    if ($tool instanceof RefusesConcurrentRuns && $this->getOwnerRecord()->toolRuns()
                        ->where('tool', $toolId)
                        ->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])
                        ->exists()) {
                        Notification::make()->title($tool->concurrentRunMessage())->warning()->send();

                        return;
                    }

                    $run = $this->getOwnerRecord()->toolRuns()->create([
                        ...unchanged...
```

`app/Http/Controllers/ToolRunPageController.php` `fileName()`: the date becomes
`$date = $run->summary['day'] ?? $run->summary['week'] ?? $run->run_date?->toDateString() ?? 'undated';` and the docblock reads "<tool>_<account>_<dataset>_<reviewed day, week Monday or run date>.<ext>".

`resources/views/filament/tool-runs/schedule_review.blade.php`:

```blade
@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? [];
    $day = $run->summary['day'] ?? null;
    $pct = static fn ($value): string => is_numeric($value) ? round((float) $value).'%' : 'n/a';
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
            <x-filament::badge color="gray">{{ number_format((int) ($counts['booked'] ?? 0)) }} jobs</x-filament::badge>
            <x-filament::badge color="success">{{ $pct($counts['completed_pct'] ?? null) }} completed</x-filament::badge>
            <x-filament::badge color="info">{{ $pct($counts['ontime_pct'] ?? null) }} on time</x-filament::badge>
            <x-filament::badge color="gray">{{ (int) ($counts['views'] ?? 0) }} views</x-filament::badge>
            @if ($day)
                <span class="text-gray-600 dark:text-gray-400">{{ \Carbon\CarbonImmutable::parse($day)->isoFormat('dddd D MMMM YYYY') }}</span>
            @endif
        </div>
        @if ($hasPage)
        <div class="flex gap-2">
            <x-filament::button tag="a" href="{{ route('tool-runs.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download data JSON</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The review's own dashboard, as schedule_review.py rendered it. Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', [$dataset, $run]) }}"
    title="Daily schedule review"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 14rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The page is not available for this run.</p>
@endif
```

The warnings are not repeated in this view: the generic run page (`resources/views/filament/pages/tool-run.blade.php`, line 46) already lists `$run->warnings` above the tool view, which is what the test's `assertSee('removing finished jobs')` finds.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact --filter='ScheduleReviewPagesTest|LoadAuditPagesTest|RegionAvailabilityPagesTest|RunsTabTest'
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/RelationManagers/ToolRunsRelationManager.php app/Http/Controllers/ToolRunPageController.php resources/views/filament/tool-runs/schedule_review.blade.php tests/Feature/ScheduleReview/ScheduleReviewPagesTest.php
git commit -m "Schedule review: run page view, refusal of a concurrent review, downloads named by the day"
```

---

### Task 8: Golden test on real data; README

**Files:**
- Create: `tests/Feature/ScheduleReview/ScheduleReviewGoldenTest.php`
- Modify: `README.md`

- [ ] **Step 1: Write the golden test**

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
use App\Models\ToolState;
use App\Support\DatasetSettings;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\RegionAvailability\AccountSettingsFile;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Proves the web run hands the real script what the command line does. Needs a real pso-tools checkout and a
 * folder holding load, plan and eod exports (.xml or .zip), optionally audits.zip. Keep that folder outside this
 * repo (customer data):
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools SCHEDULE_REVIEW_SAMPLE_DIR=~/pso-data/SATE/sunday php artisan test --filter=ScheduleReviewGolden
 * Skipped otherwise.
 */
it('produces the same data JSON through the web as the command line, and keeps history across runs', function () {
    $checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    $sample = env('SCHEDULE_REVIEW_SAMPLE_DIR');
    if (blank($checkoutPath) || blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH and SCHEDULE_REVIEW_SAMPLE_DIR to run the golden test.');
    }
    config(['tools.pso_tools_path' => $checkoutPath, 'tools.schedule_review.timeout_seconds' => 600]);

    $find = static function (string $name) use ($sample): ?string {
        foreach (['xml', 'zip'] as $ext) {
            if (is_file("{$sample}/{$name}.{$ext}")) {
                return "{$sample}/{$name}.{$ext}";
            }
        }

        return null;
    };
    $paths = array_filter(['load' => $find('load'), 'plan' => $find('plan'), 'eod' => $find('eod'), 'audits' => $find('audits')]);
    expect($paths)->toHaveKeys(['load', 'plan', 'eod']);

    Storage::fake('r2');
    $scratchPath = sys_get_temp_dir().'/exports-golden-sr-'.uniqid();
    $work = sys_get_temp_dir().'/golden-sr-cli-'.uniqid();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => $scratchPath]);
    $removeDirectory = static fn (string $directory) => is_dir($directory) && File::deleteDirectory($directory);
    $settings = ['display_name' => 'SaskTel', 'time_zone' => 'America/Regina', 'eod_cutoff_hour' => 22, 'coverage_km' => 50,
        'exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'],
        'attribute_labels' => ['task_type' => 'Task Type', 'district' => 'District', 'sub_district' => 'Sub District', 'supervisor_email' => 'Supervisor Email', 'job_id' => 'Job ID'],
        'resource_type_names' => ['DISTRICTTECH' => 'district tech', 'CONSUMER' => 'consumer', 'BUSINESS' => 'business', 'LONGCYCLE' => 'long-cycle', 'SHORTCYCLE' => 'short-cycle']];

    $normalise = static function (array $data): array {
        $norm = static function ($o) use (&$norm) {
            if (is_array($o) && array_is_list($o)) {
                $items = array_map($norm, $o);
                usort($items, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

                return $items;
            }
            if (is_array($o)) {
                unset($o['generated']);

                return array_map($norm, $o);
            }

            return $o;
        };

        return $norm($data);
    };

    try {
        $dataset = Dataset::factory()->for(Environment::factory()->state(['account_id' => 'SATE']))->create(['name' => 'NORTH', 'settings' => $settings]);
        $storage = app(ExportStorage::class);
        $files = [];
        foreach ($paths as $kind => $path) {
            $file = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::from($kind), 'content_type' => pathinfo($path, PATHINFO_EXTENSION), 'summary' => ['dataset_id' => 'NORTH']]);
            Storage::disk('r2')->put($storage->objectKey($file), file_get_contents($path));
            $files[$kind] = $file->id;
        }
        $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null, 'inputs' => ['files' => $files, 'options' => []]]);

        (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));
        $run->refresh();
        expect($run->status)->toBe(ToolRunStatus::Succeeded, $run->error_message ?? '');

        // The command line from an empty history, with the same account file.
        mkdir($work, 0770, true);
        AccountSettingsFile::write(DatasetSettings::fromArray($settings), "{$work}/accounts", 'SATE');
        $checkout = PsoToolsCheckout::fromConfig();
        $argv = [$checkout->python(), '-I', $checkout->scheduleReviewScript(), '--load', $paths['load'], '--plan', $paths['plan'], '--eod', $paths['eod']];
        if (isset($paths['audits'])) {
            array_push($argv, '--audits', $paths['audits']);
        }
        array_push($argv, '--account', 'SATE', '--time-zone', 'America/Regina', '--accounts-dir', "{$work}/accounts", '--out-root', "{$work}/out");
        $cli = Process::path($work)->timeout(600)->run($argv);
        expect($cli->successful())->toBeTrue($cli->errorOutput());
        $cliJson = json_decode(file_get_contents(glob("{$work}/out/*/*/schedule_review_data_*.json")[0]), true);

        expect($normalise($run->result['data']))->toEqual($normalise($cliJson))
            ->and($run->run_date->toDateString())->toBe($cliJson['meta']['day_iso'])
            ->and($run->summary['day'])->toBe($cliJson['meta']['day_iso']);

        // History: one row per view for that day, and a second run replaces rather than duplicates it.
        $history = ToolState::withoutGlobalScopes()->where('scope_id', $dataset->getKey())->where('key', 'history')->firstOrFail()->state;
        expect($history['all'])->toHaveCount(1)->and($history['all'][0]['date'])->toBe($cliJson['meta']['day_iso']);

        $second = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null, 'inputs' => ['files' => $files, 'options' => []]]);
        (new RunToolJob($second->id))->handle($storage, app(ToolRegistry::class));
        expect($second->fresh()->status)->toBe(ToolRunStatus::Succeeded);
        $history = ToolState::withoutGlobalScopes()->where('scope_id', $dataset->getKey())->where('key', 'history')->firstOrFail()->state;
        expect($history['all'])->toHaveCount(1);
    } finally {
        // The fake disk holds copies of the customer exports; never leave them under storage/.
        Storage::disk('r2')->deleteDirectory('');
        $removeDirectory($scratchPath);
        $removeDirectory($work);
    }
});
```

- [ ] **Step 2: Run it on the real data**

```bash
TOOLS_PSO_TOOLS_PATH="/Users/idawoodjee/Documents/Projects/PSO Tools" SCHEDULE_REVIEW_SAMPLE_DIR=~/pso-data/SATE/sunday php artisan test --compact --filter=ScheduleReviewGolden
```
Expected: PASS in about 20 s (two real runs plus the command line). If it fails on the JSON comparison, print the first differing top-level key before changing anything: the likely causes are the account file (compare `$work/accounts/SATE.json` with the repo's `accounts/SATE.json`) or `--time-zone`.

- [ ] **Step 3: README**

In `README.md`, after "### Region availability", add:

```markdown
### Daily schedule review

The morning LOAD and plan against the end-of-day export: booked work and its fates, on-time performance,
planned versus actual per technician, utilisation, duration estimates versus actuals, reassignments and
overtime, plus an appointments section when the day's audit messages zip is uploaded too. Run as a child
process from the pso-tools checkout (nothing is ported). Start it on a dataset's Runs tab with "Run Daily
schedule review" after uploading the LOAD, plan and EOD for the day (and optionally the audits zip); there
are no options. The run's date is the reviewed day.

The dashboard's trend lines need the previous days: the app keeps the script's 60-day history per dataset
(encrypted, table `tool_states`) and hands it to each run, so run the days in order and the lines fill in.
Re-running a day replaces that day. A second review is refused while one is running on the same dataset.

Settings it reads beyond the common ones: customer job types (blank: inferred), skills to ignore, the end of
day cut-off hour. Memory: the script peaks at a few hundred MB on a quiet day and more on a busy one; the
container should have 2 GB (`pct set 199 --memory 2048` on the Proxmox host). A run that dies with
"stopped while ..." and no error text was killed for memory.
```

- [ ] **Step 4: Full suite and commit**

```bash
php artisan test --compact --exclude-filter=ExampleTest
vendor/bin/pint --dirty
git add tests/Feature/ScheduleReview/ScheduleReviewGoldenTest.php README.md
git commit -m "Schedule review: golden test on real data; README"
```

---

### Task 9: Execution notes and handback

**Files:**
- Create: `docs/superpowers/notes/2026-10-11-schedule-review-execution-notes.md`

- [ ] **Step 1: Write the notes**

Follow `docs/superpowers/notes/2026-10-11-region-availability-execution-notes.md` for the shape: per-task result and review rounds, rulings made during execution, the memory numbers measured in Task 1 (before and after), the golden test outcome and data used, what was not done on purpose (no browser check), and a "Deferred minors" list. Do not repeat the spec or the plan.

- [ ] **Step 2: Commit and hand back**

```bash
git add docs/superpowers/notes/2026-10-11-schedule-review-execution-notes.md
git commit -m "Notes: schedule review execution"
```

Hand back: branch `feat/schedule-review` ready for the whole-branch review and merge. Deploy is the spec's usual sequence (pso-tools main already pushed in Task 2; this branch merged to main and pushed; the box runs `php artisan migrate --force --path=database/migrations/2026_10_12_000001_create_tool_states_table.php` because of the production `.env` placeholder, then caches and worker restart). Ish sets the LXC to 2 GB.
