# Travel Tuning (web) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A wizard tool, Travel tuning, that takes two or more finished Plan travels runs (checked with Google) of one scope, in order, each with an optional SYS export for its polygons, runs pso-tools `travel/travel_tuning.py` on them and keeps the report page.

**Architecture:** The first tool whose inputs are other runs. A new contract `TakesRunInputs` lets `StartToolRun` check and store the picked runs as `inputs['runs']` (a list of `run_id`, `label`, `sys_file_id`); the tool declares no export kinds, so `RunToolJob` stays unchanged and only makes the scratch folder. `TravelTuningTool::run` writes each source run's stored CSV, an XML copy of each picked SYS export and a manifest into that folder, runs the script through `ScriptRun`, deletes the SYS copies and stores the page (encrypted) with a counts-only summary. `TravelTuningEntry` replaces the Inputs step with a run table and an ordered repeater (up and down buttons), and prefills the options from the dataset.

**Tech Stack:** Laravel 13, PHP 8.5, Filament v5 (Schemas, Wizard, Repeater, Select, TextInput, Textarea, View, Text), Livewire 4, Pest 4, SQLite in memory for tests (MySQL in production). Python 3.9+ for the pso-tools script.

**Spec:** `docs/superpowers/specs/2026-10-18-travel-tuning-design.md`, section 4 (amended 2026-10-18: Unfiled accepted, every run in a report comes from the run's own scope, polygons are SYS exports of the dataset's environment or the user's Unfiled SYS exports), section 3.1 (the command contract) and section 6 (no uploaded CSVs, no review CSV on the web). The spec is the authority; this plan argues from it. Where the plan settles something the spec leaves open, it is listed under "Settled rulings".

## Global Constraints

- Work in pso-test-tools (`~/Herd/pso-test-tools`) on a branch `travel-tuning-web` made from `main`. Never commit to `main` directly. Before Task 1, `git status` must be clean: main may still carry the coordinator's uncommitted wizard change (the Where step opens on "A dataset"); wait until it is committed.
- Do not modify pso-tools. The script `travel/travel_tuning.py` and its template are read from the configured checkout (`config('tools.pso_tools_path')`).
- Customer identifiers (resource, activity, shift, polygon ids, GB codes, file contents) never go into `ToolRun.summary`, `ToolRun.warnings`, `ToolRun.error_message` or a log line. They live only in the encrypted `ToolRun.result`. The summary holds counts (and the baseline's first date). Warnings are the script's `meta.warnings`, which by spec 3.1 carry only labels and counts. `ScriptRun` never logs `warning:` lines; keep it that way.
- A SYS export holds live credentials. It is copied only into the run's scratch folder (`ExportStorage::scratchDirectoryFor()`), its copy is deleted in a `finally` as soon as the script ends, it is never stored anywhere else and never served. Only the polygon numbers reach the page.
- Scope ids are compared as strings: `(string) $scope->getKey()` on every query and every comparison (a user's id is an integer; `scope_id` is a char column).
- Wording: plain English for a customer audience. "Allocated", never "placed". No em dashes anywhere (copy, comments, commit messages, code). Warnings show on the page, not only in tooltips.
- House conventions (CLAUDE.md): no abbreviated variable names; `app()` over `new` for Filament and Laravel classes; never `final` or `readonly` on classes that extend Filament; `static fn` for closures that do not use `$this`; constructor property promotion; explicit return and parameter types. Filament v5 namespaces only: fields `Filament\Forms\Components\`, layout `Filament\Schemas\Components\`, `Get`/`Set` from `Filament\Schemas\Components\Utilities\`, actions `Filament\Actions\`.
- Tests: users that open the panel need an `@mac.com` email. Livewire 4.4 renders a 404 instead of throwing: assert with `->assertNotFound()`. The known failing `Tests\Feature\ExampleTest` is ignored. Tests that read `~/pso-data/...` or need a real pso-tools checkout skip themselves when the env vars are not set, and never copy customer data into the repo. Unfiled tests must choose Unfiled explicitly with `->fillForm(['where' => 'unfiled'])` (the Where step opens on "A dataset").
- Do not open, curl or drive the app in a browser; Ish checks the UI himself.
- Before each commit run `vendor/bin/pint --dirty`, then `git diff` and revert any whitespace-only hunk outside the lines you changed.
- Stage only the paths the task lists. Never `git add -A`, `git add .` or `git add docs/`: untracked customer files may sit in `docs/`.
- Every commit message ends with these two lines:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
  ```

## Review Focus

1. A source Plan travels run deleted, or its SYS export purged (12 hour TTL), between the pick and the job: the run fails with a plain sentence naming the row, no script call, no SYS copy left. Pinned in Task 5.
2. Labels and options with commas, quotes, newlines or a leading dash (`Grid, "v6"`, a title `-draft`): the manifest CSV and the argv carry them intact. Pinned in Tasks 4 and 5.
3. A forged form state: one run, the same run twice, a run without Google, another dataset's or another user's run, a SYS of another environment: refused by `StartToolRun` before a run is queued. Pinned in Task 3 (and Task 6 for the wizard path).
4. A SYS export uploaded before `polygon_count` existed, or a zipped one: scanned once on pick, the count remembered on its summary, the scratch copy removed. Pinned in Task 2.
5. Unfiled: the user's own Unfiled Plan travels runs and Unfiled SYS exports only, ids compared as strings, another user's Unfiled run refused. Pinned in Tasks 3 and 6.

## Settled rulings

1. **Inputs on the run.** `ToolRun.inputs = ['files' => [], 'options' => [...], 'runs' => [['run_id' => uuid, 'label' => string, 'sys_file_id' => uuid|null], ...]]`, baseline first. A new contract `App\Tools\Contracts\TakesRunInputs` (`needs()`, `checkRunInputs()`) is the only extension point: `StartToolRun::start()` gains a trailing `array $runs = []`, calls `checkRunInputs()` for such a tool and refuses runs for any other tool; `rerun()` passes them on. `RunToolJob` is unchanged: the tool declares no kinds, the job still makes and removes the scratch folder, and the tool writes into the same folder (`scratchDirectoryFor($run->id)`).
2. **Which runs can be picked.** Succeeded Plan travels runs on the same scope (`scope_type` and `(string) scope_id` equal). "Has Google" means `summary['google']` is an array: a run without the Google check, or whose Google step failed, keeps it null and its CSV has no Google columns (plan_travels.py writes them only when `meta.google`). The job checks the CSV header again (`Google Duration (min)`) before running.
3. **Picker.** A read-only table of every finished Plan travels run on the scope (dates, made, legs, flagged, Google), greyed with "Run plan travels again with Google on" when it has no Google; under it a Repeater (two rows to start, item numbers, up and down buttons, "Add a run") with a run select (runs without Google disabled), a label and a polygons select (SYS exports of the scope, placeholder "None"). A SYS drop zone sits below for a new upload.
4. **Labels.** Blank means the positional default (Baseline, Run 1, Run 2, the script's), resolved by `StartToolRun` from the final order, so reordering keeps typed labels with their runs. Whitespace is collapsed and labels cut to 60 characters.
5. **Limits.** At least 2 runs, at most 10 (the page stays readable); the same run twice is refused.
6. **Polygon table check.** A SYS export "has a Polygon table" when it holds at least one `Polygon` row directly under `DsSystemData` (the real 251-polygon file has them at that depth). `ExportSummariser` records `polygon_count` for every new SYS upload; an older SYS without it is scanned once when picked (zip or XML) and the count is written back to its summary. A SYS that is not XML is refused with a sentence. The check runs on pick (wizard) and again in `StartToolRun`.
7. **Options.** Title (default "Travel Tuning Report"), client (dataset settings `display_name`, else the environment's account id), dataset name (settings `dataset_display_name`, else the dataset name), both blank for Unfiled and kept once the user types; the five thresholds with the script's defaults; region label (see 8); map tiles default esri-gray; notes as a Markdown textarea written to `notes.md`. No review CSV, no offline.
8. **Region label (decided by the product owner, 2026-10-18).** The label never defaults to "GB". A new dataset setting `region_label` (string or null, Task 5A, modelled on `supervisor_label`) relabels it per dataset. The option is prefilled from the dataset's `region_label` setting, else "Region"; an Unfiled run gets "Region". The user can still edit it in the options. `TravelTuningCommand` always passes `--region-label` explicitly, so the web never relies on the script's own default (which is now "Region" too, pso-tools commit d35fe3f). `region_label` is written to a pso-tools account file when set, like `supervisor_label` (the generic `toAccountFile()` rule; the scripts read accounts with `.get()` and ignore keys they do not use), and `fromAccountFile()` reads it back unchanged.
9. **Generated time.** `--generated` is the app clock in the run's zone (the dataset's, or the Unfiled run's Time zone option), `Y-m-d H:i`.
10. **Stored result and summary.** `result = ['html' => ...]` only. `summary = runs, baseline_flagged, final_flagged, baseline_error_hours, final_error_hours, fixed, newly_broken, shared_legs, first_date, line`. `first_date` is the baseline run's first date (a date, no id) so downloads are named `travel_tuning_<account>_<dataset>_<date>.html`. `line` is built in PHP from counts ("2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs"); the script's stdout summary line names the labels and is not stored.
11. **Run page.** The report in the sandboxed iframe under the standard `SANDBOX` CSP (no app links), Open full page and Download HTML. No JSON or CSV download. The Inputs panel lists the source runs in order with a link to each while it exists ("plan travels run deleted" after), and the SYS file name per row or "None".
12. **Runs tab.** The tool is `WizardOnly`: no Run action on a dataset's Runs tab (its inputs need the wizard).
13. **Free text in `inputs`.** Labels, title, client and notes are stored in `ToolRun.inputs['options']` and `inputs['runs']` (plain JSON), like every other tool's options. Flagged for Ish (notes may carry customer wording).
14. **Tests.** Feature tests run a stub `tests/Fixtures/pso-tools/travel/travel_tuning.py` (as Plan travels does). Task 8 runs the real script: on a synthetic set when `TOOLS_PSO_TOOLS_PATH` is set, and against `~/pso-data/SATE/1013` when `TRAVEL_TUNING_SAMPLE_DIR` is set too (like the Region availability golden).

## File Structure

Create:
- `app/Tools/Contracts/TakesRunInputs.php`: the run-inputs contract.
- `app/Support/Tools/UnusableRunInputException.php`: a refused pick, with its row number.
- `app/Support/Tools/SourceRunList.php`: the source runs of a run, for the Inputs panel.
- `app/Tools/TravelTuning/SysPolygons.php`: polygon count of a SYS export, and its XML copy in scratch.
- `app/Tools/TravelTuning/SourceRuns.php`: the pickable Plan travels runs of a scope and the check of a pick.
- `app/Tools/TravelTuning/TravelTuningCommand.php`: the script's argv (pure).
- `app/Tools/TravelTuning/TravelTuningTool.php`: the tool.
- `app/Filament/Pages/Tools/TravelTuningEntry.php`: the wizard page.
- `resources/views/filament/pages/tools/travel-tuning-runs.blade.php`: the run table on Inputs.
- `resources/views/filament/tool-runs/travel_tuning.blade.php`: the run view.
- `tests/Fixtures/pso-tools/travel/travel_tuning.py`, `tests/Fixtures/pso-tools/travel/travel_tuning_template.html`: the stub.
- `tests/Support/FakeRunInputTool.php`, `tests/Support/TravelTuningFixtures.php`: test helpers.
- `tests/Feature/TravelTuning/*Test.php`: the tests named in each task.

Modify:
- `app/Enums/Tool.php`, `app/Support/Tools/StartToolRun.php`, `app/Support/Exports/RunInputPicker.php`, `app/Support/Exports/ExportSummariser.php`, `app/Tools/LoadAudit/PsoToolsCheckout.php`, `config/tools.php`, `app/Providers/AppServiceProvider.php`, `app/Filament/Pages/Tools/ToolWizardPage.php`, `app/Filament/Widgets/ToolCatalogue.php`, `resources/views/filament/tool-runs/_run-panel.blade.php`, `tests/Pest.php`, `tests/Unit/ToolAcceptsUnfiledTest.php`, `tests/Feature/NavigationGroupsTest.php`, `tests/Feature/ExportSummariserTest.php`.

---

### Task 0: Branch

- [ ] **Step 1: Make the branch**

```bash
cd ~/Herd/pso-test-tools
git status --short   # must print nothing; if main has uncommitted work, stop and ask
git switch main && git switch -c travel-tuning-web
```

---

### Task 1: Tool id and the run-inputs contract

**Files:**
- Modify: `app/Enums/Tool.php`
- Create: `app/Tools/Contracts/TakesRunInputs.php`
- Create: `app/Support/Tools/UnusableRunInputException.php`
- Modify: `app/Support/Tools/StartToolRun.php` (`start()`, `rerun()`)
- Create: `tests/Support/FakeRunInputTool.php`
- Modify: `tests/Unit/ToolAcceptsUnfiledTest.php`
- Test: `tests/Feature/TravelTuning/RunInputsContractTest.php`

**Interfaces:**
- Produces: `ToolId::TravelTuning` (`'travel_tuning'`, label "Travel tuning", accepts Unfiled, icon `Heroicon::OutlinedAdjustmentsHorizontal`).
- Produces: `interface TakesRunInputs { public function needs(): string; public function checkRunInputs(Model $scope, array $picked): array; }`
- Produces: `UnusableRunInputException(int $row, string $message)` with `public readonly int $row` (1-based row, 0 for the whole list).
- Produces: `StartToolRun::start(Model $scope, ToolId $toolId, array $files, array $options, User $user, array $runs = []): ToolRun`; stores `inputs['runs']` only for a `TakesRunInputs` tool.

- [ ] **Step 1: Write the failing tests**

`tests/Support/FakeRunInputTool.php`:

```php
<?php

namespace Tests\Support;

use App\Enums\Tool as ToolId;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\Contracts\TakesRunInputs;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\Contracts\WizardOnly;
use App\Tools\ToolContext;
use Illuminate\Database\Eloquent\Model;

/** A tool that takes other runs as inputs, for the StartToolRun tests: refuses fewer than two, keeps only run_id. */
class FakeRunInputTool implements TakesRunInputs, Tool, WizardOnly
{
    /** @var list<array<string, mixed>>|null what checkRunInputs() was given last */
    public static ?array $seen = null;

    public static function id(): ToolId
    {
        return ToolId::TravelTuning;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function optionFields(): array
    {
        return [];
    }

    public function needs(): string
    {
        return 'Needs two or more test runs';
    }

    public function checkRunInputs(Model $scope, array $picked): array
    {
        self::$seen = $picked;
        if (count($picked) < 2) {
            throw new UnusableRunInputException(0, 'Pick at least two runs.');
        }

        return array_map(static fn (array $row): array => ['run_id' => (string) $row['run_id']], $picked);
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        return new ToolResult(['html' => '<p>ok</p>']);
    }
}
```

`tests/Feature/TravelTuning/RunInputsContractTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRunInputTool;
use Tests\Support\FakeTool;

beforeEach(function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::TravelTuning, FakeRunInputTool::class);
    FakeRunInputTool::$seen = null;
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    FakeTool::$kinds = null;
});

it('stores the runs the tool checked, in order, and dispatches the job', function () {
    $run = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'T'], $this->user, [['run_id' => 'b'], ['run_id' => 'a']]);

    expect($run->inputs)->toBe(['files' => [], 'options' => ['title' => 'T'], 'runs' => [['run_id' => 'b'], ['run_id' => 'a']]])
        ->and(FakeRunInputTool::$seen)->toBe([['run_id' => 'b'], ['run_id' => 'a']]);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('creates no run when the tool refuses the runs', function () {
    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], [], $this->user, [['run_id' => 'a']]))
        ->toThrow(UnusableRunInputException::class, 'Pick at least two runs.');

    expect(ToolRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses runs for a tool that takes export files', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    FakeTool::$kinds = [[], []];

    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, [], [], $this->user, [['run_id' => 'a']]))
        ->toThrow(InvalidArgumentException::class, 'Load Health Audit does not take other runs as inputs.');
    expect(ToolRun::count())->toBe(0);
});

it('runs on Unfiled, since Travel tuning accepts it', function () {
    $run = app(StartToolRun::class)->start($this->user, ToolId::TravelTuning, [], [], $this->user, [['run_id' => 'a'], ['run_id' => 'b']]);

    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->getKey());
});

it('passes the runs on when a run is started again', function () {
    $first = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'T'], $this->user, [['run_id' => 'a'], ['run_id' => 'b']]);

    $again = app(StartToolRun::class)->rerun($first, ['title' => 'U'], $this->user);

    expect($again->inputs['runs'])->toBe([['run_id' => 'a'], ['run_id' => 'b']])
        ->and($again->inputs['options'])->toBe(['title' => 'U']);
});
```

Change `tests/Unit/ToolAcceptsUnfiledTest.php` so the expected list ends with the new tool:

```php
    expect($accepting)->toBe([Tool::LoadAudit, Tool::AppointmentTimeline, Tool::PlanTravels, Tool::TrimExport, Tool::TravelTuning]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/RunInputsContractTest.php tests/Unit/ToolAcceptsUnfiledTest.php`
Expected: FAIL (`Undefined constant App\Enums\Tool::TravelTuning`, class `TakesRunInputs` not found).

- [ ] **Step 3: Implement**

`app/Enums/Tool.php`: add the case after `TrimExport`, and the matching arms.

```php
    case TrimExport = 'trim_export';
    case TravelTuning = 'travel_tuning';
```

```php
    public function acceptsUnfiled(): bool
    {
        return in_array($this, [self::LoadAudit, self::AppointmentTimeline, self::PlanTravels, self::TrimExport, self::TravelTuning], true);
    }
```

In `question()`:

```php
            self::TravelTuning => 'Did this round of travel weightings bring PSO\'s travel times closer to real road times, and what did it break?',
```

In `getLabel()`:

```php
            self::TravelTuning => 'Travel tuning',
```

In `icon()`:

```php
            self::TravelTuning => Heroicon::OutlinedAdjustmentsHorizontal,
```

`app/Tools/Contracts/TakesRunInputs.php`:

```php
<?php

namespace App\Tools\Contracts;

use App\Support\Tools\UnusableRunInputException;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool whose inputs are other finished runs (Travel tuning reads Plan travels runs), not export files. It declares
 * no export kinds; StartToolRun stores what checkRunInputs() returns as the run's inputs['runs'], in order.
 */
interface TakesRunInputs
{
    /** Shown on the catalogue tile in place of the export kinds. */
    public function needs(): string;

    /**
     * The picked runs, checked against the scope and normalised. Each row carries the tool's own keys.
     *
     * @param  list<array<string, mixed>>  $picked  in order, as the wizard sent them
     * @return list<array<string, mixed>>
     *
     * @throws UnusableRunInputException
     */
    public function checkRunInputs(Model $scope, array $picked): array;
}
```

`app/Support/Tools/UnusableRunInputException.php`:

```php
<?php

namespace App\Support\Tools;

use RuntimeException;

/** A pick of source runs the tool refused; the message is shown to the user as it is. */
class UnusableRunInputException extends RuntimeException
{
    /** @param  int  $row  the 1-based row on the Inputs step, 0 when the whole list is wrong */
    public function __construct(public readonly int $row, string $message)
    {
        parent::__construct($message);
    }
}
```

`app/Support/Tools/StartToolRun.php`: add `use App\Tools\Contracts\TakesRunInputs;`, change the signature and docblock of `start()`, and replace the `create([...])` call:

```php
    /**
     * @param  array<string, string|null>  $files  kind value => export file id
     * @param  array<string, mixed>  $options
     * @param  list<array<string, mixed>>  $runs  source runs in order, for a tool that takes runs (TakesRunInputs)
     *
     * @throws UnusableInputException
     * @throws UnusableRunInputException
     */
    public function start(Model $scope, ToolId $toolId, array $files, array $options, User $user, array $runs = []): ToolRun
```

```php
        $this->assertUsable($scope, $toolId, $chosen);

        $inputs = ['files' => $chosen, 'options' => $options];
        if ($tool instanceof TakesRunInputs) {
            $inputs['runs'] = $tool->checkRunInputs($scope, array_values($runs));
        } elseif ($runs !== []) {
            throw new InvalidArgumentException("{$toolId->getLabel()} does not take other runs as inputs.");
        }

        $run = $scope->toolRuns()->create([
            'tool' => $toolId,
            'inputs' => $inputs,
            'started_by_user_id' => $user->id,
            'status' => ToolRunStatus::Queued,
        ]);
```

In `rerun()`:

```php
        return $this->start($run->scope, $run->tool, $inputs['files'] ?? [], [...($inputs['options'] ?? []), ...$options], $user, $inputs['runs'] ?? []);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/RunInputsContractTest.php tests/Unit/ToolAcceptsUnfiledTest.php tests/Unit/ToolIconTest.php tests/Unit/ToolQuestionTest.php tests/Feature/StartToolRunTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Enums/Tool.php app/Tools/Contracts/TakesRunInputs.php app/Support/Tools/UnusableRunInputException.php app/Support/Tools/StartToolRun.php tests/Support/FakeRunInputTool.php tests/Unit/ToolAcceptsUnfiledTest.php tests/Feature/TravelTuning/RunInputsContractTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): tool id and the run-inputs contract

StartToolRun stores the source runs a TakesRunInputs tool checked as inputs['runs'], and passes them on when a run is started again.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 2: Polygons in a SYS export

**Files:**
- Modify: `app/Support/Exports/ExportSummariser.php` (`summariseSys()`)
- Modify: `app/Support/Exports/RunInputPicker.php` (new public `optionsForKind()`)
- Create: `app/Tools/TravelTuning/SysPolygons.php`
- Create: `tests/Support/TravelTuningFixtures.php`; Modify: `tests/Pest.php`
- Modify: `tests/Feature/ExportSummariserTest.php`
- Test: `tests/Feature/TravelTuning/SysPolygonsTest.php`

**Interfaces:**
- Produces: SYS summaries gain `polygon_count` (int).
- Produces: `RunInputPicker::optionsForKind(Model $scope, ExportKind $kind): array<string, string>` (file id => label with the purge countdown; a dataset sees its environment's SYS, Unfiled sees the user's).
- Produces: `SysPolygons::count(ExportFile $file): int`, `SysPolygons::localXml(ExportFile $file, string $directory, string $name): string` (path `<directory>/<name>.xml`), `SysPolygons::countIn(string $xmlPath): int`.
- Produces (tests): `polygonSysXml(int $polygons = 1): string`, `storedSys(Model $scope, string $xmlPath, bool $zipped = false, array $attributes = []): ExportFile`, `tuningCsv(array $psoSeconds = [2400, 2700, 630, 900], bool $google = true): string`, `planTravelsRun(Model $scope, array $attributes = [], ?string $csv = null): ToolRun`.

- [ ] **Step 1: Write the test helpers**

`tests/Support/TravelTuningFixtures.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SysFileBuilder;

/** A fake DsSystemData with $polygons axis-aligned rectangles G01, G02, ... (weighting 0.4); G01 covers the first trip of tuningCsv(). */
function polygonSysXml(int $polygons = 1): string
{
    $builder = SysFileBuilder::make()->version('6.14.0.0', 'Creation', '2026-01-01 00:00:00');
    for ($number = 1; $number <= $polygons; $number++) {
        $south = 50.0 + ($number - 1) * 0.2;
        $north = $south + 0.1;
        $id = sprintf('G%02d', $number);
        $builder->row('Polygon', [
            'id' => $id, 'description' => 'Grid w0.40 | test',
            'latitude_1' => (string) $south, 'longitude_1' => '-101.00', 'latitude_2' => (string) $south, 'longitude_2' => '-100.80',
            'latitude_3' => (string) $north, 'longitude_3' => '-100.80', 'latitude_4' => (string) $north, 'longitude_4' => '-101.00',
        ])->row('Travel_Time_Polygon', ['polygon_id' => $id, 'weighting' => '0.4']);
    }

    return $builder->write('tuning');
}

/** A SYS export on $scope (an environment, or a user for Unfiled) with the file's bytes on the exports disk. */
function storedSys(Model $scope, string $xmlPath, bool $zipped = false, array $attributes = []): ExportFile
{
    $file = ExportFile::factory()->for($scope, 'scope')->sys()->create([
        'content_type' => $zipped ? 'zip' : 'xml', 'original_filename' => $zipped ? 'PSO_Sys.zip' : 'PSO_Sys.xml', ...$attributes,
    ]);
    $source = $zipped ? zipOf($xmlPath, 'PSO_Sys.xml') : $xmlPath;
    Storage::disk((string) config('exports.disk'))->put(app(ExportStorage::class)->objectKey($file), (string) file_get_contents($source));

    return $file;
}

/** A plan travels CSV of four synthetic legs (no customer data); Google columns unless $google is false. */
function tuningCsv(array $psoSeconds = [2400, 2700, 630, 900], bool $google = true): string
{
    $trips = [
        [50.02, -100.98, 50.08, -100.82, 900, 15.0],
        [50.22, -100.58, 50.27, -100.32, 1500, 22.0],
        [50.70, -100.50, 50.75, -100.40, 600, 9.5],
        [50.41, -100.19, 50.44, -100.01, 840, 14.0],
    ];
    $header = ['Resource ID', 'GB (Previous Activity)', 'GB (Destination Activity)', 'Start Location Latitude', 'Start Location Longitude',
        'End Location Latitude', 'End Location Longitude', 'Distance (km)', 'Expected Travel Time (sec)', 'Merged Fragments'];
    if ($google) {
        array_push($header, 'Google Duration (min)', 'Google Duration (sec)', 'Google Distance (km)', 'Validation Error');
    }
    $lines = [implode(',', $header)];
    foreach ($trips as $index => [$fromLat, $fromLng, $toLat, $toLng, $googleSeconds, $kilometres]) {
        $row = ['R'.($index + 1), 'AA1', 'BB2', $fromLat, $fromLng, $toLat, $toLng, $kilometres, $psoSeconds[$index], 1];
        if ($google) {
            array_push($row, $googleSeconds / 60, $googleSeconds, $kilometres, '');
        }
        $lines[] = implode(',', array_map('strval', $row));
    }

    return implode("\n", $lines)."\n";
}

/** A finished Plan travels run on $scope, checked with Google unless the summary says otherwise. */
function planTravelsRun(Model $scope, array $attributes = [], ?string $csv = null): ToolRun
{
    return ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::PlanTravels,
        'status' => ToolRunStatus::Succeeded,
        'result' => ['html' => '<p>plan travels</p>', 'csv' => $csv ?? tuningCsv()],
        'summary' => ['leg_count' => 4, 'review_count' => 2, 'first_date' => '2026-10-05', 'last_date' => '2026-10-05', 'days' => 1,
            'google' => ['cache' => 4, 'google' => 0, 'error' => 0], 'line' => '4 legs for 4 resources, 2026-10-05'],
        ...$attributes,
    ]);
}
```

In `tests/Pest.php`, after the other `require_once` lines:

```php
require_once __DIR__.'/Support/TravelTuningFixtures.php';
```

- [ ] **Step 2: Write the failing tests**

In `tests/Feature/ExportSummariserTest.php`, extend the SYS test's expectation and add one test:

```php
    expect($summary->toArray())->toMatchArray(['root' => 'DsSystemData', 'pso_version' => '6.14.0.0', 'parameter_count' => 2, 'exception_type_count' => 1, 'polygon_count' => 0])
```

```php
it('counts the Polygon rows of a SYS export', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Sys, 'xml'), polygonSysXml(3), 'UTC');

    expect($summary->toArray()['polygon_count'])->toBe(3);
});
```

`tests/Feature/TravelTuning/SysPolygonsTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
use App\Tools\TravelTuning\SysPolygons;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create();
    $this->dataset = Dataset::factory()->for($this->environment)->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
});

