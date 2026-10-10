# Export Sets, Tool Runs and Dataset Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give pso-test-tools one shared way to upload PSO exports (LOAD, plan, EOD, audits on a dataset; SYS on an environment) straight to R2 with a 12 hour purge, run a tool against them on the queue, keep the encrypted result in the database, and hold per-dataset settings, so the tool ports that follow only add a parser and a dashboard view.

**Architecture:** `export_files` rows (morph-scoped to Dataset or Environment) are created by a slot endpoint that returns a presigned R2 PUT URL; the browser uploads directly, then calls a complete endpoint that queues a streaming summariser. `tool_runs` rows are executed by `RunToolJob`, which streams inputs to a scratch folder, calls a `Tool` implementation and stores the result with the `encrypted:array` cast. A purge command deletes R2 objects at `expires_at`. Dataset settings are a typed value object cast on `datasets.settings`.

**Tech Stack:** Laravel 13, Livewire 4, Filament v5, PHP 8.4, Pest 4, Flysystem S3 adapter (Cloudflare R2), `XMLReader`, `ZipArchive`, in-memory SQLite for tests with `Storage::fake('r2')`.

**Spec:** `docs/superpowers/specs/2026-10-10-export-sets-design.md`. Builds on `docs/superpowers/specs/2026-10-10-dataset-reference-values-design.md` (datasets, `DatasetOwnedModel`, the Dataset page). Executors read both.

## Global Constraints

- Filament namespaces (CLAUDE.md): form fields `Filament\Forms\Components\`, layout `Filament\Schemas\Components\`, `Get`/`Set` from `Filament\Schemas\Components\Utilities\`, table columns `Filament\Tables\Columns\`, actions `Filament\Actions\` only. No `final`/`readonly` on classes extending Filament. `static fn` when `$this` is unused. Explicit return types. No abbreviated variable names.
- **No app route ever accepts a file body.** Files go browser to R2 via a presigned PUT. `post_max_size` must stay irrelevant.
- **Raw bodies are never held in memory.** Read R2 objects with `readStream` into a scratch file; parse with `XMLReader`; zips through `ZipArchive` on the scratch file.
- **Object keys are built from uuids only**: `exports/{scope}/{scope_id}/{export_file_id}.{ext}`. Nothing user-supplied reaches a path.
- Raw files expire **12 hours** after upload (`config('exports.ttl_hours')`). Results are kept until deleted. `tool_runs.result` uses the `encrypted:array` cast.
- Parsers keep only what checks need. SYS credentials and audit customer contact fields never leave a parser.
- Dashboard wording rules from the pso-tools CLAUDE.md: plain English check names, "allocated" never "placed", areas named Load data / Configuration / PSO system / Engine performance, warnings shown on the page, no em dashes in generated text.
- Scope rules: `sys` only on an Environment; `load`, `plan`, `eod`, `audits` only on a Dataset.
- Commits: imperative, `feat:`/`fix:`/`test:`/`docs:` prefix, ending with the attribution lines from the session reminder.

## Review Focus

1. A zip whose only member is a SYS export uploaded as kind `load`: must end `failed` with a message naming the mismatch, never `ready`. Test in Task 4.
2. A slot requested for a dataset the user does not own, or `sys` on a dataset: 403 / 422, no row created. Test in Task 5.
3. `complete` called for an object that never arrived or whose size differs from the declared size: row `failed`, no job queued. Test in Task 5.
4. The purge must not delete an object that a running tool is reading: `RunToolJob` streams inputs to scratch before touching anything, and a run started after a file is `purged` is refused with a plain message. Test in Task 7.
5. A scratch folder left behind by a job killed mid-way must be swept, and a user must not be able to open another user's run page. Tests in Task 6 and Task 9.

---

### Task 1: Config, enums, migrations and models

**Files:**
- Create: `config/exports.php`
- Create: `app/Enums/ExportKind.php`, `app/Enums/ExportFileStatus.php`, `app/Enums/Tool.php`, `app/Enums/ToolRunStatus.php`
- Create: `database/migrations/2026_10_11_000001_create_export_files_table.php`, `database/migrations/2026_10_11_000002_create_tool_runs_table.php`, `database/migrations/2026_10_11_000003_add_settings_to_datasets_table.php`
- Create: `app/Models/ExportFile.php`, `app/Models/ToolRun.php`, `app/Models/Scopes/ScopeOwnedModel.php`
- Create: `database/factories/ExportFileFactory.php`, `database/factories/ToolRunFactory.php`
- Modify: `app/Models/Dataset.php`, `app/Models/Environment.php` (relations)
- Test: `tests/Feature/ExportFileModelTest.php`

**Interfaces:**
- Produces: `ExportKind` (`Load='load'`, `Plan='plan'`, `Eod='eod'`, `Audits='audits'`, `Sys='sys'`) with `allowedScope(): string` (model class), `extensions(): array`, `getLabel()`. `ExportFileStatus` (`Uploading`, `Summarising`, `Ready`, `Failed`, `Purged`). `Tool` (`LoadAudit='load_audit'`, `ScheduleReview`, `ScheduleRollup`, `RegionAvailability`, `AppointmentTimeline`, `PlanTravels`, `SysCompare`) with `getLabel()`, `scope(): string` (Dataset or Environment class). `ToolRunStatus` (`Queued`, `Running`, `Succeeded`, `Failed`). `ExportFile` (morph `scope()`, `uploadedBy()`, casts, `scopeReady()`, `isExpired()`, `objectExtension()`), `ToolRun` (morph `scope()`, `startedBy()`, `result` encrypted:array, `inputs` array, `warnings` array). `Dataset::exportFiles()`, `Dataset::toolRuns()`, `Environment::exportFiles()`, `Environment::toolRuns()`. `ScopeOwnedModel` global scope: rows whose scope is a Dataset owned (through its environment) or an Environment owned by `auth()->id()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportKind;
use App\Enums\ExportFileStatus;
use App\Enums\Tool;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates the tables and the settings column', function () {
    expect(Schema::hasTable('export_files'))->toBeTrue()
        ->and(Schema::hasTable('tool_runs'))->toBeTrue()
        ->and(Schema::hasColumn('datasets', 'settings'))->toBeTrue();
});

it('maps kinds to the scope they are allowed on', function () {
    expect(ExportKind::Sys->allowedScope())->toBe(Environment::class)
        ->and(ExportKind::Load->allowedScope())->toBe(Dataset::class)
        ->and(ExportKind::Audits->extensions())->toBe(['zip'])
        ->and(ExportKind::Load->extensions())->toBe(['xml', 'json', 'zip'])
        ->and(Tool::LoadAudit->scope())->toBe(Dataset::class)
        ->and(Tool::SysCompare->scope())->toBe(Environment::class);
});

it('refuses a sys file on a dataset and a load file on an environment', function () {
    $dataset = Dataset::factory()->create();

    expect(fn () => ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Sys]))
        ->toThrow(InvalidArgumentException::class, 'SYS');
    expect(fn () => ExportFile::factory()->for($dataset->environment, 'scope')->create(['kind' => ExportKind::Load]))
        ->toThrow(InvalidArgumentException::class);
});

it('scopes export files and runs to the logged-in user through the dataset or environment', function () {
    $me = User::factory()->create();
    $mine = Dataset::factory()->for(Environment::factory()->for($me))->create();
    $theirs = Dataset::factory()->create();
    ExportFile::factory()->for($mine, 'scope')->create(['original_filename' => 'mine.xml']);
    ExportFile::factory()->for($theirs, 'scope')->create(['original_filename' => 'theirs.xml']);
    ExportFile::factory()->for($mine->environment, 'scope')->sys()->create(['original_filename' => 'mine-sys.xml']);
    ExportFile::factory()->for($theirs->environment, 'scope')->sys()->create(['original_filename' => 'theirs-sys.xml']);
    ToolRun::factory()->for($mine, 'scope')->create();
    ToolRun::factory()->for($theirs, 'scope')->create();

    $this->actingAs($me);

    expect(ExportFile::pluck('original_filename')->sort()->values()->all())->toBe(['mine-sys.xml', 'mine.xml'])
        ->and(ToolRun::count())->toBe(1)
        ->and($mine->exportFiles()->count())->toBe(1)
        ->and($mine->environment->exportFiles()->count())->toBe(1);
});

it('sets expires_at twelve hours after creation and knows when it is expired', function () {
    config(['exports.ttl_hours' => 12]);
    $this->travelTo(now()->startOfMinute());
    $file = ExportFile::factory()->create();

    expect($file->expires_at->eq(now()->addHours(12)))->toBeTrue()
        ->and($file->isExpired())->toBeFalse();

    $this->travel(13)->hours();
    expect($file->fresh()->isExpired())->toBeTrue();
});

