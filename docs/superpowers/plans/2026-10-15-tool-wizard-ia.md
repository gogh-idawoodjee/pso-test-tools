# Tool Wizards and Intent-Based Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three-place tool flow (entry page, Exports tab, Runs tab) with one tool-first wizard per tool, and regroup the sidebar and home page by what a consultant is trying to do.

**Architecture:** One abstract Filament page, `ToolWizardPage`, renders a `Wizard` schema driven by the `Tool` contract (`requiredKinds`, `optionalKinds`, `optionFields`, `Tool::scope()`); the existing `*Entry` pages become its subclasses. Run creation moves out of the Runs tab into a `StartToolRun` service used by both. The panel declares four intent-named navigation groups, a render hook adds a banner on live-action pages, and a custom Dashboard carries a tool catalogue widget and a recent-runs widget.

**Tech Stack:** Laravel 13, Filament v5 (Schemas, Wizard, Livewire schema component), Livewire 4, Pest 4, MySQL. Tests run with `php artisan test --compact` (or `vendor/bin/pest`).

**Spec:** `docs/superpowers/specs/2026-10-15-tool-wizard-ia-design.md`

## Global Constraints

- Filament v5 APIs only (`Filament\Schemas\Components\Wizard`, `Filament\Schemas\Components\Wizard\Step`, `Filament\Schemas\Schema`). No v3 `Forms\Form` signatures.
- Customer-facing plain English in every label and message. No em dashes anywhere in generated text. Say "Allocated", never "placed".
- Tool names come from `Tool::getLabel()`; export kind names from `ExportKind::getLabel()`.
- Ownership: environments through the `UserOwnedModel` global scope; datasets through `Dataset::ownedByCurrentUser()`; export files and runs through `ScopeOwnedModel`. The wizard never accepts a scope id the user does not own, in the selects and again on submit.
- Nothing is written until Run except uploads, which are ordinary `ExportFile` rows.
- Slugs stay `tools/{tool}` so existing links keep working.
- Do not touch the internals of Sys compare, Filter Load File, Travel Analyzer or the API pages beyond their navigation group.
- Run `vendor/bin/pint --dirty` before each commit.

## Review Focus

1. A dataset select left at a stale value after the environment changes: switching environment must clear `dataset_id` so a dataset of another environment can never be submitted. (Task 4 test "clears the dataset when the environment changes".)
2. A user with one environment and one dataset and no `last_tool_context`: Where must still pre-fill both, not show empty selects. (Task 4 test "pre-fills the only environment and dataset".)
3. Export file deleted or purged between Inputs and Run: submit must refuse, create no run, and keep the user on the wizard. (Task 7 test "refuses a file that is no longer usable".)
4. Tool with an environment scope (Sys compare) chosen in the catalogue: the wizard must not render a dataset select and `StartToolRun` must attach the run to the environment. (Task 4 and Task 2 tests.)
5. Tool registry empty in a fresh install: the home catalogue and the sidebar must render without errors and without those tools. (Task 10 test "hides unregistered tools".)

---

## File structure

Create:
- `database/migrations/2026_10_15_000001_add_last_tool_context_to_users_table.php`
- `app/Support/Tools/StartToolRun.php` creates a `ToolRun` and dispatches `RunToolJob`; validates inputs against `RunInputPicker`.
- `app/Filament/Pages/Tools/ToolWizardPage.php` (renamed from `ToolEntryPage.php`) the wizard base.
- `resources/views/filament/pages/tools/tool-wizard.blade.php` page view: the form, or the run panel after submit.
- `resources/views/filament/pages/tools/wizard-upload.blade.php` the drop zone block inside the Inputs step.
- `resources/views/filament/tool-runs/_run-panel.blade.php` the run header, progress, warnings, inputs and result (extracted from `tool-run.blade.php`).
- `app/Filament/Navigation/LivePsoActionPages.php` the list of page classes that change a live environment.
- `resources/views/filament/live-environment-banner.blade.php`
- `app/Filament/Pages/Dashboard.php` custom dashboard.
- `app/Filament/Widgets/ToolCatalogue.php` + `resources/views/filament/widgets/tool-catalogue.blade.php`
- `app/Filament/Widgets/RecentRuns.php` table widget.
- Tests: `tests/Feature/UserLastToolContextTest.php`, `tests/Feature/StartToolRunTest.php`, `tests/Feature/ToolWizardPageTest.php` (replaces `ToolEntryPageTest.php`), `tests/Feature/NavigationGroupsTest.php`, `tests/Feature/LiveEnvironmentBannerTest.php`, `tests/Feature/DashboardTest.php`, `tests/Unit/ToolQuestionTest.php`.

Modify:
- `app/Models/User.php` cast and fillable for `last_tool_context`.
- `app/Enums/Tool.php` add `question(): string`.
- `app/Filament/RelationManagers/ToolRunsRelationManager.php` use `StartToolRun`.
- `app/Filament/Pages/Tools/{LoadAuditEntry,RegionAvailabilityEntry,AppointmentTimelineEntry,PlanTravelsEntry,ScheduleReviewEntry}.php` extend `ToolWizardPage`.
- `resources/views/filament/pages/tool-run.blade.php` include the partial.
- `app/Providers/Filament/AppPanelProvider.php` navigation groups, dashboard, widgets, render hook, colours.
- Navigation group on: `EnvironmentResource`, `DatasetResource`, `DatasetReferenceValueResource`, `TokenUsageLogResource`, `SysFileCompare`, `FilterLoadFile`, `PreferenceCalculator`, `TravelAnalyzer`, `GenericDelete`, `GenerateCustomException`, `HomePages/*`, `BasePages/*`, `Backups`, `HealthCheckResults`, `IssueToken`.

Delete:
- `app/Filament/Widgets/QuickLaunch.php`, `resources/views/filament/widgets/quick-launch.blade.php`, `app/Filament/Widgets/StatsOverview.php`, `resources/views/filament/pages/tools/tool-entry.blade.php`, `tests/Feature/ToolEntryPageTest.php`.

---

### Task 1: Remember the last tool context on the user

**Files:**
- Create: `database/migrations/2026_10_15_000001_add_last_tool_context_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserLastToolContextTest.php`

**Interfaces:**
- Produces: `User::$last_tool_context` as `?array{environment_id: ?int, dataset_id: ?int}`; `User::rememberToolContext(?int $environmentId, ?int $datasetId): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

it('remembers the last environment and dataset used in a tool', function () {
    $user = User::factory()->create();

    expect($user->last_tool_context)->toBeNull();

    $user->rememberToolContext(environmentId: 7, datasetId: 12);

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 7, 'dataset_id' => 12]);
});

it('stores a null dataset for environment-scoped tools', function () {
    $user = User::factory()->create();
    $user->rememberToolContext(environmentId: 7, datasetId: null);

    expect($user->fresh()->last_tool_context)->toBe(['environment_id' => 7, 'dataset_id' => null]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/UserLastToolContextTest.php`
Expected: FAIL, "Call to undefined method App\Models\User::rememberToolContext()".

