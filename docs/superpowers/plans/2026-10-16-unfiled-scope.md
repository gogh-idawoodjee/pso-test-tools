# Unfiled Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Trace an activity, Load Health Audit and Plan travels run on a file with no environment or dataset, filed under a personal Unfiled scope (the user), with the time zone chosen per run and the files and runs listed on a new My files page.

**Architecture:** `App\Models\User` becomes a third morph scope for `ExportFile` and `ToolRun` next to Dataset and Environment (no migration). `ExportKind::allows()` and `Tool::acceptsUnfiled()` say what may live there; the ownership global scope, the upload slot, the drop zone and the input picker learn the User scope; the wizard's Where step offers Unfiled for accepting tools and the Options step adds a time zone; `RunToolJob` takes the zone from the run's options. Run pages and downloads move to `{scopeType}/{scopeId}/runs/{run}` with `unfiled/me`, and the Exports and Runs tables are extracted into shared classes so My files reuses them.

**Tech Stack:** Laravel 13, PHP 8.5, Filament v5.10 (Schemas, Wizard, Radio, Select, TableWidget), Livewire 4, Pest 4, MySQL in production, SQLite in memory for tests. Tests run with `vendor/bin/pest <paths>`.

**Spec:** `docs/superpowers/specs/2026-10-16-unfiled-scope-design.md` (issue #45). The spec is the authority; this plan argues from it.

## Global Constraints

- No migration. The Unfiled scope is the user: `scope_type` = `(new User)->getMorphClass()` (`App\Models\User`, there is no morph map), `scope_id` = the user's integer id stored in the `uuidMorphs` char column.
- Always query and compare `scope_id` as a string: `(string) $scope->getKey()`. MySQL compares a char column with an integer numerically (index lost, and a uuid like `7c9e...` casts to 7); SQLite in the tests hides this, so the cast is a rule, not something a test catches.
- Accepting tools are exactly `Tool::AppointmentTimeline` (Trace an activity), `Tool::LoadAudit` and `Tool::PlanTravels`. Region availability and Sys compare never see a User scope; nothing changes for a run started under a dataset.
- `ExportKind::allows(string $scopeClass): bool`: SYS allows Environment and User; every other kind allows Dataset and User. `allowedScope()` is removed.
- Unfiled URLs name no id: the run page is `unfiled/me/runs/{run}`, downloads are `unfiled/me/runs/{run}/...`, the upload slot sends `scope_id: 'me'` and the server ignores it.
- `last_tool_context` always holds four keys: `environment_id`, `dataset_id`, `unfiled` (bool), `time_zone` (IANA name or null).
- Filament v5 namespaces only: form fields `Filament\Forms\Components\` (`Radio`, `Select`), layout `Filament\Schemas\Components\`, `Get` from `Filament\Schemas\Components\Utilities\Get`, actions `Filament\Actions\` (never sub-namespaces), columns `Filament\Tables\Columns\`.
- House conventions (CLAUDE.md): no abbreviated variable names; `app()` over `new` for Filament and Laravel classes; never `final` or `readonly` on classes that extend Filament; `static fn` for closures that do not use `$this`; constructor property promotion; explicit return types and parameter types.
- UI copy is plain English for a customer audience. No em dashes anywhere (copy, comments, commit messages). Say "Allocated", never "placed".
- Never log or display secrets. Customer identifiers never leave encrypted columns: an export's original file name may go into a script's argv and so into the encrypted `ToolRun.result` page, never into `ToolRun.summary`, `ToolRun.warnings` or a log line.
- Before each commit run `vendor/bin/pint --dirty`, then `git diff` and revert any whitespace-only hunk outside the lines you changed (the repo has style drift; the rule is "do not change unrelated code").
- Stage only the paths the task lists. Never `git add -A`, `git add .` or `git add docs/`: untracked customer exports sit in `docs/`.
- The known failing `ExampleTest` is ignored. Do not open, curl or drive the app in a browser; Ish checks the UI himself.
- Every commit message ends with these two lines:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
  ```

## Review Focus

1. Another user's unfiled file id (or a dataset file id) forced into an unfiled run: the run must be refused as "no longer available" and nothing created. (Task 3 test "refuses another user unfiled file, or a dataset file, on an unfiled run"; Task 3 RunToolJob test "refuses a file of another user on an unfiled run".)
2. `data.where` forced to `unfiled` on a tool that needs a dataset (Region availability): the run must still go to the chosen dataset, never to the user. (Task 4 test "never runs a tool that needs a dataset on unfiled files, even when where is forced"; Task 3 guard test in StartToolRun.)
3. Switching Where between Unfiled and A dataset after files were preselected: the file choices must swap to the new scope's files, never keep an id from the other scope. (Task 4 test "shows the environment and dataset once A dataset is chosen, and swaps the files".)
4. An expired unfiled export reaching the purge or a run: the object key for an integer user id must build (`exports/user/<id>/<uuid>.<ext>`), so purge deletes it instead of throwing. (Task 2 tests in `ExportStorageTest` and `PurgeExportsTest`.)
5. A time zone that is missing, blank or not a real IANA name in the run options (an old run, a forged request): the wizard refuses it, and `RunToolJob` falls back to the app zone instead of crashing. (Task 5 test "refuses a time zone that is not in the list"; Task 3 test "falls back to the app zone for an unfiled run with a missing or unknown zone".)

---

## File structure

Create:
- `app/Tools/UnfiledLabel.php` the name a tool page shows for an unfiled run (the original file name).
- `app/Filament/Tables/ExportFilesTable.php` the Exports table (columns, purge countdown, delete), shared by the Exports tab and My files.
- `app/Filament/Tables/ToolRunsTable.php` the Runs table (columns, open, delete), shared by the Runs tab and My files.
- `app/Filament/Pages/MyFiles.php` and `resources/views/filament/pages/my-files.blade.php` the My files page.
- `app/Filament/Widgets/MyExportFiles.php`, `app/Filament/Widgets/MyToolRuns.php` the two tables on My files (not discovered onto the dashboard).
- Tests: `tests/Unit/ExportKindAllowsTest.php`, `tests/Unit/ToolAcceptsUnfiledTest.php`, `tests/Feature/ToolWizardUnfiledTest.php`, `tests/Feature/UnfiledRunPagesTest.php`, `tests/Feature/MyFilesTest.php`.

Modify:
- `app/Enums/ExportKind.php`, `app/Enums/Tool.php`, `app/Models/User.php`, `app/Models/ExportFile.php`, `app/Models/Scopes/ScopeOwnedModel.php`
- `app/Support/Exports/ExportStorage.php`, `app/Http/Requests/ExportSlotRequest.php`, `app/Http/Controllers/Exports/ExportSlotController.php`, `app/Livewire/ExportDropZone.php`, `resources/views/livewire/export-drop-zone.blade.php`, `app/Support/Exports/RunInputPicker.php`
- `app/Support/Tools/StartToolRun.php`, `app/Jobs/RunToolJob.php`
- `app/Filament/Pages/Tools/ToolWizardPage.php`
- `app/Tools/LoadAudit/LoadAuditTool.php`, `app/Tools/PlanTravels/PlanTravelsTool.php`, `app/Tools/ActivityTrace/ActivityTraceTool.php`
- `routes/web.php`, `app/Http/Controllers/ToolRunPageController.php`, `app/Filament/Pages/ToolRunPage.php`, `resources/views/filament/tool-runs/{load_audit,appointment_timeline,plan_travels,region_availability}.blade.php`
- `app/Filament/RelationManagers/ExportFilesRelationManager.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php`, `app/Filament/Widgets/RecentRuns.php`
- `README.md` (Navigation section)
- Existing tests whose expectations change: `tests/Feature/UserLastToolContextTest.php`, `tests/Feature/StartToolRunTest.php`, `tests/Feature/ToolWizardPageTest.php`, `tests/Feature/ExportFileModelTest.php`, `tests/Feature/ActivityTrace/EventLogExportKindTest.php`, `tests/Feature/PlanTravels/PlanTravelsWizardTest.php`, the route calls in `tests/Feature/LoadAudit/LoadAuditPagesTest.php`, `tests/Feature/ActivityTrace/ActivityTraceToolTest.php`, `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php`, `tests/Feature/PlanTravels/PlanTravelsRunPageTest.php`, plus `tests/Feature/NavigationGroupsTest.php` and `tests/Feature/DashboardTest.php`.

Not touched (checked): `PurgeExports` (finds rows by status and date), `SummariseExportFileJob` (a non-dataset file is dated in the app zone, accepted by the spec), `ToolContext` (unchanged), `RegionAvailabilityTool`, the Sys compare pages, the home `ToolCatalogue`.

---

### Task 1: User as a third scope (enums, User model, ownership, save guard)

**Files:**
- Modify: `app/Enums/ExportKind.php` (replace `allowedScope()`, `forScope()`)
- Modify: `app/Enums/Tool.php` (add `acceptsUnfiled()`)
- Modify: `app/Models/User.php` (`rememberToolContext()`, `exportFiles()`, `toolRuns()`)
- Modify: `app/Models/Scopes/ScopeOwnedModel.php`
- Modify: `app/Models/ExportFile.php` (saving guard, class docblock)
- Modify (mechanical `allowedScope` to `allows` swap, behaviour unchanged for Dataset and Environment): `app/Http/Requests/ExportSlotRequest.php:53`, `app/Livewire/ExportDropZone.php:53`, `app/Filament/Pages/Tools/ToolWizardPage.php:259-262`, `app/Support/Exports/RunInputPicker.php:109-114`
- Create: `tests/Unit/ExportKindAllowsTest.php`, `tests/Unit/ToolAcceptsUnfiledTest.php`
- Modify tests: `tests/Feature/ExportFileModelTest.php`, `tests/Feature/UserLastToolContextTest.php`, `tests/Feature/StartToolRunTest.php:60,67`, `tests/Feature/ToolWizardPageTest.php:211`, `tests/Feature/ActivityTrace/EventLogExportKindTest.php:25`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `ExportKind::allows(string $scopeClass): bool`
  - `ExportKind::forScope(string $scopeClass): array` (unchanged signature; `forScope(User::class)` is every kind)
  - `Tool::acceptsUnfiled(): bool`
  - `User::rememberToolContext(?string $environmentId, ?string $datasetId, bool $unfiled = false, ?string $timeZone = null): void` storing `['environment_id', 'dataset_id', 'unfiled', 'time_zone']`
  - `User::exportFiles(): MorphMany`, `User::toolRuns(): MorphMany`
  - `ScopeOwnedModel` lets the signed-in user see rows whose scope is that user.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ExportKindAllowsTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;

it('says which scopes each kind may be stored on', function (ExportKind $kind, bool $onDataset, bool $onEnvironment) {
    expect($kind->allows(Dataset::class))->toBe($onDataset)
        ->and($kind->allows(Environment::class))->toBe($onEnvironment)
        ->and($kind->allows(User::class))->toBeTrue()
        ->and($kind->allows(stdClass::class))->toBeFalse();
})->with([
    'LOAD' => [ExportKind::Load, true, false],
    'Plan' => [ExportKind::Plan, true, false],
    'End of day' => [ExportKind::Eod, true, false],
    'Audit messages' => [ExportKind::Audits, true, false],
    'IFS event log' => [ExportKind::EventLog, true, false],
    'SYS' => [ExportKind::Sys, false, true],
]);

it('lists every kind for the Unfiled scope and keeps SYS apart from a dataset', function () {
    expect(ExportKind::forScope(User::class))->toBe(ExportKind::cases())
        ->and(ExportKind::forScope(Dataset::class))->not->toContain(ExportKind::Sys)
        ->and(ExportKind::forScope(Environment::class))->toBe([ExportKind::Sys]);
});
```

Create `tests/Unit/ToolAcceptsUnfiledTest.php`:

```php
<?php

use App\Enums\Tool;

it('lets only the file-only tools run Unfiled', function () {
    $accepting = array_values(array_filter(Tool::cases(), static fn (Tool $tool): bool => $tool->acceptsUnfiled()));

    expect($accepting)->toBe([Tool::LoadAudit, Tool::AppointmentTimeline, Tool::PlanTravels]);
});
```

In `tests/Feature/ExportFileModelTest.php` replace the body of `it('maps kinds to the scope they are allowed on', ...)` with:

```php
    expect(ExportKind::Sys->allows(Environment::class))->toBeTrue()
        ->and(ExportKind::Load->allows(Dataset::class))->toBeTrue()
        ->and(ExportKind::Audits->extensions())->toBe(['zip'])
        ->and(ExportKind::Load->extensions())->toBe(['xml', 'json', 'zip'])
        ->and(Tool::LoadAudit->scope())->toBe(Dataset::class)
        ->and(Tool::SysCompare->scope())->toBe(Environment::class);
```

and add, after the test `it('scopes export files and runs to the logged-in user through the dataset or environment', ...)`:

```php
it('stores any kind unfiled on a user and shows it only to that user', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $mySys = ExportFile::factory()->for($me, 'scope')->sys()->create(['original_filename' => 'my-sys.xml']);
    ExportFile::factory()->for($me, 'scope')->create(['kind' => ExportKind::Audits, 'original_filename' => 'my-audits.zip', 'content_type' => 'zip']);
    ExportFile::factory()->for($them, 'scope')->create(['original_filename' => 'their-load.xml']);
    ToolRun::factory()->for($me, 'scope')->create();
    ToolRun::factory()->for($them, 'scope')->create();

    $this->actingAs($me);

    expect(ExportFile::pluck('original_filename')->sort()->values()->all())->toBe(['my-audits.zip', 'my-sys.xml'])
        ->and(ToolRun::count())->toBe(1)
        ->and($me->exportFiles()->count())->toBe(2)
        ->and($me->toolRuns()->count())->toBe(1)
        ->and($mySys->fresh()->scope)->toBeInstanceOf(User::class)
        ->and((string) $mySys->fresh()->scope_id)->toBe((string) $me->id);
});