it('stores the result encrypted', function () {
    $run = ToolRun::factory()->create(['result' => ['findings' => ['one']]]);

    $raw = \Illuminate\Support\Facades\DB::table('tool_runs')->where('id', $run->id)->value('result');

    expect($raw)->not->toContain('findings')
        ->and($run->fresh()->result)->toBe(['findings' => ['one']]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ExportFileModelTest.php`
Expected: FAIL, enums and models not found.

- [ ] **Step 3: Config**

`config/exports.php`:

```php
<?php

return [
    // R2 disk (config/filesystems.php) that holds raw exports.
    'disk' => env('EXPORTS_DISK', 'r2'),
    // Raw exports are deleted this long after upload. Results in the database are kept.
    'ttl_hours' => (int) env('EXPORTS_TTL_HOURS', 12),
    'max_file_megabytes' => (int) env('EXPORTS_MAX_FILE_MEGABYTES', 400),
    // How long a presigned upload URL stays valid.
    'slot_minutes' => (int) env('EXPORTS_SLOT_MINUTES', 15),
    // Scratch folders older than this are swept by exports:purge (a job died mid-way).
    'scratch_sweep_hours' => (int) env('EXPORTS_SCRATCH_SWEEP_HOURS', 2),
    'scratch_path' => storage_path('app/private/exports'),
];
```

- [ ] **Step 4: Enums**

`app/Enums/ExportKind.php`:

```php
<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use Filament\Support\Contracts\HasLabel;

enum ExportKind: string implements HasLabel
{
    case Load = 'load';
    case Plan = 'plan';
    case Eod = 'eod';
    case Audits = 'audits';
    case Sys = 'sys';

    /** The model class an export of this kind hangs off. */
    public function allowedScope(): string
    {
        return $this === self::Sys ? Environment::class : Dataset::class;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return match ($this) {
            self::Audits => ['zip'],
            default => ['xml', 'json', 'zip'],
        };
    }

    /** Root element the summariser expects once a zip is opened. */
    public function expectedRoot(): ?string
    {
        return match ($this) {
            self::Sys => 'DsSystemData',
            self::Audits => null,
            default => 'dsScheduleData',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Load => 'LOAD',
            self::Plan => 'Plan',
            self::Eod => 'End of day',
            self::Audits => 'Audit messages',
            self::Sys => 'SYS',
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->allowedScope() === $scopeClass));
    }
}
```

`app/Enums/ExportFileStatus.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExportFileStatus: string implements HasColor, HasLabel
{
    case Uploading = 'uploading';
    case Summarising = 'summarising';
    case Ready = 'ready';
    case Failed = 'failed';
    case Purged = 'purged';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Purged => 'gray',
            default => 'warning',
        };
    }
}
```

`app/Enums/Tool.php`:

```php
<?php

namespace App\Enums;

use App\Models\Dataset;
use App\Models\Environment;
use Filament\Support\Contracts\HasLabel;

enum Tool: string implements HasLabel
{
    case LoadAudit = 'load_audit';
    case ScheduleReview = 'schedule_review';
    case ScheduleRollup = 'schedule_rollup';
    case RegionAvailability = 'region_availability';
    case AppointmentTimeline = 'appointment_timeline';
    case PlanTravels = 'plan_travels';
    case SysCompare = 'sys_compare';

    public function scope(): string
    {
        return $this === self::SysCompare ? Environment::class : Dataset::class;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LoadAudit => 'Load and configuration health audit',
            self::ScheduleReview => 'Daily schedule review',
            self::ScheduleRollup => 'Schedule review roll-up',
            self::RegionAvailability => 'Region availability',
            self::AppointmentTimeline => 'Appointment timeline',
            self::PlanTravels => 'Plan travels',
            self::SysCompare => 'PSO sys file compare',
        };
    }

    /** @return list<self> */
    public static function forScope(string $scopeClass): array
    {
        return array_values(array_filter(self::cases(), static fn (self $tool): bool => $tool->scope() === $scopeClass));
    }
}
```

`app/Enums/ToolRunStatus.php`: same shape as `ExportFileStatus` with cases `Queued`, `Running`, `Succeeded` (success), `Failed` (danger), others `warning`.

- [ ] **Step 5: Migrations**

`2026_10_11_000001_create_export_files_table.php`:

```php
<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('scope');
            $table->string('kind', 16);
            $table->foreignIdFor(User::class, 'uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('content_type', 8);
            $table->json('summary')->nullable();
            $table->string('status', 16);
            $table->text('error_message')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
```

`2026_10_11_000002_create_tool_runs_table.php`:

```php
        Schema::create('tool_runs', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('scope');
            $table->string('tool', 32);
            $table->date('run_date')->nullable();
            $table->json('inputs');
            $table->foreignIdFor(User::class, 'started_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->longText('result')->nullable();   // encrypted JSON, so longText not json
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'tool', 'run_date']);
        });
```

`2026_10_11_000003_add_settings_to_datasets_table.php`: `$table->json('settings')->nullable();` with a matching `dropColumn` in `down()`.

- [ ] **Step 6: Scope and models**

`app/Models/Scopes/ScopeOwnedModel.php`:

```php
<?php

namespace App\Models\Scopes;

use App\Models\Dataset;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Rows hang off a Dataset or an Environment (morph "scope"); either way the
 * owner is the environment's user. Applied to export files and tool runs.
 */
class ScopeOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        $builder->where(static function (Builder $query) use ($userId): void {
            $query->whereHasMorph('scope', [Dataset::class], static fn (Builder $dataset) => $dataset
                ->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', $userId)))
                ->orWhereHasMorph('scope', [Environment::class], static fn (Builder $environment) => $environment->where('user_id', $userId));
        });
    }
}
```

Note: `Environment` has its own `UserOwnedModel` global scope, which also filters by `auth()->id()`; inside `whereHasMorph` it applies as well, which is harmless. Jobs that run with no user must use `withoutGlobalScopes()` on these models and `Environment::withoutGlobalScopes()` when they walk to the environment (see the reference values sync job for the pattern).

`app/Models/ExportFile.php`:

```php
<?php

namespace App\Models;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Scopes\ScopeOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Override;

/**
 * One uploaded PSO export (LOAD, plan, EOD, audits zip on a dataset; SYS on an
 * environment). The object lives on R2 until expires_at; the row stays as history.
 *
 * @mixin Builder
 */
class ExportFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'scope_type', 'scope_id', 'kind', 'uploaded_by_user_id', 'original_filename', 'stored_path',
        'file_size_bytes', 'content_type', 'summary', 'status', 'error_message', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'status' => ExportFileStatus::class,
            'summary' => 'array',
            'file_size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeOwnedModel);

        static::creating(static function (self $file): void {
            $file->expires_at ??= now()->addHours((int) config('exports.ttl_hours', 12));
            $file->status ??= ExportFileStatus::Uploading;
        });

        static::saving(static function (self $file): void {
            $kind = $file->kind instanceof ExportKind ? $file->kind : ExportKind::from($file->kind);
            if ($file->scope_type !== $kind->allowedScope()) {
                $label = $kind->getLabel();
                $scope = class_basename($kind->allowedScope());

                throw new InvalidArgumentException("A {$label} export belongs on a {$scope}, not a ".class_basename($file->scope_type).'.');
            }
        });
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', ExportFileStatus::Ready);
    }

    public function scopeOfKind(Builder $query, ExportKind $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === ExportFileStatus::Ready && ! $this->isExpired();
    }

    /** The export's local date, from the summary, when known. */
    public function exportDate(): ?string
    {
        return data_get($this->summary, 'export_date');
    }
}
```

`app/Models/ToolRun.php`: same skeleton with `fillable` for its columns, casts `tool => Tool::class`, `status => ToolRunStatus::class`, `inputs => 'array'`, `warnings => 'array'`, `result => 'encrypted:array'`, `run_date => 'date'`, the three timestamps as `datetime`; relations `scope(): MorphTo`, `startedBy(): BelongsTo`; `ScopeOwnedModel` in `booted()`; `creating` defaults `status` to `Queued` and `queued_at` to now.

Add to `Dataset` and `Environment`:

```php
    public function exportFiles(): MorphMany
    {
        return $this->morphMany(ExportFile::class, 'scope');
    }

    public function toolRuns(): MorphMany
    {
        return $this->morphMany(ToolRun::class, 'scope');
    }
```

Add `'settings' => 'array'` to `Dataset::casts()` for now (Task 2 replaces it with the value-object cast).

Register the morph map in `AppServiceProvider::boot()` so `scope_type` holds `dataset` / `environment`, not class names:

```php
        Relation::enforceMorphMap([
            'dataset' => Dataset::class,
            'environment' => Environment::class,
        ]);
```

Then `allowedScope()` comparisons in the model must compare classes, not the stored alias: use `$file->scope_type !== (new ($kind->allowedScope()))->getMorphClass()`; with `enforceMorphMap` the stored value is the alias, and `getMorphClass()` returns the alias. Write it that way from the start.

- [ ] **Step 7: Factories**

`ExportFileFactory::definition()`: `scope_type`/`scope_id` from `Dataset::factory()` (use `for($model, 'scope')` in tests), `kind => ExportKind::Load`, `uploaded_by_user_id => User::factory()`, `original_filename => 'load.xml'`, `stored_path => 'exports/dataset/'.fake()->uuid().'/'.fake()->uuid().'.xml'`, `file_size_bytes => 1024`, `content_type => 'xml'`, `status => ExportFileStatus::Ready`, `summary => null`. States: `sys()` (kind Sys, filename `sys.xml`), `uploading()`, `purged()`, `failed()`. Because the default `scope` is a Dataset while `sys()` needs an Environment, tests always pass the scope with `for(..., 'scope')`; the default morph target is `Dataset::factory()` through a `configure()` hook that fills `scope_type`/`scope_id` when unset.

`ToolRunFactory::definition()`: scope Dataset, `tool => Tool::LoadAudit`, `run_date => today()`, `inputs => []`, `started_by_user_id => User::factory()`, `status => ToolRunStatus::Succeeded`, `result => ['ok' => true]`, `warnings => []`, `queued_at/started_at/finished_at => now()`.

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/ExportFileModelTest.php`, then `php artisan test`.
Expected: PASS (6 new tests); whole suite green apart from the known `ExampleTest` failure.

- [ ] **Step 9: Commit**

```bash
git add config/exports.php app/Enums app/Models app/Providers/AppServiceProvider.php database tests/Feature/ExportFileModelTest.php
git commit -m "feat: export files, tool runs and dataset settings schema"
```

---

### Task 2: Dataset settings value object, form section and seed command

**Files:**
- Create: `app/Support/DatasetSettings.php`, `app/Casts/DatasetSettingsCast.php`
- Create: `app/Console/Commands/SeedDatasetSettings.php`
- Modify: `app/Models/Dataset.php` (cast, form section)
- Test: `tests/Unit/DatasetSettingsTest.php`, `tests/Feature/DatasetSettingsFormTest.php`