- [ ] **Step 3: Add the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('last_tool_context')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_tool_context');
        });
    }
};
```

- [ ] **Step 4: Add the cast, fillable entry and method to `app/Models/User.php`**

Add `'last_tool_context'` to `$fillable`, `'last_tool_context' => 'array'` to `casts()`, and:

```php
    /** @param  int|null  $datasetId  null for environment-scoped tools */
    public function rememberToolContext(?int $environmentId, ?int $datasetId): void
    {
        $this->forceFill(['last_tool_context' => ['environment_id' => $environmentId, 'dataset_id' => $datasetId]])->save();
    }
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/pest tests/Feature/UserLastToolContextTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_10_15_000001_add_last_tool_context_to_users_table.php app/Models/User.php tests/Feature/UserLastToolContextTest.php
git commit -m "Users remember the last environment and dataset used in a tool"
```

---

### Task 2: Extract StartToolRun from the Runs tab

**Files:**
- Create: `app/Support/Tools/StartToolRun.php`
- Modify: `app/Filament/RelationManagers/ToolRunsRelationManager.php`
- Test: `tests/Feature/StartToolRunTest.php`

**Interfaces:**
- Consumes: `RunInputPicker::optionsFor(Model $scope, ToolId $toolId): array` (kind value => [file id => label]); `RunToolJob::dispatch(string $runId)`.
- Produces: `StartToolRun::start(Model $scope, ToolId $toolId, array $files, array $options, User $user): ToolRun`. Throws `App\Support\Tools\UnusableInputException` (extends `RuntimeException`, has `public readonly ExportKind $kind`) when a chosen file id is not among the picker's current options for that kind.

- [ ] **Step 1: Write the failing tests**

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
use App\Models\User;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTool;

beforeEach(function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

it('creates a queued run with the chosen files and options and dispatches the job', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $run = app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id], ['ifs_release' => '25.2.7'], $this->user);

    expect($run)->toBeInstanceOf(ToolRun::class)
        ->and($run->status)->toBe(ToolRunStatus::Queued)
        ->and($run->scope_id)->toBe($this->dataset->getKey())
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->and($run->started_by_user_id)->toBe($this->user->id);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('drops blank optional files and refuses a file that is not usable on this scope', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $purged = ExportFile::factory()->for($this->dataset, 'scope')->purged()->create(['kind' => ExportKind::Sys]);

    $run = app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id, 'sys' => null], [], $this->user);
    expect($run->inputs['files'])->toBe(['load' => $load->id]);

    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id, 'sys' => $purged->id], [], $this->user))
        ->toThrow(UnusableInputException::class, 'The System export you chose is no longer available. Choose or upload another.');
    expect(ToolRun::count())->toBe(1);
    Queue::assertPushed(RunToolJob::class, 1);
});

it('refuses a required file that is missing', function () {
    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, [], [], $this->user))
        ->toThrow(UnusableInputException::class, 'A Load export is required.');
});

it('remembers the context on the user, with a null dataset for an environment-scoped run', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id], [], $this->user);
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id]);

    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    FakeTool::$kinds = [[ExportKind::Sys], []];
    app(ToolRegistry::class)->register(ToolId::SysCompare, FakeTool::class);
    app(StartToolRun::class)->start($this->dataset->environment, ToolId::SysCompare, ['sys' => $sys->id], [], $this->user);
    FakeTool::$kinds = null;
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => null]);
});
```

Check the exact label text with `php artisan tinker --execute="echo App\Enums\ExportKind::Sys->getLabel();"` and `ExportKind::Load->getLabel()`; the two messages in the tests must use those labels verbatim. Adjust the expected strings if the labels differ from "System" and "Load".

- [ ] **Step 2: Let FakeTool override its kinds**

In `tests/Support/FakeTool.php` add:

```php
    /** @var array{0: list<ExportKind>, 1: list<ExportKind>}|null required, optional */
    public static ?array $kinds = null;

    public function requiredKinds(): array
    {
        return self::$kinds[0] ?? [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return self::$kinds[1] ?? [ExportKind::Sys];
    }
```

(replace the two existing methods).

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/StartToolRunTest.php`
Expected: FAIL, class `App\Support\Tools\StartToolRun` not found.

- [ ] **Step 4: Write the service and exception**

`app/Support/Tools/UnusableInputException.php`:

```php
<?php

namespace App\Support\Tools;

use App\Enums\ExportKind;
use RuntimeException;

class UnusableInputException extends RuntimeException
{
    public function __construct(public readonly ExportKind $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function missing(ExportKind $kind): self
    {
        return new self($kind, 'A '.$kind->getLabel().' export is required.');
    }

    public static function unavailable(ExportKind $kind): self
    {
        return new self($kind, 'The '.$kind->getLabel().' export you chose is no longer available. Choose or upload another.');
    }
}
```

`app/Support/Tools/StartToolRun.php`:

```php
<?php

namespace App\Support\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way a tool run starts, from the Runs tab or a wizard: checks the chosen
 * files are still usable on this scope, creates the queued run, dispatches the job
 * and remembers the context on the user.
 */
class StartToolRun
{
    public function __construct(private readonly ToolRegistry $registry, private readonly RunInputPicker $picker) {}

    /**
     * @param  array<string, string|null>  $files  kind value => export file id
     * @param  array<string, mixed>  $options
     *
     * @throws UnusableInputException
     */
    public function start(Model $scope, ToolId $toolId, array $files, array $options, User $user): ToolRun
    {
        $tool = $this->registry->for($toolId);
        $usable = $this->picker->optionsFor($scope, $toolId);
        $chosen = [];

        foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
            $fileId = $files[$kind->value] ?? null;
            if (blank($fileId)) {
                if (in_array($kind, $tool->requiredKinds(), true)) {
                    throw UnusableInputException::missing($kind);
                }

                continue;
            }
            if (! array_key_exists((string) $fileId, $usable[$kind->value] ?? [])) {
                throw UnusableInputException::unavailable($kind);
            }
            $chosen[$kind->value] = (string) $fileId;
        }

        $run = $scope->toolRuns()->create([
            'tool' => $toolId,
            'inputs' => ['files' => $chosen, 'options' => $options],
            'started_by_user_id' => $user->id,
            'status' => ToolRunStatus::Queued,
        ]);

        RunToolJob::dispatch($run->id);

        $user->rememberToolContext(
            environmentId: $scope instanceof Dataset ? $scope->environment_id : $scope->getKey(),
            datasetId: $scope instanceof Dataset ? $scope->getKey() : null,
        );

        return $run;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/StartToolRunTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Make the Runs tab use it**

In `app/Filament/RelationManagers/ToolRunsRelationManager.php` replace the `->action(function (array $data) use ($toolId): void { ... })` body with:

```php
                ->action(function (array $data) use ($toolId): void {
                    try {
                        app(StartToolRun::class)->start($this->getOwnerRecord(), $toolId, $data['files'] ?? [], $data['options'] ?? [], auth()->user());
                    } catch (UnusableInputException $e) {
                        Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();
                    }
                });
```

Add `use App\Support\Tools\StartToolRun; use App\Support\Tools\UnusableInputException; use Filament\Notifications\Notification;` and drop the now unused `RunToolJob` and `ToolRunStatus` imports.

- [ ] **Step 7: Run the Runs tab tests**

Run: `vendor/bin/pest tests/Feature/RunsTabTest.php tests/Feature/LoadAudit tests/Feature/RegionAvailability`
Expected: PASS, same count as before the change.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Tools tests/Feature/StartToolRunTest.php tests/Support/FakeTool.php app/Filament/RelationManagers/ToolRunsRelationManager.php
git commit -m "Start tool runs through one StartToolRun service"
```

---

### Task 3: Each tool states the question it answers

**Files:**
- Modify: `app/Enums/Tool.php`
- Test: `tests/Unit/ToolQuestionTest.php`

**Interfaces:**
- Produces: `Tool::question(): string`, one plain sentence per case, used by the wizard heading and the home catalogue.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Tool;

it('gives every tool a one-sentence question without em dashes', function () {
    foreach (Tool::cases() as $tool) {
        $question = $tool->question();
        expect($question)->toBeString()->not->toBe('')
            ->and(str_ends_with($question, '?'))->toBeTrue()
            ->and(str_contains($question, "\u{2014}"))->toBeFalse();
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/ToolQuestionTest.php`
Expected: FAIL, undefined method `question`.

- [ ] **Step 3: Add the method**

```php
    /** The question a consultant has in mind when they reach for this tool; shown in the catalogue and the wizard. */
    public function question(): string
    {
        return match ($this) {
            self::LoadAudit => 'What is wrong with the data and configuration in this LOAD?',
            self::ScheduleReview => 'How did today\'s schedule come out, and what needs attention?',
            self::ScheduleRollup => 'How has the schedule trended over the last weeks?',
            self::RegionAvailability => 'Which regions have enough technicians available on each day?',
            self::AppointmentTimeline => 'What happened to one activity\'s appointment request, offers and booking?',
            self::PlanTravels => 'How long are the planned travels, and do they match real road times?',
            self::SysCompare => 'What differs between these SYS exports and the baseline?',
        };
    }
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/pest tests/Unit/ToolQuestionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Enums/Tool.php tests/Unit/ToolQuestionTest.php
git commit -m "Tools state the question they answer"
```

---

### Task 4: Wizard base page with the Where step

**Files:**
- Rename: `app/Filament/Pages/Tools/ToolEntryPage.php` to `app/Filament/Pages/Tools/ToolWizardPage.php`
- Modify: the five `*Entry.php` pages in `app/Filament/Pages/Tools/`
- Create: `resources/views/filament/pages/tools/tool-wizard.blade.php`
- Delete: `resources/views/filament/pages/tools/tool-entry.blade.php`, `tests/Feature/ToolEntryPageTest.php`
- Test: `tests/Feature/ToolWizardPageTest.php`

**Interfaces:**
- Consumes: `Tool::scope()` (`Dataset::class` or `Environment::class`), `Environment::getForm()`, `User::$last_tool_context`.
- Produces: `ToolWizardPage` with `public ?array $data` (form state path `data`), `public ?string $runId = null`, `public function form(Schema $schema): Schema`, `protected function whereStep(): Step`, `protected function inputsStep(): Step` (Task 5), `protected function optionsStep(): Step` (Task 6), `public function start(): void` (Task 6), `protected function scopeModel(): Model` (the chosen Environment or Dataset, throws 404 if not owned), `public static function tool(): ToolId`. Form field names: `data.environment_id`, `data.dataset_id`, `data.files.<kind>`, `data.options.<name>`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ToolWizardPageTest.php`:

```php
<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use App\Tools\ToolRegistry;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['name' => 'SaskTel PROD']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('stays out of navigation until its tool is registered', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeFalse();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeTrue();
});

it('shows the tool question and only the user own environments and datasets', function () {
    $other = Dataset::factory()->create(['name' => 'THEIRS']);

    Livewire::test(LoadAuditEntry::class)
        ->assertOk()
        ->assertSee(ToolId::LoadAudit->question())
        ->assertFormFieldExists('environment_id')
        ->assertFormFieldExists('dataset_id')
        ->assertSee('SaskTel PROD')
        ->assertDontSee($other->environment->name)
        ->assertDontSee('THEIRS');
});

it('pre-fills the only environment and dataset when nothing was remembered', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
});