it('matches the unfiled owner on the user id as a string', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    expect(ExportFile::query()->getBindings())->toContain((string) $me->id)
        ->and(ToolRun::query()->getBindings())->toContain((string) $me->id);
});
```

In `tests/Feature/UserLastToolContextTest.php` change the two expectations and add a test:

```php
    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => 'ds-12', 'unfiled' => false, 'time_zone' => null]);
```

```php
    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => null, 'unfiled' => false, 'time_zone' => null]);
```

```php
it('remembers an unfiled run with its time zone', function () {
    $user = User::factory()->create();
    $user->rememberToolContext(environmentId: 'env-7', datasetId: 'ds-12', unfiled: true, timeZone: 'America/Regina');

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 'env-7', 'dataset_id' => 'ds-12', 'unfiled' => true, 'time_zone' => 'America/Regina']);
});
```

In `tests/Feature/StartToolRunTest.php` line 60 and line 67, add the two new keys:

```php
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id, 'unfiled' => false, 'time_zone' => null]);
```

```php
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => null, 'unfiled' => false, 'time_zone' => null]);
```

In `tests/Feature/ToolWizardPageTest.php` line 211:

```php
        ->and($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id, 'unfiled' => false, 'time_zone' => null]);
```

In `tests/Feature/ActivityTrace/EventLogExportKindTest.php` line 25:

```php
        ->and(ExportKind::EventLog->allows(Dataset::class))->toBeTrue()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ExportKindAllowsTest.php tests/Unit/ToolAcceptsUnfiledTest.php tests/Feature/ExportFileModelTest.php tests/Feature/UserLastToolContextTest.php`
Expected: FAIL with "Call to undefined method App\Enums\ExportKind::allows()", "Call to undefined method App\Enums\Tool::acceptsUnfiled()", "Unknown named parameter $unfiled", and the export model test throwing "A LOAD export belongs on a Dataset, not a User."

- [ ] **Step 3: Implement**

`app/Enums/ExportKind.php`: add `use App\Models\User;`, delete `allowedScope()` and put in its place:

```php
    /** Whether an export of this kind may be stored on $scopeClass: SYS on an environment, the rest on a dataset, any kind Unfiled (on a user). */
    public function allows(string $scopeClass): bool
    {
        return match ($scopeClass) {
            User::class => true,
            Environment::class => $this === self::Sys,
            Dataset::class => $this !== self::Sys,
            default => false,
        };
    }
```

and change `forScope()`:

```php
    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->allows($scopeClass)));
    }
```

`app/Enums/Tool.php`: add after `scope()`:

```php
    /** Tools that only read the files: they may run Unfiled, with no environment or dataset. */
    public function acceptsUnfiled(): bool
    {
        return in_array($this, [self::LoadAudit, self::AppointmentTimeline, self::PlanTravels], true);
    }
```

`app/Models/User.php`: add `use Illuminate\Database\Eloquent\Relations\MorphMany;` and replace `rememberToolContext()`:

```php
    /**
     * @param  string|null  $datasetId  null for environment-scoped tools
     * @param  bool  $unfiled  the last run was on the user's own Unfiled files
     * @param  string|null  $timeZone  the zone chosen for that Unfiled run
     */
    public function rememberToolContext(?string $environmentId, ?string $datasetId, bool $unfiled = false, ?string $timeZone = null): void
    {
        $this->forceFill(['last_tool_context' => [
            'environment_id' => $environmentId,
            'dataset_id' => $datasetId,
            'unfiled' => $unfiled,
            'time_zone' => $timeZone,
        ]])->save();
    }

    /** Unfiled exports: files that belong to no environment or dataset. */
    public function exportFiles(): MorphMany
    {
        return $this->morphMany(ExportFile::class, 'scope');
    }

    /** Unfiled runs. */
    public function toolRuns(): MorphMany
    {
        return $this->morphMany(ToolRun::class, 'scope');
    }
```

`app/Models/Scopes/ScopeOwnedModel.php`: add `use App\Models\User;`, update the class docblock and `apply()`:

```php
/**
 * Rows hang off a Dataset or an Environment (owned by the environment's user), or off a
 * User (Unfiled, owned by that user). Applied to export files and tool runs.
 */
class ScopeOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        $builder->where(static function (Builder $query) use ($userId, $model): void {
            $query->whereHasMorph('scope', [Dataset::class], static fn (Builder $dataset) => $dataset
                ->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', $userId)))
                ->orWhereHasMorph('scope', [Environment::class], static fn (Builder $environment) => $environment->where('user_id', $userId))
                // scope_id is a char column: compare the user id as a string so MySQL keeps the index.
                ->orWhere(static fn (Builder $unfiled): Builder => $unfiled
                    ->where($model->qualifyColumn('scope_type'), (new User)->getMorphClass())
                    ->where($model->qualifyColumn('scope_id'), (string) $userId));
        });
    }
}
```

`app/Models/ExportFile.php`: class docblock first line becomes `One uploaded PSO export (LOAD, plan, EOD, audits zip on a dataset; SYS on an environment; any kind Unfiled, on the user).` and the saving hook becomes:

```php
        static::saving(static function (self $file): void {
            $kind = $file->kind instanceof ExportKind ? $file->kind : ExportKind::from($file->kind);
            if (! $kind->allows((string) $file->scope_type)) {
                throw new InvalidArgumentException("A {$kind->getLabel()} export cannot be stored on a ".class_basename((string) $file->scope_type).'.');
            }
        });