**Interfaces:**
- Produces: `DatasetSettings` (readonly-style value object with typed public properties for every key in spec 3.4, `fromArray(array): self` rejecting unknown keys with `InvalidArgumentException`, `toArray(): array`, `fromAccountFile(array $json): self` mapping the pso-tools account keys), `Dataset::settings` returning `DatasetSettings` (never null; defaults when the column is null), `Dataset::timeZone(): string` (settings time zone or `config('app.timezone')`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/DatasetSettingsTest.php`:

```php
<?php

use App\Support\DatasetSettings;

it('maps a pso-tools account file onto the typed keys', function () {
    $settings = DatasetSettings::fromAccountFile([
        'display_name' => 'SaskTel',
        'coverage_km' => 50,
        'eod_cutoff_hour' => 22,
        'exclude_resource_types' => ['LONGCYCLE'],
        'resource_type_names' => ['DISTRICTTECH' => 'district tech'],
        'attributes' => ['task_type' => 'Task Type'],
        'region_pivot' => ['red_below' => 1, 'amber_below' => 3],
        'notes' => ['end_of_day' => 'Take exports before 21:30.'],
        'regions' => ['district_label' => 'Territory', 'district_label_plural' => 'Territories'],
        'supervisor_label' => 'Company',
    ]);

    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->coverageKm)->toBe(50.0)
        ->and($settings->eodCutoffHour)->toBe(22)
        ->and($settings->excludeResourceTypes)->toBe(['LONGCYCLE'])
        ->and($settings->attributeLabels)->toBe(['task_type' => 'Task Type'])
        ->and($settings->regionPivot)->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($settings->regionLabels)->toBe(['district_label' => 'Territory', 'district_label_plural' => 'Territories'])
        ->and($settings->supervisorLabel)->toBe('Company')
        ->and($settings->timeZone)->toBeNull();
});

it('round trips through toArray and fromArray', function () {
    $settings = DatasetSettings::fromArray(['display_name' => 'Rogers', 'coverage_km' => 15, 'time_zone' => 'America/Toronto']);

    expect(DatasetSettings::fromArray($settings->toArray())->toArray())->toBe($settings->toArray())
        ->and($settings->toArray()['time_zone'])->toBe('America/Toronto');
});

it('rejects unknown keys and bad values', function () {
    expect(fn () => DatasetSettings::fromArray(['colour' => 'blue']))->toThrow(InvalidArgumentException::class, 'colour');
    expect(fn () => DatasetSettings::fromArray(['coverage_km' => 'far']))->toThrow(InvalidArgumentException::class, 'coverage_km');
    expect(fn () => DatasetSettings::fromArray(['time_zone' => 'Mars/Olympus']))->toThrow(InvalidArgumentException::class, 'time_zone');
});

it('has sensible defaults when nothing is set', function () {
    $settings = DatasetSettings::fromArray([]);

    expect($settings->coverageKm)->toBe(50.0)
        ->and($settings->regionPivot)->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($settings->excludeResourceTypes)->toBe([])
        ->and($settings->displayName)->toBeNull();
});
```

`tests/Feature/DatasetSettingsFormTest.php`:

```php
<?php