it('pre-fills the remembered environment and dataset', function () {
    $second = Dataset::factory()->for($this->environment)->create(['name' => 'SOUTH']);
    $this->user->rememberToolContext($this->environment->id, $second->id);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['environment_id' => $this->environment->id, 'dataset_id' => $second->id]);
});

it('clears the dataset when the environment changes', function () {
    $otherEnvironment = Environment::factory()->for($this->user)->create(['name' => 'Rogers PROD']);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['dataset_id' => $this->dataset->id])
        ->fillForm(['environment_id' => $otherEnvironment->id])
        ->assertFormSet(['dataset_id' => null]);
});

it('has no dataset select for an environment-scoped tool', function () {
    FakeTool::$kinds = [[\App\Enums\ExportKind::Sys], []];
    app(ToolRegistry::class)->register(ToolId::SysCompare, FakeTool::class);

    Livewire::test(\App\Filament\Pages\Tools\SysCompareEntry::class)
        ->assertOk()
        ->assertFormFieldExists('environment_id')
        ->assertFormFieldDoesNotExist('dataset_id');
    FakeTool::$kinds = null;
});

it('creates an environment inline and selects it', function () {
    Livewire::test(LoadAuditEntry::class)
        ->callFormComponentAction('environment_id', 'createOption', data: [
            'name' => 'New env', 'base_url' => 'https://example.test', 'account_id' => 'ACME', 'username' => 'u', 'password' => 'p', 'description' => 'x',
        ])
        ->assertHasNoFormComponentActionErrors();

    expect(Environment::query()->where('name', 'New env')->where('user_id', $this->user->id)->exists())->toBeTrue();
});
```

Before relying on the last test, read `Environment::getForm()` and `database/factories/EnvironmentFactory.php` and use exactly the required field names there in `data: [...]`; drop fields that are not required.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php`
Expected: FAIL (page has no form; `SysCompareEntry` does not exist).

- [ ] **Step 3: Rename the base and write the Where step**

`git mv app/Filament/Pages/Tools/ToolEntryPage.php app/Filament/Pages/Tools/ToolWizardPage.php`, then replace its contents:

```php
<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;
use UnitEnum;

/**
 * One guided page per tool: Where (environment, dataset), Inputs (exports),
 * Options (when the tool has any), Run. Subclasses only name their tool.
 * Hidden from navigation until the tool has an implementation registered.
 */
abstract class ToolWizardPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Analyse exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected string $view = 'filament.pages.tools.tool-wizard';

    public ?array $data = [];

    public ?string $runId = null;

    abstract public static function tool(): ToolId;

    public function mount(): void
    {
        $remembered = auth()->user()->last_tool_context ?? [];
        $environments = Environment::query()->orderBy('name')->get(['id']);
        $environmentId = in_array($remembered['environment_id'] ?? null, $environments->pluck('id')->all(), true)
            ? $remembered['environment_id']
            : ($environments->count() === 1 ? $environments->first()->id : null);

        $datasetId = null;
        if ($this->isDatasetScoped() && $environmentId !== null) {
            $datasets = Dataset::query()->ownedByCurrentUser()->where('environment_id', $environmentId)->get(['id']);
            $datasetId = in_array($remembered['dataset_id'] ?? null, $datasets->pluck('id')->all(), true)
                ? $remembered['dataset_id']
                : ($datasets->count() === 1 ? $datasets->first()->id : null);
        }

        $this->form->fill(['environment_id' => $environmentId, 'dataset_id' => $datasetId, 'files' => [], 'options' => []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make($this->steps())
                    ->submitAction(new HtmlString('<x-filament::button type="submit" wire:click="start">Run '.e(static::tool()->getLabel()).'</x-filament::button>')),
            ]);
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [$this->whereStep()];
    }

    protected function whereStep(): Step
    {
        return Step::make('Where')
            ->description($this->isDatasetScoped() ? 'The environment and dataset the exports belong to.' : 'The environment the exports belong to.')
            ->schema(array_values(array_filter([
                Select::make('environment_id')
                    ->label('Environment')
                    ->options(fn (): array => Environment::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(static fn (Set $set) => $set('dataset_id', null))
                    ->createOptionForm(Environment::getForm())
                    ->createOptionUsing(static fn (array $data): int => Environment::query()->create([...$data, 'user_id' => auth()->id()])->getKey()),
                $this->isDatasetScoped() ? Select::make('dataset_id')
                    ->label('Dataset')
                    ->options(fn (Get $get): array => $get('environment_id')
                        ? Dataset::query()->ownedByCurrentUser()->where('environment_id', $get('environment_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->required()
                    ->live()
                    ->native(false)
                    ->disabled(static fn (Get $get): bool => blank($get('environment_id')))
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                        TextInput::make('rota')->required()->helperText('The PSO rota id for this dataset.'),
                    ])
                    ->createOptionUsing(static fn (array $data, Get $get): int => Dataset::query()->create([...$data, 'environment_id' => (int) $get('environment_id')])->getKey())
                    : null,
            ])));
    }

    protected function isDatasetScoped(): bool
    {
        return static::tool()->scope() === Dataset::class;
    }

    /** The chosen scope, or 404 when it is not one of the user's own. */
    protected function scopeModel(): Model
    {
        $environment = Environment::query()->findOrFail($this->data['environment_id'] ?? null);
        if (! $this->isDatasetScoped()) {
            return $environment;
        }

        return Dataset::query()->ownedByCurrentUser()->where('environment_id', $environment->id)->findOrFail($this->data['dataset_id'] ?? null);
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return app(ToolRegistry::class)->has(static::tool());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return static::tool()->getLabel();
    }

    #[Override]
    public static function getSlug(?Panel $panel = null): string
    {
        return 'tools/'.str_replace('_', '-', static::tool()->value);
    }

    #[Override]
    public function getTitle(): string
    {
        return static::tool()->getLabel();
    }

    public function getSubheading(): ?string
    {
        return static::tool()->question();
    }
}
```

Check `Environment::getForm()` for a field that would not accept a plain create (for example a password reveal); if `createOptionUsing` needs to hash or transform a field, do what `CreateEnvironment` does. Check `Dataset` creation requirements against its migration: if `settings` or other columns are not nullable, add their defaults in `createOptionUsing`.

- [ ] **Step 4: Update the five entry pages and add SysCompareEntry**

Each `*Entry.php` becomes:

```php
<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;

class LoadAuditEntry extends ToolWizardPage
{
    public static function tool(): ToolId
    {
        return ToolId::LoadAudit;
    }
}
```