```

Mechanical swaps (same behaviour for Dataset and Environment):

`app/Http/Requests/ExportSlotRequest.php` line 53:

```php
            if (! $kind->allows(self::SCOPES[$this->input('scope_type')])) {
```

`app/Livewire/ExportDropZone.php` line 53:

```php
            if (! $kind->allows($scope::class)) {
```

`app/Filament/Pages/Tools/ToolWizardPage.php` `uploadScopeFor()`:

```php
    /** Where an upload of this kind goes: the scope, or a dataset's environment for a kind a dataset cannot hold (SYS). */
    private function uploadScopeFor(ExportKind $kind, Model $scope): Model
    {
        return $scope instanceof Dataset && ! $kind->allows(Dataset::class) ? $scope->environment : $scope;
    }
```

`app/Support/Exports/RunInputPicker.php` `candidates()` first statement:

```php
        [$scopeType, $scopeId] = $scope instanceof Dataset && ! $kind->allows(Dataset::class)
            ? [(new Environment)->getMorphClass(), $scope->environment_id]
            : [$scope->getMorphClass(), (string) $scope->getKey()];
```

Then confirm nothing else calls the old method: `grep -rn "allowedScope" app tests resources` prints nothing.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ExportKindAllowsTest.php tests/Unit/ToolAcceptsUnfiledTest.php tests/Feature/ExportFileModelTest.php tests/Feature/UserLastToolContextTest.php tests/Feature/StartToolRunTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/ActivityTrace/EventLogExportKindTest.php tests/Feature/ExportDropZoneTest.php tests/Feature/ExportUploadEndpointsTest.php tests/Feature/RunsTabTest.php tests/Feature/ExportsTabTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Enums/ExportKind.php app/Enums/Tool.php app/Models/User.php app/Models/Scopes/ScopeOwnedModel.php app/Models/ExportFile.php \
  app/Http/Requests/ExportSlotRequest.php app/Livewire/ExportDropZone.php app/Filament/Pages/Tools/ToolWizardPage.php app/Support/Exports/RunInputPicker.php \
  tests/Unit/ExportKindAllowsTest.php tests/Unit/ToolAcceptsUnfiledTest.php tests/Feature/ExportFileModelTest.php tests/Feature/UserLastToolContextTest.php \
  tests/Feature/StartToolRunTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/ActivityTrace/EventLogExportKindTest.php
git commit -F - <<'EOF'
Unfiled scope: the user is a third export and run scope (#45)

ExportKind::allows() replaces allowedScope(): SYS on an environment, the rest on a
dataset, every kind Unfiled. Tool::acceptsUnfiled() names Trace an activity, Load
Health Audit and Plan travels. The ownership scope lets a user see rows filed on
themselves, and the remembered tool context gains unfiled and time_zone.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 2: Uploads to Unfiled (object key, slot request and controller, drop zone, picker)

**Files:**
- Modify: `app/Support/Exports/ExportStorage.php` (`objectKey()`, new `assertScopeKey()`)
- Modify: `app/Http/Requests/ExportSlotRequest.php` (`SCOPES`, `rules()`)
- Modify: `app/Http/Controllers/Exports/ExportSlotController.php`
- Modify: `app/Livewire/ExportDropZone.php` (`serverFiles()`, `render()`)
- Modify: `resources/views/livewire/export-drop-zone.blade.php:84`
- Test: `tests/Feature/ExportStorageTest.php`, `tests/Feature/PurgeExportsTest.php`, `tests/Feature/ExportUploadEndpointsTest.php`, `tests/Feature/ExportDropZoneTest.php`, `tests/Feature/RunsTabTest.php`

**Interfaces:**
- Consumes (Task 1): `ExportKind::allows()`, `ScopeOwnedModel` User branch, `RunInputPicker::candidates()` already reading a User scope from the user.
- Produces:
  - Object keys `exports/user/<user id>/<file uuid>.<ext>` for Unfiled files.
  - `ExportSlotRequest::SCOPES['unfiled'] === User::class`; a slot request `{scope_type: 'unfiled', scope_id: 'me' | absent}` files the upload on the signed-in user.
  - The drop zone view variables `$scopeType` (`'dataset' | 'environment' | 'unfiled'`) and `$scopeId` (`'me'` for Unfiled).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ExportStorageTest.php`: add `use App\Models\User;` and:

```php
it('builds an unfiled object key from the user id and the file uuid', function () {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'content_type' => 'xml']);
    $file->scope_type = (new User)->getMorphClass();
    $file->scope_id = '42';

    expect(app(ExportStorage::class)->objectKey($file))->toBe('exports/user/42/0f8fad5b-d9cb-469f-a165-70867728950e.xml');
});

it('takes a number as the scope id only for a user', function (string $scopeType, string $scopeId) {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);
    $file->scope_type = $scopeType;
    $file->scope_id = $scopeId;

    expect(fn () => app(ExportStorage::class)->objectKey($file))->toThrow(InvalidArgumentException::class);
})->with([
    'a dataset with a number' => [App\Models\Dataset::class, '42'],
    'a user with a path' => [User::class, '../42'],
    'a user with zero' => [User::class, '0'],
]);
```

`tests/Feature/PurgeExportsTest.php`: add `use App\Models\User;` and:

```php
it('purges an expired unfiled export like any other', function () {
    $storage = app(ExportStorage::class);
    $expired = ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['expires_at' => now()->subMinute()]);
    Storage::disk('r2')->put($storage->objectKey($expired), 'x');

    Artisan::call('exports:purge');

    expect($expired->refresh()->status)->toBe(ExportFileStatus::Purged)
        ->and($storage->exists($expired))->toBeFalse();
});
```

`tests/Feature/ExportUploadEndpointsTest.php`: add `use App\Enums\ExportKind;` and:

```php
it('files an unfiled upload on the current user and ignores the scope id it is sent', function () {
    fakeUploadUrl();
    $other = User::factory()->create();

    $this->postJson(route('filament.app.exports.slots.store'), ['scope_type' => 'unfiled', 'scope_id' => (string) $other->id, 'kind' => 'sys', 'file_name' => 'prod-sys.xml', 'file_size' => 2048])
        ->assertCreated();

    $file = ExportFile::sole();
    expect($file->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $file->scope_id)->toBe((string) $this->user->id)
        ->and($file->kind)->toBe(ExportKind::Sys)
        ->and($file->stored_path)->toBe("exports/user/{$this->user->id}/{$file->id}.xml");
});

it('takes me or no scope id for an unfiled upload, and still checks the extension', function () {
    fakeUploadUrl();
    $route = route('filament.app.exports.slots.store');

    $this->postJson($route, ['scope_type' => 'unfiled', 'scope_id' => 'me', 'kind' => 'load', 'file_name' => 'load.xml', 'file_size' => 10])->assertCreated();
    $this->postJson($route, ['scope_type' => 'unfiled', 'kind' => 'audits', 'file_name' => 'audits.zip', 'file_size' => 10])->assertCreated();
    $this->postJson($route, ['scope_type' => 'unfiled', 'scope_id' => 'me', 'kind' => 'audits', 'file_name' => 'audits.xml', 'file_size' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('file_name');

    expect(ExportFile::count())->toBe(2);
});

it('completes an unfiled upload the user owns, and not another user one', function () {
    Queue::fake();
    $mine = ExportFile::factory()->for($this->user, 'scope')->uploading()->create(['file_size_bytes' => 17, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($mine), '<dsScheduleData/>');
    $theirs = ExportFile::factory()->for(User::factory()->create(), 'scope')->uploading()->create();

    $this->postJson(route('filament.app.exports.complete', $mine))->assertStatus(202);
    $this->postJson(route('filament.app.exports.complete', $theirs))->assertNotFound();

    expect($mine->refresh()->status)->toBe(ExportFileStatus::Summarising);
});
```

The spec's "a kind check still refuses what it refused before" is the existing test `refuses a sys file on a dataset and a load file on an environment`; leave it as it is.

`tests/Feature/ExportDropZoneTest.php`:

```php
it('offers every kind Unfiled and posts it as unfiled for me', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->user])
        ->assertSee('Drop a new LOAD')->assertSee('Drop a new SYS')->assertSee('Drop a new IFS event log')->assertSee('Drop a new Audit messages')
        ->assertSeeHtml("scope_type: 'unfiled'")
        ->assertSeeHtml("scope_id: 'me'")
        ->assertDontSeeHtml("scope_id: '{$this->user->id}'");
});

it('shows an unfiled upload the server is still reading', function () {
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod_load.zip', 'status' => ExportFileStatus::Summarising]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->user, 'kinds' => [ExportKind::Load], 'compact' => true])
        ->assertSee('Reading prod_load.zip')
        ->assertSet('serverStates', ['load' => 'reading']);
});
```

`tests/Feature/RunsTabTest.php` (the picker tests live here):

```php
it('offers an unfiled run only the user own unfiled files, SYS included', function () {
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);
    $sys = ExportFile::factory()->for($this->user, 'scope')->sys()->create();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load]);

    $picker = app(RunInputPicker::class);
    expect(array_keys($picker->optionsFor($this->user, ToolId::LoadAudit)['load']))->toBe([$load->id])
        ->and(array_keys($picker->optionsFor($this->user, ToolId::LoadAudit)['sys']))->toBe([$sys->id])
        ->and($picker->defaultsFor($this->user, ToolId::LoadAudit))->toBe(['load' => $load->id, 'sys' => $sys->id]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ExportStorageTest.php tests/Feature/PurgeExportsTest.php tests/Feature/ExportUploadEndpointsTest.php tests/Feature/ExportDropZoneTest.php tests/Feature/RunsTabTest.php`
Expected: FAIL: "Export ids must be uuids." from the object key, 422 on `scope_type` for the slot (`unfiled` not allowed), the drop zone posting `scope_type: 'environment'` and the user's numeric id. The picker test may already pass after Task 1; it pins the behaviour.

- [ ] **Step 3: Implement**

`app/Support/Exports/ExportStorage.php`: add `use App\Models\User;`, replace the second assertion in `objectKey()` and add the helper:

```php
    public function objectKey(ExportFile $file): string
    {
        $this->assertUuid((string) $file->id);
        $this->assertScopeKey((string) $file->scope_type, (string) $file->scope_id);
        $scope = preg_replace('/[^a-z]/', '', strtolower(class_basename((string) $file->scope_type))) ?: 'scope';
        $extension = in_array($file->content_type, ['xml', 'json', 'zip', 'xlsx', 'csv'], true) ? $file->content_type : 'bin';

        return "exports/{$scope}/{$file->scope_id}/{$file->id}.{$extension}";
    }
```

```php
    /** Datasets and environments have uuid keys; a user (the Unfiled scope) has a positive whole-number key. */
    private function assertScopeKey(string $scopeType, string $scopeId): void
    {
        if ($scopeType !== (new User)->getMorphClass()) {
            $this->assertUuid($scopeId);

            return;
        }

        if (preg_match('/^[1-9]\d{0,19}$/', $scopeId) !== 1) {
            throw new InvalidArgumentException('An unfiled export must belong to a user id.');
        }
    }
```

`app/Http/Requests/ExportSlotRequest.php`: add `use App\Models\User;`, then:

```php
    /** Unfiled is always the signed-in user, so its scope_id is ignored (the drop zone sends "me"). */
    public const array SCOPES = [
        'dataset' => Dataset::class,
        'environment' => Environment::class,
        'unfiled' => User::class,
    ];
```

and in `rules()` replace the `scope_id` line:

```php
            'scope_id' => $this->input('scope_type') === 'unfiled' ? ['nullable', 'string', 'max:64'] : ['required', 'uuid'],
```

`app/Http/Controllers/Exports/ExportSlotController.php`: replace the scope lookup:

```php
        $scopeId = (string) $request->validated('scope_id');
        $scope = match ($request->validated('scope_type')) {
            'dataset' => Dataset::query()->ownedByCurrentUser()->findOrFail($scopeId),
            'environment' => Environment::query()->findOrFail($scopeId),
            'unfiled' => $request->user(),
        };
```

and update the class docblock's first line to `Hands the browser a presigned PUT url so the file goes straight to R2, filed on a dataset, an environment or the user (Unfiled). No`.

`app/Livewire/ExportDropZone.php`: add `use App\Models\Environment;` and `use App\Models\User;`. In `serverFiles()`:

```php
            $files = ExportFile::query()
                ->where('scope_type', $this->scope->getMorphClass())->where('scope_id', (string) $this->scope->getKey())
                ->where('kind', $kind);
```

In `render()` replace the `scopeType` entry with:

```php
            'scopeType' => match (true) {
                $this->scope instanceof Dataset => 'dataset',
                $this->scope instanceof Environment => 'environment',
                default => 'unfiled',
            },
            // Unfiled is always the signed-in user's own, so the slot request names no id.
            'scopeId' => $this->scope instanceof User ? 'me' : (string) $this->scope->getKey(),
```

Update the class docblock's first line to `One drop target per export kind (all the scope accepts, or the ones given), on a dataset, an environment or Unfiled (the user).`

`resources/views/livewire/export-drop-zone.blade.php` line 84:

```blade
                        body: JSON.stringify({ scope_type: @js($scopeType), scope_id: @js($scopeId), kind: this.kind, file_name: file.name, file_size: file.size }),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ExportStorageTest.php tests/Feature/PurgeExportsTest.php tests/Feature/ExportUploadEndpointsTest.php tests/Feature/ExportDropZoneTest.php tests/Feature/RunsTabTest.php tests/Feature/ExportsTabTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/SummariseExportFileJobTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Support/Exports/ExportStorage.php app/Http/Requests/ExportSlotRequest.php app/Http/Controllers/Exports/ExportSlotController.php \
  app/Livewire/ExportDropZone.php resources/views/livewire/export-drop-zone.blade.php \
  tests/Feature/ExportStorageTest.php tests/Feature/PurgeExportsTest.php tests/Feature/ExportUploadEndpointsTest.php tests/Feature/ExportDropZoneTest.php tests/Feature/RunsTabTest.php
git commit -F - <<'EOF'
Unfiled scope: upload any export kind to Unfiled (#45)

The upload slot takes scope_type unfiled and files the upload on the signed-in
user (scope_id is ignored; the drop zone sends "me"). Object keys for a user are
exports/user/<id>/<uuid>, so purge and runs read them like any other.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 3: Starting and running an Unfiled run (StartToolRun, RunToolJob)

**Files:**
- Modify: `app/Support/Tools/StartToolRun.php`
- Modify: `app/Jobs/RunToolJob.php` (`handle()`, `scopeOf()`, `belongsTo()`, new `optionTimeZone()`, the "does not belong" message)
- Test: `tests/Feature/StartToolRunTest.php`, `tests/Feature/RunToolJobTest.php`

**Interfaces:**
- Consumes (Task 1): `Tool::acceptsUnfiled()`, `User::toolRuns()`, `User::rememberToolContext(..., bool $unfiled, ?string $timeZone)`, `RunInputPicker::optionsFor(User, ToolId)` (Task 1 and 2).
- Produces:
  - `StartToolRun::start(Model $scope, ToolId $toolId, array $files, array $options, User $user): ToolRun` (signature unchanged) accepts `$scope` = `$user` for an accepting tool and throws `InvalidArgumentException` for a tool that needs a dataset or for another user. It remembers `unfiled: true`, `timeZone: $options['time_zone']` and keeps the previously remembered environment and dataset.
  - `RunToolJob` builds `ToolContext($run, $user, null, $zone, false)` for a User scope, where `$zone` is `inputs.options.time_zone` when it is a real IANA name, else `config('app.timezone')`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StartToolRunTest.php` (its imports already cover these tests):

```php
it('starts an unfiled run on the user and remembers Unfiled, the zone and the last dataset', function () {
    $this->user->rememberToolContext($this->dataset->environment_id, $this->dataset->id);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    $run = app(StartToolRun::class)->start($this->user, ToolId::LoadAudit, ['load' => $load->id], ['time_zone' => 'America/Regina'], $this->user)->fresh();

    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->id)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']])
        ->and($this->user->fresh()->last_tool_context)->toBe([
            'environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id, 'unfiled' => true, 'time_zone' => 'America/Regina',
        ]);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('refuses another user unfiled file, or a dataset file, on an unfiled run', function () {
    $theirs = ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load]);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    foreach ([$theirs, $datasetLoad] as $file) {
        expect(fn () => app(StartToolRun::class)->start($this->user, ToolId::LoadAudit, ['load' => $file->id], [], $this->user))
            ->toThrow(UnusableInputException::class, 'The LOAD export you chose is no longer available. Choose or upload another.');
    }
    expect(ToolRun::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an unfiled run for a tool that needs a dataset, or on another user', function () {
    app(ToolRegistry::class)->register(ToolId::RegionAvailability, FakeTool::class);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    expect(fn () => app(StartToolRun::class)->start($this->user, ToolId::RegionAvailability, ['load' => $load->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(StartToolRun::class)->start(User::factory()->create(), ToolId::LoadAudit, ['load' => $load->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class);
    expect(ToolRun::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});
```

`tests/Feature/RunToolJobTest.php`: add `use App\Models\User;` and:

```php
/** A queued Unfiled run of the fake tool on a LOAD stored on $user. */
function unfiledRunFor(User $user, array $options): ToolRun
{
    $load = ExportFile::factory()->for($user, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'summary' => ['export_date' => '2026-10-11']]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($load), '<dsScheduleData/>');

    return ToolRun::factory()->for($user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null,
        'inputs' => ['files' => ['load' => $load->id], 'options' => $options],
    ]);
}

it('runs an unfiled run in the zone chosen for it, with no dataset settings', function () {
    $run = unfiledRunFor(User::factory()->create(), ['time_zone' => 'America/Regina']);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($run->result['time_zone'])->toBe('America/Regina')
        ->and($run->result['display_name'])->toBeNull()
        ->and($run->summary)->toBe(['counts' => ['red' => 1]])
        ->and($run->run_date->toDateString())->toBe('2026-10-11');
});

it('falls back to the app zone for an unfiled run with a missing or unknown zone', function (array $options) {
    config(['app.timezone' => 'America/Toronto']);
    $run = unfiledRunFor(User::factory()->create(), $options);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Succeeded)
        ->and($run->result['time_zone'])->toBe('America/Toronto');
})->with([
    'no zone' => [[]],
    'a blank zone' => [['time_zone' => '']],
    'an unknown zone' => [['time_zone' => 'Mars/Olympus']],
]);

it('refuses a file of another user on an unfiled run', function () {
    $owner = User::factory()->create();
    $foreign = ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($foreign), '<dsScheduleData/>');
    $run = ToolRun::factory()->for($owner, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null,
        'inputs' => ['files' => ['load' => $foreign->id], 'options' => ['time_zone' => 'UTC']],
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('does not belong');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/StartToolRunTest.php tests/Feature/RunToolJobTest.php`
Expected: FAIL: the remembered context has the user id as `environment_id` and `unfiled => false`; no `InvalidArgumentException` is thrown; the job fails the unfiled runs with "The dataset or environment this run belongs to no longer exists."

- [ ] **Step 3: Implement**

`app/Support/Tools/StartToolRun.php`: add `use InvalidArgumentException;`. Update the class docblock to `The one way a tool run starts, from the Runs tab or a wizard: checks the chosen files are still usable on this scope (a dataset, an environment, or the user for Unfiled), creates the queued run, dispatches the job and remembers the context on the user.` Then in `start()` add the guard as the first statement and replace the remember call:

```php
    public function start(Model $scope, ToolId $toolId, array $files, array $options, User $user): ToolRun
    {
        if ($scope instanceof User && ($scope->isNot($user) || ! $toolId->acceptsUnfiled())) {
            throw new InvalidArgumentException("{$toolId->getLabel()} cannot run on these unfiled exports.");
        }

        $tool = $this->registry->for($toolId);
```

(the body up to `RunToolJob::dispatch($run->id);` is unchanged), then:

```php
        RunToolJob::dispatch($run->id);

        $this->remember($scope, $options, $user);

        return $run;
    }

    /**
     * An Unfiled run keeps the environment and dataset remembered before it, so a dataset tool still opens on them.
     *
     * @param  array<string, mixed>  $options
     */
    private function remember(Model $scope, array $options, User $user): void
    {
        if ($scope instanceof User) {
            $previous = $user->last_tool_context ?? [];
            $user->rememberToolContext(
                environmentId: $previous['environment_id'] ?? null,
                datasetId: $previous['dataset_id'] ?? null,
                unfiled: true,
                timeZone: is_string($options['time_zone'] ?? null) && $options['time_zone'] !== '' ? $options['time_zone'] : null,
            );

            return;
        }

        $user->rememberToolContext(
            environmentId: $scope instanceof Dataset ? $scope->environment_id : (string) $scope->getKey(),
            datasetId: $scope instanceof Dataset ? $scope->getKey() : null,
        );
    }
```

`app/Jobs/RunToolJob.php`: add `use App\Models\User;` and `use DateTimeZone;`. In `handle()` replace the two lines that set `$settings` and `$timeZone`:

```php
            $settings = $scope instanceof Dataset ? $scope->settings : null;
            $timeZone = $scope instanceof Dataset ? $scope->timeZone() : self::optionTimeZone($run);
```

Replace `scopeOf()`:

```php
    private function scopeOf(ToolRun $run): Model
    {
        $scope = match ($run->scope_type) {
            (new Dataset)->getMorphClass() => Dataset::query()->withoutGlobalScopes()->find($run->scope_id),
            (new Environment)->getMorphClass() => Environment::query()->withoutGlobalScopes()->find($run->scope_id),
            (new User)->getMorphClass() => User::query()->find($run->scope_id),
            default => null,
        };

        return $scope ?? throw new RuntimeException('The dataset or environment this run belongs to no longer exists.');
    }

    /** An Unfiled run's own zone (the wizard's Time zone option); missing, blank or unknown is the app zone. */
    private static function optionTimeZone(ToolRun $run): string
    {
        $zone = $run->inputs['options']['time_zone'] ?? null;

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : (string) config('app.timezone', 'UTC');
    }
```

In `resolveInputs()` change the message:

```php
                throw new RuntimeException("The file chosen as the {$kind->getLabel()} export does not belong to this run's dataset, environment or unfiled files.");
```

Replace `belongsTo()`:

```php
    /** A file may sit on the run's own scope (a user's id is read back as a string), or on the environment of a dataset run (a SYS). */
    private function belongsTo(ExportFile $file, Model $scope): bool
    {
        if ($file->scope_type === $scope->getMorphClass() && (string) $file->scope_id === (string) $scope->getKey()) {
            return true;
        }

        return $scope instanceof Dataset
            && $file->scope_type === (new Environment)->getMorphClass()
            && $file->scope_id === $scope->environment_id;
    }
```

`$timeZoneIsDefault` stays `$scope instanceof Dataset && ! $scope->hasTimeZone()`: an Unfiled run always has an explicit zone.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/StartToolRunTest.php tests/Feature/RunToolJobTest.php tests/Feature/RunsTabTest.php tests/Feature/ToolWizardPageTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Support/Tools/StartToolRun.php app/Jobs/RunToolJob.php tests/Feature/StartToolRunTest.php tests/Feature/RunToolJobTest.php
git commit -F - <<'EOF'
Unfiled scope: start and run a tool on the user's own files (#45)

StartToolRun takes the user as scope for Trace an activity, Load Health Audit and
Plan travels only, and remembers Unfiled with the chosen zone. RunToolJob reads
the zone from the run's options (the app zone when missing or unknown) and passes
no dataset settings.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 4: The wizard's Where step offers Unfiled

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php` (constants, `mount()`, `whereStep()`, `hasScope()`, new `isUnfiled()`, `scopeModel()`, `pendingKinds()`, class docblock)
- Create: `tests/Feature/ToolWizardUnfiledTest.php`
- Modify tests: `tests/Feature/ToolWizardPageTest.php` (beforeEach, "pre-fills the only environment and dataset when nothing was remembered"), `tests/Feature/PlanTravels/PlanTravelsWizardTest.php` (beforeEach)

**Interfaces:**
- Consumes: `Tool::acceptsUnfiled()` (Task 1), `RunInputPicker` and drop zone on a User scope (Task 2), `StartToolRun::start($user, ...)` (Task 3).
- Produces:
  - `ToolWizardPage::WHERE_UNFILED = 'unfiled'`, `ToolWizardPage::WHERE_DATASET = 'dataset'` (public constants) and the form field `where` (only for accepting tools).
  - `protected function isUnfiled(): bool` (true only for an accepting tool with `data.where === 'unfiled'`), used by Task 5.
  - `scopeModel()` returns `auth()->user()` when `isUnfiled()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ToolWizardUnfiledTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\RegionAvailabilityEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    app(ToolRegistry::class)->register(ToolId::RegionAvailability, FakeTool::class);
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['name' => 'Acme PROD']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

afterEach(function () {
    FakeTool::$optionFields = [];
});

it('offers Unfiled or a dataset, defaults to Unfiled and hides the environment and dataset', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertFormFieldExists('where')
        ->assertFormSet(['where' => 'unfiled'])
        ->assertSee('Where the exports live.')
        ->assertSee('Just the file. Nothing is sent anywhere and the run is listed under My files.')
        ->assertSee('The exports belong to an environment and dataset you have set up.')
        ->assertFormFieldHidden('environment_id')
        ->assertFormFieldHidden('dataset_id');
});

it('shows the environment and dataset once A dataset is chosen, and swaps the files', function () {
    $unfiledLoad = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $unfiledLoad->id])
        ->fillForm(['where' => 'dataset'])
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id')
        ->assertFormSet(['files.load' => $datasetLoad->id])
        ->fillForm(['where' => 'unfiled'])
        ->assertFormSet(['files.load' => $unfiledLoad->id]);
});