use App\Filament\Resources\DatasetResource\Pages\EditDataset;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('saves typed settings from the dataset form', function () {
    Livewire::test(EditDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->fillForm([
            'settings.display_name' => 'SaskTel',
            'settings.coverage_km' => 50,
            'settings.time_zone' => 'America/Regina',
            'settings.exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $this->dataset->fresh()->settings;
    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->timeZone)->toBe('America/Regina')
        ->and($settings->excludeResourceTypes)->toBe(['LONGCYCLE', 'SHORTCYCLE'])
        ->and($this->dataset->fresh()->timeZone())->toBe('America/Regina');
});

it('seeds settings from a pso-tools account file', function () {
    $path = tempnam(sys_get_temp_dir(), 'acct_');
    file_put_contents($path, json_encode(['display_name' => 'SaskTel', 'coverage_km' => 50, 'resource_type_names' => ['CONSUMER' => 'consumer']]));

    Artisan::call('datasets:seed-settings', ['dataset' => $this->dataset->id, 'file' => $path, '--time-zone' => 'America/Regina']);

    $settings = $this->dataset->fresh()->settings;
    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->resourceTypeNames)->toBe(['CONSUMER' => 'consumer'])
        ->and($settings->timeZone)->toBe('America/Regina');
    @unlink($path);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Unit/DatasetSettingsTest.php tests/Feature/DatasetSettingsFormTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: The value object**

`app/Support/DatasetSettings.php`:

```php
<?php

namespace App\Support;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Per-dataset settings the ported tools read (what accounts/SATE.json and
 * ROGE.json hold in the pso-tools repo). Fixed key list; unknown keys are an
 * error so a typo in the form or a seed file cannot silently do nothing.
 */
class DatasetSettings
{
    public const array KEYS = [
        'display_name', 'dataset_display_name', 'coverage_km', 'eod_cutoff_hour', 'exclude_resource_types',
        'resource_type_names', 'attribute_labels', 'region_pivot', 'region_labels', 'supervisor_label',
        'supervisor_label_plural', 'notes', 'windows_time_zone', 'time_zone',
    ];

    /** Keys as they appear in a pso-tools account file that differ from ours. */
    private const array ACCOUNT_FILE_ALIASES = [
        'dataset_name' => 'dataset_display_name',
        'attributes' => 'attribute_labels',
        'regions' => 'region_labels',
    ];

    public function __construct(
        public ?string $displayName = null,
        public ?string $datasetDisplayName = null,
        public float $coverageKm = 50.0,
        public ?int $eodCutoffHour = null,
        public array $excludeResourceTypes = [],
        public array $resourceTypeNames = [],
        public array $attributeLabels = [],
        public array $regionPivot = ['red_below' => 1, 'amber_below' => 3],
        public array $regionLabels = [],
        public ?string $supervisorLabel = null,
        public ?string $supervisorLabelPlural = null,
        public array $notes = [],
        public ?string $windowsTimeZone = null,
        public ?string $timeZone = null,
    ) {}

    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown dataset setting: '.implode(', ', $unknown));
        }

        $number = static function (string $key, mixed $value, float $default): float {
            if ($value === null || $value === '') {
                return $default;
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("{$key} must be a number.");
            }

            return (float) $value;
        };
        $intOrNull = static function (string $key, mixed $value): ?int {
            if ($value === null || $value === '') {
                return null;
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("{$key} must be a whole number.");
            }

            return (int) $value;
        };
        $map = static fn (mixed $value): array => is_array($value) ? array_map(static fn ($item) => is_scalar($item) ? (string) $item : $item, $value) : [];
        $list = static fn (mixed $value): array => is_array($value) ? array_values(array_filter(array_map('strval', $value), 'strlen')) : [];

        $timeZone = $values['time_zone'] ?? null;
        if ($timeZone !== null && $timeZone !== '' && ! in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("time_zone must be an IANA zone name, got {$timeZone}.");
        }

        $pivot = $values['region_pivot'] ?? ['red_below' => 1, 'amber_below' => 3];

        return new self(
            displayName: self::stringOrNull($values['display_name'] ?? null),
            datasetDisplayName: self::stringOrNull($values['dataset_display_name'] ?? null),
            coverageKm: $number('coverage_km', $values['coverage_km'] ?? null, 50.0),
            eodCutoffHour: $intOrNull('eod_cutoff_hour', $values['eod_cutoff_hour'] ?? null),
            excludeResourceTypes: $list($values['exclude_resource_types'] ?? []),
            resourceTypeNames: $map($values['resource_type_names'] ?? []),
            attributeLabels: $map($values['attribute_labels'] ?? []),
            regionPivot: ['red_below' => (int) ($pivot['red_below'] ?? 1), 'amber_below' => (int) ($pivot['amber_below'] ?? 3)],
            regionLabels: $map($values['region_labels'] ?? []),
            supervisorLabel: self::stringOrNull($values['supervisor_label'] ?? null),
            supervisorLabelPlural: self::stringOrNull($values['supervisor_label_plural'] ?? null),
            notes: $map($values['notes'] ?? []),
            windowsTimeZone: self::stringOrNull($values['windows_time_zone'] ?? null),
            timeZone: self::stringOrNull($timeZone),
        );
    }

    /** Accepts the key names used by the pso-tools account files. */
    public static function fromAccountFile(array $json): self
    {
        $values = [];
        foreach ($json as $key => $value) {
            $values[self::ACCOUNT_FILE_ALIASES[$key] ?? $key] = $value;
        }

        return self::fromArray($values);
    }

    public function toArray(): array
    {
        return [
            'display_name' => $this->displayName,
            'dataset_display_name' => $this->datasetDisplayName,
            'coverage_km' => $this->coverageKm,
            'eod_cutoff_hour' => $this->eodCutoffHour,
            'exclude_resource_types' => $this->excludeResourceTypes,
            'resource_type_names' => $this->resourceTypeNames,
            'attribute_labels' => $this->attributeLabels,
            'region_pivot' => $this->regionPivot,
            'region_labels' => $this->regionLabels,
            'supervisor_label' => $this->supervisorLabel,
            'supervisor_label_plural' => $this->supervisorLabelPlural,
            'notes' => $this->notes,
            'windows_time_zone' => $this->windowsTimeZone,
            'time_zone' => $this->timeZone,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
```

- [ ] **Step 4: The cast and the model**

`app/Casts/DatasetSettingsCast.php` implements `CastsAttributes`: `get()` returns `DatasetSettings::fromArray(json_decode($value ?? '{}', true) ?: [])`; `set()` accepts a `DatasetSettings` or an array (runs it through `fromArray` to validate) and returns `json_encode($settings->toArray())`.

In `Dataset`: `'settings' => DatasetSettingsCast::class` (replacing the Task 1 array cast), and:

```php
    public function timeZone(): string
    {
        return $this->settings->timeZone ?? (string) config('app.timezone', 'UTC');
    }
```

Form: add a `Section::make('Settings')` to `Dataset::getForm()` (collapsible, collapsed on create) with: `TextInput settings.display_name`, `TextInput settings.dataset_display_name`, `TextInput settings.coverage_km numeric`, `TextInput settings.eod_cutoff_hour numeric integer 0..23`, `Select settings.time_zone` searchable with `DateTimeZone::listIdentifiers()`, `TagsInput settings.exclude_resource_types`, `KeyValue settings.resource_type_names` (keys "PSO resource type", values "Shown as"), `KeyValue settings.attribute_labels`, `TextInput settings.region_pivot.red_below` and `.amber_below` numeric, `KeyValue settings.region_labels`, `TextInput settings.supervisor_label`, `TextInput settings.supervisor_label_plural`, `KeyValue settings.notes`, `TextInput settings.windows_time_zone`. Filament writes the nested `settings.*` state back as one array, which the cast validates on save.

Because `EditDataset` fills the form from `$record->toArray()`, make sure `settings` serialises to its array: add to `Dataset` a `toArray()` override is unnecessary; the cast's `get()` returns the object and Filament calls `->toArray()`? It does not. Instead implement `Illuminate\Contracts\Support\Arrayable` on `DatasetSettings` and `JsonSerializable`, so Eloquent's `attributesToArray()` serialises it through `toArray()`. Verify with the form test.

- [ ] **Step 5: The seed command**

`app/Console/Commands/SeedDatasetSettings.php`: signature `datasets:seed-settings {dataset : dataset id or "ENV_SLUG/NAME"} {file : path to a pso-tools account JSON} {--time-zone=}`. Resolves the dataset without global scopes (it runs from the console), reads the JSON, builds `DatasetSettings::fromAccountFile()`, applies `--time-zone` when given, saves, prints the keys set. Errors in plain words for a missing file or dataset.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Unit/DatasetSettingsTest.php tests/Feature/DatasetSettingsFormTest.php`
Expected: PASS, 6 tests. Run the whole suite.

- [ ] **Step 7: Commit**

```bash
git add app/Support/DatasetSettings.php app/Casts app/Console/Commands/SeedDatasetSettings.php app/Models/Dataset.php tests/Unit/DatasetSettingsTest.php tests/Feature/DatasetSettingsFormTest.php
git commit -m "feat: typed dataset settings with a form section and a seed command"
```

---

### Task 3: `ExportStorage`: keys, presigned PUT, checks, streams, delete

**Files:**
- Create: `app/Support/Exports/ExportStorage.php`
- Test: `tests/Unit/ExportStorageTest.php`

**Interfaces:**
- Produces: `ExportStorage::objectKey(ExportFile $file): string` (`exports/{scope_type}/{scope_id}/{id}.{content_type}`), `presignedPutUrl(ExportFile $file): string` (valid `exports.slot_minutes`, content type header fixed), `exists(ExportFile)`, `size(ExportFile): ?int`, `readToScratch(ExportFile $file, string $scratchDirectory): string` (streams the object to `{scratch}/{id}.{ext}` with `stream_copy_to_stream`, returns the path), `delete(ExportFile)`, `scratchDirectoryFor(string $id): string` (creates `config('exports.scratch_path')/{id}`), `removeScratch(string $directory)`. All ids are validated as uuids before use; anything else throws `InvalidArgumentException`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('builds object keys from uuids only', function () {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'content_type' => 'zip']);
    $file->setRelation('scope', $dataset = Dataset::factory()->make(['id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']));
    $file->scope_type = 'dataset';
    $file->scope_id = $dataset->id;

    expect(app(ExportStorage::class)->objectKey($file))
        ->toBe('exports/dataset/7c9e6679-7425-40de-944b-e07fc1f90ae7/0f8fad5b-d9cb-469f-a165-70867728950e.zip');
});

it('refuses an id that is not a uuid', function () {
    $file = ExportFile::factory()->make(['id' => '../etc/passwd']);
    $file->scope_type = 'dataset';
    $file->scope_id = 'x';

    expect(fn () => app(ExportStorage::class)->objectKey($file))->toThrow(InvalidArgumentException::class);
});

it('reports existence and size, streams to scratch, and deletes', function () {
    $file = ExportFile::factory()->create(['content_type' => 'xml']);
    $storage = app(ExportStorage::class);
    Storage::disk('r2')->put($storage->objectKey($file), '<dsScheduleData/>');

    expect($storage->exists($file))->toBeTrue()
        ->and($storage->size($file))->toBe(strlen('<dsScheduleData/>'));

    $scratch = $storage->scratchDirectoryFor($file->id);
    $path = $storage->readToScratch($file, $scratch);
    expect(file_get_contents($path))->toBe('<dsScheduleData/>')
        ->and(str_starts_with($path, $scratch))->toBeTrue();

    $storage->removeScratch($scratch);
    expect(is_dir($scratch))->toBeFalse();

    $storage->delete($file);
    expect($storage->exists($file))->toBeFalse()
        ->and($storage->size($file))->toBeNull();
});

it('issues a presigned PUT url for the object key', function () {
    $file = ExportFile::factory()->create(['content_type' => 'xml']);

    $url = app(ExportStorage::class)->presignedPutUrl($file);

    expect($url)->toBeString()->toContain(app(ExportStorage::class)->objectKey($file));
});
```

`Storage::fake()` returns a local adapter whose `temporaryUploadUrl` throws; for the last test bind a fake disk that supports it, or assert that `presignedPutUrl` delegates to `Storage::disk()->temporaryUploadUrl($key, $expiry, ['ContentType' => ...])` by mocking the disk with `Storage::shouldReceive`. Prefer the mock: `Storage::shouldReceive('disk->temporaryUploadUrl')->once()->withArgs(fn ($key, $expiry, $options) => str_contains($key, $file->id) && $options['ContentType'] === 'application/xml')->andReturn(['url' => 'https://r2.example/'.$key, 'headers' => []])`. Note Laravel's `temporaryUploadUrl` returns `['url' => ..., 'headers' => [...]]`; `presignedPutUrl()` returns that array, not a string. Adjust the test to `expect($url['url'])`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Unit/ExportStorageTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Exports;

use App\Models\ExportFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where raw exports live (R2) and how jobs get at them (streamed to a scratch
 * folder). Object keys are built from uuids only, so nothing user-supplied can
 * reach a path.
 */
class ExportStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('exports.disk', 'r2'));
    }

    public function objectKey(ExportFile $file): string
    {
        $this->assertUuid((string) $file->id);
        $this->assertUuid((string) $file->scope_id);
        $scope = preg_replace('/[^a-z]/', '', (string) $file->scope_type) ?: 'scope';
        $extension = in_array($file->content_type, ['xml', 'json', 'zip'], true) ? $file->content_type : 'bin';

        return "exports/{$scope}/{$file->scope_id}/{$file->id}.{$extension}";
    }

    /** @return array{url: string, headers: array<string, string>} */
    public function presignedPutUrl(ExportFile $file): array
    {
        return $this->disk()->temporaryUploadUrl(
            $this->objectKey($file),
            now()->addMinutes((int) config('exports.slot_minutes', 15)),
            ['ContentType' => self::mimeFor($file->content_type)],
        );
    }

    public static function mimeFor(string $contentType): string
    {
        return match ($contentType) {
            'json' => 'application/json',
            'zip' => 'application/zip',
            default => 'application/xml',
        };
    }

    public function exists(ExportFile $file): bool
    {
        return $this->disk()->exists($this->objectKey($file));
    }

    public function size(ExportFile $file): ?int
    {
        return $this->exists($file) ? (int) $this->disk()->size($this->objectKey($file)) : null;
    }

    public function readToScratch(ExportFile $file, string $scratchDirectory): string
    {
        $target = rtrim($scratchDirectory, '/').'/'.$file->id.'.'.$file->content_type;
        $source = $this->disk()->readStream($this->objectKey($file));
        if ($source === null) {
            throw new RuntimeException('The export is no longer in storage.');
        }
        $destination = fopen($target, 'wb');
        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($destination);
            fclose($source);
        }

        return $target;
    }

    public function delete(ExportFile $file): void
    {
        $this->disk()->delete($this->objectKey($file));
    }

    public function scratchDirectoryFor(string $id): string
    {
        $this->assertUuid($id);
        $directory = rtrim((string) config('exports.scratch_path'), '/').'/'.$id;
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        return $directory;
    }

    public function removeScratch(string $directory): void
    {
        $root = rtrim((string) config('exports.scratch_path'), '/');
        if (! str_starts_with($directory, $root.'/') || ! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            @unlink($directory.'/'.$entry);
        }
        @rmdir($directory);
    }

    private function assertUuid(string $value): void
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('Export ids must be uuids.');
        }
    }
}
```

- [ ] **Step 4: Run, then commit**

Run: `php artisan test tests/Unit/ExportStorageTest.php` → PASS.

```bash
git add app/Support/Exports/ExportStorage.php tests/Unit/ExportStorageTest.php
git commit -m "feat: export storage on R2 with uuid keys, presigned uploads and scratch streaming"
```

---

### Task 4: `ExportSummariser` and `SummariseExportFileJob`

**Files:**
- Create: `app/Support/Exports/ExportSummariser.php`, `app/Support/Exports/ExportSummary.php` (small DTO), `app/Support/Exports/InvalidExportException.php`
- Create: `app/Jobs/SummariseExportFileJob.php`
- Test: `tests/Unit/ExportSummariserTest.php`, `tests/Feature/SummariseExportFileJobTest.php`
- Test fixtures: built inline by helper functions (synthetic XML/JSON/zip in temp files)

**Interfaces:**
- Consumes: `ScheduleDataSummaryExtractor::extract($path, $format)`, `ScheduleDataZipExtractor::extract($zip, $dir)`, the sys compare `QuickRead` (read its constructor and use it for `sys`), `ExportStorage` (Task 3).
- Produces: `ExportSummariser::summarise(ExportFile $file, string $localPath, string $timeZone): ExportSummary` returning `['root' => ..., 'dataset_id' => ..., 'input_reference_datetime' => iso|null, 'export_date' => 'YYYY-MM-DD'|null, 'activity_count', 'resource_count']` for load/plan/eod; `['root' => 'DsSystemData', 'pso_version', 'parameter_count', 'exception_type_count']` for sys; `['message_count', 'first_message_at', 'last_message_at', 'appointment_request_count']` for audits. Throws `InvalidExportException` when the root does not match `ExportKind::expectedRoot()` or the zip has no usable member. `SummariseExportFileJob(string $exportFileId)`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ExportSummariserTest.php` (helpers write temp files):