(same for RegionAvailability, AppointmentTimeline, PlanTravels, ScheduleReview). Add `SysCompareEntry.php` with `ToolId::SysCompare`. It stays hidden until the sys compare tool registers (#14); the existing `SysFileCompare` page is unaffected.

- [ ] **Step 5: Write the page view**

`resources/views/filament/pages/tools/tool-wizard.blade.php`:

```blade
<x-filament-panels::page>
    @if ($this->runId === null)
        <form wire:submit="start">
            {{ $this->form }}
        </form>
    @else
        @include('filament.pages.tools._run-step')
    @endif
</x-filament-panels::page>
```

Create a placeholder `resources/views/filament/pages/tools/_run-step.blade.php` containing `<div>Run</div>`; Task 7 replaces it. Add `public function start(): void {}` to the base for now; Task 6 fills it. Delete `tool-entry.blade.php` and `tests/Feature/ToolEntryPageTest.php`.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/Filament/Pages/Tools resources/views/filament/pages/tools tests/Feature/ToolWizardPageTest.php tests/Feature/ToolEntryPageTest.php
git commit -m "Tool wizard base page with the Where step"
```

---

### Task 5: Inputs step

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php`
- Create: `resources/views/filament/pages/tools/wizard-upload.blade.php`
- Test: `tests/Feature/ToolWizardPageTest.php` (append)

**Interfaces:**
- Consumes: `RunInputPicker::optionsFor/defaultsFor(Model $scope, ToolId)`, `App\Livewire\ExportDropZone` (mount param `scope`), its `export-uploaded` event, `ExportFile::$expires_at`, `ExportFileStatus`.
- Produces: fields `data.files.<kind>`; `public function refreshInputs(): void` (re-applies picker defaults for kinds that are empty); `public function hasPendingUploads(): bool`.

- [ ] **Step 1: Append the failing tests**

```php
it('offers the newest usable file per kind, preselected, and names the time left', function () {
    $this->travelTo(now()->startOfMinute());
    $old = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load, 'original_filename' => 'old-load.zip', 'created_at' => now()->subHours(3), 'expires_at' => now()->addHours(9)]);
    $new = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load, 'original_filename' => 'new-load.zip', 'expires_at' => now()->addHours(11)]);
    \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->purged()->create(['kind' => \App\Enums\ExportKind::Load, 'original_filename' => 'gone.zip']);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files' => ['load' => $new->id]])
        ->assertSee('new-load.zip')
        ->assertSee('old-load.zip')
        ->assertDontSee('gone.zip')
        ->assertSee('Purges in 11 h 0 m');
});

it('blocks the run while a required export is missing and names it', function () {
    Livewire::test(LoadAuditEntry::class)
        ->call('start')
        ->assertHasFormErrors(['files.load' => 'required'])
        ->assertSee('A Load export is required.');
    expect(\App\Models\ToolRun::count())->toBe(0);
});

it('marks the required kind and shows where a sys export comes from', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertSee('Load export')
        ->assertSee('Required')
        ->assertSee('Optional')
        ->assertSee('from the environment');
});

it('picks up a file that finished uploading after the step opened', function () {
    $component = Livewire::test(LoadAuditEntry::class)->assertFormSet(['files' => ['load' => null]]);
    $load = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load]);

    $component->dispatch('export-uploaded')->assertFormSet(['files' => ['load' => $load->id]]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php`
Expected: the four new tests FAIL (no `files.load` field).

- [ ] **Step 3: Add the Inputs step**

In `ToolWizardPage`:

```php
    /** @return list<Step> */
    protected function steps(): array
    {
        return [$this->whereStep(), $this->inputsStep()];
    }

    protected function inputsStep(): Step
    {
        return Step::make('Inputs')
            ->description('The exports to run against. Reuse a file already uploaded or drop a new one.')
            ->schema(fn (): array => [...$this->inputFields(), $this->uploadBlock()])
            ->afterValidation(fn () => $this->refreshInputs());
    }

    /** @return list<Select> */
    protected function inputFields(): array
    {
        if (! $this->hasScope()) {
            return [];
        }
        $tool = app(ToolRegistry::class)->for(static::tool());
        $scope = $this->scopeModel();
        $options = app(RunInputPicker::class)->optionsFor($scope, static::tool());
        $fields = [];

        foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
            $required = in_array($kind, $tool->requiredKinds(), true);
            $fromEnvironment = $kind->allowedScope() === Environment::class && $scope instanceof Dataset;
            $fields[] = Select::make("files.{$kind->value}")
                ->label($kind->getLabel().' export')
                ->hint($required ? 'Required' : 'Optional')
                ->helperText($fromEnvironment ? 'A '.$kind->getLabel().' export comes from the environment, not the dataset, because it describes the whole PSO system.' : null)
                ->options($options[$kind->value] ?? [])
                ->getOptionLabelUsing(fn ($value): ?string => ($options[$kind->value] ?? [])[$value] ?? null)
                ->placeholder(($options[$kind->value] ?? []) === [] ? 'No '.$kind->getLabel().' export uploaded yet' : 'Choose a file')
                ->required($required)
                ->validationMessages(['required' => 'A '.$kind->getLabel().' export is required.'])
                ->native(false);
        }

        return $fields;
    }

    protected function uploadBlock(): View
    {
        return View::make('filament.pages.tools.wizard-upload')
            ->viewData(fn (): array => ['scope' => $this->hasScope() ? $this->scopeModel() : null, 'pending' => $this->hasPendingUploads()]);
    }

    protected function hasScope(): bool
    {
        return filled($this->data['environment_id'] ?? null) && (! $this->isDatasetScoped() || filled($this->data['dataset_id'] ?? null));
    }

    /** Fill every empty file field with the picker's default (the newest set that belongs together). */
    #[On('export-uploaded')]
    public function refreshInputs(): void
    {
        if (! $this->hasScope()) {
            return;
        }
        $defaults = app(RunInputPicker::class)->defaultsFor($this->scopeModel(), static::tool());
        foreach ($defaults as $kind => $fileId) {
            if (blank($this->data['files'][$kind] ?? null)) {
                $this->data['files'][$kind] = $fileId;
            }
        }
    }

    public function hasPendingUploads(): bool
    {
        if (! $this->hasScope()) {
            return false;
        }
        $scope = $this->scopeModel();

        return ExportFile::query()->where('scope_type', $scope->getMorphClass())->where('scope_id', $scope->getKey())
            ->whereIn('status', [ExportFileStatus::Uploading, ExportFileStatus::Summarising])->exists();
    }
```

Imports to add: `App\Enums\ExportFileStatus`, `App\Models\ExportFile`, `App\Support\Exports\RunInputPicker`, `Filament\Schemas\Components\View`, `Livewire\Attributes\On`.

Option labels must include the purge countdown so the "Purges in 11 h 0 m" assertion holds. `RunInputPicker::labelFor()` is private and does not include it, so add a public method to `RunInputPicker`:

```php
    /** Options with the raw-file purge countdown appended, for places that show one file set at a time. */
    public function optionsWithExpiryFor(Model $scope, ToolId $toolId): array
    {
        $options = [];
        foreach ($this->kindsOf($toolId) as $kind) {
            $options[$kind->value] = $this->candidates($scope, $kind)
                ->mapWithKeys(fn (ExportFile $file): array => [$file->id => $this->labelFor($file, $scope).'  '.$this->purgeCountdown($file)])
                ->all();
        }

        return $options;
    }

    public function purgeCountdown(ExportFile $file): string
    {
        $minutes = (int) floor(now()->diffInMinutes($file->expires_at, false));

        return $minutes <= 0 ? 'Purging soon' : 'Purges in '.intdiv($minutes, 60).' h '.($minutes % 60).' m';
    }
```

and use `optionsWithExpiryFor` in `inputFields()`. Also call `$this->refreshInputs()` at the end of `mount()` so defaults are preselected on first load, and in the `environment_id` and `dataset_id` `afterStateUpdated` callbacks reset `files` to `[]` then call `$livewire->refreshInputs()` (inject `$livewire` in the closure).

- [ ] **Step 4: Write the upload block view**

`resources/views/filament/pages/tools/wizard-upload.blade.php`:

```blade
<div class="space-y-2" @if ($pending) wire:poll.5s="refreshInputs" @endif>
    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Upload a new export</p>
    @if ($scope === null)
        <p class="text-sm text-gray-500">Choose where first.</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Files are kept for 12 hours, then the raw file is deleted. Results from runs that used it are kept.</p>
        @livewire(\App\Livewire\ExportDropZone::class, ['scope' => $scope], key('wizard-drop-zone-'.$scope->getMorphClass().'-'.$scope->getKey()))
        @if ($pending)
            <p class="text-sm text-gray-500">Reading the file you just uploaded. It will appear above when ready.</p>
        @endif
    @endif
</div>
```

Check `ExportDropZone` and its blade: it dispatches `export-uploaded` to the parent with `$wire.dispatch`, which reaches the page because the page listens with `#[On]`. If the drop zone only accepts `ExportKind::forScope($scope::class)`, a dataset scope will not offer SYS; that matches the Exports tab and is acceptable (the helper text says SYS comes from the environment).

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php tests/Feature/RunsTabTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Tools/ToolWizardPage.php app/Support/Exports/RunInputPicker.php resources/views/filament/pages/tools/wizard-upload.blade.php tests/Feature/ToolWizardPageTest.php
git commit -m "Tool wizard: Inputs step with reuse or upload per export kind"
```

---

### Task 6: Options step and starting the run

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php`
- Test: `tests/Feature/ToolWizardPageTest.php` (append)

**Interfaces:**
- Consumes: `Tool::optionFields(): list<Component>` (fields already named `options.<name>`), `StartToolRun::start(...)`, `UnusableInputException`.
- Produces: `start()` creates the run, sets `$this->runId`; `optionsStep()` hidden when the tool has no option fields.

- [ ] **Step 1: Append the failing tests**

```php
it('has no Options step when the tool has no option fields, and one when it has', function () {
    FakeTool::$optionFields = [];
    Livewire::test(LoadAuditEntry::class)->assertDontSee('Options');

    FakeTool::$optionFields = [\Filament\Forms\Components\TextInput::make('options.ifs_release')->label('IFS Cloud release')];
    Livewire::test(LoadAuditEntry::class)->assertSee('Options')->assertFormFieldExists('options.ifs_release');
    FakeTool::$optionFields = [];
});

it('starts a run with the chosen file and options, remembers the context and shows the run', function () {
    \Illuminate\Support\Facades\Queue::fake();
    FakeTool::$optionFields = [\Filament\Forms\Components\TextInput::make('options.ifs_release')];
    $load = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)
        ->fillForm(['options' => ['ifs_release' => '25.2.7']])
        ->call('start')
        ->assertHasNoFormErrors();
    FakeTool::$optionFields = [];

    $run = \App\Models\ToolRun::sole();
    expect($run->tool)->toBe(ToolId::LoadAudit)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->and($component->get('runId'))->toBe($run->id)
        ->and($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunToolJob::class);
});

it('refuses a scope the user does not own even if the id is forced into the form', function () {
    $theirs = Dataset::factory()->for(Environment::factory())->create();
    \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->set('data.dataset_id', $theirs->id)
        ->call('start')
        ->assertNotFound();
    expect(\App\Models\ToolRun::count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Add the Options step and `start()`**

```php
    /** @return list<Step> */
    protected function steps(): array
    {
        return [$this->whereStep(), $this->inputsStep(), $this->optionsStep()];
    }

    protected function optionsStep(): Step
    {
        $fields = app(ToolRegistry::class)->for(static::tool())->optionFields();

        return Step::make('Options')
            ->description('Settings for this run. Leave blank for the defaults.')
            ->schema($fields)
            ->hidden($fields === []);
    }

    public function start(): void
    {
        $state = $this->form->getState();
        $scope = $this->scopeModel();

        try {
            $run = app(StartToolRun::class)->start($scope, static::tool(), $state['files'] ?? [], $state['options'] ?? [], auth()->user());
        } catch (UnusableInputException $e) {
            $this->data['files'][$e->kind->value] = null;
            $this->refreshInputs();
            Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->runId = $run->id;
    }
```

Imports: `App\Support\Tools\StartToolRun`, `App\Support\Tools\UnusableInputException`, `Filament\Notifications\Notification`. Verify in the browser or with `php artisan tinker` that `Step::hidden(true)` removes the step from the Wizard header in v5 (the `CanBeHidden` trait is on every component); if the header still shows it, filter hidden steps out in `steps()` instead: `array_values(array_filter([...], fn (Step $s) => ! $s->isHidden()))`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php tests/Feature/StartToolRunTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Tools/ToolWizardPage.php tests/Feature/ToolWizardPageTest.php
git commit -m "Tool wizard: Options step and starting the run"
```

---

### Task 7: Run step with the shared run panel

**Files:**
- Create: `resources/views/filament/tool-runs/_run-panel.blade.php`
- Modify: `resources/views/filament/pages/tool-run.blade.php`, `resources/views/filament/pages/tools/_run-step.blade.php`, `app/Filament/Pages/Tools/ToolWizardPage.php`
- Test: `tests/Feature/ToolWizardPageTest.php` (append), existing `tests/Feature/RunsTabTest.php` must still pass.

**Interfaces:**
- Consumes: `ToolRunPage::urlFor(Model $scope, ToolRun $run)`, `ToolRunStatus`, the per-tool result views `filament.tool-runs.<tool>` and `_generic`.
- Produces: partial `filament.tool-runs._run-panel` taking `$run` (ToolRun), `$inputFiles` (Collection<string, ExportFile|null>), `$resultView` (string), `$isRunning` (bool); `ToolWizardPage::currentRun(): ?ToolRun`, `inputFiles()`, `resultView()`, `isRunning()`, `runAgain(): void`, `resetWizard(): void`.

- [ ] **Step 1: Append the failing tests**

```php
it('shows the run panel after starting, with open, run again and home links', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $load = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load, 'original_filename' => 'morning-load.zip']);

    $component = Livewire::test(LoadAuditEntry::class)->call('start')->assertHasNoFormErrors();
    $run = \App\Models\ToolRun::sole();

    $component->assertSee('Queued')
        ->assertSee('morning-load.zip')
        ->assertSee(\App\Filament\Pages\ToolRunPage::urlFor($this->dataset, $run), false)
        ->assertSee('Run again')
        ->assertSee('Back to home');

    $run->update(['status' => \App\Enums\ToolRunStatus::Succeeded, 'result' => ['ok' => true], 'warnings' => ['Two activities have no location'], 'finished_at' => now()]);
    $component->call('$refresh')->assertSee('Succeeded')->assertSee('Two activities have no location');
});

it('run again returns to the form with the same choices', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $load = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)->call('start')
        ->call('runAgain')
        ->assertSet('runId', null)
        ->assertFormSet(['dataset_id' => $this->dataset->id, 'files' => ['load' => $load->id]]);
});

it('refuses a file that is no longer usable and sends the user back to choose another', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $load = \App\Models\ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => \App\Enums\ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)->assertFormSet(['files' => ['load' => $load->id]]);
    $load->update(['status' => \App\Enums\ExportFileStatus::Purged]);

    $component->call('start')
        ->assertSet('runId', null)
        ->assertNotified('The run did not start')
        ->assertFormSet(['files' => ['load' => null]]);
    expect(\App\Models\ToolRun::count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Extract the run panel partial**

Move everything inside `<div ... class="space-y-6">` of `resources/views/filament/pages/tool-run.blade.php` into `resources/views/filament/tool-runs/_run-panel.blade.php`, replacing `$this->isRunning()` with `$isRunning`, `$this->inputFiles()` with `$inputFiles` and `$this->resultView()` with `$resultView`. The partial begins with the first `<x-filament::section>` and ends with the `@if ($run->result !== null) @include($resultView, ['run' => $run]) @endif` block. `tool-run.blade.php` becomes:

```blade
@php
    $run = $this->getToolRun();
@endphp

<x-filament-panels::page>
    <div @if ($this->isRunning()) wire:poll.2s @endif class="space-y-6">
        @include('filament.tool-runs._run-panel', ['run' => $run, 'inputFiles' => $this->inputFiles(), 'resultView' => $this->resultView(), 'isRunning' => $this->isRunning()])
    </div>
</x-filament-panels::page>
```

- [ ] **Step 4: Write the Run step view and page methods**

`resources/views/filament/pages/tools/_run-step.blade.php`:

```blade
@php
    $run = $this->currentRun();
@endphp

<div @if ($this->isRunning()) wire:poll.2s @endif class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button tag="a" :href="\App\Filament\Pages\ToolRunPage::urlFor($run->scope, $run)" icon="heroicon-o-arrow-top-right-on-square">Open the full result</x-filament::button>
        <x-filament::button color="gray" wire:click="runAgain" icon="heroicon-o-arrow-path">Run again</x-filament::button>
        <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\Dashboard::getUrl()" icon="heroicon-o-home">Back to home</x-filament::button>
    </div>

    @include('filament.tool-runs._run-panel', ['run' => $run, 'inputFiles' => $this->inputFiles(), 'resultView' => $this->resultView(), 'isRunning' => $this->isRunning()])
</div>
```

`App\Filament\Pages\Dashboard` is created in Task 10; until then use `\Filament\Pages\Dashboard::getUrl()` and change it in Task 10.

In `ToolWizardPage`:

```php
    public function currentRun(): ?ToolRun
    {
        return $this->runId === null ? null : ToolRun::query()->findOrFail($this->runId);
    }

    public function isRunning(): bool
    {
        $run = $this->currentRun();

        return $run !== null && in_array($run->status, [ToolRunStatus::Queued, ToolRunStatus::Running], true);
    }

    /** @return Collection<string, ExportFile|null> */
    public function inputFiles(): Collection
    {
        $named = $this->currentRun()?->inputs['files'] ?? [];
        $files = ExportFile::query()->whereKey(array_values($named))->get()->keyBy('id');

        return collect($named)->map(static fn (string $fileId): ?ExportFile => $files->get($fileId));
    }

    public function resultView(): string
    {
        $specific = 'filament.tool-runs.'.static::tool()->value;

        return view()->exists($specific) ? $specific : 'filament.tool-runs._generic';
    }

    /** Back to the form with the same choices. */
    public function runAgain(): void
    {
        $this->runId = null;
    }
```

Imports: `App\Enums\ToolRunStatus`, `App\Models\ToolRun`, `Illuminate\Support\Collection`. The `ToolRun` global scope makes another user's run a 404, so `findOrFail` is enough.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/ToolWizardPageTest.php tests/Feature/RunsTabTest.php tests/Feature/LoadAudit tests/Feature/RegionAvailability`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Tools/ToolWizardPage.php resources/views/filament/tool-runs/_run-panel.blade.php resources/views/filament/pages/tool-run.blade.php resources/views/filament/pages/tools/_run-step.blade.php tests/Feature/ToolWizardPageTest.php
git commit -m "Tool wizard: Run step sharing the run panel with the run page"
```

---

### Task 8: Sidebar groups by intent

**Files:**
- Modify: `app/Providers/Filament/AppPanelProvider.php` and every page or resource listed under "Modify" in the file structure.
- Test: `tests/Feature/NavigationGroupsTest.php`

**Interfaces:**
- Produces: the four group labels as constants on `App\Filament\Navigation\Groups`: `ANALYSE = 'Analyse exports'`, `LIVE = 'Live PSO actions'`, `SETUP = 'Setup'`, `ADMIN = 'Admin'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Filament\Navigation\Groups;
use App\Filament\Pages;
use App\Filament\Resources;
use App\Models\User;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('puts every page in one of the four intent groups', function (string $class, string $group) {
    expect($class::getNavigationGroup())->toBe($group);
})->with([
    [Pages\Tools\LoadAuditEntry::class, Groups::ANALYSE],
    [Pages\SysFileCompare::class, Groups::ANALYSE],
    [Pages\FilterLoadFile::class, Groups::ANALYSE],
    [Pages\PreferenceCalculator::class, Groups::ANALYSE],
    [Pages\HomePages\PSOActivityHome::class, Groups::LIVE],
    [Pages\HomePages\PSOResourceHome::class, Groups::LIVE],
    [Pages\HomePages\PSOModellingHome::class, Groups::LIVE],
    [Pages\Activity\UpdateActivityStatus::class, Groups::LIVE],
    [Pages\Resource\ResourceEvent::class, Groups::LIVE],
    [Pages\Modelling\ARPRegion::class, Groups::LIVE],
    [Pages\TechnicianDetails::class, Groups::LIVE],
    [Pages\GenericDelete::class, Groups::LIVE],
    [Pages\GenerateCustomException::class, Groups::LIVE],
    [Pages\TravelAnalyzer::class, Groups::LIVE],
    [Resources\EnvironmentResource::class, Groups::SETUP],
    [Resources\DatasetResource::class, Groups::SETUP],
    [Resources\DatasetReferenceValueResource::class, Groups::SETUP],
    [Resources\TokenUsageLogResource::class, Groups::ADMIN],
    [Pages\Backups::class, Groups::ADMIN],
    [Pages\HealthCheckResults::class, Groups::ADMIN],
    [Pages\IssueToken::class, Groups::ADMIN],
]);

it('orders the groups analyse, live, setup, admin', function () {
    $labels = collect(filament()->getPanel('app')->getNavigationGroups())
        ->map(fn ($group) => $group instanceof \Filament\Navigation\NavigationGroup ? $group->getLabel() : $group)->values()->all();
    expect($labels)->toBe([Groups::ANALYSE, Groups::LIVE, Groups::SETUP, Groups::ADMIN]);
});

it('no longer uses the old group names anywhere', function () {
    $hits = shell_exec("grep -rln \"'Base Data'\\|'API Services'\\|'Additional Tools'\\|'Core'\" app resources/views || true");
    expect(trim((string) $hits))->toBe('');
});
```

If `Panel::getNavigationGroups()` returns something other than the array given to `navigationGroups()`, read `vendor/filament/filament/src/Panel/Concerns/HasNavigation.php` and adjust the accessor in the second test; do not weaken the assertion.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/NavigationGroupsTest.php`
Expected: FAIL, `App\Filament\Navigation\Groups` not found.

- [ ] **Step 3: Add the constants and regroup**

`app/Filament/Navigation/Groups.php`:

```php
<?php

namespace App\Filament\Navigation;

/** Sidebar groups, named by what the consultant is trying to do. Order here is the sidebar order. */
final class Groups
{
    public const ANALYSE = 'Analyse exports';

    public const LIVE = 'Live PSO actions';

    public const SETUP = 'Setup';

    public const ADMIN = 'Admin';

    /** @return list<string> */
    public static function ordered(): array
    {
        return [self::ANALYSE, self::LIVE, self::SETUP, self::ADMIN];
    }
}
```

In `AppPanelProvider::panel()` add `->navigationGroups(Groups::ordered())` and replace the four colour entries `core`, `base-data`, `api-services`, `additional-tools` with `analyse => Color::Cyan`, `live => Color::Rose`, `setup => Color::Amber`, `admin => Color::Emerald`.

Change `$navigationGroup = '...'` on every class in the test table to the matching `Groups::` constant (the base pages in `app/Filament/BasePages/` carry it for the Activity, Resource and Modelling sub-pages). `Backups` and `HealthCheckResults` override `getNavigationGroup()`; make both return `Groups::ADMIN`. `IssueToken` has no group: add `protected static string|UnitEnum|null $navigationGroup = Groups::ADMIN;`. `TokenUsageLogResource` goes from `Core` to `Groups::ADMIN`.

- [ ] **Step 4: Run the test and the whole suite**

Run: `vendor/bin/pest tests/Feature/NavigationGroupsTest.php && php artisan test --compact`
Expected: PASS. The suite may still report the pre-existing `ExampleTest` failure; nothing else.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/Filament app/Providers tests/Feature/NavigationGroupsTest.php
git commit -m "Sidebar groups named by intent: Analyse exports, Live PSO actions, Setup, Admin"
```

---

### Task 9: Banner on live PSO action pages

**Files:**
- Create: `app/Filament/Navigation/LivePsoActionPages.php`, `resources/views/filament/live-environment-banner.blade.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php`
- Test: `tests/Feature/LiveEnvironmentBannerTest.php`

**Interfaces:**
- Produces: `LivePsoActionPages::all(): list<class-string>`; render hook `PanelsRenderHook::PAGE_START` scoped to those classes.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Filament\Navigation\LivePsoActionPages;
use App\Filament\Pages\GenericDelete;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('lists every page in the live group and nothing from the other groups', function () {
    $pages = LivePsoActionPages::all();
    expect($pages)->toContain(GenericDelete::class)->not->toContain(LoadAuditEntry::class);
    foreach ($pages as $page) {
        expect($page::getNavigationGroup())->toBe(\App\Filament\Navigation\Groups::LIVE);
    }
});

it('shows the live-environment banner on a live page and not on a wizard', function () {
    Livewire::test(GenericDelete::class)->assertSee('changes a live PSO environment');
    Livewire::test(LoadAuditEntry::class)->assertDontSee('changes a live PSO environment');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/LiveEnvironmentBannerTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the list, the view and the hook**

`app/Filament/Navigation/LivePsoActionPages.php`:

```php
<?php

namespace App\Filament\Navigation;

use App\Filament\Pages;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;

/** Pages whose actions change a live PSO environment; each shows the warning banner. */
final class LivePsoActionPages
{
    /** @return list<class-string<\Filament\Pages\Page>> */
    public static function all(): array
    {
        return [
            Pages\HomePages\PSOActivityHome::class,
            Pages\HomePages\PSOResourceHome::class,
            Pages\HomePages\PSOModellingHome::class,
            Pages\Activity\DeleteActivities::class,
            Pages\Activity\GenerateActivities::class,
            Pages\Activity\UpdateActivityStatus::class,
            Pages\Resource\ResourceEvent::class,
            Pages\Resource\ResourceUnavailability::class,
            Pages\Resource\ResourceUpdateShift::class,
            Pages\Resource\ResourceUpdateUnavailability::class,
            Pages\Modelling\ARPRegion::class,
            Pages\Modelling\ARPResource::class,
            Pages\TechnicianDetails::class,
            Pages\GenericDelete::class,
            Pages\GenerateCustomException::class,
            Pages\TravelAnalyzer::class,
            EnvironmentTools::class,
        ];
    }
}
```

Compare this list with `ls app/Filament/Pages/Activity app/Filament/Pages/Resource app/Filament/Pages/Modelling app/Filament/Pages/HomePages` and add any class missing. `EnvironmentTools` is a resource page, so `getNavigationGroup()` returns the resource's group; exclude it from the group loop in the test by checking `is_subclass_of($page, \Filament\Resources\Pages\Page::class)` and skipping.

`resources/views/filament/live-environment-banner.blade.php`:

```blade
<div class="mb-6 rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-200" role="note">
    <span class="font-semibold">Live action.</span> What you do on this page changes a live PSO environment. Check the environment selected before you send anything.
</div>
```

In `AppPanelProvider::register()` add:

```php
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn (): string => view('filament.live-environment-banner')->render(),
            scopes: LivePsoActionPages::all(),
        );
```

with `use Filament\View\PanelsRenderHook; use App\Filament\Navigation\LivePsoActionPages;`.

- [ ] **Step 4: Run the test**

Run: `vendor/bin/pest tests/Feature/LiveEnvironmentBannerTest.php`
Expected: PASS. If the banner does not render in the Livewire test because render hooks need the full page, change the second test to `$this->get(GenericDelete::getUrl())->assertSee(...)` and `$this->get(LoadAuditEntry::getUrl())->assertDontSee(...)`.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Navigation/LivePsoActionPages.php resources/views/filament/live-environment-banner.blade.php app/Providers/Filament/AppPanelProvider.php tests/Feature/LiveEnvironmentBannerTest.php
git commit -m "Banner on every page that changes a live PSO environment"
```

---

### Task 10: Home page: tool catalogue and recent runs

**Files:**
- Create: `app/Filament/Pages/Dashboard.php`, `app/Filament/Widgets/ToolCatalogue.php`, `resources/views/filament/widgets/tool-catalogue.blade.php`, `app/Filament/Widgets/RecentRuns.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php`, `resources/views/filament/pages/tools/_run-step.blade.php` (home link)
- Delete: `app/Filament/Widgets/QuickLaunch.php`, `resources/views/filament/widgets/quick-launch.blade.php`, `app/Filament/Widgets/StatsOverview.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `Tool::question()`, `Tool::getLabel()`, `ToolRegistry::has()`, `Tool::requiredKinds()/optionalKinds()`, `ToolRunPage::urlFor()`, the `*Entry` pages' `getUrl()`.
- Produces: `ToolCatalogue::sections(): list<array{label: string, accent: string, items: list<array{label: string, question: string, needs: string, url: string}>}>`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\RecentRuns;
use App\Filament\Widgets\ToolCatalogue;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists registered tools with their question and the files they need, grouped by intent', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);

    Livewire::test(ToolCatalogue::class)
        ->assertSee('Analyse exports')
        ->assertSee(ToolId::LoadAudit->getLabel())
        ->assertSee(ToolId::LoadAudit->question())
        ->assertSee('Needs a '.ExportKind::Load->getLabel().' export; '.ExportKind::Sys->getLabel().' optional')
        ->assertDontSee(ToolId::RegionAvailability->getLabel())
        ->assertSee('Live PSO actions')
        ->assertSee('Setup')
        ->assertSee('Admin');
});

it('hides unregistered tools and still renders with an empty registry', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    Livewire::test(ToolCatalogue::class)->assertOk()->assertDontSee(ToolId::LoadAudit->getLabel());
});

it('shows the current user recent runs with a link to each result, newest first', function () {
    $mine = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'queued_at' => now()->subMinute()]);
    $newer = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'queued_at' => now()]);
    $theirs = ToolRun::factory()->create(['tool' => ToolId::LoadAudit]);

    Livewire::test(RecentRuns::class)
        ->assertCanSeeTableRecords([$newer, $mine], inOrder: true)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee('NORTH');
});

it('replaces the default dashboard', function () {
    $this->get(Dashboard::getUrl())->assertOk()->assertSee('Analyse exports')->assertSee('Recent runs');
    expect(class_exists(\App\Filament\Widgets\QuickLaunch::class))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/DashboardTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the dashboard and widgets**

`app/Filament/Pages/Dashboard.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RecentRuns;
use App\Filament\Widgets\ToolCatalogue;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/** Home: what can I do here, and what did I run lately. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Home';

    public function getWidgets(): array
    {
        return [AccountWidget::class, ToolCatalogue::class, RecentRuns::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
```

`app/Filament/Widgets/ToolCatalogue.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Navigation\Groups;
use App\Filament\Pages;
use App\Filament\Resources;
use App\Tools\ToolRegistry;
use Filament\Support\Colors\Color;
use Filament\Widgets\Widget;

class ToolCatalogue extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tool-catalogue';

    /** @return list<array{label: string, accent: string, items: list<array{label: string, question: string, needs: ?string, url: string}>}> */
    public function sections(): array
    {
        $registry = app(ToolRegistry::class);
        $analyse = [];
        foreach (ToolId::cases() as $tool) {
            $page = $this->wizardPageFor($tool);
            if ($page === null || ! $registry->has($tool)) {
                continue;
            }
            $impl = $registry->for($tool);
            $analyse[] = ['label' => $tool->getLabel(), 'question' => $tool->question(), 'needs' => $this->needs($impl->requiredKinds(), $impl->optionalKinds()), 'url' => $page::getUrl()];
        }
        $analyse[] = ['label' => 'PSO Sys File Compare', 'question' => 'What differs between two or more SYS exports?', 'needs' => 'Needs two or more '.ExportKind::Sys->getLabel().' exports', 'url' => Pages\SysFileCompare::getUrl()];
        $analyse[] = ['label' => 'Filter Load File', 'question' => 'Can I cut a LOAD down to a few resources or activities?', 'needs' => 'Needs a '.ExportKind::Load->getLabel().' export', 'url' => Pages\FilterLoadFile::getUrl()];
        $analyse[] = ['label' => 'Preference Calculator', 'question' => 'What preference values give the ranking I want?', 'needs' => null, 'url' => Pages\PreferenceCalculator::getUrl()];

        return [
            ['label' => Groups::ANALYSE, 'accent' => Color::Cyan[500], 'items' => $analyse],
            ['label' => Groups::LIVE, 'accent' => Color::Rose[500], 'items' => [
                ['label' => 'Activity Services', 'question' => 'Create, update or delete activities in a live environment.', 'needs' => null, 'url' => Pages\HomePages\PSOActivityHome::getUrl()],
                ['label' => 'Resource Services', 'question' => 'Change shifts, events and unavailability for a resource.', 'needs' => null, 'url' => Pages\HomePages\PSOResourceHome::getUrl()],
                ['label' => 'Modelling Services', 'question' => 'Generate regions and resources for a model.', 'needs' => null, 'url' => Pages\HomePages\PSOModellingHome::getUrl()],
                ['label' => 'Gateway upload', 'question' => 'Send a schedule data file to an environment.', 'needs' => 'Open an environment, then its Tools tab', 'url' => Resources\EnvironmentResource::getUrl()],
                ['label' => 'Travel Analyzer', 'question' => 'How do PSO travel times compare with the map provider?', 'needs' => null, 'url' => Pages\TravelAnalyzer::getUrl()],
                ['label' => 'Generic Delete', 'question' => 'Delete one object from a live environment.', 'needs' => null, 'url' => Pages\GenericDelete::getUrl()],
                ['label' => 'Generate Custom Exception', 'question' => 'Raise a custom exception in a live environment.', 'needs' => null, 'url' => Pages\GenerateCustomException::getUrl()],
            ]],
            ['label' => Groups::SETUP, 'accent' => Color::Amber[500], 'items' => [
                ['label' => 'Environments', 'question' => 'The PSO systems you connect to.', 'needs' => null, 'url' => Resources\EnvironmentResource::getUrl()],
                ['label' => 'Datasets', 'question' => 'One per customer dataset: settings, exports and run history.', 'needs' => null, 'url' => Resources\DatasetResource::getUrl()],
                ['label' => 'Reference values', 'question' => 'Regions, skills and types synced from PSO.', 'needs' => null, 'url' => Resources\DatasetReferenceValueResource::getUrl()],
            ]],
            ['label' => Groups::ADMIN, 'accent' => Color::Emerald[500], 'items' => [
                ['label' => 'Token usage', 'question' => 'API calls made through this app.', 'needs' => null, 'url' => Resources\TokenUsageLogResource::getUrl()],
                ['label' => 'Issue token', 'question' => 'Create an API token.', 'needs' => null, 'url' => Pages\IssueToken::getUrl()],
                ['label' => 'Health', 'question' => 'Is this app healthy?', 'needs' => null, 'url' => Pages\HealthCheckResults::getUrl()],
                ['label' => 'Backups', 'question' => 'Database and file backups.', 'needs' => null, 'url' => Pages\Backups::getUrl()],
            ]],
        ];
    }

    /** @return class-string<Pages\Tools\ToolWizardPage>|null */
    private function wizardPageFor(ToolId $tool): ?string
    {
        foreach ([Pages\Tools\LoadAuditEntry::class, Pages\Tools\RegionAvailabilityEntry::class, Pages\Tools\AppointmentTimelineEntry::class, Pages\Tools\PlanTravelsEntry::class, Pages\Tools\ScheduleReviewEntry::class, Pages\Tools\SysCompareEntry::class] as $page) {
            if ($page::tool() === $tool) {
                return $page;
            }
        }

        return null;
    }

    /** @param  list<ExportKind>  $required  @param  list<ExportKind>  $optional */
    private function needs(array $required, array $optional): string
    {
        $need = implode(', ', array_map(static fn (ExportKind $k): string => $k->getLabel(), $required));
        $opt = implode(', ', array_map(static fn (ExportKind $k): string => $k->getLabel(), $optional));

        return 'Needs a '.$need.' export'.($opt !== '' ? '; '.$opt.' optional' : '');
    }
}
```

Check that the lines about "Preference Calculator" and "Filter Load File" describe what those pages actually do (open them, read the top of each class); fix the wording if not. Check "ScheduleReviewEntry" exists after the schedule review removal (branch chore/remove-schedule-review, commit a4cddd6); if it was deleted, drop it from the list.

`resources/views/filament/widgets/tool-catalogue.blade.php`:

```blade
<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach ($this->sections() as $section)
            <x-filament::section style="--accent: {{ $section['accent'] }}">
                <x-slot name="heading">
                    <span class="inline-flex items-center gap-2"><span class="h-2 w-2 rounded-full" style="background-color: var(--accent)"></span>{{ $section['label'] }}</span>
                </x-slot>
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($section['items'] as $item)
                        <li class="py-3">
                            <a href="{{ $item['url'] }}" class="block rounded-lg px-2 py-1 -mx-2 hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $item['label'] }}</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-300">{{ $item['question'] }}</span>
                                @if ($item['needs'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item['needs'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
```

`app/Filament/Widgets/RecentRuns.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ToolRunPage;
use App\Models\ToolRun;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRuns extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent runs')
            ->query(ToolRun::query()->with(['scope', 'startedBy'])->latest('queued_at')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('tool')->badge(),
                TextColumn::make('scope.name')->label('Dataset or environment'),
                TextColumn::make('status')->badge(),
                TextColumn::make('queued_at')->label('Started')->since(),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->url(static fn (ToolRun $record): string => ToolRunPage::urlFor($record->scope, $record)),
            ])
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Pick a tool above to run one.');
    }
}
```

The `ToolRun` global scope already limits rows to the user's own.

In `AppPanelProvider`: replace `Pages\Dashboard::class` in `->pages([...])` with `\App\Filament\Pages\Dashboard::class` and reduce `->widgets([...])` to `[Widgets\AccountWidget::class]`. Delete `QuickLaunch.php`, `quick-launch.blade.php`, `StatsOverview.php`. In `_run-step.blade.php` point "Back to home" at `\App\Filament\Pages\Dashboard::getUrl()`.

- [ ] **Step 4: Run the tests and the suite**

Run: `vendor/bin/pest tests/Feature/DashboardTest.php && php artisan test --compact`
Expected: PASS (except the pre-existing `ExampleTest` failure if still present).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/Filament/Pages/Dashboard.php app/Filament/Widgets resources/views/filament/widgets resources/views/filament/pages/tools/_run-step.blade.php app/Providers/Filament/AppPanelProvider.php tests/Feature/DashboardTest.php
git commit -m "Home page: tool catalogue by intent and recent runs"
```