it('uses the stored count without reading the file', function () {
    $file = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['pso_version' => '6.14.0.0', 'polygon_count' => 7]]);

    expect(app(SysPolygons::class)->count($file))->toBe(7);
});

it('scans a zipped SYS export once, remembers the count and leaves no copy behind', function () {
    $file = storedSys($this->environment, polygonSysXml(2), zipped: true, attributes: ['summary' => ['pso_version' => '6.14.0.0']]);

    expect(app(SysPolygons::class)->count($file))->toBe(2)
        ->and($file->fresh()->summary)->toBe(['pso_version' => '6.14.0.0', 'polygon_count' => 2])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('counts zero for a SYS export without polygons', function () {
    $file = storedSys($this->environment, sysXml());

    expect(app(SysPolygons::class)->count($file))->toBe(0);
});

it('copies a zipped SYS export into a folder as plain XML under the given name', function () {
    $file = storedSys($this->environment, polygonSysXml(1), zipped: true);
    $directory = sys_get_temp_dir().'/tuning-copy-'.uniqid();
    mkdir($directory);

    try {
        $path = app(SysPolygons::class)->localXml($file, $directory, 'polygons-2');

        expect($path)->toBe($directory.'/polygons-2.xml')
            ->and(SysPolygons::countIn($path))->toBe(1)
            ->and(array_map('basename', glob($directory.'/*')))->toBe(['polygons-2.xml']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a file that is not a SYS export', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    expect(fn () => app(SysPolygons::class)->localXml($load, sys_get_temp_dir(), 'x'))->toThrow(RuntimeException::class, 'Only a SYS export can hold polygons.');
});

it('lists the SYS exports of a dataset\'s environment, or of the user for Unfiled', function () {
    $environmentSys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'env_sys.xml']);
    $unfiledSys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['original_filename' => 'my_sys.xml']);
    ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['expires_at' => now()->subHour()]);

    $picker = app(RunInputPicker::class);

    expect(array_keys($picker->optionsForKind($this->dataset, ExportKind::Sys)))->toBe([$environmentSys->id])
        ->and(array_keys($picker->optionsForKind($this->user, ExportKind::Sys)))->toBe([$unfiledSys->id])
        ->and($picker->optionsForKind($this->dataset, ExportKind::Sys)[$environmentSys->id])->toContain('env_sys.xml')->toContain('Purges in');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/SysPolygonsTest.php tests/Feature/ExportSummariserTest.php`
Expected: FAIL (`polygon_count` missing, class `SysPolygons` not found, `optionsForKind` undefined).

- [ ] **Step 4: Implement**

`app/Support/Exports/ExportSummariser.php`, in `summariseSys()`: add `$polygons = 0;` beside `$parameters = 0;`, a match arm, and the key.

```php
                match ($reader->localName) {
                    'Profile_Parameter' => $parameters++,
                    'Org_Schedule_Exception_Type' => $exceptionTypes++,
                    // Travel tuning refuses a SYS export with no polygons; counted here so a pick needs no second read.
                    'Polygon' => $polygons++,
                    default => null,
                };
```

```php
        return new ExportSummary([
            'root' => 'DsSystemData',
            'pso_version' => $version,
            'parameter_count' => $parameters,
            'exception_type_count' => $exceptionTypes,
            'polygon_count' => $polygons,
        ]);
```

`app/Support/Exports/RunInputPicker.php`, after `optionsWithExpiryFor()`:

```php
    /**
     * One kind's usable files on this scope with the purge countdown, newest first (a dataset sees its environment's
     * SYS exports, Unfiled the user's own), for a tool that picks files per row (Travel tuning's polygons).
     *
     * @return array<string, string> export file id => label
     */
    public function optionsForKind(Model $scope, ExportKind $kind): array
    {
        return $this->candidates($scope, $kind)
            ->mapWithKeys(fn (ExportFile $file): array => [$file->id => $this->labelFor($file, $scope).', '.$this->purgeCountdown($file)])
            ->all();
    }
```

`app/Tools/TravelTuning/SysPolygons.php`:

```php
<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\Exports\ExportFormat;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Str;
use RuntimeException;
use XMLReader;

/**
 * The polygons of a SYS export, for Travel tuning: how many Polygon rows sit directly under DsSystemData (from the
 * summary, or one streamed pass whose count is then kept on the summary), and a plain XML copy in a scratch folder
 * for the script. A SYS export holds live credentials: it is only ever copied into a scratch folder, and whoever
 * asked for the copy deletes it.
 */
class SysPolygons
{
    public function __construct(private ExportStorage $storage) {}

    public function count(ExportFile $file): int
    {
        $stored = $file->summary['polygon_count'] ?? null;
        if (is_int($stored)) {
            return $stored;
        }

        $scratch = $this->storage->scratchDirectoryFor((string) Str::uuid());
        try {
            $count = self::countIn($this->localXml($file, $scratch, 'sys'));
        } finally {
            $this->storage->removeScratch($scratch);
        }
        $file->forceFill(['summary' => [...($file->summary ?? []), 'polygon_count' => $count]])->save();

        return $count;
    }

    /** Copies the export into $directory as <name>.xml (the largest member when it is zipped) and returns the path. */
    public function localXml(ExportFile $file, string $directory, string $name): string
    {
        if ($file->kind !== ExportKind::Sys) {
            throw new RuntimeException('Only a SYS export can hold polygons.');
        }

        $copy = $this->storage->readToScratch($file, $directory);
        $format = ExportFormat::sniff($copy) ?? $file->content_type;
        if ($format === 'zip') {
            try {
                [$member, $format] = ExportFormat::largestMember($copy);
            } finally {
                @unlink($copy);
            }
            $copy = $member;
        }
        if ($format !== 'xml') {
            @unlink($copy);

            throw new RuntimeException('This SYS export is not XML, so its polygons cannot be read.');
        }

        $target = rtrim($directory, '/').'/'.$name.'.xml';
        if (! @rename($copy, $target)) {
            @unlink($copy);

            throw new RuntimeException('The SYS export could not be prepared for the report.');
        }

        return $target;
    }

    /** Polygon rows directly under the root element, whatever the namespace (as the summariser counts tables). */
    public static function countIn(string $xmlPath): int
    {
        $reader = new XMLReader;
        if (! @$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The SYS export could not be read as XML.');
        }

        $count = 0;
        try {
            $moved = @$reader->read();
            while ($moved) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 1) {
                    if ($reader->localName === 'Polygon') {
                        $count++;
                    }
                    // next() lands on the following sibling, which must then be looked at, not read past.
                    $moved = @$reader->next();

                    continue;
                }
                $moved = @$reader->read();
            }
        } finally {
            $reader->close();
        }

        return $count;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/SysPolygonsTest.php tests/Feature/ExportSummariserTest.php tests/Feature/StartToolRunTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Exports/ExportSummariser.php app/Support/Exports/RunInputPicker.php app/Tools/TravelTuning/SysPolygons.php tests/Support/TravelTuningFixtures.php tests/Pest.php tests/Feature/ExportSummariserTest.php tests/Feature/TravelTuning/SysPolygonsTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): count the polygons of a SYS export

New SYS uploads record polygon_count; an older one is scanned once (zipped or not) and the count kept. RunInputPicker lists one kind's files per scope.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 3: The Plan travels runs a report can use

**Files:**
- Create: `app/Tools/TravelTuning/SourceRuns.php`
- Test: `tests/Feature/TravelTuning/SourceRunsTest.php`

**Interfaces:**
- Consumes: `RunInputPicker::optionsForKind()`, `SysPolygons::count()` (Task 2), `UnusableRunInputException` (Task 1).
- Produces: `SourceRuns::finished(Model $scope): Collection<int, ToolRun>` (no results loaded), `SourceRuns::rows(Model $scope): list<array{id: string, range: string, made: string, legs: int, flagged: int, google: bool}>`, `SourceRuns::optionLabel(array $row): string`, `SourceRuns::check(Model $scope, array $picked): list<array{run_id: string, label: string, sys_file_id: ?string}>`, `SourceRuns::sysError(ExportFile $file): ?string`, static `hasGoogle(ToolRun)`, `label(mixed $typed, int $index)`, `defaultLabel(int $index)`, constants `MAX_RUNS = 10`, `NO_POLYGONS = 'This SYS export has no Polygon table'`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TravelTuning/SourceRunsTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\TravelTuning\SourceRuns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create();
    $this->dataset = Dataset::factory()->for($this->environment)->create(['settings' => ['time_zone' => 'America/Regina']]);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 251]]);
    $this->actingAs($this->user);
});