```php
<?php

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;

function scheduleXml(string $datasetId = 'NORTH', string $datetime = '2026-10-10T07:12:00', int $activities = 2, int $resources = 1): string
{
    $body = "<Input_Reference><id>x</id><datetime>{$datetime}</datetime><dataset_id>{$datasetId}</dataset_id></Input_Reference>";
    for ($i = 0; $i < $activities; $i++) { $body .= "<Activity><id>A{$i}</id></Activity>"; }
    for ($i = 0; $i < $resources; $i++) { $body .= "<Resources><id>R{$i}</id></Resources>"; }
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'.$body.'</dsScheduleData>');

    return $path;
}

function sysXml(): string
{
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><DsSystemData><Version><version>6.14.0.0</version></Version><Parameter><id>P1</id><value>1</value></Parameter><Parameter><id>P2</id><value>2</value></Parameter><Exception_Type><id>E1</id></Exception_Type><User><id>admin</id><password>secret</password></User></DsSystemData>');

    return $path;
}

function zipOf(string $memberPath, string $memberName): string
{
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($memberPath, $memberName);
    $zip->close();

    return $path;
}

function fileOfKind(ExportKind $kind, string $contentType): ExportFile
{
    return ExportFile::factory()->make(['kind' => $kind, 'content_type' => $contentType]);
}

it('summarises a LOAD xml: dataset, input reference, counts and local export date', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'xml'), scheduleXml(datetime: '2026-10-11T02:30:00Z'), 'America/Regina');

    expect($summary->toArray())->toMatchArray([
        'root' => 'dsScheduleData',
        'dataset_id' => 'NORTH',
        'activity_count' => 2,
        'resource_count' => 1,
        'export_date' => '2026-10-10',
    ]);
});

it('summarises a plan inside a zip by its largest xml member', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Plan, 'zip'), zipOf(scheduleXml(activities: 5), 'whatever.xml'), 'UTC');

    expect($summary->toArray()['activity_count'])->toBe(5);
});

it('rejects a SYS export uploaded as a LOAD, naming both roots', function () {
    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'xml'), sysXml(), 'UTC'))
        ->toThrow(InvalidExportException::class, 'DsSystemData');
});

it('summarises a SYS export without touching users or credentials', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Sys, 'xml'), sysXml(), 'UTC');

    expect($summary->toArray())->toMatchArray(['root' => 'DsSystemData', 'pso_version' => '6.14.0.0', 'parameter_count' => 2, 'exception_type_count' => 1])
        ->and(json_encode($summary->toArray()))->not->toContain('secret')->not->toContain('admin');
});

it('summarises an audits zip by message count and time span, keeping no contact fields', function () {
    $dir = sys_get_temp_dir().'/audits-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/m1.xml", '<Audit><Plan><output_datetime>2026-10-10T08:00:00</output_datetime></Plan><Appointment_Request><id>AR1</id><customer_phone>555</customer_phone></Appointment_Request></Audit>');
    file_put_contents("$dir/m2.xml", '<Audit><Input_Reference><datetime>2026-10-10T09:00:00</datetime></Input_Reference><Appointment_Offer><id>1</id></Appointment_Offer></Audit>');
    $zipPath = tempnam(sys_get_temp_dir(), 'exp_').'.zip';
    $zip = new ZipArchive; $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile("$dir/m1.xml", 'm1.xml'); $zip->addFile("$dir/m2.xml", 'm2.xml'); $zip->close();

    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Audits, 'zip'), $zipPath, 'UTC');

    expect($summary->toArray())->toMatchArray(['message_count' => 2, 'appointment_request_count' => 1, 'first_message_at' => '2026-10-10T08:00:00+00:00', 'last_message_at' => '2026-10-10T09:00:00+00:00'])
        ->and(json_encode($summary->toArray()))->not->toContain('555');
});

it('rejects a zip with no xml or json member', function () {
    $txt = tempnam(sys_get_temp_dir(), 'exp_'); file_put_contents($txt, 'hello');

    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'zip'), zipOf($txt, 'notes.txt'), 'UTC'))
        ->toThrow(InvalidExportException::class);
});
```

`tests/Feature/SummariseExportFileJobTest.php`:

```php
<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('streams the object, writes the summary, marks ready and cleans scratch', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(scheduleXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    $file->refresh();
    expect($file->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['activity_count'])->toBe(2)
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('marks the row failed with the summariser message on a mismatched root', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(sysXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('DsSystemData');
});

it('marks the row failed when the object is missing', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['status' => ExportFileStatus::Summarising]);

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('no longer in storage');
});
```

Reuse the `scheduleXml()` and `sysXml()` helpers by moving them to `tests/Support/ExportFixtures.php` (plain functions, required from `tests/Pest.php`).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Unit/ExportSummariserTest.php tests/Feature/SummariseExportFileJobTest.php` → FAIL, classes not found.

- [ ] **Step 3: Implement the summariser**

`ExportSummary` is a small DTO with a `public array $values` and `toArray()`. `InvalidExportException extends RuntimeException`.

`ExportSummariser::summarise()`:

1. Resolve the member to read: if `content_type === 'zip'`, open with `ZipArchive`, pick the largest member whose name ends in `.xml` or `.json` (for `audits`, keep the zip itself), extract that one member to the same directory as `$localPath` with `ZipArchive::extractTo($dir, [$name])` and continue with that path; no member → `InvalidExportException('The zip holds no .xml or .json file.')`.
2. Detect the root with `XMLReader` (first ELEMENT's `localName`), or for JSON read the first object key (`dsScheduleData` or `ScheduleData` count as `dsScheduleData`). For `audits` skip this step.
3. Compare with `$file->kind->expectedRoot()`; mismatch → `InvalidExportException("This file is a <{$root}> export, not a {$kind->getLabel()} (<{$expected}>). Upload it under the right kind.")`.
4. Per kind:
   - load/plan/eod: `ScheduleDataSummaryExtractor::extract($path, $format)` gives dataset id, input reference datetime, counts. `export_date` is the input reference datetime converted to `$timeZone`, formatted `Y-m-d`, null when unknown.
   - sys: stream with `XMLReader` counting `Parameter` and `Exception_Type` elements at depth 1 and reading `Version/version` (check the real SYS layout against `app/Support/SysCompare/SysFileReader.php` and reuse its reader if it already exposes these). Never read `User` or any element holding credentials.
   - audits: iterate the zip members; for each `.xml` member stream with `XMLReader`, take the first `Plan/output_datetime` or `Input_Reference/datetime` as the message time, count `Appointment_Request` elements; record min and max times as ISO 8601 with offset and the counts. No other fields.
5. Return the `ExportSummary`.

- [ ] **Step 4: Implement the job**

`SummariseExportFileJob implements ShouldQueue`, `$timeout = 300`, `$tries = 1`, constructor `public string $exportFileId`. `handle(ExportStorage $storage, ExportSummariser $summariser)`: load the file with `ExportFile::withoutGlobalScopes()->find()`; if missing or status not `Summarising`, return. Resolve the time zone: for a Dataset scope `Dataset::find($file->scope_id)?->timeZone()`, otherwise `config('app.timezone')` (loaded without the user scopes). `$scratch = $storage->scratchDirectoryFor($file->id)`; try `$path = $storage->readToScratch(...)`, `$summary = $summariser->summarise($file, $path, $timeZone)`, `forceFill(['summary' => ..., 'status' => Ready, 'error_message' => null])->save()`; catch `InvalidExportException|RuntimeException` → status `Failed`, `error_message` = message; catch `Throwable` → `Failed`, "The export could not be read: ..." and log; finally `removeScratch($scratch)`. `failed()` also marks the row `Failed` if still `Summarising`.

- [ ] **Step 5: Run, then commit**

Run both test files → PASS. Whole suite green.

```bash
git add app/Support/Exports app/Jobs/SummariseExportFileJob.php tests/Unit/ExportSummariserTest.php tests/Feature/SummariseExportFileJobTest.php tests/Support/ExportFixtures.php tests/Pest.php
git commit -m "feat: summarise uploaded exports by kind on the queue"
```

---

### Task 5: Slot and complete endpoints

**Files:**
- Create: `app/Http/Controllers/Exports/ExportSlotController.php`, `app/Http/Controllers/Exports/ExportCompleteController.php`, `app/Http/Requests/ExportSlotRequest.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (two routes inside `authenticatedRoutes`)
- Test: `tests/Feature/ExportUploadEndpointsTest.php`