---

### Task 11: Dataset Runs tab points at the wizard, docs and handoff

**Files:**
- Modify: `app/Filament/RelationManagers/ToolRunsRelationManager.php` (empty state), `README.md`, `docs/superpowers/notes/2026-10-15-tool-wizard-execution-notes.md` (create)
- Test: `tests/Feature/RunsTabTest.php` (append one test)

- [ ] **Step 1: Append the failing test**

```php
it('tells an empty Runs tab to start from a tool wizard', function () {
    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('Start a run from a tool under Analyse exports');
});
```

- [ ] **Step 2: Run to verify failure, then add the empty state**

Run: `vendor/bin/pest tests/Feature/RunsTabTest.php`
Expected: the new test FAILS. In `ToolRunsRelationManager::table()` add after `->defaultSort(...)`:

```php
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Start a run from a tool under Analyse exports; it will appear here.')
```

- [ ] **Step 3: Run the test**

Run: `vendor/bin/pest tests/Feature/RunsTabTest.php`
Expected: PASS.

- [ ] **Step 4: Write the notes and README lines**

Create `docs/superpowers/notes/2026-10-15-tool-wizard-execution-notes.md` with: what was built (one line per task), anything deferred, and the after-merge steps: `php artisan migrate`, `php artisan optimize:clear`, restart the queue worker, and a browser check list (home page renders four sections; Load audit wizard runs end to end on the SaskTel dataset; Sys compare entry hidden; banner on Activity Services; sidebar shows the four groups in order). In `README.md`, under the section that describes the panel, replace the old group names with the four new ones and one sentence on the wizard.