function refusal(callable $call): UnusableRunInputException
{
    try {
        $call();
    } catch (UnusableRunInputException $e) {
        return $e;
    }
    throw new RuntimeException('Expected the pick to be refused.');
}

it('lists the finished plan travels runs of the scope, newest first, and says which have Google', function () {
    $older = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', 'UTC')]);
    $newer = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-14 04:35:00', 'UTC'), 'summary' => ['leg_count' => 3369, 'review_count' => 10, 'first_date' => '2026-10-05', 'last_date' => '2026-10-09', 'google' => null]]);
    planTravelsRun($this->dataset, ['status' => ToolRunStatus::Failed]);
    planTravelsRun(Dataset::factory()->for($this->environment)->create());
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit]);

    expect(app(SourceRuns::class)->rows($this->dataset))->toBe([
        ['id' => $newer->id, 'range' => '5 Oct 2026 to 9 Oct 2026', 'made' => '13 Oct 2026 22:35', 'legs' => 3369, 'flagged' => 10, 'google' => false],
        ['id' => $older->id, 'range' => '5 Oct 2026', 'made' => '12 Oct 2026 22:35', 'legs' => 4, 'flagged' => 2, 'google' => true],
    ]);
});

it('checks a pick and fills the default labels from the final order', function () {
    $base = planTravelsRun($this->dataset);
    $middle = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset);

    $checked = app(SourceRuns::class)->check($this->dataset, [
        ['run_id' => $base->id, 'label' => '', 'sys_file_id' => null],
        ['run_id' => $middle->id, 'label' => "  Grid\n  v5 ", 'sys_file_id' => ''],
        ['run_id' => $final->id, 'label' => null, 'sys_file_id' => $this->sys->id],
    ]);

    expect($checked)->toBe([
        ['run_id' => $base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $middle->id, 'label' => 'Grid v5', 'sys_file_id' => null],
        ['run_id' => $final->id, 'label' => 'Run 2', 'sys_file_id' => $this->sys->id],
    ])->and(SourceRuns::label(str_repeat('x', 80), 1))->toHaveLength(60);
});

it('refuses fewer than two runs, more than ten, a blank row and the same run twice', function () {
    $run = planTravelsRun($this->dataset);
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id]]))->getMessage())
        ->toBe('Pick at least two plan travels runs: the baseline first, then each tuned run.')
        ->and(refusal(fn () => $sources->check($this->dataset, array_fill(0, 11, ['run_id' => $run->id])))->getMessage())
        ->toBe('Pick at most 10 plan travels runs for one report.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id], ['run_id' => '']]))->row)->toBe(2)
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id], ['run_id' => $run->id]]))->getMessage())
        ->toBe('Row 2 is the same plan travels run as row 1. Pick each run once.');
});

it('refuses a run without Google, a failed run, and one from another dataset', function () {
    $base = planTravelsRun($this->dataset);
    $noGoogle = planTravelsRun($this->dataset, ['summary' => ['leg_count' => 4, 'review_count' => 0, 'google' => null]]);
    $failed = planTravelsRun($this->dataset, ['status' => ToolRunStatus::Failed]);
    $elsewhere = planTravelsRun(Dataset::factory()->for($this->environment)->create());
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $noGoogle->id]]))->getMessage())
        ->toBe('Row 2: that plan travels run has no Google columns. Run plan travels again with Google on.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $failed->id], ['run_id' => $base->id]]))->getMessage())
        ->toBe('Row 1: that plan travels run is no longer available here. Choose another.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $elsewhere->id]]))->row)->toBe(2);
});

it('refuses a SYS export of another environment and one with no Polygon table', function () {
    $base = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset);
    $otherSys = ExportFile::factory()->for(Environment::factory()->for($this->user)->create(), 'scope')->sys()->create(['summary' => ['polygon_count' => 3]]);
    $emptySys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 0]]);
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $final->id, 'sys_file_id' => $otherSys->id]]))->getMessage())
        ->toBe('Row 2: the SYS export you chose is no longer available. Choose or upload another.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $final->id, 'sys_file_id' => $emptySys->id]]))->getMessage())
        ->toBe('This SYS export has no Polygon table (row 2). Choose another, or None.');
});