**Interfaces:**
- Produces: `POST /exports/slots` (name `filament.app.exports.slots.store`) body `{scope_type: 'dataset'|'environment', scope_id, kind, file_name, file_size}` → 201 `{id, upload: {url, headers}, expires_at}`; `POST /exports/{exportFile}/complete` (name `filament.app.exports.complete`) → 202 `{id, status: 'summarising'}` after dispatching `SummariseExportFileJob`, or 422 `{message}` and the row marked `failed` when the object is missing or its size differs.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportFileStatus;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.max_file_megabytes' => 400]);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
    Storage::shouldReceive('disk->temporaryUploadUrl')->andReturn(['url' => 'https://r2.example/put', 'headers' => ['Content-Type' => 'application/xml']])->byDefault();
});

function slotPayload(Dataset $dataset, array $overrides = []): array
{
    return array_replace(['scope_type' => 'dataset', 'scope_id' => $dataset->id, 'kind' => 'load', 'file_name' => 'load.xml', 'file_size' => 1024], $overrides);
}

it('creates an uploading row and returns a presigned url', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset))
        ->assertCreated()
        ->assertJsonPath('upload.url', 'https://r2.example/put')
        ->assertJsonStructure(['id', 'expires_at']);

    $file = ExportFile::first();
    expect($file->status)->toBe(ExportFileStatus::Uploading)
        ->and($file->content_type)->toBe('xml')
        ->and($file->uploaded_by_user_id)->toBe($this->user->id);
});

it('refuses a dataset the user does not own', function () {
    $other = Dataset::factory()->create();

    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($other))->assertStatus(404);
    expect(ExportFile::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a sys file on a dataset and a load file on an environment', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'sys', 'file_name' => 'sys.xml']))
        ->assertStatus(422)->assertJsonValidationErrors('kind');
    $this->postJson(route('filament.app.exports.slots.store'), ['scope_type' => 'environment', 'scope_id' => $this->dataset->environment_id, 'kind' => 'load', 'file_name' => 'load.xml', 'file_size' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('kind');
});

it('refuses files over the size cap and with the wrong extension', function () {
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['file_size' => 401 * 1024 * 1024]))
        ->assertStatus(422)->assertJsonValidationErrors('file_size');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['file_name' => 'load.csv']))
        ->assertStatus(422)->assertJsonValidationErrors('file_name');
    $this->postJson(route('filament.app.exports.slots.store'), slotPayload($this->dataset, ['kind' => 'audits', 'file_name' => 'audits.xml']))
        ->assertStatus(422)->assertJsonValidationErrors('file_name');
});

it('completes an upload whose object arrived with the declared size and queues the summary', function () {
    Queue::fake();
    $file = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['file_size_bytes' => 17, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), '<dsScheduleData/>');

    $this->postJson(route('filament.app.exports.complete', $file))->assertStatus(202);

    expect($file->refresh()->status)->toBe(ExportFileStatus::Summarising);
    Queue::assertPushed(SummariseExportFileJob::class, fn ($job) => $job->exportFileId === $file->id);
});

it('fails the row when the object is missing or the size differs', function () {
    Queue::fake();
    $missing = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create();
    $this->postJson(route('filament.app.exports.complete', $missing))->assertStatus(422);
    expect($missing->refresh()->status)->toBe(ExportFileStatus::Failed);

    $short = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['file_size_bytes' => 999, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($short), '<dsScheduleData/>');
    $this->postJson(route('filament.app.exports.complete', $short))->assertStatus(422);
    expect($short->refresh()->status)->toBe(ExportFileStatus::Failed)->and($short->error_message)->toContain('size');
    Queue::assertNothingPushed();
});

it('does not complete another user file', function () {
    $foreign = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create();

    $this->postJson(route('filament.app.exports.complete', $foreign))->assertStatus(404);
});
```

- [ ] **Step 2: Run to verify failure** → FAIL, routes missing.

- [ ] **Step 3: Request, controllers, routes**

`ExportSlotRequest` rules: `scope_type in:dataset,environment`, `scope_id uuid`, `kind` enum `ExportKind`, `file_name string max 255`, `file_size integer min 1 max (config max MB * 1024 * 1024)`. `withValidator`: kind allowed on scope (`ExportKind::allowedScope()` vs the morph alias) → error on `kind`; extension of `file_name` in `kind->extensions()` → error on `file_name`. Resolve the scope model with the user's scopes applied (`Dataset::ownedByCurrentUser()->findOrFail` / `Environment::findOrFail`, the latter already user-scoped) → 404 when not owned.

`ExportSlotController::__invoke(ExportSlotRequest $request, ExportStorage $storage)`: create the `ExportFile` (`status Uploading`, `content_type` = extension, `stored_path` = `objectKey` computed after the id is known: create, then `update(['stored_path' => $storage->objectKey($file)])`), return 201 with `id`, `upload` (the presign array), `expires_at`.

`ExportCompleteController::__invoke(ExportFile $exportFile, ExportStorage $storage)`: route-model binding goes through the global scope, so a foreign file 404s. If status is not `Uploading` → 409. `size = $storage->size($file)`; null → mark `Failed` ("The file never arrived in storage. Try the upload again.") and 422; size mismatch → `Failed` ("The uploaded size does not match the declared size (x vs y).") and 422; else `Summarising`, dispatch job, 202.

Routes in `AppPanelProvider::authenticatedRoutes()` next to the sys compare ones:

```php
                Route::post('exports/slots', ExportSlotController::class)->middleware('throttle:60,1')->name('exports.slots.store');
                Route::post('exports/{exportFile}/complete', ExportCompleteController::class)->middleware('throttle:60,1')->name('exports.complete');
```

- [ ] **Step 4: Run, then commit** → PASS (7 tests).

```bash
git add app/Http/Controllers/Exports app/Http/Requests/ExportSlotRequest.php app/Providers/Filament/AppPanelProvider.php tests/Feature/ExportUploadEndpointsTest.php
git commit -m "feat: upload slots with presigned R2 urls and completion checks"
```

---

### Task 6: `exports:purge` and the scratch sweeper

**Files:**
- Create: `app/Console/Commands/PurgeExports.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/PurgeExportsTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportFileStatus;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid(), 'exports.scratch_sweep_hours' => 2]);
});

it('deletes expired objects and marks rows purged, leaving live ones alone', function () {
    $storage = app(ExportStorage::class);
    $expired = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['expires_at' => now()->subMinute()]);
    $live = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['expires_at' => now()->addHour()]);
    Storage::disk('r2')->put($storage->objectKey($expired), 'x');
    Storage::disk('r2')->put($storage->objectKey($live), 'y');

    Artisan::call('exports:purge');

    expect($expired->refresh()->status)->toBe(ExportFileStatus::Purged)
        ->and($storage->exists($expired))->toBeFalse()
        ->and($live->refresh()->status)->toBe(ExportFileStatus::Ready)
        ->and($storage->exists($live))->toBeTrue();
});

it('purges an expired slot that never received an object without error', function () {
    $ghost = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create(['expires_at' => now()->subMinute()]);

    Artisan::call('exports:purge');

    expect($ghost->refresh()->status)->toBe(ExportFileStatus::Purged);
});