it('opens on the choice remembered from the last run', function () {
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'dataset', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id])
        ->assertFormFieldVisible('dataset_id');

    $this->user->rememberToolContext($this->environment->id, $this->dataset->id, unfiled: true);
    Livewire::test(LoadAuditEntry::class)->assertFormSet(['where' => 'unfiled']);
});

it('lists only the user own unfiled files on Inputs, SYS included, and uploads there as unfiled', function () {
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml']);
    $sys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['original_filename' => 'prod-sys.xml']);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north-load.xml']);
    ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'their-load.xml']);

    $html = Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $mine->id, 'files.sys' => $sys->id])
        ->assertSee('prod-load.xml')
        ->assertDontSee('north-load.xml')
        ->assertDontSee('their-load.xml')
        ->assertDontSee('from the environment')
        ->html();

    expect($html)->toContain("scope_type: 'unfiled', scope_id: 'me', kind: this.kind")
        ->not->toContain("scope_type: 'dataset'");
});

it('starts an unfiled run on the user', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)->call('start')->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and($run->inputs['files'])->toBe(['load' => $load->id]);
});

it('keeps the Where step as it was for a tool that needs a dataset', function () {
    Livewire::test(RegionAvailabilityEntry::class)
        ->assertFormFieldDoesNotExist('where')
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id')
        ->assertSee('The environment and dataset the exports belong to.')
        ->assertDontSee('Just the file.');
});

it('never runs a tool that needs a dataset on unfiled files, even when where is forced', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(RegionAvailabilityEntry::class)
        ->set('data.where', 'unfiled')
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->dataset->getMorphClass())
        ->and($run->inputs['files']['load'])->toBe($datasetLoad->id);
});
```

In `tests/Feature/ToolWizardPageTest.php` (these tests are about the dataset path, which a remembered dataset context selects):

- in `beforeEach`, after the dataset is created, add `$this->user->rememberToolContext($this->environment->id, $this->dataset->id);`
- replace the test `it('pre-fills the only environment and dataset when nothing was remembered', ...)` with:

```php
it('pre-fills the only environment and dataset when nothing was remembered, behind Unfiled', function () {
    $this->user->forceFill(['last_tool_context' => null])->save();

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
});
```

In `tests/Feature/PlanTravels/PlanTravelsWizardTest.php` `beforeEach`, after the dataset is created, add `$this->user->rememberToolContext($this->dataset->environment_id, $this->dataset->id);`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ToolWizardUnfiledTest.php`
Expected: FAIL with "Failed asserting that a field with the name [where] exists on the form".

- [ ] **Step 3: Implement**

`app/Filament/Pages/Tools/ToolWizardPage.php`: add `use Filament\Forms\Components\Radio;`. Update the class docblock to:

```php
/**
 * One guided page per tool: Where (Unfiled, or environment and dataset), Inputs (exports),
 * Options (when the tool has any, or for an Unfiled run's time zone), Run. Subclasses only name their tool.
 * Hidden from navigation until the tool has an implementation registered.
 */
```

Below `private const WIZARD_KEY = 'tool-wizard';` add:

```php
    /** The Where choice of a tool that accepts Unfiled: the user's own files, or a dataset. */
    public const WHERE_UNFILED = 'unfiled';

    public const WHERE_DATASET = 'dataset';
```

In `mount()`, replace the last three statements (from the comment `// fill() with no state ...`) with:

```php
        // fill() with no state applies the fields' own defaults (an option's default value); the scope and files go on top.
        $this->form->fill();
        $this->data = [...$this->data ?? [], 'environment_id' => $environmentId, 'dataset_id' => $datasetId, 'files' => $this->emptyFiles()];
        if (static::tool()->acceptsUnfiled()) {
            // The remembered choice, else Unfiled: a context saved before Unfiled existed has no `unfiled` key.
            $this->data['where'] = ($remembered['unfiled'] ?? true) === true ? self::WHERE_UNFILED : self::WHERE_DATASET;
        }
        $this->refreshInputs();
```

Replace `whereStep()`:

```php
    protected function whereStep(): Step
    {
        $acceptsUnfiled = static::tool()->acceptsUnfiled();
        // Only shown for "A dataset" when the tool accepts Unfiled; always shown otherwise (where is then unset).
        $showsSelects = static fn (Get $get): bool => $get('where') !== self::WHERE_UNFILED;

        return Step::make('Where')
            ->description(match (true) {
                $acceptsUnfiled => 'Where the exports live.',
                $this->isDatasetScoped() => 'The environment and dataset the exports belong to.',
                default => 'The environment the exports belong to.',
            })
            ->schema(array_values(array_filter([
                $acceptsUnfiled ? Radio::make('where')
                    ->hiddenLabel()
                    ->options([self::WHERE_UNFILED => 'Unfiled', self::WHERE_DATASET => 'A dataset'])
                    ->descriptions([
                        self::WHERE_UNFILED => 'Just the file. Nothing is sent anywhere and the run is listed under My files.',
                        self::WHERE_DATASET => 'The exports belong to an environment and dataset you have set up.',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(static fn (self $livewire) => $livewire->resetInputs())
                    : null,
                Select::make('environment_id')
                    ->label('Environment')
                    ->options(fn (): array => Environment::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->native(false)
                    ->visible($showsSelects)
                    ->afterStateUpdated(static function (Set $set, self $livewire): void {
                        $set('dataset_id', null);
                        $livewire->resetInputs();
                    })
                    ->createOptionForm(Environment::getForm())
                    // The form encrypts the password on dehydrate; the model's creating hook sets slug and commit token.
                    ->createOptionUsing(static fn (array $data): string => Environment::query()->create([...$data, 'user_id' => auth()->id()])->getKey()),
                $this->isDatasetScoped() ? Select::make('dataset_id')
                    ->label('Dataset')
                    ->options(fn (Get $get): array => $get('environment_id')
                        ? Dataset::query()->ownedByCurrentUser()->where('environment_id', $get('environment_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->required()
                    ->live()
                    ->native(false)
                    ->visible($showsSelects)
                    ->disabled(static fn (Get $get): bool => blank($get('environment_id')))
                    ->afterStateUpdated(static fn (self $livewire) => $livewire->resetInputs())
                    ->belowContent(static fn (Get $get): ?Text => self::missingTimeZoneWarning($get('environment_id'), $get('dataset_id')))
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                        TextInput::make('rota')->required()->helperText('The PSO rota id for this dataset.'),
                    ])
                    ->createOptionUsing(static function (array $data, Get $get): string {
                        // Only into one of the user's own environments.
                        $environment = Environment::query()->findOrFail($get('environment_id'));

                        return Dataset::query()->create([...$data, 'environment_id' => $environment->getKey()])->getKey();
                    })
                    : null,
            ])));
    }
```

Replace `hasScope()` and add `isUnfiled()` below it:

```php
    protected function hasScope(): bool
    {
        if ($this->isUnfiled()) {
            return true;
        }

        return filled($this->data['environment_id'] ?? null) && (! $this->isDatasetScoped() || filled($this->data['dataset_id'] ?? null));
    }

    /** An accepting tool with Unfiled chosen. A forced `where` on any other tool is ignored. */
    protected function isUnfiled(): bool
    {
        return static::tool()->acceptsUnfiled() && ($this->data['where'] ?? null) === self::WHERE_UNFILED;
    }
```

In `pendingKinds()` compare the key as a string:

```php
            return ExportFile::query()->where('scope_type', $uploadScope->getMorphClass())->where('scope_id', (string) $uploadScope->getKey())
                ->where('kind', $kind)->pending()->exists();
```

Replace `scopeModel()`:

```php
    /** The chosen scope: the user for Unfiled, else the environment or dataset, or 404 when it is not one of the user's own. */
    protected function scopeModel(): Model
    {
        if ($this->isUnfiled()) {
            return auth()->user();
        }

        $environment = Environment::query()->findOrFail($this->data['environment_id'] ?? null);
        if (! $this->isDatasetScoped()) {
            return $environment;
        }

        return Dataset::query()->ownedByCurrentUser()->where('environment_id', $environment->id)->findOrFail($this->data['dataset_id'] ?? null);
    }
```