it('takes the user\'s own Unfiled runs and SYS exports, never another user\'s', function () {
    $mine = planTravelsRun($this->user);
    $mineToo = planTravelsRun($this->user);
    $mySys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['summary' => ['polygon_count' => 1]]);
    $theirs = planTravelsRun(User::factory()->create());
    $sources = app(SourceRuns::class);

    expect($sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $mineToo->id, 'sys_file_id' => $mySys->id]])[1]['sys_file_id'])->toBe($mySys->id)
        ->and(refusal(fn () => $sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $theirs->id]]))->row)->toBe(2)
        ->and(refusal(fn () => $sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $mineToo->id, 'sys_file_id' => $this->sys->id]]))->row)->toBe(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/SourceRunsTest.php`
Expected: FAIL (class `SourceRuns` not found).

- [ ] **Step 3: Implement**

`app/Tools/TravelTuning/SourceRuns.php`:

```php
<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\UnusableRunInputException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The Plan travels runs a Travel tuning report can be built from: the finished runs of one scope (a dataset, or a
 * user's Unfiled runs), newest first. Only a run checked with Google has the Google columns the report compares
 * against: its summary's `google` is set (a run without the check, or whose Google step failed, keeps it null).
 * The queries skip the global scope and name the scope themselves, so a job can use them too.
 */
class SourceRuns
{
    public const MAX_RUNS = 10;

    public const NO_POLYGONS = 'This SYS export has no Polygon table';

    public function __construct(private RunInputPicker $picker, private SysPolygons $polygons) {}

    /** @return Collection<int, ToolRun> without their results */
    public function finished(Model $scope): Collection
    {
        return ToolRun::query()->withoutGlobalScopes()->withoutResult()
            ->where('scope_type', $scope->getMorphClass())
            ->where('scope_id', (string) $scope->getKey())
            ->where('tool', ToolId::PlanTravels)
            ->where('status', ToolRunStatus::Succeeded)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public static function hasGoogle(ToolRun $run): bool
    {
        return is_array($run->summary['google'] ?? null);
    }

    /** @return list<array{id: string, range: string, made: string, legs: int, flagged: int, google: bool}> */
    public function rows(Model $scope): array
    {
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');

        return $this->finished($scope)->map(static fn (ToolRun $run): array => [
            'id' => (string) $run->id,
            'range' => self::range($run),
            'made' => $run->created_at->copy()->setTimezone($timeZone)->format('j M Y H:i'),
            'legs' => (int) ($run->summary['leg_count'] ?? 0),
            'flagged' => (int) ($run->summary['review_count'] ?? 0),
            'google' => self::hasGoogle($run),
        ])->values()->all();
    }

    /** "5 Oct 2026 to 9 Oct 2026, made 13 Oct 2026 22:35, 3,369 legs, 10 flagged" */
    public static function optionLabel(array $row): string
    {
        return "{$row['range']}, made {$row['made']}, ".number_format($row['legs']).' legs, '.number_format($row['flagged']).' flagged'
            .($row['google'] ? '' : ' (Run plan travels again with Google on)');
    }

    /**
     * @param  list<array<string, mixed>>  $picked  rows in order: run_id, label, sys_file_id
     * @return list<array{run_id: string, label: string, sys_file_id: ?string}>
     *
     * @throws UnusableRunInputException
     */
    public function check(Model $scope, array $picked): array
    {
        $picked = array_values(array_filter($picked, 'is_array'));
        if (count($picked) < 2) {
            throw new UnusableRunInputException(0, 'Pick at least two plan travels runs: the baseline first, then each tuned run.');
        }
        if (count($picked) > self::MAX_RUNS) {
            throw new UnusableRunInputException(0, 'Pick at most '.self::MAX_RUNS.' plan travels runs for one report.');
        }

        $runs = $this->finished($scope)->keyBy(static fn (ToolRun $run): string => (string) $run->id);
        $sysOptions = $this->picker->optionsForKind($scope, ExportKind::Sys);
        $checked = [];
        $seen = [];

        foreach ($picked as $index => $row) {
            $number = $index + 1;
            $runId = trim((string) ($row['run_id'] ?? ''));
            if ($runId === '') {
                throw new UnusableRunInputException($number, "Row {$number}: choose a plan travels run.");
            }
            $run = $runs->get($runId);
            if ($run === null) {
                throw new UnusableRunInputException($number, "Row {$number}: that plan travels run is no longer available here. Choose another.");
            }
            if (! self::hasGoogle($run)) {
                throw new UnusableRunInputException($number, "Row {$number}: that plan travels run has no Google columns. Run plan travels again with Google on.");
            }
            if (isset($seen[$runId])) {
                throw new UnusableRunInputException($number, "Row {$number} is the same plan travels run as row {$seen[$runId]}. Pick each run once.");
            }
            $seen[$runId] = $number;

            $sysId = trim((string) ($row['sys_file_id'] ?? ''));
            if ($sysId !== '') {
                if (! array_key_exists($sysId, $sysOptions)) {
                    throw new UnusableRunInputException($number, "Row {$number}: the SYS export you chose is no longer available. Choose or upload another.");
                }
                $problem = $this->sysError(ExportFile::query()->withoutGlobalScopes()->findOrFail($sysId));
                if ($problem !== null) {
                    throw new UnusableRunInputException($number, "{$problem} (row {$number}). Choose another, or None.");
                }
            }

            $checked[] = ['run_id' => $runId, 'label' => self::label($row['label'] ?? null, $index), 'sys_file_id' => $sysId === '' ? null : $sysId];
        }

        return $checked;
    }

    /** Why a SYS export cannot give a run its polygons, or null when it can. */
    public function sysError(ExportFile $file): ?string
    {
        try {
            return $this->polygons->count($file) === 0 ? self::NO_POLYGONS : null;
        } catch (RuntimeException $exception) {
            return 'This SYS export could not be read: '.$exception->getMessage();
        }
    }

    /** What the user typed (one line, at most 60 characters), else Baseline, Run 1, Run 2 (the script's defaults). */
    public static function label(mixed $typed, int $index): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) $typed));

        return $label === '' ? self::defaultLabel($index) : mb_substr($label, 0, 60);
    }

    public static function defaultLabel(int $index): string
    {
        return $index === 0 ? 'Baseline' : "Run {$index}";
    }

    private static function range(ToolRun $run): string
    {
        $format = static fn (mixed $date): ?string => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? Carbon::parse($date)->format('j M Y') : null;
        $first = $format($run->summary['first_date'] ?? null);
        $last = $format($run->summary['last_date'] ?? null);

        return match (true) {
            $first === null => 'Dates not known',
            $last === null || $last === $first => $first,
            default => "{$first} to {$last}",
        };
    }
}
```

The first test's times: `created_at` 2026-10-13 04:35 UTC is 12 Oct 22:35 in America/Regina (UTC-6).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/SourceRunsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/TravelTuning/SourceRuns.php tests/Feature/TravelTuning/SourceRunsTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): the plan travels runs a report can use, and the check of a pick

Finished runs of the same scope with Google columns, 2 to 10, each once, labels defaulting to Baseline, Run 1, Run 2; a SYS export must be the scope's and hold a Polygon table.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 4: The script command and the stub

**Files:**
- Modify: `app/Tools/LoadAudit/PsoToolsCheckout.php`, `config/tools.php`
- Create: `app/Tools/TravelTuning/TravelTuningCommand.php`
- Create: `tests/Fixtures/pso-tools/travel/travel_tuning.py`, `tests/Fixtures/pso-tools/travel/travel_tuning_template.html`
- Test: `tests/Feature/TravelTuning/TravelTuningCommandTest.php`

**Interfaces:**
- Produces: `PsoToolsCheckout::travelTuningScript(): string`, `PsoToolsCheckout::travelTuningTemplate(): string`; `config('tools.travel_tuning.timeout_seconds')` (default 600).
- Produces: `TravelTuningCommand::build(PsoToolsCheckout $checkout, string $workDir, array $options, string $generated, bool $withNotes): list<string>`; constants `MANIFEST = 'manifest.csv'`, `NOTES = 'notes.md'`, `OUT_HTML = 'travel_tuning.html'`, `OUT_JSON = 'travel_tuning.json'`, `TILES` (value => label), `NUMBERS` (option key => [flag, default]).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TravelTuning/TravelTuningCommandTest.php`:

```php
<?php

use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\TravelTuning\TravelTuningCommand;

beforeEach(function () {
    $this->root = base_path('tests/Fixtures/pso-tools');
    config(['tools.pso_tools_path' => $this->root, 'tools.python' => 'python3']);
});

it('passes the manifest, outputs, template, generated time, options and defaults', function () {
    $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), '/work/', ['title' => 'Travel Tuning Report', 'client' => 'SaskTel', 'dataset' => 'North', 'region_label' => 'Territory'], '2026-10-18 09:30', false);

    expect($argv)->toBe([
        'python3', $this->root.'/travel/travel_tuning.py',
        '--manifest', '/work/manifest.csv', '--out', '/work/travel_tuning.html', '--json', '/work/travel_tuning.json',
        '--template', $this->root.'/travel/travel_tuning_template.html', '--generated', '2026-10-18 09:30',
        '--title=Travel Tuning Report', '--client=SaskTel', '--dataset=North', '--region-label=Territory',
        '--review-minutes=20', '--small-minutes=5', '--model-tol-min=1', '--model-tol-pct=5', '--long-leg-km=300',
        '--tiles=esri-gray',
    ]);
});

it('keeps text that starts with a dash, folds newlines, drops blanks and falls back on bad numbers and tiles', function () {
    $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), '/work', [
        'title' => "-draft\nround 2", 'client' => '  ', 'review_minutes' => '12.5', 'small_minutes' => '-1', 'model_tol_pct' => 'lots', 'tiles' => 'satellite',
    ], '2026-10-18 09:30', true);

    expect($argv)->toContain('--title=-draft round 2', '--review-minutes=12.5', '--small-minutes=5', '--model-tol-pct=5', '--tiles=esri-gray', '--notes', '/work/notes.md')
        ->and(implode(' ', $argv))->not->toContain('--client')->not->toContain('--offline')->not->toContain('--review-csv');
});

it('says the checkout is not set up when the script is missing', function () {
    config(['tools.pso_tools_path' => sys_get_temp_dir()]);

    expect(fn () => PsoToolsCheckout::fromConfig()->travelTuningScript())
        ->toThrow(ToolSetupException::class, 'travel/travel_tuning.py is missing');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningCommandTest.php`
Expected: FAIL (class `TravelTuningCommand` not found).

- [ ] **Step 3: Implement**

`app/Tools/LoadAudit/PsoToolsCheckout.php`, after `planTravelsTemplate()`:

```php
    public function travelTuningScript(): string
    {
        return $this->file('travel/travel_tuning.py');
    }

    public function travelTuningTemplate(): string
    {
        return $this->file('travel/travel_tuning_template.html');
    }
```

`config/tools.php`, after the `plan_travels` block:

```php
    'travel_tuning' => [
        // One script run over the picked plan travels CSVs and polygon files; the 2026-10-13 SaskTel pair takes seconds.
        'timeout_seconds' => (int) env('TOOLS_TRAVEL_TUNING_TIMEOUT_SECONDS', 600),
    ],
```

`app/Tools/TravelTuning/TravelTuningCommand.php`:

```php
<?php

namespace App\Tools\TravelTuning;

use App\Tools\LoadAudit\PsoToolsCheckout;

/**
 * The argument list for travel_tuning.py. Pure: no process, no filesystem. Text options go as --flag=value so a value
 * that starts with a dash is not read as a flag; the web never passes --offline or --review-csv (spec 4.2).
 */
class TravelTuningCommand
{
    public const MANIFEST = 'manifest.csv';

    public const NOTES = 'notes.md';

    public const OUT_HTML = 'travel_tuning.html';

    public const OUT_JSON = 'travel_tuning.json';

    public const TILES = ['esri-gray' => 'Esri grey', 'esri' => 'Esri streets', 'osm' => 'OpenStreetMap', 'none' => 'No map tiles'];

    /** option key => [flag, the script's default] */
    public const NUMBERS = [
        'review_minutes' => ['--review-minutes', 20.0],
        'small_minutes' => ['--small-minutes', 5.0],
        'model_tol_min' => ['--model-tol-min', 1.0],
        'model_tol_pct' => ['--model-tol-pct', 5.0],
        'long_leg_km' => ['--long-leg-km', 300.0],
    ];

    private const TEXT = ['title' => '--title', 'client' => '--client', 'dataset' => '--dataset', 'region_label' => '--region-label'];

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $options, string $generated, bool $withNotes): array
    {
        $dir = rtrim($workDir, '/');
        $argv = [
            $checkout->python(), $checkout->travelTuningScript(),
            '--manifest', $dir.'/'.self::MANIFEST, '--out', $dir.'/'.self::OUT_HTML, '--json', $dir.'/'.self::OUT_JSON,
            '--template', $checkout->travelTuningTemplate(), '--generated', $generated,
        ];

        foreach (self::TEXT as $key => $flag) {
            $text = self::text($options[$key] ?? null);
            if ($text !== '') {
                $argv[] = $flag.'='.$text;
            }
        }
        foreach (self::NUMBERS as $key => [$flag, $default]) {
            $value = $options[$key] ?? null;
            $argv[] = $flag.'='.self::number(is_numeric($value) && (float) $value >= 0 ? (float) $value : $default);
        }
        $tiles = (string) ($options['tiles'] ?? '');
        $argv[] = '--tiles='.(array_key_exists($tiles, self::TILES) ? $tiles : 'esri-gray');

        if ($withNotes) {
            array_push($argv, '--notes', $dir.'/'.self::NOTES);
        }

        return $argv;
    }

    /** One line, at most 120 characters. */
    private static function text(mixed $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '')), 0, 120);
    }

    /** 20.0 is "20", 12.5 is "12.5". */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
```

`tests/Fixtures/pso-tools/travel/travel_tuning_template.html`:

```html
<!doctype html><html><head><title>__TITLE__</title></head><body><h1>Fake travel tuning page</h1><script type="application/json" id="data">__DATA__</script></body></html>
```

`tests/Fixtures/pso-tools/travel/travel_tuning.py`:

```python
"""Stand-in for pso-tools/travel/travel_tuning.py in tests. Same arguments and output files; computes nothing.

FAKE_TUNING_MODE: ok (default) | fail (exit 1 with a Stopped during line) | silent (exit 0, writes nothing).
FAKE_TUNING_ARGS_FILE: append one JSON line per call: argv, cwd, the manifest rows as read (label, run CSV name, its
header line, polygon file name and how many <Polygon> rows it holds) and the notes text.
Numbers: the baseline has 75 flagged legs and 138.2 hours of error, the final run 10 and 98.2 hours, a run between
them 40 and 116.7; 44 fixed, 0 newly broken, 2,112 shared legs. One warning, labels and counts only, as the script's.
"""
import csv, json, os, sys


def arg(name, default=None):
    for item in sys.argv:
        if item.startswith(name + '='):
            return item[len(name) + 1:]
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


mode = os.environ.get('FAKE_TUNING_MODE', 'ok')
manifest = arg('--manifest')
base = os.path.dirname(manifest)
with open(manifest, encoding='utf-8-sig', newline='') as fh:
    rows = list(csv.DictReader(fh))
seen = []
for r in rows:
    with open(os.path.join(base, r['RunCsv']), encoding='utf-8-sig') as fh:
        header = fh.readline().strip()
    poly = (r.get('PolygonXml') or '').strip()
    entry = {'label': r['Label'], 'run_csv': r['RunCsv'], 'header': header, 'polygon_xml': poly}
    if poly:
        with open(os.path.join(base, poly), encoding='utf-8') as fh:
            entry['polygon_rows'] = fh.read().count('<Polygon>')
    seen.append(entry)
notes = arg('--notes')
record = {'argv': sys.argv[1:], 'cwd': os.getcwd(), 'rows': seen,
          'notes': open(notes, encoding='utf-8').read() if notes else None}
log = os.environ.get('FAKE_TUNING_ARGS_FILE')
if log:
    with open(log, 'a') as fh:
        fh.write(json.dumps(record) + '\n')

if mode == 'fail':
    sys.stderr.write('Stopped during: reading Run 1: Run 1 (run-2.csv) has no Google columns. Run plan travels again '
                     'for this run with the Google check on.\n')
    sys.exit(1)
if mode == 'silent':
    print('summary: nothing written')
    sys.exit(0)

labels = [r['Label'] for r in rows]
middle = len(rows) - 2
flagged = [75] + [40] * middle + [10]
total_h = [138.2] + [116.7] * middle + [98.2]
timeline = [{'label': l, 'flagged': f, 'total_h': h, 'total_min': round(h * 60)} for l, f, h in zip(labels, flagged, total_h)]
warnings = ['Legs common to every run are 55% of the smallest run.']
data = {'meta': {'title': arg('--title', 'Travel Tuning Report'), 'generated': arg('--generated'), 'shared': 2112,
                 'region': arg('--region-label', 'Region'), 'tiles': arg('--tiles', 'esri-gray'), 'warnings': warnings},
        'timeline': timeline, 'acc': {'pair': {'shared': 2112, 'fixed': 44, 'newly_broken': 0, 'still': 10}}}
with open(arg('--template'), encoding='utf-8') as fh:
    template = fh.read()
page = template.replace('__TITLE__', data['meta']['title'], 1).replace('__DATA__', json.dumps(data).replace('<', '\\u003c'), 1)
with open(arg('--out'), 'w', encoding='utf-8') as fh:
    fh.write(page)
if arg('--json'):
    with open(arg('--json'), 'w', encoding='utf-8') as fh:
        json.dump(data, fh)
for w in warnings:
    sys.stderr.write('warning: %s\n' % w)
print('summary: %s to %s: flagged 75 to 10, total error 8,292 to 5,892 min, 44 fixed, 0 newly broken' % (labels[0], labels[-1]))
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/LoadAudit/PsoToolsCheckout.php config/tools.php app/Tools/TravelTuning/TravelTuningCommand.php tests/Fixtures/pso-tools/travel/travel_tuning.py tests/Fixtures/pso-tools/travel/travel_tuning_template.html tests/Feature/TravelTuning/TravelTuningCommandTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): the travel_tuning.py command and a test stand-in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 5: TravelTuningTool

**Files:**
- Create: `app/Tools/TravelTuning/TravelTuningTool.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the tool)
- Test: `tests/Feature/TravelTuning/TravelTuningToolTest.php`