it('sweeps scratch folders older than the sweep window', function () {
    $root = config('exports.scratch_path');
    mkdir("$root/old", 0770, true); touch("$root/old/file.xml", time() - 3 * 3600); touch("$root/old", time() - 3 * 3600);
    mkdir("$root/new", 0770, true); touch("$root/new/file.xml");

    Artisan::call('exports:purge');

    expect(is_dir("$root/old"))->toBeFalse()->and(is_dir("$root/new"))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure** → FAIL, command not found.

- [ ] **Step 3: Implement**

`PurgeExports` signature `exports:purge`. Query `ExportFile::withoutGlobalScopes()->where('expires_at', '<', now())->where('status', '!=', Purged)` in chunks of 100; for each: `try { $storage->delete($file); } catch (Throwable $e) { log warning; continue }` then `forceFill(['status' => Purged])->saveQuietly()`. Then scan `config('exports.scratch_path')` for directories whose mtime is older than `scratch_sweep_hours` and remove them with `ExportStorage::removeScratch()`. Print counts. Schedule in `routes/console.php`: `Schedule::command('exports:purge')->everyTenMinutes();`.

- [ ] **Step 4: Run, then commit** → PASS.

```bash
git add app/Console/Commands/PurgeExports.php routes/console.php tests/Feature/PurgeExportsTest.php
git commit -m "feat: purge expired exports from R2 and sweep stale scratch folders"
```

---

### Task 7: Tool contract, registry and `RunToolJob`

**Files:**
- Create: `app/Tools/Contracts/Tool.php`, `app/Tools/Contracts/ToolResult.php`, `app/Tools/ToolRegistry.php`, `app/Tools/ToolContext.php`
- Create: `app/Jobs/RunToolJob.php`
- Create (test double): `tests/Support/FakeTool.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind the registry)
- Test: `tests/Feature/RunToolJobTest.php`

**Interfaces:**
- Produces:

```php
interface Tool
{
    public static function id(): \App\Enums\Tool;
    /** @return list<ExportKind> */
    public function requiredKinds(): array;
    /** @return list<ExportKind> kinds that may be supplied; a dataset tool may list ExportKind::Sys to take the environment's newest SYS */
    public function optionalKinds(): array;
    /** @param array<string, string> $inputPaths kind value => local scratch path */
    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult;
}
```

`ToolResult` DTO: `array $result`, `array $warnings` (plain-English strings). `ToolContext`: the `ToolRun`, its scope model (loaded without user scopes), the `DatasetSettings` when the scope is a dataset, the time zone. `ToolRegistry::for(\App\Enums\Tool $tool): Tool` resolving from a map registered in `AppServiceProvider` (empty in this plan apart from tests; each port registers one). `RunToolJob(string $toolRunId)`, `$timeout = 900`, `$tries = 1`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTool;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->dataset = Dataset::factory()->create(['settings' => ['display_name' => 'SaskTel', 'time_zone' => 'America/Regina']]);
    $this->load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'summary' => ['export_date' => '2026-10-10']]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->load), '<dsScheduleData><Activity/></dsScheduleData>');
});

function runFor(Dataset $dataset, ExportFile $load, array $options = []): ToolRun
{
    return ToolRun::factory()->for($dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null,
        'inputs' => ['files' => ['load' => $load->id], 'options' => $options],
    ]);
}

it('streams the inputs to scratch, runs the tool with the dataset settings, stores result and warnings, cleans up', function () {
    $run = runFor($this->dataset, $this->load, ['flag' => true]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($run->result['seen_kinds'])->toBe(['load'])
        ->and($run->result['bytes'])->toBe(strlen('<dsScheduleData><Activity/></dsScheduleData>'))
        ->and($run->result['display_name'])->toBe('SaskTel')
        ->and($run->result['time_zone'])->toBe('America/Regina')
        ->and($run->result['options'])->toBe(['flag' => true])
        ->and($run->warnings)->toBe(['Fake warning'])
        ->and($run->run_date->toDateString())->toBe('2026-10-10')
        ->and($run->finished_at)->not->toBeNull()
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('refuses to run against a purged or missing input in plain words', function () {
    $this->load->forceFill(['status' => ExportFileStatus::Purged])->save();
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('no longer available');
});

it('fails the run when a required kind is not supplied', function () {
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'inputs' => ['files' => [], 'options' => []]]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('LOAD');
});

it('records a tool exception as a failed run and still cleans scratch', function () {
    FakeTool::$throw = new RuntimeException('boom');
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    FakeTool::$throw = null;

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('boom')
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});
```

`tests/Support/FakeTool.php`:

```php
<?php

namespace Tests\Support;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use Throwable;

class FakeTool implements Tool
{
    public static ?Throwable $throw = null;

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
        return [ExportKind::Sys];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        if (self::$throw) {
            throw self::$throw;
        }

        return new ToolResult(
            result: [
                'seen_kinds' => array_keys($inputPaths),
                'bytes' => filesize($inputPaths['load']),
                'display_name' => $context->settings?->displayName,
                'time_zone' => $context->timeZone,
                'options' => $options,
            ],
            warnings: ['Fake warning'],
        );
    }
}
```

Add `tests/Support` to the `Tests\Support` autoload (check `composer.json` `autoload-dev`; `tests/Support/SysFileBuilder.php` already lives there, so the namespace exists).

- [ ] **Step 2: Run to verify failure** → FAIL, classes not found.

- [ ] **Step 3: Implement**

`ToolRegistry`: `register(ToolId $id, string $class)`, `for(ToolId $id): Tool` (`app($class)`; unknown → `RuntimeException("No implementation registered for {$id->getLabel()}.")`), `has(ToolId)`. Bound as a singleton in `AppServiceProvider::register()`.

`ToolContext` constructor: `ToolRun $run`, `Model $scope`, `?DatasetSettings $settings`, `string $timeZone`.

`RunToolJob::handle(ExportStorage $storage, ToolRegistry $registry)`:

1. `$run = ToolRun::withoutGlobalScopes()->find($this->toolRunId)`; return if missing or status not `Queued`.
2. Mark `Running`, `started_at`.
3. Resolve scope without user scopes: `Dataset::find` or `Environment::withoutGlobalScopes()->find` by `scope_type` alias. Settings and time zone from the dataset, else null and `config('app.timezone')`.
4. `$tool = $registry->for($run->tool)`. For each required kind, the inputs map must name a file id; missing → fail with "This run needs a {label} export." Load each named `ExportFile::withoutGlobalScopes()->find`; not found, not `Ready`, or expired → fail with "The {label} export used by this run is no longer available (exports are kept for 12 hours). Upload it again." Also check the file's kind matches the key.
5. `$scratch = $storage->scratchDirectoryFor($run->id)`; stream each file with `readToScratch` into `$inputPaths[kind]`.
6. `$result = $tool->run($context, $inputPaths, $run->inputs['options'] ?? [])`.
7. `run_date` = `$run->run_date ?? export_date of the LOAD (or first input) summary`; store `result`, `warnings`, `Succeeded`, `finished_at`.
8. Catch `Throwable`: `Failed`, `error_message` = message (plain), `finished_at`; log with the run id. Finally `removeScratch`.

- [ ] **Step 4: Run, then commit** → PASS (4 tests).

```bash
git add app/Tools app/Jobs/RunToolJob.php app/Providers/AppServiceProvider.php tests/Support/FakeTool.php tests/Feature/RunToolJobTest.php
git commit -m "feat: tool contract, registry and the queued tool run"
```

---

### Task 8: Exports tab: drop zone component and relation managers

**Files:**
- Create: `app/Livewire/ExportDropZone.php`, `resources/views/livewire/export-drop-zone.blade.php`
- Create: `app/Filament/RelationManagers/ExportFilesRelationManager.php` (abstract, scope-agnostic) with subclasses `app/Filament/Resources/DatasetResource/RelationManagers/ExportFilesRelationManager.php` and `app/Filament/Resources/EnvironmentResource/RelationManagers/ExportFilesRelationManager.php`
- Modify: `app/Filament/Resources/DatasetResource.php`, `app/Filament/Resources/EnvironmentResource.php` (`getRelations()`)
- Test: `tests/Feature/ExportsTabTest.php`

**Interfaces:**
- Consumes: the slot and complete routes (Task 5), `ExportKind::forScope()`, `ExportFile` scopes.
- Produces: `<livewire:export-drop-zone :scope="$record" />` rendering one drop target per allowed kind with the JS from the sys compare drop zone adapted to: POST slot → PUT to the presigned URL (XHR, progress from `upload.onprogress`, headers from the slot response) → POST complete → `$wire.$refresh()`. The relation manager table: kind badge, original file name, export date, input reference time, activity and resource counts (or SYS version / message count), uploaded by, status badge with the error as a tooltip and on the row, "purges in …" (`expires_at->diffForHumans()`), delete action (deletes the object too, via `ExportStorage::delete`). Header renders the drop zone component above the table via `->headerActions([])` plus a `View` in the table's `->header()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ExportFilesRelationManager as DatasetExports;
use App\Filament\Resources\EnvironmentResource\Pages\ViewEnvironment;
use App\Filament\Resources\EnvironmentResource\RelationManagers\ExportFilesRelationManager as EnvironmentExports;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists a dataset export with its summary and purge countdown', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::Load, 'original_filename' => 'morning-load.zip',
        'summary' => ['export_date' => '2026-10-10', 'activity_count' => 1234, 'resource_count' => 56],
        'expires_at' => now()->addHours(9),
    ]);

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertCanSeeTableRecords([$file])
        ->assertSee('morning-load.zip')
        ->assertSee('1,234')
        ->assertSee('9 hours');
});

it('offers only sys on an environment and the other four on a dataset', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset])
        ->assertSee('LOAD')->assertSee('End of day')->assertDontSee('SYS');
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset->environment])
        ->assertSee('SYS')->assertDontSee('LOAD');
});

it('deletes the object with the row', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create(['content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), 'x');

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('delete', $file);

    expect(ExportFile::withoutGlobalScopes()->find($file->id))->toBeNull()
        ->and(app(ExportStorage::class)->exists($file))->toBeFalse();
});

it('shows a failed upload with its reason on the row', function () {
    $file = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->failed()->create(['error_message' => 'This file is a <dsScheduleData> export, not a SYS.']);

    Livewire::test(EnvironmentExports::class, ['ownerRecord' => $this->dataset->environment, 'pageClass' => ViewEnvironment::class])
        ->assertSee('Failed')
        ->assertSee('not a SYS');
});
```

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the drop zone**

`ExportDropZone` Livewire component: `public Model $scope;` `public array $kinds` (set in `mount()` from `ExportKind::forScope($scope::class)` as `[value => label]`), `render()` the view. The view is one `x-data` block per kind (reuse the markup of `resources/views/filament/pages/sys-file-compare/dropzone.blade.php`), with:

```js
async send(file, kind) {
    const slotResponse = await fetch(@js(route('filament.app.exports.slots.store')), {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ scope_type: @js($scopeType), scope_id: @js($scope->getKey()), kind, file_name: file.name, file_size: file.size }),
    })
    if (! slotResponse.ok) { upload.error = (await slotResponse.json()).message ?? 'The upload could not start.'; return }
    const slot = await slotResponse.json()
    await new Promise((resolve) => {
        const request = new XMLHttpRequest()
        request.open('PUT', slot.upload.url)
        Object.entries(slot.upload.headers ?? {}).forEach(([name, value]) => request.setRequestHeader(name, value))
        request.upload.onprogress = (event) => { if (event.lengthComputable) upload.percent = Math.round(100 * event.loaded / event.total) }
        request.onload = resolve; request.onerror = resolve
        request.send(file)
    })
    const done = await fetch(`${@js(url('exports'))}/${slot.id}/complete`, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } })
    if (! done.ok) { upload.error = (await done.json()).message ?? 'The upload did not complete.'; return }
    this.remove(upload.id)
    $wire.dispatch('export-uploaded')
}
```

Validation messages from the slot response's `errors` object are joined into `upload.error`. `$scopeType` is the morph alias (`$scope->getMorphClass()`). The relation managers listen for `export-uploaded` (`#[On('export-uploaded')]` → `$this->resetTable()`), and the table polls every 10 seconds while any row is `uploading` or `summarising` (`->poll('10s')` conditionally).

- [ ] **Step 4: Implement the relation managers**