`isDatasetScoped()` keeps its meaning (the tool's own scope) and now only decides which selects exist.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ToolWizardUnfiledTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/PlanTravels/PlanTravelsWizardTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php tests/Feature/LiveEnvironmentBannerTest.php tests/Feature/DashboardTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Filament/Pages/Tools/ToolWizardPage.php tests/Feature/ToolWizardUnfiledTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/PlanTravels/PlanTravelsWizardTest.php
git commit -F - <<'EOF'
Unfiled scope: the wizard's Where step offers Unfiled (#45)

For Trace an activity, Load Health Audit and Plan travels the Where step starts
with Unfiled or A dataset (the remembered choice, else Unfiled). Unfiled hides the
environment and dataset, lists the user's own unfiled files and uploads there.
Region availability and Sys compare keep the step as it was.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 5: The time zone option for an Unfiled run

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php` (`mount()`, `optionsStep()`, new `timeZoneField()`, new `rememberedTimeZone()`, `start()`; add `use DateTimeZone;`)
- Test: `tests/Feature/ToolWizardUnfiledTest.php`, `tests/Feature/ToolWizardPageTest.php` (the "has no Options step" test)

**Interfaces:**
- Consumes: `isUnfiled()`, `WHERE_UNFILED` (Task 4), `StartToolRun` remembering `time_zone` (Task 3), `RunToolJob` reading `inputs.options.time_zone` (Task 3).
- Produces: the form field `options.time_zone` (Select of `DateTimeZone::listIdentifiers()`), present on the Options step of every accepting tool and visible only for Unfiled; an Unfiled run's `inputs.options.time_zone`; a dataset run never carries `time_zone`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ToolWizardUnfiledTest.php`:

```php
it('asks for the time zone on Options for an Unfiled run, defaulting to the remembered zone', function () {
    config(['app.timezone' => 'America/Toronto']);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormFieldExists('options.time_zone')
        ->assertFormFieldVisible('options.time_zone')
        ->assertFormSet(['options.time_zone' => 'America/Toronto'])
        ->assertSee('The zone the pages show times in.');

    $this->user->rememberToolContext(null, null, unfiled: true, timeZone: 'America/Regina');
    Livewire::test(LoadAuditEntry::class)->assertFormSet(['options.time_zone' => 'America/Regina']);

    $this->user->rememberToolContext(null, null, unfiled: true, timeZone: 'Mars/Olympus');
    Livewire::test(LoadAuditEntry::class)->assertFormSet(['options.time_zone' => 'America/Toronto']);
});

it('has an Options step for the time zone alone when the tool has no options, hidden for a dataset run', function () {
    FakeTool::$optionFields = [];
    Livewire::test(LoadAuditEntry::class)
        ->assertSee('Settings for this run')
        ->assertFormFieldVisible('options.time_zone');

    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    Livewire::test(LoadAuditEntry::class)->assertFormFieldHidden('options.time_zone');

    Livewire::test(RegionAvailabilityEntry::class)
        ->assertFormFieldDoesNotExist('options.time_zone')
        ->assertDontSee('Settings for this run');
});

it('starts an unfiled run with the chosen zone and remembers it with the last dataset', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id, unfiled: true);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['options' => ['time_zone' => 'America/Regina']])
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->id)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']])
        ->and($this->user->fresh()->last_tool_context)->toBe([
            'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id, 'unfiled' => true, 'time_zone' => 'America/Regina',
        ]);
});

it('refuses a time zone that is not in the list', function () {
    Queue::fake();
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->set('data.options.time_zone', 'Mars/Olympus')
        ->call('start')
        ->assertHasFormErrors(['options.time_zone']);

    expect(ToolRun::count())->toBe(0);
});

it('keeps the time zone out of a dataset run', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->set('data.options.time_zone', 'America/Regina')
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options'])->toBe([]);
});
```

In `tests/Feature/ToolWizardPageTest.php` add `use App\Filament\Pages\Tools\RegionAvailabilityEntry;` and replace the test `it('has no Options step when the tool has no option fields, and one when it has', ...)` with:

```php
it('has no Options step when a dataset tool has no option fields, and one when it has', function () {
    app(ToolRegistry::class)->register(ToolId::RegionAvailability, FakeTool::class);
    FakeTool::$optionFields = [];
    Livewire::test(RegionAvailabilityEntry::class)->assertDontSee('Settings for this run');

    FakeTool::$optionFields = [TextInput::make('options.ifs_release')->label('IFS Cloud release')];
    Livewire::test(LoadAuditEntry::class)->assertSee('Settings for this run')->assertFormFieldExists('options.ifs_release');
    FakeTool::$optionFields = [];
});
```

(Load audit accepts Unfiled, so it now always has an Options step for the time zone.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ToolWizardUnfiledTest.php`
Expected: FAIL with "Failed asserting that a field with the name [options.time_zone] exists on the form".

- [ ] **Step 3: Implement**

`app/Filament/Pages/Tools/ToolWizardPage.php`: add `use DateTimeZone;`.

In `mount()`, inside the `if (static::tool()->acceptsUnfiled())` block added in Task 4, after the `where` line, add:

```php
            $this->data['options'] = [...($this->data['options'] ?? []), 'time_zone' => self::rememberedTimeZone($remembered)];
```

Replace `optionsStep()` and add the two helpers below it:

```php
    protected function optionsStep(): Step
    {
        $fields = app(ToolRegistry::class)->for(static::tool())->optionFields();
        if (static::tool()->acceptsUnfiled()) {
            // Appended by the wizard, never by a tool: an Unfiled run has no dataset zone.
            $fields[] = $this->timeZoneField();
        }

        return Step::make('Options')
            ->description('Settings for this run. Leave blank for the defaults.')
            ->schema($fields)
            ->hidden($fields === []);
    }

    /** The zone an Unfiled run's pages show times in; hidden (so neither validated nor kept) for a dataset run. */
    private function timeZoneField(): Select
    {
        $zones = DateTimeZone::listIdentifiers();

        return Select::make('options.time_zone')
            ->label('Time zone')
            ->helperText('The zone the pages show times in.')
            ->options(array_combine($zones, $zones))
            ->searchable()
            ->required()
            ->native(false)
            ->visible(static fn (Get $get): bool => $get('where') === self::WHERE_UNFILED);
    }

    /**
     * The zone an Unfiled run starts with: the one last used when it is still a known zone, else the app's own.
     *
     * @param  array<string, mixed>  $remembered
     */
    private static function rememberedTimeZone(array $remembered): string
    {
        $zone = $remembered['time_zone'] ?? null;

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : (string) config('app.timezone', 'UTC');
    }
```

In `start()`, replace the line that calls `StartToolRun::start()` with:

```php
            $options = $state['options'] ?? [];
            if (! $this->isUnfiled()) {
                // Only an Unfiled run takes a zone; a dataset run uses the dataset's.
                unset($options['time_zone']);
            }
            $run = app(StartToolRun::class)->start($scope, static::tool(), $state['files'] ?? [], $options, auth()->user());
```

The Select validates its value against its options, so a zone not in the list is a form error.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ToolWizardUnfiledTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/PlanTravels/PlanTravelsWizardTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php tests/Feature/LoadAudit/LoadAuditPagesTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Filament/Pages/Tools/ToolWizardPage.php tests/Feature/ToolWizardUnfiledTest.php tests/Feature/ToolWizardPageTest.php
git commit -F - <<'EOF'
Unfiled scope: choose the time zone for an Unfiled run (#45)

The Options step of an accepting tool gains a searchable Time zone select, shown
for Unfiled only, defaulting to the zone remembered from the last Unfiled run or
the app zone. A dataset run never carries a zone option.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 6: Tool pages name an Unfiled run after its file

**Files:**
- Create: `app/Tools/UnfiledLabel.php`
- Modify: `app/Tools/LoadAudit/LoadAuditTool.php:63-66`, `app/Tools/PlanTravels/PlanTravelsTool.php:152`, `app/Tools/ActivityTrace/ActivityTraceTool.php:66-69`
- Test: `tests/Feature/LoadAudit/LoadAuditToolTest.php`, `tests/Feature/PlanTravels/PlanTravelsToolTest.php`, `tests/Feature/ActivityTrace/ActivityTraceToolTest.php`

**Interfaces:**
- Consumes: `ToolContext` (unchanged: `run`, `scope`, `settings`, `timeZone`, `timeZoneIsDefault`), a User scope from `RunToolJob` (Task 3).
- Produces: `App\Tools\UnfiledLabel::for(ToolContext $context, ExportKind $kind): ?string`, the original file name of the run's `$kind` input when the scope is a User, else null.

Which file names the page: Load audit uses the LOAD; Plan travels uses the Plan export (its only required input; the spec says "LOAD" but Plan travels takes no LOAD); Trace an activity uses the Audit messages file.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/LoadAudit/LoadAuditToolTest.php`: add `use App\Models\User;` and:

```php
it('labels an unfiled audit with the LOAD file name', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);
    $user = User::factory()->create();
    $load = ExportFile::factory()->for($user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod_north_load_1013.xml']);
    $run = ToolRun::factory()->for($user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null,
        'inputs' => ['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']],
    ]);

    (new LoadAuditTool)->run(new ToolContext($run, $user, null, 'America/Regina'), ['load' => $this->scratch.'/load.xml'], []);

    $argv = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($argv[array_search('--org', $argv, true) + 1])->toBe('prod_north_load_1013.xml')
        ->and($argv[array_search('--tz', $argv, true) + 1])->toBe('America/Regina');
});

it('still passes no label for a dataset with no display name', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);
    $dataset = Dataset::factory()->create(['settings' => ['time_zone' => 'America/Regina']]);
    $load = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.xml']);
    $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null, 'inputs' => ['files' => ['load' => $load->id], 'options' => []]]);

    (new LoadAuditTool)->run(new ToolContext($run, $dataset, $dataset->settings, 'America/Regina'), ['load' => $this->scratch.'/load.xml'], []);

    expect(json_decode(file_get_contents($argsFile), true)['argv'])->not->toContain('--org');
});
```

`tests/Feature/PlanTravels/PlanTravelsToolTest.php`:

```php
it('names an unfiled run after the plan export file', function () {
    Http::fake();
    $plan = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Plan, 'original_filename' => 'north_plan_1005.xml', 'summary' => ['export_date' => '2026-10-05']]);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::PlanTravels, 'result' => null, 'progress' => null, 'inputs' => ['files' => ['plan' => $plan->id], 'options' => []],
    ]);

    app(PlanTravelsTool::class)->run(new ToolContext($run, $this->user, null, 'America/Regina'), $this->inputs, ['first_date' => '2026-10-05', 'days' => 1, 'google' => false]);

    $argv = planCalls($this->argsFile)[0]['argv'];
    expect($argv[array_search('--dataset-name', $argv, true) + 1])->toBe('north_plan_1005.xml');
});
```

`tests/Feature/ActivityTrace/ActivityTraceToolTest.php`: add `use App\Models\ExportFile;` and:

```php
it('names an unfiled trace after the audits file and passes the chosen zone, with no IFS zone', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeTraceEnv('FAKE_TRACE_ARGS_FILE', $argsFile);
    $audits = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Audits, 'original_filename' => 'audits_1004.zip', 'content_type' => 'zip']);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::AppointmentTimeline, 'result' => null, 'progress' => null,
        'inputs' => ['files' => ['audits' => $audits->id], 'options' => ['activity_id' => 'abc', 'time_zone' => 'America/Regina']],
    ]);

    (new ActivityTraceTool)->run(new ToolContext($run, $this->user, null, 'America/Regina'), ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => 'abc']);

    $argv = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($argv[array_search('--dataset-name', $argv, true) + 1])->toBe('audits_1004.zip')
        ->and($argv[array_search('--tz', $argv, true) + 1])->toBe('America/Regina')
        ->and($argv)->not->toContain('--ifs-tz');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/LoadAudit/LoadAuditToolTest.php tests/Feature/PlanTravels/PlanTravelsToolTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php`
Expected: FAIL: `array_search` returns false for `--org` and `--dataset-name`, so the index lookups fail ("Undefined array key 1" or the wrong value).

- [ ] **Step 3: Implement**

Create `app/Tools/UnfiledLabel.php`:

```php
<?php

namespace App\Tools;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Models\User;

/**
 * An Unfiled run has no dataset, so a tool page names it after the file it read: the original file name of
 * the run's input of one kind. It goes only into the script's argv and so into the encrypted result page.
 */
class UnfiledLabel
{
    public static function for(ToolContext $context, ExportKind $kind): ?string
    {
        if (! $context->scope instanceof User) {
            return null;
        }

        $fileId = $context->run->inputs['files'][$kind->value] ?? null;
        if (blank($fileId)) {
            return null;
        }

        $name = ExportFile::withoutGlobalScopes()->whereKey($fileId)->value('original_filename');

        return filled($name) ? (string) $name : null;
    }
}
```

`app/Tools/LoadAudit/LoadAuditTool.php` (`ExportKind` is already imported): add `use App\Tools\UnfiledLabel;` and change the build call:

```php
        $argv = LoadAuditCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZone,
            $context->settings?->displayName ?: UnfiledLabel::for($context, ExportKind::Load),
        );
```

`app/Tools/PlanTravels/PlanTravelsTool.php`: add `use App\Tools\UnfiledLabel;` and change line 152:

```php
        $datasetName = $context->settings?->displayName ?: ($context->scope instanceof Dataset ? $context->scope->name : UnfiledLabel::for($context, ExportKind::Plan));
```

`app/Tools/ActivityTrace/ActivityTraceTool.php`: add `use App\Tools\UnfiledLabel;` and change the last argument of the build call:

```php
        $argv = ActivityTraceCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZoneIsDefault ? null : $context->timeZone, $ifsTimeZone,
            $context->scope instanceof Dataset ? $context->scope->name : UnfiledLabel::for($context, ExportKind::Audits),
        );
```

The IFS zone branch for a non-Dataset scope already returns null, so the trace reads IFS times in the run's zone.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/LoadAudit tests/Feature/PlanTravels tests/Feature/ActivityTrace tests/Unit/LoadAudit`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Tools/UnfiledLabel.php app/Tools/LoadAudit/LoadAuditTool.php app/Tools/PlanTravels/PlanTravelsTool.php app/Tools/ActivityTrace/ActivityTraceTool.php \
  tests/Feature/LoadAudit/LoadAuditToolTest.php tests/Feature/PlanTravels/PlanTravelsToolTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php
git commit -F - <<'EOF'
Unfiled scope: tool pages name an Unfiled run after its file (#45)

With no dataset, the Load audit header, the Plan travels page and the trace page
show the original name of the LOAD, plan or audits file the run read.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 7: Run pages and downloads at `unfiled/me`

**Files:**
- Modify: `routes/web.php` (the tool-runs group)
- Modify: `app/Http/Controllers/ToolRunPageController.php` (every action's parameters, `find()`, `fileName()`, new `params()`)
- Modify: `app/Filament/Pages/ToolRunPage.php` (`urlFor()`, `loadToolRun()`, class docblock)
- Modify: `resources/views/filament/tool-runs/load_audit.blade.php`, `appointment_timeline.blade.php`, `plan_travels.blade.php`, `region_availability.blade.php`
- Create: `tests/Feature/UnfiledRunPagesTest.php`
- Modify tests (route parameters only): `tests/Feature/LoadAudit/LoadAuditPagesTest.php`, `tests/Feature/ActivityTrace/ActivityTraceToolTest.php`, `tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php`, `tests/Feature/PlanTravels/PlanTravelsRunPageTest.php`

**Interfaces:**
- Consumes: User-scoped runs (Task 3), the wizard run panel linking with `ToolRunPage::urlFor($run->scope, $run)` (unchanged view).
- Produces:
  - Routes `tool-runs.page`, `tool-runs.download-html`, `tool-runs.download-json`, `tool-runs.download-csv`, `tool-runs.download-booking-csv`, `tool-runs.download-history-csv` at `{scopeType}/{scopeId}/runs/{run}/...`, `scopeType` in `datasets|unfiled`.
  - `ToolRunPageController::params(Model $scope, ToolRun $run): array{scopeType: string, scopeId: string, run: string}`
  - `ToolRunPageController::fileName(Model $scope, ToolRun $run, string $extension): string` (`<tool>_unfiled_<date>...` for a user)
  - `ToolRunPage::urlFor($user, $run)` ends with `/unfiled/me/runs/{run}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/UnfiledRunPagesTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Http\Controllers\ToolRunPageController;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'ACME']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'run_date' => '2026-10-04',
        'result' => ['html' => '<!doctype html><html><body>Audit page</body></html>', 'findings' => [['check' => 'x']], 'counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
        'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
    ]);
    $this->actingAs($this->user);
});

it('builds unfiled route parameters with no user id', function () {
    expect(ToolRunPageController::params($this->user, $this->run))->toBe(['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $this->run->id])
        ->and(ToolRunPageController::params($this->dataset, $this->run))->toBe(['scopeType' => 'datasets', 'scopeId' => $this->dataset->id, 'run' => $this->run->id])
        ->and(route('tool-runs.page', ToolRunPageController::params($this->user, $this->run)))->toEndWith("/unfiled/me/runs/{$this->run->id}/page")
        ->and(ToolRunPage::urlFor($this->user, $this->run))->toEndWith("/unfiled/me/runs/{$this->run->id}");
});

it('opens an unfiled run page with its sandboxed page and downloads', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('2 red')
        ->assertSeeHtml(e(route('tool-runs.page', ToolRunPageController::params($this->user, $this->run))))
        ->assertSeeHtml(e(route('tool-runs.download-json', ToolRunPageController::params($this->user, $this->run))));
});

it('does not open another user unfiled run, or an unfiled run under any id but me', function () {
    $theirs = ToolRun::factory()->for(User::factory()->create(), 'scope')->create();

    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $theirs->id])->assertNotFound();
    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => (string) $this->user->id, 'run' => $this->run->id])->assertNotFound();
});

it('serves the page and downloads of an unfiled run and names the files unfiled', function () {
    $params = ToolRunPageController::params($this->user, $this->run);

    $this->get(route('tool-runs.page', $params))
        ->assertOk()
        ->assertSee('Audit page', false)
        ->assertHeader('Content-Security-Policy', ToolRunPageController::SANDBOX);
    $this->get(route('tool-runs.download-html', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04.html');
    $this->get(route('tool-runs.download-json', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04.json');
});

it('gives 404 on the unfiled routes for another user run, a dataset run, or an id other than me', function () {
    $theirs = ToolRun::factory()->for(User::factory()->create(), 'scope')->create(['result' => ['html' => '<p>x</p>']]);
    $datasetRun = ToolRun::factory()->for($this->dataset, 'scope')->create(['result' => ['html' => '<p>x</p>']]);

    $this->get(route('tool-runs.page', ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $theirs->id]))->assertNotFound();
    $this->get(route('tool-runs.page', ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $datasetRun->id]))->assertNotFound();
    $this->get(route('tool-runs.page', ['scopeType' => 'unfiled', 'scopeId' => (string) $this->user->id, 'run' => $this->run->id]))->assertNotFound();
    $this->get("/environments/x/runs/{$this->run->id}/page")->assertNotFound();
});

it('links the wizard run panel of an unfiled run to unfiled/me', function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)->call('start')->assertHasNoFormErrors();
    $run = ToolRun::query()->whereKeyNot($this->run->id)->sole();

    $component->assertSee(ToolRunPage::urlFor($this->user, $run), false);
    expect(ToolRunPage::urlFor($this->user, $run))->toContain('/unfiled/me/runs/');
});
```

Rewrite the existing route calls from positional `[$dataset, $run]` to named parameters (the dataset id is the dataset model's route key, so `$this->dataset` and `$other->scope_id` both work):

```bash
perl -pi -e 's/route\(\x27tool-runs\.([a-z-]+)\x27, \[(\$[^,\]]+), (\$[^\]]+)\]\)/route(\x27tool-runs.$1\x27, [\x27scopeType\x27 => \x27datasets\x27, \x27scopeId\x27 => $2, \x27run\x27 => $3])/g' \
  tests/Feature/LoadAudit/LoadAuditPagesTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php \
  tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php tests/Feature/PlanTravels/PlanTravelsRunPageTest.php
grep -rn "tool-runs\.[a-z-]*', \[\\$" tests
```

The `grep` must print nothing. A rewritten line reads, for example, `route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UnfiledRunPagesTest.php tests/Feature/LoadAudit/LoadAuditPagesTest.php`
Expected: FAIL: "Call to undefined method ToolRunPageController::params()", and the rewritten dataset tests fail with "Missing required parameter for [Route: tool-runs.page]" (the route still names `{dataset}`).

- [ ] **Step 3: Implement**

`routes/web.php`: replace the tool-runs group:

```php
Route::middleware(['web', Authenticate::class])
    ->prefix('{scopeType}/{scopeId}/runs/{run}')
    ->where(['scopeType' => 'datasets|unfiled'])
    ->controller(ToolRunPageController::class)
    ->group(static function (): void {
        Route::get('page', 'page')->name('tool-runs.page');
        Route::get('download/html', 'downloadHtml')->name('tool-runs.download-html');
        Route::get('download/json', 'downloadJson')->name('tool-runs.download-json');
        Route::get('download/csv', 'downloadCsv')->name('tool-runs.download-csv');
        Route::get('download/booking-csv', 'downloadBookingCsv')->name('tool-runs.download-booking-csv');
        Route::get('download/history-csv', 'downloadHistoryCsv')->name('tool-runs.download-history-csv');
    });
```

`app/Http/Controllers/ToolRunPageController.php`: add `use App\Models\User;`, `use Illuminate\Database\Eloquent\Model;`, `use InvalidArgumentException;`. Every public action takes `(string $scopeType, string $scopeId, string $run)` and passes all three to `find()` (or `csv()`):

```php
    public function page(string $scopeType, string $scopeId, string $run): Response
    {
        [, $toolRun] = $this->find($scopeType, $scopeId, $run);

        return response($toolRun->result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $toolRun->tool === ToolId::PlanTravels ? self::SANDBOX_WITH_APP_LINKS : self::SANDBOX,
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadHtml(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo $toolRun->result['html'];
        }, self::fileName($scope, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The run's data file: the viewer's gathered JSON (`data`), the audit's findings (`findings`), or for a
     * run that stored only its page (Plan travels) the data the page embeds.
     */
    public function downloadJson(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);
        $payload = $toolRun->result['data'] ?? $toolRun->result['findings'] ?? EmbeddedPageData::fromHtml($toolRun->result['html']) ?? [];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, self::fileName($scope, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    public function downloadBookingCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'booking_csv', 'booking');
    }

    public function downloadHistoryCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'history_csv', 'history');
    }

    public function downloadCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'csv', null);
    }

    /** One of the run's stored CSV texts (`$key` in the result), named like the other downloads plus `_$suffix`. */
    private function csv(string $scopeType, string $scopeId, string $run, string $key, ?string $suffix): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);
        $text = $toolRun->result[$key] ?? null;
        if (! is_string($text)) {
            abort(404);
        }

        $name = self::fileName($scope, $toolRun, 'csv');
        if ($suffix !== null) {
            $name = str_replace('.csv', "_{$suffix}.csv", $name);
        }

        return response()->streamDownload(static function () use ($text): void {
            echo $text;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The route parameters of a run's page and downloads: a dataset by id, or Unfiled as `unfiled/me`
     * (always the signed-in user's own, so the url names no id).
     *
     * @return array{scopeType: string, scopeId: string, run: string}
     */
    public static function params(Model $scope, ToolRun $run): array
    {
        return match (true) {
            $scope instanceof User => ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => (string) $run->getKey()],
            $scope instanceof Dataset => ['scopeType' => 'datasets', 'scopeId' => (string) $scope->getRouteKey(), 'run' => (string) $run->getKey()],
            default => throw new InvalidArgumentException('Only dataset and unfiled runs have a page here.'),
        };
    }

    /** @return array{0: Dataset|User, 1: ToolRun} the user's dataset (or the user for Unfiled) and a finished run on it that holds a page, else 404 */
    private function find(string $scopeType, string $scopeId, string $run): array
    {
        $scope = match ($scopeType) {
            'datasets' => Dataset::query()->ownedByCurrentUser()->findOrFail($scopeId),
            'unfiled' => $scopeId === 'me' ? auth()->user() : abort(404),
            default => abort(404),
        };
        $toolRun = ToolRun::query()
            ->where('scope_type', $scope->getMorphClass())
            ->where('scope_id', (string) $scope->getKey())
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$scope, $toolRun];
    }

    /**
     * <tool>_<account>_<dataset>_<week Monday or run date>[_activity<id> or _worktask<id>].<ext>, with `unfiled`
     * in place of <account>_<dataset> for an Unfiled run, cleaned to letters, digits, _ and -. The activity part
     * names what a trace run traced.
     */
    public static function fileName(Model $scope, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);
        $date = $run->summary['week'] ?? $run->summary['first_date'] ?? $run->run_date?->toDateString() ?? 'undated';

        $activityId = $run->summary['activity_id'] ?? $run->inputs['options']['activity_id'] ?? null;
        $workTaskId = $run->summary['work_task_id'] ?? $run->inputs['options']['work_task_id'] ?? null;
        $subject = match (true) {
            is_scalar($activityId) && trim((string) $activityId) !== '' => '_activity'.$clean(trim((string) $activityId)),
            is_scalar($workTaskId) && trim((string) $workTaskId) !== '' => '_worktask'.$clean(trim((string) $workTaskId)),
            default => '',
        };
        $where = $scope instanceof Dataset ? $clean($scope->environment?->account_id).'_'.$clean($scope->name) : 'unfiled';

        return $run->tool->value.'_'.$where.'_'.$clean($date).$subject.'.'.$extension;
    }
```

`app/Filament/Pages/ToolRunPage.php`: add `use App\Models\User;`. Class docblock:

```php
/**
 * The dashboard of one tool run, reached from a dataset's or an environment's Runs tab, My files
 * (Unfiled, at unfiled/me) or the recent runs. The ToolRun global scope means another user's run is a 404.
 */
```

Replace `urlFor()` and `loadToolRun()`:

```php
    public static function urlFor(Model $scope, ToolRun $run): string
    {
        return static::getUrl([
            'scope' => match (true) {
                $scope instanceof User => 'unfiled',
                $scope instanceof Dataset => 'datasets',
                default => 'environments',
            },
            // Unfiled is always the signed-in user's own, so the url names no id.
            'scopeId' => $scope instanceof User ? 'me' : $scope->getRouteKey(),
            'run' => $run->getKey(),
        ]);
    }
```

```php
    private function loadToolRun(): ToolRun
    {
        $scopeModel = match ($this->scope) {
            'datasets' => Dataset::query()->ownedByCurrentUser()->findOrFail($this->scopeId),
            'environments' => Environment::query()->where('slug', $this->scopeId)->firstOrFail(),
            'unfiled' => $this->scopeId === 'me' ? auth()->user() : abort(404),
            default => abort(404),
        };

        return ToolRun::query()
            ->where('scope_type', $scopeModel->getMorphClass())
            ->where('scope_id', (string) $scopeModel->getKey())
            ->findOrFail($this->run);
    }
```

The four run views build their links with the helper:

```bash
perl -pi -e 's/\$dataset = \$run->scope;/\$routeParams = \\App\\Http\\Controllers\\ToolRunPageController::params(\$run->scope, \$run);/; s/\[\$dataset, \$run\]/\$routeParams/g' \
  resources/views/filament/tool-runs/load_audit.blade.php resources/views/filament/tool-runs/appointment_timeline.blade.php \
  resources/views/filament/tool-runs/plan_travels.blade.php resources/views/filament/tool-runs/region_availability.blade.php
grep -n '\$dataset' resources/views/filament/tool-runs/*.blade.php
```

The `grep` must print nothing. Each view now starts `$routeParams = \App\Http\Controllers\ToolRunPageController::params($run->scope, $run);` and its links read `route('tool-runs.page', $routeParams)`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/UnfiledRunPagesTest.php tests/Feature/LoadAudit tests/Feature/ActivityTrace tests/Feature/RegionAvailability tests/Feature/PlanTravels tests/Feature/RunsTabTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/ToolWizardUnfiledTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add routes/web.php app/Http/Controllers/ToolRunPageController.php app/Filament/Pages/ToolRunPage.php \
  resources/views/filament/tool-runs/load_audit.blade.php resources/views/filament/tool-runs/appointment_timeline.blade.php \
  resources/views/filament/tool-runs/plan_travels.blade.php resources/views/filament/tool-runs/region_availability.blade.php \
  tests/Feature/UnfiledRunPagesTest.php tests/Feature/LoadAudit/LoadAuditPagesTest.php tests/Feature/ActivityTrace/ActivityTraceToolTest.php \
  tests/Feature/RegionAvailability/RegionAvailabilityPagesTest.php tests/Feature/PlanTravels/PlanTravelsRunPageTest.php
git commit -F - <<'EOF'
Unfiled scope: run pages and downloads at unfiled/me (#45)

Run page and download routes move to {scopeType}/{scopeId}/runs/{run} with the
same names; ToolRunPageController::params() builds them for a dataset or for
Unfiled (unfiled/me, never a user id). Unfiled downloads are named
<tool>_unfiled_<date>.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 8: My files page, shared tables and the recent runs Where column

**Files:**
- Create: `app/Filament/Tables/ExportFilesTable.php`, `app/Filament/Tables/ToolRunsTable.php`
- Modify: `app/Filament/RelationManagers/ExportFilesRelationManager.php`, `app/Filament/RelationManagers/ToolRunsRelationManager.php` (use the shared tables)
- Create: `app/Filament/Widgets/MyExportFiles.php`, `app/Filament/Widgets/MyToolRuns.php`
- Create: `app/Filament/Pages/MyFiles.php`, `resources/views/filament/pages/my-files.blade.php`
- Modify: `app/Filament/Widgets/RecentRuns.php`
- Create: `tests/Feature/MyFilesTest.php`
- Modify tests: `tests/Feature/DashboardTest.php`, `tests/Feature/NavigationGroupsTest.php`

**Interfaces:**
- Consumes: `ToolRunPage::urlFor($user, $run)` (Task 7), the drop zone on a User scope (Task 2), the User branch of `ScopeOwnedModel` (Task 1).
- Produces:
  - `ExportFilesTable::configure(Table $table): Table` (columns, purge countdown, delete; default sort newest first)
  - `ToolRunsTable::configure(Table $table, Model $scope): Table` (columns, open on `ToolRunPage::urlFor($scope, $record)`, delete; no result column loaded)
  - Page `App\Filament\Pages\MyFiles` at slug `my-files` in `Groups::ANALYSE`; widgets `MyExportFiles`, `MyToolRuns` (`$isDiscovered = false`).
  - `RecentRuns` column `where` labelled "Where": `Unfiled` for a User scope, else the scope's name.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MyFilesTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Navigation\Groups;
use App\Filament\Pages\MyFiles;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Widgets\MyExportFiles;
use App\Filament\Widgets\MyToolRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->other = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('sits under Analysis tools at my-files with a drop target for every kind', function () {
    expect(MyFiles::getNavigationGroup())->toBe(Groups::ANALYSE)
        ->and(MyFiles::getUrl())->toEndWith('/my-files');

    $this->get(MyFiles::getUrl())
        ->assertOk()
        ->assertSee('My files')
        ->assertSee('Drop a new LOAD')->assertSee('Drop a new SYS')->assertSee('Drop a new IFS event log')
        ->assertSeeLivewire(MyExportFiles::class)
        ->assertSeeLivewire(MyToolRuns::class);
});

it('lists the user unfiled exports with the purge countdown, and no dataset or other user file', function () {
    $this->travelTo(now()->startOfMinute());
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml', 'expires_at' => now()->addHours(9)]);
    $datasetFile = ExportFile::factory()->for($this->dataset, 'scope')->create(['original_filename' => 'north-load.xml']);
    $theirs = ExportFile::factory()->for($this->other, 'scope')->create(['original_filename' => 'their-load.xml']);

    Livewire::test(MyExportFiles::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$datasetFile, $theirs])
        ->assertSee('prod-load.xml')
        ->assertSee('Purges in 9 h 0 m')
        ->assertDontSee('north-load.xml');
});

it('deletes an unfiled export with its object', function () {
    $file = ExportFile::factory()->for($this->user, 'scope')->create(['content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), 'x');

    Livewire::test(MyExportFiles::class)->callTableAction('delete', $file);

    expect(ExportFile::withoutGlobalScopes()->find($file->id))->toBeNull()
        ->and(app(ExportStorage::class)->exists($file))->toBeFalse();
});

it('lists the user unfiled runs, each opening on unfiled/me, and no dataset or other user run', function () {
    $mine = ToolRun::factory()->for($this->user, 'scope')->create(['tool' => ToolId::LoadAudit]);
    $datasetRun = ToolRun::factory()->for($this->dataset, 'scope')->create();
    $theirs = ToolRun::factory()->for($this->other, 'scope')->create();

    Livewire::test(MyToolRuns::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$datasetRun, $theirs])
        ->assertTableActionHasUrl('open', ToolRunPage::urlFor($this->user, $mine), record: $mine);
});
```

`tests/Feature/DashboardTest.php`: add `use App\Filament\Pages\ToolRunPage;` and:

```php
it('says Unfiled under Where for a run on the user own files', function () {
    $unfiled = ToolRun::factory()->for($this->user, 'scope')->create(['tool' => ToolId::LoadAudit, 'queued_at' => now()]);
    $datasetRun = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'queued_at' => now()->subMinute()]);

    Livewire::test(RecentRuns::class)
        ->assertCanSeeTableRecords([$unfiled, $datasetRun], inOrder: true)
        ->assertSee('Where')
        ->assertDontSee('Dataset or environment')
        ->assertTableColumnStateSet('where', 'Unfiled', record: $unfiled)
        ->assertTableColumnStateSet('where', 'NORTH', record: $datasetRun)
        ->assertTableActionHasUrl('open', ToolRunPage::urlFor($this->user, $unfiled), record: $unfiled);
});
```

`tests/Feature/NavigationGroupsTest.php`: add to the dataset list, after the `LoadAuditEntry` row:

```php
    [Pages\MyFiles::class, Groups::ANALYSE],
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/MyFilesTest.php tests/Feature/DashboardTest.php tests/Feature/NavigationGroupsTest.php`
Expected: FAIL with "Class App\Filament\Pages\MyFiles not found" and the recent runs test not finding the `where` column.

- [ ] **Step 3: Implement**

Create `app/Filament/Tables/ExportFilesTable.php` (the body of today's `ExportFilesRelationManager::table()` columns and actions, and its three private helpers, moved unchanged):

```php
<?php

namespace App\Filament\Tables;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Tables;
use Filament\Tables\Table;

/** The exports table: a dataset's or environment's Exports tab, and My files. */
class ExportFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->description(static fn (ExportFile $record): ?string => $record->error_message)
                    ->searchable(),
                Tables\Columns\TextColumn::make('summary.export_date')->label('Export date')->placeholder('-'),
                Tables\Columns\TextColumn::make('summary.input_reference_datetime')->label('Input reference time')->dateTime()->placeholder('-'),
                Tables\Columns\TextColumn::make('contents')
                    ->label('Contents')
                    ->state(static fn (ExportFile $record): ?string => self::contentsOf($record))
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('uploadedBy.name')->label('Uploaded by'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->tooltip(static fn (ExportFile $record): ?string => $record->error_message),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Raw file')
                    ->state(static fn (ExportFile $record): string => self::purgeCountdown($record)),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('The file is removed from storage too. Results from runs that used it are kept.')
                    ->using(static function (ExportFile $record): void {
                        app(ExportStorage::class)->delete($record);
                        $record->delete();
                    }),
            ]);
    }

    private static function contentsOf(ExportFile $record): ?string
    {
        $summary = $record->summary ?? [];

        return match ($record->kind) {
            ExportKind::Sys => isset($summary['pso_version'])
                ? 'Version '.$summary['pso_version'].', '.number_format((int) ($summary['parameter_count'] ?? 0)).' parameters'
                : null,
            ExportKind::Audits => isset($summary['message_count'])
                ? number_format((int) $summary['message_count']).' messages, '.number_format((int) ($summary['appointment_request_count'] ?? 0)).' appointment requests'
                : null,
            ExportKind::EventLog => isset($summary['event_count'])
                ? number_format((int) $summary['event_count']).' events'.self::eventSpan($record, $summary)
                : null,
            default => isset($summary['allocation_count'])
                ? number_format((int) $summary['allocation_count']).' allocations'
                : (isset($summary['activity_count'])
                ? number_format((int) $summary['activity_count']).' activities, '.number_format((int) ($summary['resource_count'] ?? 0)).' resources'
                : null),
        };
    }

    /** @param array<string, mixed> $summary */
    private static function eventSpan(ExportFile $record, array $summary): string
    {
        if (! isset($summary['first_event'], $summary['last_event'])) {
            return '';
        }

        $zone = $record->scope instanceof Dataset ? $record->scope->timeZone() : (string) config('app.timezone', 'UTC');
        $format = static fn (string $time): string => CarbonImmutable::parse($time)->setTimezone($zone)->format('j M H:i');

        return ', '.$format($summary['first_event']).' to '.$format($summary['last_event']);
    }

    private static function purgeCountdown(ExportFile $record): string
    {
        if ($record->status === ExportFileStatus::Purged) {
            return 'Deleted from storage';
        }

        $minutes = (int) floor(now()->diffInMinutes($record->expires_at, false));

        if ($minutes <= 0) {
            return 'Purging soon';
        }

        return 'Purges in '.intdiv($minutes, 60).' h '.($minutes % 60).' m';
    }
}
```

`app/Filament/RelationManagers/ExportFilesRelationManager.php` becomes:

```php
<?php

namespace App\Filament\RelationManagers;

use App\Filament\Tables\ExportFilesTable;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** The Exports tab: drop zone above a table of uploaded files. Scope-agnostic; subclasses are per resource. */
abstract class ExportFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'exportFiles';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedArrowUpTray;

    /** Filament makes relation managers read-only on a view page; uploads and deletes happen here. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Exports';
    }

    /** The drop zone dispatches this once a file is stored; the re-render shows the new row. */
    #[On('export-uploaded')]
    public function refreshAfterUpload(): void {}

    public function table(Table $table): Table
    {
        return ExportFilesTable::configure($table)
            ->header(fn () => view('livewire.export-drop-zone-header', ['scope' => $this->getOwnerRecord()]))
            ->poll(fn (): ?string => $this->getOwnerRecord()->exportFiles()->pending()->exists() ? '10s' : null);
    }
}
```

Create `app/Filament/Tables/ToolRunsTable.php` (the columns and record actions of today's `ToolRunsRelationManager::table()`, moved unchanged):

```php
<?php