**Interfaces:**
- Consumes: `SourceRuns` (Task 3), `SysPolygons::localXml()` (Task 2), `TravelTuningCommand::build()` (Task 4), `ScriptRun::execute()`, `ExportStorage::scratchDirectoryFor()`.
- Produces: `TravelTuningTool implements TakesRunInputs, Tool, WizardOnly`; `optionFields()` names `options.title`, `options.client`, `options.dataset`, `options.review_minutes`, `options.small_minutes`, `options.long_leg_km`, `options.model_tol_min`, `options.model_tol_pct`, `options.region_label`, `options.tiles`, `options.notes`; `run()` returns `result = ['html' => string]`, summary keys `runs, baseline_flagged, final_flagged, baseline_error_hours, final_error_hours, fixed, newly_broken, shared_legs, first_date, line`; static `summary(array $data, ?string $firstDate): array`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TravelTuning/TravelTuningToolTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\TravelTuning\TravelTuningTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

function tuningCalls(string $file): array
{
    return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

function tuningEnv(string $name, ?string $value): void
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
    Storage::fake('r2');
    config([
        'exports.disk' => 'r2', 'exports.scratch_path' => realpath(sys_get_temp_dir()).'/exports-tuning-'.uniqid(),
        'tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3',
    ]);
    $this->argsFile = sys_get_temp_dir().'/tuning-calls-'.uniqid().'.jsonl';
    tuningEnv('FAKE_TUNING_ARGS_FILE', $this->argsFile);
    $this->travelTo(Carbon::parse('2026-10-18 15:30:00', 'UTC'));
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel']]);
    $this->base = planTravelsRun($this->dataset);
    $this->final = planTravelsRun($this->dataset, csv: tuningCsv([960, 1560, 620, 880]));
    $this->sys = storedSys($this->environment, polygonSysXml(2), zipped: true);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
    @unlink($this->argsFile);
    tuningEnv('FAKE_TUNING_ARGS_FILE', null);
    tuningEnv('FAKE_TUNING_MODE', null);
});

function tuningRun(Model $scope, array $runs, array $options = []): ToolRun
{
    return ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        'inputs' => ['files' => [], 'options' => ['title' => 'Travel Tuning Report', 'client' => 'SaskTel', 'dataset' => 'NORTH', ...$options], 'runs' => $runs],
    ]);
}

function runTuningJob(ToolRun $run): ToolRun
{
    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    return $run->refresh();
}

it('is registered as a wizard-only tool that takes runs', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::TravelTuning);

    expect($tool)->toBeInstanceOf(TravelTuningTool::class)
        ->and($tool->requiredKinds())->toBe([])
        ->and($tool->optionalKinds())->toBe([])
        ->and($tool->needs())->toBe('Needs two or more Plan travels runs checked with Google');
});

it('writes the runs, the polygons and the manifest, runs the script and keeps the page with counts only', function () {
    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Grid, "v6"', 'sys_file_id' => $this->sys->id],
    ]));

    $call = tuningCalls($this->argsFile)[0];
    $scratch = config('exports.scratch_path').'/'.$run->id;
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message)
        ->and($call['cwd'])->toBe($scratch)
        ->and($call['argv'])->toContain('--manifest', $scratch.'/manifest.csv', '--generated', '2026-10-18 09:30', '--client=SaskTel', '--tiles=esri-gray')
        ->and(implode(' ', $call['argv']))->not->toContain('--notes')
        ->and($call['rows'])->toBe([
            ['label' => 'Baseline', 'run_csv' => 'run-1.csv', 'header' => strtok(tuningCsv(), "\n"), 'polygon_xml' => ''],
            ['label' => 'Grid, "v6"', 'run_csv' => 'run-2.csv', 'header' => strtok(tuningCsv(), "\n"), 'polygon_xml' => 'polygons-2.xml', 'polygon_rows' => 2],
        ])
        ->and($run->result)->toHaveKeys(['html'])->toHaveCount(1)
        ->and($run->result['html'])->toContain('Fake travel tuning page')
        ->and($run->warnings)->toBe(['Legs common to every run are 55% of the smallest run.'])
        ->and($run->summary)->toBe([
            'runs' => 2, 'baseline_flagged' => 75, 'final_flagged' => 10, 'baseline_error_hours' => 138.2, 'final_error_hours' => 98.2,
            'fixed' => 44, 'newly_broken' => 0, 'shared_legs' => 2112, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs',
        ])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([app(ExportStorage::class)->objectKey($this->sys)]);
});

it('writes the notes for the script when there are some', function () {
    runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ], ['notes' => "## Round 2\n- **Corridors** tightened"]));

    $call = tuningCalls($this->argsFile)[0];
    expect($call['argv'])->toContain('--notes')
        ->and($call['notes'])->toBe("## Round 2\n- **Corridors** tightened\n");
});

it('fails in plain words when the script stops, and deletes the SYS copy at once', function () {
    tuningEnv('FAKE_TUNING_MODE', 'fail');
    $run = tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]);
    $scratch = app(ExportStorage::class)->scratchDirectoryFor($run->id);
    $context = new ToolContext($run, $this->dataset, $this->dataset->settings, 'America/Regina');

    expect(fn () => app(TravelTuningTool::class)->run($context, [], $run->inputs['options']))
        ->toThrow(RuntimeException::class, 'The travel tuning report stopped while reading Run 1: Run 1 (run-2.csv) has no Google columns.');
    expect(glob($scratch.'/polygons-*'))->toBe([])
        ->and(is_file($scratch.'/run-2.csv'))->toBeTrue();
});

it('fails before the script when a source run has gone, naming the row', function () {
    $run = tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ]);
    $this->final->delete();

    $run = runTuningJob($run);

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('Row 2: the plan travels run is no longer available, so the report cannot be built. Start again with runs that are still listed.')
        ->and(tuningCalls($this->argsFile))->toBe([]);
});

it('fails before the script when the SYS export has expired', function () {
    $this->sys->update(['expires_at' => now()->subMinute()]);

    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]));

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('Row 2: the SYS export chosen for the polygons is no longer available (exports are kept for 12 hours). Upload it again and start a new run.')
        ->and(tuningCalls($this->argsFile))->toBe([]);
});