Abstract `App\Filament\RelationManagers\ExportFilesRelationManager extends RelationManager` with `$relationship = 'exportFiles'`, `getTitle()` "Exports", `isReadOnly() false`, the table columns listed in Interfaces (`summary.*` columns with `->numeric()` formatting for counts; a `status` badge; `expires_at` as "purges {diffForHumans}"), `DeleteAction` with `->after(fn (ExportFile $record) => app(ExportStorage::class)->delete($record))` (delete the object before the row goes: use `->using()` to delete object then row), `->header(view('livewire.export-drop-zone-header', ['scope' => $this->getOwnerRecord()]))` which renders `@livewire('export-drop-zone', ['scope' => $scope])`. The two subclasses only set their namespace; register each in its resource's `getRelations()` (for Dataset, after the Customers tab; for Environment, after Datasets).

- [ ] **Step 5: Run, then commit** → PASS (4 tests); suite green. Open a dataset in the browser and drop a small XML to see a real upload (needs R2 credentials and the bucket CORS rule in the README; without them the slot returns 500: say so in the README).

```bash
git add app/Livewire/ExportDropZone.php resources/views/livewire app/Filament/RelationManagers app/Filament/Resources tests/Feature/ExportsTabTest.php
git commit -m "feat: Exports tab with direct-to-R2 drop zone on datasets and environments"
```

---

### Task 9: Runs tab, Run action and the run page

**Files:**
- Create: `app/Filament/RelationManagers/ToolRunsRelationManager.php` (abstract) and the two subclasses under the Dataset and Environment resources
- Create: `app/Filament/Pages/ToolRunPage.php`, `resources/views/filament/pages/tool-run.blade.php`, `resources/views/filament/tool-runs/_generic.blade.php`
- Create: `app/Support/Exports/RunInputPicker.php`
- Test: `tests/Feature/RunsTabTest.php`

**Interfaces:**
- Consumes: `ToolRegistry`, `Tool::forScope()`, `ExportFile` scopes, `RunToolJob`.
- Produces: `RunInputPicker::optionsFor(Model $scope, Tool $tool): array` → per kind `[export_file_id => "10 Oct 07:12  morning-load.zip  (1,234 activities)"]`, newest first, SYS taken from the dataset's environment for dataset tools; `RunInputPicker::defaultsFor(...)` → the newest file per kind sharing the newest LOAD's `export_date` (or the newest of each kind when there is no LOAD). Run action per tool on the Runs tab: form with one select per required kind (required) and optional kind, options from the picker, defaults preselected; on submit creates a `ToolRun` (`inputs => ['files' => [...], 'options' => [...]]`) and dispatches `RunToolJob`. Run page route `/datasets/{dataset}/runs/{run}` and `/environments/{environment}/runs/{run}` (Filament pages with `$slug`), showing tool, status, run date, inputs (names, even if purged), warnings on the page, the error if failed, and the tool's view `filament.tool-runs.{tool}` when it exists, else `_generic` (a readable dump of the result's top-level keys). Only the owning user can open it (the `ToolRun` global scope makes `findOrFail` 404 otherwise).

- [ ] **Step 1: Write the failing tests**

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
use App\Support\Exports\RunInputPicker;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('preselects the newest set of one day and offers the environment sys for a dataset tool', function () {
    $oldLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-09'], 'created_at' => now()->subDay()]);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-10']]);
    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'status' => \App\Enums\ExportFileStatus::Purged]);

    $picker = app(RunInputPicker::class);
    expect(array_keys($picker->optionsFor($this->dataset, ToolId::LoadAudit)))->toBe(['load', 'sys'])
        ->and(array_keys($picker->optionsFor($this->dataset, ToolId::LoadAudit)['load']))->toBe([$load->id, $oldLoad->id])
        ->and($picker->defaultsFor($this->dataset, ToolId::LoadAudit))->toBe(['load' => $load->id, 'sys' => $sys->id]);
});

it('starts a run from the Runs tab with the chosen files', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id]])
        ->assertHasNoTableActionErrors();

    $run = ToolRun::first();
    expect($run->tool)->toBe(ToolId::LoadAudit)
        ->and($run->status)->toBe(ToolRunStatus::Queued)
        ->and($run->inputs['files']['load'])->toBe($load->id);
    Queue::assertPushed(RunToolJob::class, fn ($job) => $job->toolRunId === $run->id);
});

it('shows a run page with inputs, warnings and the generic result view', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'morning-load.zip']);
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'inputs' => ['files' => ['load' => $load->id], 'options' => []],
        'result' => ['checks' => 12], 'warnings' => ['Two activities have no location'],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $run->id])
        ->assertOk()
        ->assertSee('morning-load.zip')
        ->assertSee('Two activities have no location')
        ->assertSee('checks');
});

it('does not open another user run', function () {
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();

    expect(fn () => Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $run->scope_id, 'run' => $run->id]))
        ->toThrow(ModelNotFoundException::class);
});
```

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**

`RunInputPicker`: for each kind in `required + optional` of the registered tool: query `ExportFile` (user-scoped) `ready()`, not expired, of that kind, on the scope (or on `$scope->environment` for `sys` when the scope is a Dataset), newest first; label as in Interfaces (counts from the summary when present). `defaultsFor`: take the newest LOAD's `export_date` (or newest file's) as the day; for each kind pick the newest file with that `export_date`, else the newest overall for `sys` only.

`ToolRunsRelationManager` (abstract): `$relationship = 'toolRuns'`, title "Runs", columns tool label, run date, status badge, started by, finished at, and an "Open" action linking to the run page; header actions: one `Action::make("run_{$tool->value}")` per `Tool::forScope($ownerClass)` that `has()` an implementation in the registry (label "Run {label}"), with a form of `Select::make("files.{kind}")` per kind (required for required kinds), options and defaults from the picker, and `->action()` creating the `ToolRun` and dispatching the job. Add `->poll('10s')` while any run is `queued` or `running`.

`ToolRunPage` (a Filament `Page` registered with `$slug = '{scope}/{scopeId}/runs/{run}'`, hidden from navigation): `mount(string $scope, string $scopeId, string $run)` resolves the scope model (`datasets` → `Dataset::ownedByCurrentUser()->where('id' or slug)`, `environments` → `Environment` by slug) and `ToolRun::findOrFail($run)` with `scope_id` matching; view shows the header and includes `view()->exists("filament.tool-runs.{$run->tool->value}")` ? that : `_generic`. The `_generic` view lists each top-level key of `result` with a count or value. Warnings render as a list in an amber callout on the page.

- [ ] **Step 4: Run, then commit** → PASS (4 tests); suite green.

```bash
git add app/Filament app/Support/Exports/RunInputPicker.php resources/views/filament tests/Feature/RunsTabTest.php
git commit -m "feat: Runs tab with per-tool run actions and a run page"
```

---

### Task 10: Additional Tools entry pages, README, seed, final sweep

**Files:**
- Create: `app/Filament/Pages/Tools/ToolEntryPage.php` (abstract) and one subclass per dataset tool (`LoadAuditEntry`, `ScheduleReviewEntry`, `RegionAvailabilityEntry`, `AppointmentTimelineEntry`, `PlanTravelsEntry`), hidden from navigation until their tool is registered (`shouldRegisterNavigation()` checks `ToolRegistry::has()`)
- Modify: `README.md`
- Test: `tests/Feature/ToolEntryPageTest.php`

- [ ] **Step 1: Write the failing test**

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

it('lists the user datasets and links to each Runs tab', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $user = User::factory()->create();
    $mine = Dataset::factory()->for(Environment::factory()->for($user))->create(['name' => 'NORTH']);
    Dataset::factory()->create(['name' => 'THEIRS']);
    $this->actingAs($user);

    Livewire::test(LoadAuditEntry::class)
        ->assertOk()
        ->assertSee('NORTH')
        ->assertDontSee('THEIRS');
});

it('stays out of navigation until its tool is registered', function () {
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeFalse();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**

`ToolEntryPage`: `$navigationGroup = 'Additional Tools'`, abstract `tool(): ToolId`, a table (or simple list) of `Dataset::ownedByCurrentUser()->with('environment')` with a link to `DatasetResource::getUrl('view', ['record' => $dataset]).'?activeRelationManager=toolRuns'` (check the query key Filament uses for the active relation manager on the installed version), `shouldRegisterNavigation()` as described, title "Run {label}".

- [ ] **Step 4: README**

Add after the sys compare section:

```markdown
## Exports and tool runs

LOAD, plan, end-of-day and audit exports are uploaded on a dataset's **Exports** tab; SYS exports on an
environment's. Files go straight from the browser to the private R2 bucket with a short-lived signed URL
(no file passes through PHP), are summarised on the queue, and are **deleted 12 hours after upload**
(`EXPORTS_TTL_HOURS`). The **Runs** tab starts a tool against the newest set for a day; results are kept in
the database, encrypted with the app key, and rendered on the run page. A queue worker must be running.

R2 bucket setup: add a CORS rule allowing the app origin with methods `PUT` and `GET`, headers
`Content-Type`, and `MaxAgeSeconds` 3600, or uploads fail in the browser with a CORS error.
The scheduler must run (`php artisan schedule:run` every minute) for `exports:purge`.

Per-dataset settings (display names, coverage, exclusions, labels, time zone) live on the dataset's edit
form. Seed one from a pso-tools account file with
`php artisan datasets:seed-settings <dataset id> accounts/SATE.json --time-zone=America/Regina`.
```

- [ ] **Step 5: Full suite, seed on dev, commit**

Run: `php artisan test` → green apart from the known `ExampleTest`. Then on the dev database: `php artisan migrate` and `php artisan datasets:seed-settings <SATE NORTH dataset id> "/Users/idawoodjee/Documents/Projects/PSO Tools/accounts/SATE.json" --time-zone=America/Regina` (and ROGE with `America/Toronto`), and check the dataset edit form shows them.

```bash
git add app/Filament/Pages/Tools README.md tests/Feature/ToolEntryPageTest.php
git commit -m "feat: tool entry pages under Additional Tools; exports and runs documented"
```

Then open a pull request from `feat/export-sets` with the spec summary as the body, or merge locally as the user prefers.