namespace App\Filament\Tables;

use App\Filament\Pages\ToolRunPage;
use App\Models\ToolRun;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The runs table: a dataset's or environment's Runs tab, and My files. $scope is whose runs these are. */
class ToolRunsTable
{
    public static function configure(Table $table, Model $scope): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query) => $query->withoutResult())
            ->defaultSort('queued_at', 'desc')
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Start a run from a tool under Analysis tools; it will appear here.')
            ->columns([
                Tables\Columns\TextColumn::make('tool')->badge(),
                Tables\Columns\TextColumn::make('run_date')->date()->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(static fn (ToolRun $record): ?string => $record->progress['label'] ?? null)
                    ->tooltip(static fn (ToolRun $record): ?string => $record->error_message),
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
                Tables\Columns\TextColumn::make('startedBy.name')->label('Started by'),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->placeholder('-'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(static fn (ToolRun $record): string => ToolRunPage::urlFor($scope, $record)),
                DeleteAction::make(),
            ]);
    }
}
```

In `app/Filament/RelationManagers/ToolRunsRelationManager.php` replace `table()`:

```php
    public function table(Table $table): Table
    {
        return ToolRunsTable::configure($table, $this->getOwnerRecord())
            ->poll(fn (): ?string => $this->getOwnerRecord()->toolRuns()
                ->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])
                ->exists() ? '5s' : null)
            ->headerActions($this->runActions());
    }