- [ ] **Step 5: Full suite and commit**

Run: `php artisan test --compact`
Expected: PASS (except the pre-existing `ExampleTest` failure if still present).

```bash
vendor/bin/pint --dirty
git add app/Filament/RelationManagers/ToolRunsRelationManager.php tests/Feature/RunsTabTest.php README.md docs/superpowers/notes/2026-10-15-tool-wizard-execution-notes.md
git commit -m "Runs tab points at the wizards; execution notes"
```

---

## Self-review

Spec coverage: section 1 (groups, home, hidden tools, recent runs) in Tasks 8 and 10; section 2 steps 1 to 4 in Tasks 4 to 7, slug kept in Task 4, entry pages as subclasses in Task 4, legacy pages regrouped only in Task 8; section 3 in Task 1; section 4 errors in Tasks 2, 6, 7 (expired file, setup exception surfaces through the run panel's failed state as today, ownership 404 in Task 6); section 5 tests across Tasks 4 to 10; banner in Task 9. The spec's "Gateway upload" item has no sidebar page of its own, so the catalogue links to Environments and says where it lives (Task 10).

Type consistency: `StartToolRun::start(Model, ToolId, array, array, User): ToolRun` used identically in Tasks 2, 6; `UnusableInputException::$kind` read in Task 6; `Groups::` constants used in Tasks 8, 9, 10; `ToolWizardPage::tool()` static in Tasks 4, 10; `RunInputPicker::optionsWithExpiryFor` introduced and used only in Task 5.

Review Focus mapping: 1 Task 4 "clears the dataset"; 2 Task 4 "pre-fills the only"; 3 Task 7 "refuses a file that is no longer usable"; 4 Task 4 "no dataset select" and Task 2 "null dataset for an environment-scoped run"; 5 Task 10 "hides unregistered tools".