it('runs Unfiled on the user\'s own runs and SYS export, and refuses an environment\'s SYS there', function () {
    $mine = planTravelsRun($this->user);
    $mineToo = planTravelsRun($this->user, csv: tuningCsv([960, 1560, 620, 880]));
    $mySys = storedSys($this->user, polygonSysXml(1));

    $ok = runTuningJob(tuningRun($this->user, [
        ['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $mineToo->id, 'label' => 'Run 1', 'sys_file_id' => $mySys->id],
    ], ['time_zone' => 'America/Toronto']));
    $refused = runTuningJob(tuningRun($this->user, [
        ['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $mineToo->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]));

    expect($ok->status)->toBe(ToolRunStatus::Succeeded, (string) $ok->error_message)
        ->and(tuningCalls($this->argsFile)[0]['argv'])->toContain('--generated', '2026-10-18 11:30')
        ->and($refused->status)->toBe(ToolRunStatus::Failed)
        ->and($refused->error_message)->toBe("Row 2: the SYS export chosen for the polygons does not belong to this run's environment or unfiled files.");
});

it('says so when the script finishes without a page', function () {
    tuningEnv('FAKE_TUNING_MODE', 'silent');

    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ]));

    expect($run->error_message)->toBe('The travel tuning report finished but wrote no page.');
});
```

The scratch path is built on `realpath(sys_get_temp_dir())` because macOS's temp folder is a symlink and Python reports the resolved `cwd`. Created times in these tests are given in UTC on purpose: the app zone is America/Toronto.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningToolTest.php`
Expected: FAIL (`No implementation registered for Travel tuning.`).

- [ ] **Step 3: Implement**

`app/Tools/TravelTuning/TravelTuningTool.php`:

```php
<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\Contracts\TakesRunInputs;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\Contracts\WizardOnly;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Runs pso-tools/travel/travel_tuning.py on two or more Plan travels runs of one scope (baseline first), each with
 * an optional SYS export for its polygons, and keeps the page. The tool takes no export files: its inputs are
 * inputs['runs'], checked by SourceRuns when the run starts and again here. Each source run's stored CSV and an
 * XML copy of each SYS export go into the run's scratch folder with a manifest; the SYS copies are deleted as soon
 * as the script ends (they hold live credentials). The page outlives the runs and exports it was built from.
 */
class TravelTuningTool implements TakesRunInputs, Tool, WizardOnly
{
    public function __construct(private SourceRuns $sourceRuns, private SysPolygons $polygons, private ExportStorage $storage) {}

    public static function id(): ToolId
    {
        return ToolId::TravelTuning;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function needs(): string
    {
        return 'Needs two or more Plan travels runs checked with Google';
    }

    public function checkRunInputs(Model $scope, array $picked): array
    {
        return $this->sourceRuns->check($scope, $picked);
    }

    public function optionFields(): array
    {
        return [
            TextInput::make('options.title')->label('Report title')->default('Travel Tuning Report')->maxLength(120)->required(),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('options.client')->label('Client')->helperText('Shown in the page header. Filled in from the dataset settings.')->maxLength(120),
                TextInput::make('options.dataset')->label('Dataset name')->maxLength(120),
            ]),
            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('options.review_minutes')->label('Review threshold (minutes)')->helperText('A leg is flagged when PSO and Google differ by at least this much.')->numeric()->minValue(0)->default(20)->required(),
                TextInput::make('options.small_minutes')->label('Small error (minutes)')->helperText('Errors under this count as close enough.')->numeric()->minValue(0)->default(5)->required(),
                TextInput::make('options.long_leg_km')->label('Long leg (km)')->helperText('Legs at least this long are left off the map.')->numeric()->minValue(0)->default(300)->required(),
                TextInput::make('options.model_tol_min')->label('Model tolerance (minutes)')->helperText('How close a leg must move to the polygon prediction to count as predicted.')->numeric()->minValue(0)->default(1)->required(),
                TextInput::make('options.model_tol_pct')->label('Model tolerance (%)')->numeric()->minValue(0)->default(5)->required(),
                TextInput::make('options.region_label')->label('Region label')->helperText('What the page calls a region, as in "Region summary". Filled in from the dataset\'s Region label setting, else Region.')->default('Region')->maxLength(20)->required(),
            ]),
            Select::make('options.tiles')->label('Map tiles')->options(TravelTuningCommand::TILES)->default('esri-gray')->native(false)->required(),
            Textarea::make('options.notes')->label('Consultant notes')
                ->helperText('Markdown: headings, lists, tables, bold, italics, code and links. Shown on the page under Consultant notes. Stored with the run.')
                ->rows(6)->maxLength(20000),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $rows = array_values(array_filter((array) ($context->run->inputs['runs'] ?? []), 'is_array'));
        if (count($rows) < 2) {
            throw new RuntimeException('This run has fewer than two plan travels runs to compare.');
        }

        $checkout = PsoToolsCheckout::fromConfig();
        // The folder RunToolJob made for this run (and removes afterwards); this tool reads no export files of its own.
        $workDir = $this->storage->scratchDirectoryFor((string) $context->run->id);
        $sysCopies = [];
        $firstDate = null;

        try {
            $context->reportProgress('Gathering the runs', 0, null, 'gather');
            $manifest = [];
            foreach ($rows as $index => $row) {
                $number = $index + 1;
                $source = $this->sourceRun($context, (string) ($row['run_id'] ?? ''), $number);
                if ($index === 0) {
                    $firstDate = is_string($source->summary['first_date'] ?? null) ? $source->summary['first_date'] : null;
                }
                $csvName = "run-{$number}.csv";
                if (file_put_contents("{$workDir}/{$csvName}", $source->result['csv']) === false) {
                    throw new RuntimeException("Row {$number}: the plan travels CSV could not be written for the report.");
                }
                unset($source);

                $polygonName = '';
                if (filled($row['sys_file_id'] ?? null)) {
                    $copy = $this->sysCopy($context, (string) $row['sys_file_id'], $workDir, $number);
                    $sysCopies[] = $copy;
                    $polygonName = basename($copy);
                }
                $manifest[] = [SourceRuns::label($row['label'] ?? null, $index), $csvName, $polygonName];
            }
            $this->writeManifest($workDir, $manifest);

            $notes = trim((string) ($options['notes'] ?? ''));
            if ($notes !== '' && file_put_contents($workDir.'/'.TravelTuningCommand::NOTES, $notes."\n") === false) {
                throw new RuntimeException('The notes could not be written for the report.');
            }

            $generated = Carbon::now($context->timeZone)->format('Y-m-d H:i');
            $argv = TravelTuningCommand::build($checkout, $workDir, $options, $generated, $notes !== '');
            $context->reportProgress('Building the report', 20, null, 'build');
            ScriptRun::execute($context, $workDir, $argv, (int) config('tools.travel_tuning.timeout_seconds', 600), 'The travel tuning report');
        } finally {
            foreach ($sysCopies as $copy) {
                @unlink($copy);
            }
        }

        $html = @file_get_contents($workDir.'/'.TravelTuningCommand::OUT_HTML);
        $json = @file_get_contents($workDir.'/'.TravelTuningCommand::OUT_JSON);
        $data = $json === false ? null : json_decode($json, true);
        if ($html === false || $html === '' || ! is_array($data)) {
            throw new RuntimeException('The travel tuning report finished but wrote no page.');
        }

        // The script's warnings name only labels and counts (spec 3.1), so they may be stored as they are.
        $warnings = [];
        foreach ((array) ($data['meta']['warnings'] ?? []) as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $warnings[] = trim($warning);
            }
        }

        return new ToolResult(result: ['html' => $html], warnings: $warnings, summary: self::summary($data, $firstDate));
    }

    /**
     * Counts only (no labels, no ids), from the report data: the headline the page shows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function summary(array $data, ?string $firstDate): array
    {
        $timeline = array_values(array_filter((array) ($data['timeline'] ?? []), 'is_array'));
        $first = $timeline[0] ?? [];
        $last = $timeline[count($timeline) - 1] ?? [];
        $pair = (array) ($data['acc']['pair'] ?? []);

        $summary = [
            'runs' => count($timeline),
            'baseline_flagged' => (int) ($first['flagged'] ?? 0),
            'final_flagged' => (int) ($last['flagged'] ?? 0),
            'baseline_error_hours' => (float) ($first['total_h'] ?? 0),
            'final_error_hours' => (float) ($last['total_h'] ?? 0),
            'fixed' => (int) ($pair['fixed'] ?? 0),
            'newly_broken' => (int) ($pair['newly_broken'] ?? 0),
            'shared_legs' => (int) ($data['meta']['shared'] ?? 0),
            'first_date' => $firstDate,
        ];
        $summary['line'] = number_format($summary['runs']).' runs: flagged '.number_format($summary['baseline_flagged']).' to '.number_format($summary['final_flagged'])
            .', total error '.number_format($summary['baseline_error_hours'], 1).' to '.number_format($summary['final_error_hours'], 1).' hours, '
            .number_format($summary['fixed']).' fixed, '.number_format($summary['newly_broken']).' newly broken, '
            .number_format($summary['shared_legs']).' shared legs';

        return $summary;
    }

    private function sourceRun(ToolContext $context, string $runId, int $number): ToolRun
    {
        $source = $runId === '' ? null : ToolRun::query()->withoutGlobalScopes()->find($runId);
        $usable = $source !== null
            && $source->scope_type === $context->scope->getMorphClass()
            && (string) $source->scope_id === (string) $context->scope->getKey()
            && $source->tool === ToolId::PlanTravels
            && $source->status === ToolRunStatus::Succeeded;
        if (! $usable) {
            throw new RuntimeException("Row {$number}: the plan travels run is no longer available, so the report cannot be built. Start again with runs that are still listed.");
        }

        $csv = $source->result['csv'] ?? null;
        $header = is_string($csv) ? (string) strstr($csv."\n", "\n", true) : '';
        if (! str_contains($header, 'Google Duration (min)')) {
            throw new RuntimeException("Row {$number}: the plan travels run has no Google columns. Run plan travels again with Google on.");
        }

        return $source;
    }

    /** The SYS export as polygons-<row>.xml in the scratch folder. It must sit on the dataset's environment, or on the user for Unfiled. */
    private function sysCopy(ToolContext $context, string $fileId, string $workDir, int $number): string
    {
        $scope = $context->scope;
        [$ownerType, $ownerId] = $scope instanceof Dataset
            ? [(new Environment)->getMorphClass(), (string) $scope->environment_id]
            : [$scope->getMorphClass(), (string) $scope->getKey()];

        $file = ExportFile::query()->withoutGlobalScopes()->find($fileId);
        if ($file === null || $file->kind !== ExportKind::Sys || $file->scope_type !== $ownerType || (string) $file->scope_id !== $ownerId) {
            throw new RuntimeException("Row {$number}: the SYS export chosen for the polygons does not belong to this run's environment or unfiled files.");
        }
        if (! $file->isUsable()) {
            throw new RuntimeException("Row {$number}: the SYS export chosen for the polygons is no longer available (exports are kept for ".(int) config('exports.ttl_hours', 12).' hours). Upload it again and start a new run.');
        }

        return $this->polygons->localXml($file, $workDir, "polygons-{$number}");
    }

    /** @param list<array{0: string, 1: string, 2: string}> $rows label, run CSV, polygon file (relative to the manifest) */
    private function writeManifest(string $workDir, array $rows): void
    {
        $handle = @fopen($workDir.'/'.TravelTuningCommand::MANIFEST, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The travel tuning manifest could not be written.');
        }

        try {
            fputcsv($handle, ['Label', 'RunCsv', 'PolygonXml'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }
}
```

`app/Providers/AppServiceProvider.php`: add `use App\Tools\TravelTuning\TravelTuningTool;` and, after the Trim export line:

```php
            $registry->register(ToolId::TravelTuning, TravelTuningTool::class);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningToolTest.php tests/Feature/RunToolJobTest.php tests/Feature/RunsTabTest.php`
Expected: PASS. The Runs tab offers no Travel tuning action (WizardOnly).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/TravelTuning/TravelTuningTool.php app/Providers/AppServiceProvider.php tests/Feature/TravelTuning/TravelTuningToolTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): the tool runs travel_tuning.py on the picked plan travels runs

Stores the page encrypted and counts only in the summary; SYS copies live only in the scratch folder and are deleted when the script ends.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 5A: The Region label dataset setting

**Files:**
- Modify: `app/Support/DatasetSettings.php`, `app/Models/Dataset.php`
- Test: `tests/Unit/DatasetSettingsTest.php`

**Interfaces:**
- Produces: `DatasetSettings::$regionLabel` (`?string`), key `region_label` in `KEYS`, `fromArray()` and `toArray()`; a "Region label" input on the Dataset edit form. Consumed by Task 6's `prefillOptions()`.
- Ruling on the account file: `toAccountFile()` writes every non-null key under its own name, so `region_label` is written when set (as `supervisor_label` is) and `fromAccountFile()` reads it back with no alias. The existing test's `not->toHaveKeys([...])` list stays as it is: `region_label` is null there, so it is absent without being listed.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/DatasetSettingsTest.php`:

```php
it('keeps the region label, blank means none, and writes it to the account file when set', function () {
    expect(DatasetSettings::fromArray([])->regionLabel)->toBeNull()
        ->and(DatasetSettings::fromArray(['region_label' => ''])->regionLabel)->toBeNull();

    $settings = DatasetSettings::fromArray(['region_label' => 'Territory']);

    expect($settings->regionLabel)->toBe('Territory')
        ->and($settings->toArray()['region_label'])->toBe('Territory')
        ->and($settings->toAccountFile()['region_label'])->toBe('Territory')
        ->and(DatasetSettings::fromAccountFile($settings->toAccountFile())->toArray())->toBe($settings->toArray())
        ->and(DatasetSettings::fromArray([])->toAccountFile())->not->toHaveKey('region_label');
});
```

- [ ] **Step 2: Run it and see it fail**

```bash
php artisan test --compact tests/Unit/DatasetSettingsTest.php
```

Expected: FAIL (`Unknown dataset setting: region_label`).

- [ ] **Step 3: Add the setting**

In `app/Support/DatasetSettings.php`, change the end of `KEYS` so it lists `'supervisor_label', 'region_label',` before `'supervisor_label_plural'`:

```php
        'resource_type_names', 'attribute_labels', 'region_pivot', 'region_labels', 'supervisor_label', 'region_label',
        'supervisor_label_plural', 'notes', 'windows_time_zone', 'time_zone', 'ifs_time_zone',
```

In the constructor, after `public ?string $supervisorLabelPlural = null,` add:

```php
        public ?string $regionLabel = null,
```

In `fromArray()`, after the `supervisorLabelPlural:` line add:

```php
            regionLabel: self::stringOrNull($values['region_label'] ?? null),
```

In `toArray()`, after the `'supervisor_label_plural'` line add:

```php
            'region_label' => $this->regionLabel,
```

(Constructor arguments are named everywhere `new self(` is used; check with `grep -rn "new DatasetSettings(" app tests` and fix any positional call.)

- [ ] **Step 4: Add the form field**

In `app/Models/Dataset.php`, after the `settings.supervisor_label_plural` input (the one that follows `Supervisor label`), add:

```php
                    Forms\Components\TextInput::make('settings.region_label')->label('Region label')->helperText('What reports call a region, such as GB or Territory. Blank means Region.')->maxLength(20),
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Unit/DatasetSettingsTest.php tests/Feature
```

Expected: PASS (apart from the known failing `Tests\Feature\ExampleTest`).

- [ ] **Step 6: Commit**

```bash
git add app/Support/DatasetSettings.php app/Models/Dataset.php tests/Unit/DatasetSettingsTest.php
git commit -m "Dataset settings: a Region label that reports use instead of a hard coded word"
```

---

### Task 6: The wizard page

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php` (`chosenRuns()` hook, `start()`)
- Create: `app/Filament/Pages/Tools/TravelTuningEntry.php`
- Create: `resources/views/filament/pages/tools/travel-tuning-runs.blade.php`
- Modify: `app/Filament/Widgets/ToolCatalogue.php`
- Modify: `tests/Feature/NavigationGroupsTest.php`
- Test: `tests/Feature/TravelTuning/TravelTuningWizardTest.php`

**Interfaces:**
- Consumes: `SourceRuns::rows()`, `optionLabel()`, `sysError()`, `NO_POLYGONS`, `MAX_RUNS` (Task 3); `RunInputPicker::optionsForKind()` (Task 2); `StartToolRun::start(..., array $runs)` and `UnusableRunInputException` (Task 1); `TakesRunInputs::needs()`.
- Produces: `ToolWizardPage::chosenRuns(array $state): array` (protected, default `[]`); `TravelTuningEntry` at `tools/travel-tuning`; form state `data.runs` (uuid-keyed repeater items `run_id`, `label`, `sys_file_id`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TravelTuning/TravelTuningWizardTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\TravelTuningEntry;
use App\Filament\Widgets\ToolCatalogue;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel', 'dataset_display_name' => 'North', 'region_label' => 'Territory']]);
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    $this->actingAs($this->user);
    $this->base = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', 'UTC')]);
    $this->final = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-14 04:35:00', 'UTC')]);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'PSO_Sys_pass_1.xml', 'summary' => ['polygon_count' => 251]]);
});

function tuningRuns(array $rows): array
{
    $keyed = [];
    foreach ($rows as $index => $row) {
        $keyed["row{$index}"] = ['run_id' => null, 'label' => null, 'sys_file_id' => null, ...$row];
    }

    return $keyed;
}

it('is in the catalogue and the Analyse group', function () {
    expect(TravelTuningEntry::canAccess())->toBeTrue();
    Livewire::test(ToolCatalogue::class)->assertSee('Travel tuning')->assertSee('Needs two or more Plan travels runs checked with Google');
});

it('lists the dataset\'s finished plan travels runs and says which need Google', function () {
    planTravelsRun($this->dataset, ['summary' => ['leg_count' => 9, 'review_count' => 1, 'first_date' => '2026-09-01', 'last_date' => '2026-09-02', 'google' => null]]);
    planTravelsRun(Dataset::factory()->for($this->environment)->create(), ['summary' => ['leg_count' => 1, 'review_count' => 0, 'first_date' => '2026-08-01', 'last_date' => '2026-08-01', 'google' => []]]);

    Livewire::test(TravelTuningEntry::class)
        ->assertSee('5 Oct 2026')
        ->assertSee('12 Oct 2026 22:35')
        ->assertSee('1 Sep 2026 to 2 Sep 2026')
        ->assertSee('Run plan travels again with Google on')
        ->assertDontSee('1 Aug 2026');
});

it('prefills the options from the dataset settings and the script defaults', function () {
    Livewire::test(TravelTuningEntry::class)->assertFormSet([
        'options.title' => 'Travel Tuning Report', 'options.client' => 'SaskTel', 'options.dataset' => 'North', 'options.region_label' => 'Territory',
        'options.tiles' => 'esri-gray', 'options.review_minutes' => 20, 'options.small_minutes' => 5, 'options.long_leg_km' => 300,
        'options.model_tol_min' => 1, 'options.model_tol_pct' => 5,
    ]);
});

it('prefills the region label with Region when the dataset sets none and for Unfiled', function () {
    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel']]);

    Livewire::test(TravelTuningEntry::class)->assertFormSet(['options.region_label' => 'Region']);
    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'unfiled'])->assertFormSet(['options.region_label' => 'Region']);
});

it('starts a run with the runs in order, the default labels and the chosen SYS', function () {
    Queue::fake();

    Livewire::test(TravelTuningEntry::class)
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id]]))
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::query()->where('tool', ToolId::TravelTuning)->sole();
    expect($run->inputs['runs'])->toBe([
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id],
    ])->and($run->inputs['options']['client'])->toBe('SaskTel')
        ->and($run->inputs['options'])->not->toHaveKey('time_zone');
    Queue::assertPushed(RunToolJob::class, 1);
});

it('clears a SYS export with no Polygon table as soon as it is picked', function () {
    $empty = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 0]]);

    Livewire::test(TravelTuningEntry::class)
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->final->id]]))
        ->set('data.runs.row1.sys_file_id', $empty->id)
        ->assertNotified('This SYS export has no Polygon table')
        ->assertSet('data.runs.row1.sys_file_id', null);
});

it('refuses the same run twice and sends the user back to Inputs', function () {
    Queue::fake();

    Livewire::test(TravelTuningEntry::class)
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->base->id]]))
        ->call('start')
        ->assertSet('runId', null)
        ->assertNotified('The run did not start')
        ->assertDispatched('go-to-wizard-step', key: 'form.tool-wizard', step: 'form.inputs::data::wizard-step');
    expect(ToolRun::query()->where('tool', ToolId::TravelTuning)->count())->toBe(0);
});

it('asks for at least two runs', function () {
    Livewire::test(TravelTuningEntry::class)
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id]]))
        ->call('start')
        ->assertHasFormErrors(['runs']);
});

it('lists the user\'s Unfiled runs on Unfiled, and starts one there', function () {
    Queue::fake();
    $mine = planTravelsRun($this->user, ['summary' => ['leg_count' => 7, 'review_count' => 3, 'first_date' => '2026-07-01', 'last_date' => '2026-07-01', 'google' => []]]);
    $mineToo = planTravelsRun($this->user, ['summary' => ['leg_count' => 7, 'review_count' => 1, 'first_date' => '2026-07-02', 'last_date' => '2026-07-02', 'google' => []]]);

    Livewire::test(TravelTuningEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->assertSee('1 Jul 2026')
        ->assertDontSee('5 Oct 2026')
        ->assertFormSet(['options.client' => '', 'options.dataset' => ''])
        ->set('data.runs', tuningRuns([['run_id' => $mine->id], ['run_id' => $mineToo->id]]))
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::query()->where('tool', ToolId::TravelTuning)->sole();
    expect((string) $run->scope_id)->toBe((string) $this->user->getKey())
        ->and($run->inputs['runs'][1]['label'])->toBe('Run 1');
});
```

In `tests/Feature/NavigationGroupsTest.php`, add a dataset row after Trim export:

```php
    [Pages\Tools\TravelTuningEntry::class, Groups::ANALYSE],
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningWizardTest.php tests/Feature/NavigationGroupsTest.php`
Expected: FAIL (class `TravelTuningEntry` not found).

- [ ] **Step 3: Implement the base hook**

`app/Filament/Pages/Tools/ToolWizardPage.php`: add `use App\Support\Tools\UnusableRunInputException;`. After `chosenFiles()`:

```php
    /**
     * The source runs a run starts with, for a tool whose inputs are other runs (TakesRunInputs); none otherwise.
     *
     * @param  array<string, mixed>  $state  the form state
     * @return list<array<string, mixed>>
     */
    protected function chosenRuns(array $state): array
    {
        return [];
    }
```

In `start()`, pass the runs and catch the refusal (before the `InvalidArgumentException` catch):

```php
            $run = app(StartToolRun::class)->start($scope, static::tool(), $this->chosenFiles($state['files'] ?? []), $options, auth()->user(), $this->chosenRuns($state));
```

```php
        } catch (UnusableRunInputException $e) {
            Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();
            $this->dispatch('go-to-wizard-step', ...$this->inputsStepTarget());

            return;
        } catch (InvalidArgumentException $e) {
```

- [ ] **Step 4: Implement the page and its table**

`app/Filament/Pages/Tools/TravelTuningEntry.php`:

```php
<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\RunInputPicker;
use App\Tools\TravelTuning\SourceRuns;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Override;

/**
 * Travel tuning: Where, Inputs (finished Plan travels runs of the scope, in order, each with an optional SYS export
 * for its polygons), Options. The inputs are runs, not files, so this page replaces the Inputs step and hands the
 * rows to StartToolRun, which checks them again.
 */
class TravelTuningEntry extends ToolWizardPage
{
    /** @var array<string, string> option key => the value this page last filled in; a different value is the user's and is kept */
    #[Locked]
    public array $suggestedOptions = [];

    public static function tool(): ToolId
    {
        return ToolId::TravelTuning;
    }

    #[Override]
    public function mount(): void
    {
        parent::mount();
        $this->data['runs'] = self::emptyRuns();
        $this->prefillOptions();
        // The form was built by fill() before the scope was set, so its Inputs step is stale: build it again.
        $this->cacheSchema('form');
    }

    #[Override]
    public function resetInputs(): void
    {
        parent::resetInputs();
        $this->data['runs'] = self::emptyRuns();
        $this->prefillOptions();
    }

    #[Override]
    protected function inputsStep(): Step
    {
        return Step::make('Inputs')
            ->description('The plan travels runs to compare, baseline first and final run last, and the polygons each one used.')
            ->schema(fn (): array => $this->runFields());
    }

    /** @return list<Component> */
    protected function runFields(): array
    {
        if (! $this->hasScope()) {
            return [Text::make('Choose the environment and dataset first.')->color('gray')];
        }
        $scope = $this->scopeModel();
        $rows = app(SourceRuns::class)->rows($scope);
        if ($rows === []) {
            return [Text::make('There are no finished plan travels runs here yet. Run plan travels with the Google check on, once for the baseline and once after each change to the weightings.')->color('gray')];
        }

        $runOptions = [];
        $withoutGoogle = [];
        foreach ($rows as $row) {
            $runOptions[$row['id']] = SourceRuns::optionLabel($row);
            if (! $row['google']) {
                $withoutGoogle[] = $row['id'];
            }
        }
        $sysOptions = app(RunInputPicker::class)->optionsForKind($scope, ExportKind::Sys);
        $sysScope = $scope instanceof Dataset ? $scope->environment : $scope;

        return [
            View::make('filament.pages.tools.travel-tuning-runs')->viewData(['rows' => $rows]),
            Repeater::make('runs')
                ->label('Runs in the report')
                ->helperText('The first run is the baseline and the last is the final run. Use the arrows to change the order. A blank label becomes Baseline, Run 1, Run 2.')
                ->schema([
                    Select::make('run_id')
                        ->label('Plan travels run')
                        ->options($runOptions)
                        ->disableOptionWhen(static fn (string $value): bool => in_array($value, $withoutGoogle, true))
                        ->required()
                        ->native(false)
                        ->columnSpan(['default' => 1, 'md' => 2]),
                    TextInput::make('label')->label('Label')->placeholder('Baseline, Run 1, Run 2')->maxLength(60),
                    Select::make('sys_file_id')
                        ->label('Polygons (SYS export)')
                        ->options($sysOptions)
                        ->placeholder('None')
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(static function (?string $state, Set $set): void {
                            if (filled($state) && ! self::sysHasPolygons($state)) {
                                $set('sys_file_id', null);
                            }
                        })
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->itemNumbers()
                ->reorderableWithButtons()
                ->minItems(2)
                ->maxItems(SourceRuns::MAX_RUNS)
                ->defaultItems(2)
                ->addActionLabel('Add a run'),
            Livewire::make(ExportDropZone::class, ['scope' => $sysScope, 'kinds' => [ExportKind::Sys], 'compact' => true])
                ->key("drop-sys-{$sysScope->getKey()}"),
            Text::make('A new SYS export appears in the Polygons lists once it has been read. SYS exports are kept for '.(int) config('exports.ttl_hours', 12).' hours; the report keeps its own copy of the page.')->color('gray'),
        ];
    }

    /** Says why a picked SYS export cannot be used, and returns false so the field is emptied. */
    public static function sysHasPolygons(string $fileId): bool
    {
        $file = ExportFile::query()->ready()->find($fileId);
        $problem = $file === null ? 'That SYS export is no longer available' : app(SourceRuns::class)->sysError($file);
        if ($problem === null) {
            return true;
        }

        Notification::make()->title($problem)->body('Choose another SYS export, or None.')->danger()->send();

        return false;
    }

    #[Override]
    protected function chosenRuns(array $state): array
    {
        return array_values(array_filter((array) ($state['runs'] ?? []), 'is_array'));
    }

    /** @return array<string, array{run_id: null, label: null, sys_file_id: null}> two empty rows, keyed as the repeater keys its items */
    private static function emptyRuns(): array
    {
        $empty = ['run_id' => null, 'label' => null, 'sys_file_id' => null];

        return [(string) Str::uuid() => $empty, (string) Str::uuid() => $empty];
    }

    /** Client, dataset name and region label follow the scope until the user types their own; client and dataset are blank for Unfiled, the region label is "Region". */
    private function prefillOptions(): void
    {
        $dataset = $this->hasScope() && ! $this->isUnfiled() ? $this->scopeModel() : null;
        $settings = $dataset instanceof Dataset ? $dataset->settings : null;
        $suggested = [
            'client' => (string) ($settings?->displayName ?: ($dataset instanceof Dataset ? ($dataset->environment?->account_id ?? '') : '')),
            'dataset' => (string) ($settings?->datasetDisplayName ?: ($dataset instanceof Dataset ? $dataset->name : '')),
            'region_label' => (string) ($settings?->regionLabel ?: 'Region'),
        ];

        foreach ($suggested as $key => $value) {
            $current = $this->data['options'][$key] ?? null;
            if (blank($current) || $current === ($this->suggestedOptions[$key] ?? null)) {
                $this->data['options'][$key] = $value;
            }
        }
        $this->suggestedOptions = $suggested;
    }
}
```

`resources/views/filament/pages/tools/travel-tuning-runs.blade.php`:

```blade
{{-- The finished plan travels runs here, newest first. Only a run checked with Google can go in a report. --}}
<div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
            <tr>
                <th class="px-3 py-2 font-medium">Dates</th>
                <th class="px-3 py-2 font-medium">Made</th>
                <th class="px-3 py-2 font-medium text-right">Legs</th>
                <th class="px-3 py-2 font-medium text-right">Flagged</th>
                <th class="px-3 py-2 font-medium">Google</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach ($rows as $row)
                <tr @class(['text-gray-400 dark:text-gray-500' => ! $row['google'], 'text-gray-900 dark:text-gray-100' => $row['google']])>
                    <td class="px-3 py-2">{{ $row['range'] }}</td>
                    <td class="px-3 py-2">{{ $row['made'] }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row['legs']) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row['flagged']) }}</td>
                    <td class="px-3 py-2">{{ $row['google'] ? 'Yes' : 'Run plan travels again with Google on' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

`app/Filament/Widgets/ToolCatalogue.php`: add `use App\Tools\Contracts\TakesRunInputs;`, the page to `wizardPageFor()`'s list (`Pages\Tools\TravelTuningEntry::class` after `TrimExportEntry::class`), and the needs line:

```php
            $needs = match (true) {
                $impl instanceof TakesRunInputs => $impl->needs(),
                $impl instanceof TakesOneInput => $this->needsOne($impl->oneOfKinds()),
                default => $this->needs($impl->requiredKinds(), $impl->optionalKinds()),
            };
            $analyse[] = $this->item($page, $tool->getLabel(), $tool->question(), $needs);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningWizardTest.php tests/Feature/NavigationGroupsTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/PlanTravels/PlanTravelsWizardTest.php tests/Feature/TrimExport`
Expected: PASS. If the Unfiled test still shows the dataset's runs after `fillForm(['where' => 'unfiled'])` (a stale Inputs schema), add `$this->cacheSchema('form');` as the last line of `resetInputs()` and run again.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Tools/ToolWizardPage.php app/Filament/Pages/Tools/TravelTuningEntry.php resources/views/filament/pages/tools/travel-tuning-runs.blade.php app/Filament/Widgets/ToolCatalogue.php tests/Feature/NavigationGroupsTest.php tests/Feature/TravelTuning/TravelTuningWizardTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): the wizard picks plan travels runs in order, each with optional polygons

A table of the scope's finished runs, a repeater with up and down buttons, labels defaulting to Baseline, Run 1; a SYS export without a Polygon table is refused on pick. Options are prefilled from the dataset (the region label from its Region label setting, else Region).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 7: The run page

**Files:**
- Create: `resources/views/filament/tool-runs/travel_tuning.blade.php`
- Create: `app/Support/Tools/SourceRunList.php`
- Modify: `resources/views/filament/tool-runs/_run-panel.blade.php` (Inputs section)
- Test: `tests/Feature/TravelTuning/TravelTuningRunPageTest.php`

**Interfaces:**
- Consumes: summary keys from Task 5; `SourceRuns::defaultLabel()`; `ToolRunPage::urlFor()`; routes `tool-runs.page`, `tool-runs.download-html`.
- Produces: `SourceRunList::for(ToolRun $run): list<array{label: string, url: ?string, made: ?string, polygons: ?string}>` (empty for a run without `inputs['runs']`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TravelTuning/TravelTuningRunPageTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Http\Controllers\ToolRunPageController;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina']]);
    $this->base = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', 'UTC')]);
    $this->final = planTravelsRun($this->dataset);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'PSO_Sys_pass_1.xml']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'started_by_user_id' => $this->user->id, 'run_date' => null,
        'inputs' => ['files' => [], 'options' => [], 'runs' => [
            ['run_id' => $this->base->id, 'label' => 'Prod (no polygons)', 'sys_file_id' => null],
            ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id],
        ]],
        'result' => ['html' => '<!doctype html><title>Travel Tuning Report</title><p>tuning page</p>'],
        'summary' => ['runs' => 2, 'baseline_flagged' => 75, 'final_flagged' => 10, 'baseline_error_hours' => 138.2, 'final_error_hours' => 98.2,
            'fixed' => 44, 'newly_broken' => 0, 'shared_legs' => 2112, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs'],
        'warnings' => ['Legs common to every run are 55% of the smallest run.'],
    ]);
    $this->actingAs($this->user);
    $this->params = ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id];
    $this->routeParams = ToolRunPageController::params($this->dataset, $this->run);
});

it('shows the headline, the downloads, the page and the source runs with their polygons', function () {
    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs')
        ->assertSee('44 fixed')
        ->assertSee('0 newly broken')
        ->assertSee('Download HTML')
        ->assertSeeHtml(e(route('tool-runs.download-html', $this->routeParams)))
        ->assertSeeHtml('title="Travel tuning"')
        ->assertSee('Prod (no polygons)')
        ->assertSee('Grid v6')
        ->assertSeeHtml(e(ToolRunPage::urlFor($this->dataset, $this->base)))
        ->assertSee('Plan travels run of 12 Oct 2026 22:35')
        ->assertSee('PSO_Sys_pass_1.xml')
        ->assertSee('Polygons: None')
        ->assertSee('Legs common to every run are 55% of the smallest run.')
        ->assertDontSee('No inputs recorded.')
        ->assertDontSee('Download JSON');
});

it('names a source run that has been deleted, and keeps the page', function () {
    $this->base->delete();

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('Plan travels run deleted')
        ->assertSeeHtml('title="Travel tuning"');
});

it('serves the page sandboxed and the download named after the account, dataset and baseline date', function () {
    $this->get(route('tool-runs.page', $this->routeParams))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', ToolRunPageController::SANDBOX)
        ->assertSee('tuning page', false);

    $this->get(route('tool-runs.download-html', $this->routeParams))
        ->assertOk()
        ->assertDownload('travel_tuning_SATE_NORTH_2026-10-05.html');
});

it('keeps another user\'s tuning run out of reach', function () {
    $this->actingAs(User::factory()->create(['email' => 'someone@mac.com']));

    Livewire::test(ToolRunPage::class, $this->params)->assertNotFound();
    $this->get(route('tool-runs.download-html', $this->routeParams))->assertNotFound();
});

it('shows an Unfiled tuning run at unfiled/me', function () {
    $mine = planTravelsRun($this->user);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'started_by_user_id' => $this->user->id,
        'inputs' => ['files' => [], 'options' => [], 'runs' => [['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null], ['run_id' => $mine->id, 'label' => 'Run 1', 'sys_file_id' => null]]],
        'result' => ['html' => '<p>tuning page</p>'], 'summary' => ['runs' => 2, 'line' => '2 runs'],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $run->id])
        ->assertOk()
        ->assertSeeHtml(e(ToolRunPage::urlFor($this->user, $mine)));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningRunPageTest.php`
Expected: FAIL (the generic view shows no iframe, "No inputs recorded." is shown).

- [ ] **Step 3: Implement**

`app/Support/Tools/SourceRunList.php`:

```php
<?php

namespace App\Support\Tools;

use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Tools\TravelTuning\SourceRuns;

/**
 * The runs another run was built from (inputs['runs'], Travel tuning), for the Inputs panel: each row's label, a
 * link to the source run while it exists, when it was made, and the SYS export named for its polygons. Runs and
 * exports that have gone since are named as gone; the run's own result does not depend on them.
 */
class SourceRunList
{
    /** @return list<array{label: string, url: ?string, made: ?string, polygons: ?string}> */
    public static function for(ToolRun $run): array
    {
        $rows = array_values(array_filter((array) ($run->inputs['runs'] ?? []), 'is_array'));
        if ($rows === []) {
            return [];
        }

        $scope = $run->scope;
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');
        $runIds = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['run_id'] ?? ''), $rows)));
        $sysIds = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['sys_file_id'] ?? ''), $rows)));
        $sources = ToolRun::query()->withoutResult()->whereKey($runIds)
            ->where('scope_type', $run->scope_type)->where('scope_id', (string) $run->scope_id)
            ->get()->keyBy(static fn (ToolRun $source): string => (string) $source->id);
        $files = ExportFile::query()->whereKey($sysIds)->get()->keyBy(static fn (ExportFile $file): string => (string) $file->id);

        $list = [];
        foreach ($rows as $index => $row) {
            $source = $sources->get((string) ($row['run_id'] ?? ''));
            $sysId = (string) ($row['sys_file_id'] ?? '');
            $list[] = [
                'label' => filled($row['label'] ?? null) ? (string) $row['label'] : SourceRuns::defaultLabel($index),
                'url' => $source !== null && $scope !== null ? ToolRunPage::urlFor($scope, $source) : null,
                'made' => $source?->created_at?->copy()->setTimezone($timeZone)->format('j M Y H:i'),
                'polygons' => $sysId === '' ? null : ($files->get($sysId)?->original_filename ?? 'SYS export deleted'),
            ];
        }

        return $list;
    }
}
```

`resources/views/filament/tool-runs/_run-panel.blade.php`: replace the whole `<x-filament::section heading="Inputs">` block with:

```blade
@php
    $sourceRuns = \App\Support\Tools\SourceRunList::for($run);
@endphp
<x-filament::section heading="Inputs">
    @if ($sourceRuns !== [])
        <ol class="space-y-1 text-sm list-decimal pl-5">
            @foreach ($sourceRuns as $source)
                <li>
                    <span class="font-medium">{{ $source['label'] }}</span>:
                    @if ($source['url'])
                        <x-filament::link :href="$source['url']">Plan travels run of {{ $source['made'] }}</x-filament::link>.
                    @else
                        <span class="text-gray-500">Plan travels run deleted.</span>
                    @endif
                    Polygons: {{ $source['polygons'] ?? 'None' }}
                </li>
            @endforeach
        </ol>
    @else
        <ul class="space-y-1 text-sm">
            @forelse ($inputFiles as $kindValue => $file)
                <li>
                    <span class="font-medium">{{ \App\Enums\ExportKind::tryFrom($kindValue)?->getLabel() ?? $kindValue }}</span>:
                    @if ($file)
                        {{ $file->original_filename }}
                        @if ($file->status === \App\Enums\ExportFileStatus::Purged)
                            <span class="text-gray-500">(raw file purged)</span>
                        @endif
                    @else
                        <span class="text-gray-500">export deleted</span>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">No inputs recorded.</li>
            @endforelse
        </ul>
    @endif
</x-filament::section>
```

`resources/views/filament/tool-runs/travel_tuning.blade.php`:

```blade
@php
    $routeParams = \App\Http\Controllers\ToolRunPageController::params($run->scope, $run);
    $summary = $run->summary ?? [];
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            @if (filled($summary['line'] ?? null))
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $summary['line'] }}</p>
            @endif
            <div class="flex flex-wrap gap-2 text-sm font-medium">
                <x-filament::badge color="gray">{{ number_format((int) ($summary['runs'] ?? 0)) }} runs</x-filament::badge>
                <x-filament::badge color="warning">Flagged {{ number_format((int) ($summary['baseline_flagged'] ?? 0)) }} to {{ number_format((int) ($summary['final_flagged'] ?? 0)) }}</x-filament::badge>
                <x-filament::badge color="success">{{ number_format((int) ($summary['fixed'] ?? 0)) }} fixed</x-filament::badge>
                <x-filament::badge color="danger">{{ number_format((int) ($summary['newly_broken'] ?? 0)) }} newly broken</x-filament::badge>
            </div>
        </div>
        @if ($hasPage)
            <div class="flex flex-wrap gap-2">
                <x-filament::button tag="a" href="{{ route('tool-runs.page', $routeParams) }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray" size="sm">Open full page</x-filament::button>
                <x-filament::button tag="a" href="{{ route('tool-runs.download-html', $routeParams) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>

{{-- The page travel_tuning.py rendered. Sandboxed with no same-origin, so its scripts cannot reach the app;
     it loads Chart.js and Leaflet from cdnjs and the map tiles from their providers. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', $routeParams) }}"
    title="Travel tuning"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 10rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The travel tuning page is not available for this run.</p>
@endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningRunPageTest.php tests/Feature/TrimExport/TrimExportRunPageTest.php tests/Feature/PlanTravels/PlanTravelsRunPageTest.php tests/Feature/RunResultNotLoadedTest.php`
Expected: PASS (the other tools' Inputs panels are unchanged).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Tools/SourceRunList.php resources/views/filament/tool-runs/_run-panel.blade.php resources/views/filament/tool-runs/travel_tuning.blade.php tests/Feature/TravelTuning/TravelTuningRunPageTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): the run page shows the report, its download and the runs it was built from

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 8: The real script, synthetic and golden

**Files:**
- Test: `tests/Feature/TravelTuning/TravelTuningScriptTest.php`

**Interfaces:**
- Consumes: everything above; the real checkout at `TOOLS_PSO_TOOLS_PATH`; `TRAVEL_TUNING_SAMPLE_DIR` holding `plan_travels_output_north_initial.csv`, `plan_travels_output_pass2.csv`, `PSO_Sys_pass_1.xml` (Ish's `~/pso-data/SATE/1013`).

- [ ] **Step 1: Write the tests**

`tests/Feature/TravelTuning/TravelTuningScriptTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ToolRegistry;
use App\Tools\TravelTuning\TravelTuningCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Runs the real travel_tuning.py. Needs a pso-tools checkout:
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools php artisan test tests/Feature/TravelTuning/TravelTuningScriptTest.php
 * The golden also needs the SaskTel 2026-10-13 folder (customer data, keep it outside this repo):
 *   TRAVEL_TUNING_SAMPLE_DIR=~/pso-data/SATE/1013
 * Skipped otherwise.
 */

function embeddedTuningData(string $html): array
{
    preg_match('#<script type="application/json" id="data">(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

beforeEach(function () {
    $this->checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    if (blank($this->checkoutPath)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH to run the real travel tuning script.');
    }
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-real-'.uniqid(), 'tools.pso_tools_path' => $this->checkoutPath]);
    $this->travelTo(Carbon::parse('2026-10-18 15:30:00', 'UTC'));
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel']]);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
    // The fake disk can hold a copy of a customer SYS export (live credentials); never leave it under storage/.
    Storage::disk('r2')->deleteDirectory('exports');
});

it('builds the report from synthetic runs and a zipped SYS export with the real script', function () {
    $base = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset, csv: tuningCsv([960, 1560, 620, 880]));
    $sys = storedSys($this->environment, polygonSysXml(1), zipped: true);
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        'inputs' => ['files' => [], 'options' => ['title' => 'Travel tuning test', 'client' => 'SaskTel', 'dataset' => 'North'], 'runs' => [
            ['run_id' => $base->id, 'label' => 'Baseline', 'sys_file_id' => null],
            ['run_id' => $final->id, 'label' => 'Grid v1', 'sys_file_id' => $sys->id],
        ]],
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    $run->refresh();

    $data = embeddedTuningData($run->result['html'] ?? '');
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message)
        ->and($run->summary)->toBe([
            'runs' => 2, 'baseline_flagged' => 2, 'final_flagged' => 0, 'baseline_error_hours' => 0.8, 'final_error_hours' => 0.1,
            'fixed' => 2, 'newly_broken' => 0, 'shared_legs' => 4, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 2 to 0, total error 0.8 to 0.1 hours, 2 fixed, 0 newly broken, 4 shared legs',
        ])
        ->and($run->warnings)->toBe([])
        ->and($run->result['html'])->toContain('<title>Travel tuning test</title>')
        ->and($data['meta']['generated'])->toBe('2026-10-18 09:30')
        ->and($data['meta']['labels'])->toBe(['Baseline', 'Grid v1'])
        ->and(array_column($data['polygons'], 'id'))->toBe(['G01'])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('matches the command line on the SaskTel 2026-10-13 runs', function () {
    $sample = env('TRAVEL_TUNING_SAMPLE_DIR');
    if (blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TRAVEL_TUNING_SAMPLE_DIR to the SaskTel 2026-10-13 folder to run the golden test.');
    }
    $initial = "{$sample}/plan_travels_output_north_initial.csv";
    $pass2 = "{$sample}/plan_travels_output_pass2.csv";
    $polygons = "{$sample}/PSO_Sys_pass_1.xml";
    $base = planTravelsRun($this->dataset, csv: (string) file_get_contents($initial));
    $final = planTravelsRun($this->dataset, csv: (string) file_get_contents($pass2));
    $sys = storedSys($this->environment, $polygons);
    $options = ['title' => 'Travel Tuning Report', 'client' => 'SaskTel', 'dataset' => 'North'];
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        'inputs' => ['files' => [], 'options' => $options, 'runs' => [
            ['run_id' => $base->id, 'label' => 'Prod (no polygons)', 'sys_file_id' => null],
            ['run_id' => $final->id, 'label' => 'Grid v6', 'sys_file_id' => $sys->id],
        ]],
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message);

    // The command line on the original files, with the same flags and generated time.
    $work = sys_get_temp_dir().'/golden-tuning-cli-'.uniqid();
    mkdir($work, 0770, true);
    try {
        file_put_contents("{$work}/manifest.csv", "Label,RunCsv,PolygonXml\nProd (no polygons),{$initial},\nGrid v6,{$pass2},{$polygons}\n");
        $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), $work, $options, '2026-10-18 09:30', false);
        $cli = Process::path($work)->timeout(600)->run($argv);
        expect($cli->successful())->toBeTrue($cli->errorOutput());
        $cliData = json_decode((string) file_get_contents("{$work}/travel_tuning.json"), true);
    } finally {
        File::deleteDirectory($work);
    }

    $webData = embeddedTuningData($run->result['html']);
    // File names differ (run-1.csv against the original names); everything else must be identical.
    foreach ([&$webData, &$cliData] as &$data) {
        unset($data['meta']['files']);
        foreach ($data['runs'] as &$runRow) {
            unset($runRow['file']);
        }
        unset($runRow);
    }
    unset($data);

    expect($webData)->toEqual($cliData)
        ->and($run->summary['baseline_flagged'])->toBe(75)
        ->and($run->summary['final_flagged'])->toBe(10)
        ->and($run->summary['fixed'])->toBe(44)
        ->and($run->summary['newly_broken'])->toBe(0)
        ->and($run->summary['shared_legs'])->toBe(2112);
});
```

- [ ] **Step 2: Run them, with and without the env vars**

Run: `php artisan test tests/Feature/TravelTuning/TravelTuningScriptTest.php`
Expected: 2 skipped.

Run: `TOOLS_PSO_TOOLS_PATH="$HOME/Documents/Projects/PSO Tools" TRAVEL_TUNING_SAMPLE_DIR="$HOME/pso-data/SATE/1013" php artisan test tests/Feature/TravelTuning/TravelTuningScriptTest.php`
Expected: PASS. If the synthetic numbers differ, run the script by hand on the same CSVs and correct the expectation only after checking the script's arithmetic is the one plan A pinned (do not change the script). If the golden differs only in `meta.generated`, the clock was not frozen: check `travelTo`.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS except `Tests\Feature\ExampleTest` (known, ignored).

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git status --short   # only the test file below should be new; no data files
git add tests/Feature/TravelTuning/TravelTuningScriptTest.php
git commit -m "$(cat <<'EOF'
Travel tuning (#31): real-script tests, synthetic and the SaskTel 2026-10-13 golden

Both skip unless TOOLS_PSO_TOOLS_PATH (and TRAVEL_TUNING_SAMPLE_DIR for the golden) are set.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

## Self-review

- **Spec 4 coverage.** Tool under Analyse and in the wizard, accepts Unfiled: Tasks 1, 6. Runs of the run's own scope only: Task 3 (`finished()` filters by scope), Task 5 (re-check). Inputs table with dates, made, legs, flagged, Google: Task 6 view. Two or more, ordered with up and down: Task 6 repeater. Labels default Baseline, Run 1: Task 3. Polygons from the environment's SYS or the user's Unfiled SYS, or None, Polygon table check with the spec's sentence: Tasks 2, 3, 6. Run without Google cannot be picked, its row says so: Tasks 3, 6. Options 4.2 (prefill, thresholds, region label, tiles, notes, no review CSV, no offline): Tasks 4, 5, 5A, 6. Running 4.3 (CSVs from the encrypted result, SYS to scratch and deleted, manifest, ScriptRun, html encrypted, counts-only summary, script warnings): Task 5. Run page with sandboxed iframe and Download HTML: Task 7. 4.4 (own copy of the page): Task 7's deleted-source test. Spec 3.1 contract (manifest columns, `--json`, `--generated`, `Stopped during`, `warning:` lines): Tasks 4, 5, 8. Spec 6 (no uploaded CSVs, no review CSV): no task offers either.
- **Placeholders.** None: every code step carries the code; the one conditional (Task 6 Step 5, `cacheSchema`) names the exact line to add.
- **Names across tasks.** `SourceRuns::check/rows/optionLabel/sysError/label/defaultLabel/MAX_RUNS/NO_POLYGONS`, `SysPolygons::count/localXml/countIn`, `RunInputPicker::optionsForKind`, `TravelTuningCommand::build/MANIFEST/NOTES/OUT_HTML/OUT_JSON/TILES/NUMBERS`, `StartToolRun::start(..., $runs)`, `ToolWizardPage::chosenRuns`, `SourceRunList::for`, summary keys: used with the same spelling in every task.
- **Review Focus.** Each line has its test: 1 Task 5 (deleted run, expired SYS, SYS copy deleted on failure), 2 Tasks 4 and 5 (dash title, newline, `Grid, "v6"` label), 3 Tasks 3 and 6, 4 Task 2, 5 Tasks 3 and 6.