```

and fix its imports: add `use App\Filament\Tables\ToolRunsTable;`; remove `use App\Filament\Pages\ToolRunPage;`, `use App\Models\ToolRun;`, `use Filament\Actions\DeleteAction;`, `use Filament\Tables;` and `use Illuminate\Database\Eloquent\Builder;` (no longer used there; `runActions()` still uses `Action`, `Select`, `Notification`, `RunInputPicker`, `StartToolRun`, `UnusableInputException`, `ToolRegistry`, `ToolId`).

Create `app/Filament/Widgets/MyExportFiles.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Filament\Tables\ExportFilesTable;
use App\Models\ExportFile;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/** My files: the signed-in user's unfiled exports. Rendered by the My files page only, never on the dashboard. */
class MyExportFiles extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** The drop zone above dispatches this once a file is stored; the re-render shows the new row. */
    #[On('export-uploaded')]
    public function refreshAfterUpload(): void {}

    public function table(Table $table): Table
    {
        return ExportFilesTable::configure($table)
            ->heading('Files')
            ->query(self::unfiled())
            ->poll(static fn (): ?string => self::unfiled()->pending()->exists() ? '10s' : null)
            ->emptyStateHeading('No unfiled files')
            ->emptyStateDescription('Drop a file above, or choose Unfiled in Trace an activity, Load Health Audit or Plan travels.');
    }

    /** @return Builder<ExportFile> */
    private static function unfiled(): Builder
    {
        $user = auth()->user();

        return ExportFile::query()->where('scope_type', $user->getMorphClass())->where('scope_id', (string) $user->getKey());
    }
}
```

Create `app/Filament/Widgets/MyToolRuns.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Enums\ToolRunStatus;
use App\Filament\Tables\ToolRunsTable;
use App\Models\ToolRun;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** My files: the signed-in user's unfiled runs, each opening its run page. Rendered by the My files page only. */
class MyToolRuns extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return ToolRunsTable::configure($table, auth()->user())
            ->heading('Runs')
            ->query(self::unfiled())
            ->poll(static fn (): ?string => self::unfiled()->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])->exists() ? '5s' : null)
            ->emptyStateDescription('Run Trace an activity, Load Health Audit or Plan travels on Unfiled; the run will appear here.');
    }

    /** @return Builder<ToolRun> */
    private static function unfiled(): Builder
    {
        $user = auth()->user();

        return ToolRun::query()->where('scope_type', $user->getMorphClass())->where('scope_id', (string) $user->getKey());
    }
}
```

Create `app/Filament/Pages/MyFiles.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Filament\Navigation\Groups;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The signed-in user's Unfiled exports and runs: files that belong to no environment or dataset. */
class MyFiles extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = Groups::ANALYSE;

    protected static ?string $navigationLabel = 'My files';

    protected static ?string $title = 'My files';

    protected static ?string $slug = 'my-files';

    protected string $view = 'filament.pages.my-files';

    public function getSubheading(): ?string
    {
        return 'Exports and runs that belong to no environment or dataset. Only you can see them, and nothing here is sent anywhere.';
    }
}
```

Create `resources/views/filament/pages/my-files.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section
        heading="Upload"
        :description="'Any export kind. Files are kept for '.(int) config('exports.ttl_hours', 12).' hours, then the raw file is deleted. Results from runs that used it are kept.'"
    >
        @livewire(\App\Livewire\ExportDropZone::class, ['scope' => auth()->user()], key('my-files-drop-zone'))
    </x-filament::section>

    @livewire(\App\Filament\Widgets\MyExportFiles::class)

    @livewire(\App\Filament\Widgets\MyToolRuns::class)
</x-filament-panels::page>
```

`app/Filament/Widgets/RecentRuns.php`: add `use App\Models\User;` and replace the `scope.name` column:

```php
                TextColumn::make('where')
                    ->label('Where')
                    ->state(static fn (ToolRun $record): ?string => $record->scope instanceof User ? 'Unfiled' : $record->scope?->name),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/MyFilesTest.php tests/Feature/DashboardTest.php tests/Feature/NavigationGroupsTest.php tests/Feature/ExportsTabTest.php tests/Feature/RunsTabTest.php tests/Feature/LoadAudit/LoadAuditPagesTest.php tests/Feature/GatewayUploadTabTest.php`
Expected: PASS

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git diff --stat
git add app/Filament/Tables/ExportFilesTable.php app/Filament/Tables/ToolRunsTable.php \
  app/Filament/RelationManagers/ExportFilesRelationManager.php app/Filament/RelationManagers/ToolRunsRelationManager.php \
  app/Filament/Widgets/MyExportFiles.php app/Filament/Widgets/MyToolRuns.php app/Filament/Widgets/RecentRuns.php \
  app/Filament/Pages/MyFiles.php resources/views/filament/pages/my-files.blade.php \
  tests/Feature/MyFilesTest.php tests/Feature/DashboardTest.php tests/Feature/NavigationGroupsTest.php
git commit -F - <<'EOF'
Unfiled scope: My files page and Where on recent runs (#45)

My files, under Analysis tools, takes uploads of any kind and lists the user's
unfiled exports and runs with the same tables as a dataset's Exports and Runs
tabs (now shared classes). Recent runs say Unfiled under Where.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```

---

### Task 9: README and the full suite

**Files:**
- Modify: `README.md` (the `## Navigation` section only; the environment variables section is unaffected)

**Interfaces:**
- Consumes: everything above.
- Produces: user-facing description of Unfiled and My files.

- [ ] **Step 1: Add the paragraph**

In `README.md`, under `## Navigation`, after the existing paragraph (which ends "the home page lists the tools and your recent runs."), add:

```markdown
**Unfiled and My files.** Trace an activity, Load Health Audit and Plan travels only read the file, so their Where step offers **Unfiled** (the default) next to **A dataset**. An Unfiled run needs no environment or dataset, even for a file from production: nothing is sent anywhere, you choose the time zone the pages show times in on the Options step, and the files and runs are yours alone. **My files**, under Analysis tools, takes uploads of any export kind and lists your unfiled exports (with the same purge countdown as a dataset's Exports tab) and your unfiled runs; their pages open at `unfiled/me/runs/<run>`. Region availability and Sys compare still need a dataset or an environment.
```

- [ ] **Step 2: Check the copy rules**

Run: `grep -n "$(printf '\342\200\224')" README.md app/Enums/ExportKind.php app/Filament/Pages/Tools/ToolWizardPage.php app/Filament/Pages/MyFiles.php app/Filament/Widgets/MyExportFiles.php app/Filament/Widgets/MyToolRuns.php app/Tools/UnfiledLabel.php resources/views/filament/pages/my-files.blade.php; grep -rn "placed" app/Filament/Pages/MyFiles.php app/Filament/Widgets/My*.php resources/views/filament/pages/my-files.blade.php`
Expected: no output from either grep (no em dashes in what this plan added, no "placed").

- [ ] **Step 3: Run the full suite**

Run: `php artisan test --compact`
Expected: every test passes except the known failing `ExampleTest`. Any other failure is a regression from Tasks 1 to 8: fix it in the task's files before committing.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -F - <<'EOF'
README: Unfiled runs and the My files page (#45)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
```
