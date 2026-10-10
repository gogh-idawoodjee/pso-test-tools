# Dataset Reference Values Implementation Plan (pso-test-tools)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the five user-owned "Base Data" tables with one dataset-scoped `dataset_reference_values` table (six entity types, SLA type added), synced from PSO through the new pso-services endpoint, with Customer and Task re-scoped to the dataset.

**Architecture:** One generic table keyed by dataset, entity type and PSO id, with a JSON attributes column for task type's three fallback fields. A queued job calls `GET /api/v2/dataset/reference-values` on pso-services and upserts under four rules (create, overwrite, keep locally modified, leave missing). Six thin Filament resources share one abstract resource; the Dataset view page gets the sync action and relation managers. Three consumers (Task boot hook, appointment booking, activity generator) move to the new table.

**Tech Stack:** Laravel 13, Livewire 4, Filament v5, PHP 8.4, Pest 4, MySQL in production, SQLite in memory for tests.

**Spec:** `docs/superpowers/specs/2026-10-10-dataset-reference-values-design.md`. The pso-services side is a separate plan: `~/Herd/pso-services/docs/superpowers/plans/2026-10-10-reference-values-endpoint.md`. Execute that one first, or run Task 4 here against its faked response shape.

## Global Constraints

- Filament namespaces (CLAUDE.md): form fields `Filament\Forms\Components\`, layout `Filament\Schemas\Components\`, `Get`/`Set` from `Filament\Schemas\Components\Utilities\`, table columns `Filament\Tables\Columns\`, actions `Filament\Actions\` only.
- No abbreviated variable names; `static fn` when `$this` is unused; explicit return types; constructor promotion; never `final`/`readonly` on classes extending Filament.
- `Dataset.name` **is** the PSO dataset id. `pso_id` max 32 characters (PSO String(32)).
- Durations are **minutes**. `attributes.base_duration` is minutes; `base_value` a decimal; `priority` an integer.
- Sync never deletes a row. A user edit on a `pso` row sets `is_locally_modified`; sync then only bumps `last_seen_at`.
- Old data in regions, skills, task_types, slot_usage_rules, appointment_templates, customers and tasks is discarded, never migrated.
- Environment passwords are stored with `Crypt::encryptString`; decrypt with `Crypt::decryptString` only inside the job, never log them.
- Commit messages: imperative, `feat:`/`fix:`/`test:`/`chore:`/`docs:` prefix, ending with the attribution lines from the session reminder.

## Review Focus

1. A dataset with two rows of different types but the same `pso_id` (a region "NORTH" and a skill "NORTH") must both save: the unique index is per type. Test in Task 3.
2. A sync whose response omits a whole table (gateway returned an empty `Skill` list) must not touch existing skill rows and must count them as missing. Test in Task 4.
3. A `manual` row whose `pso_id` later appears in PSO must become `origin = pso` without losing a local label edit if it was edited. Test in Task 4 (manual to pso, both edited and unedited).
4. Two users with environments on the same PSO dataset name must never see each other's rows: scoping goes through dataset to environment to user. Test in Task 5.
5. The appointment booking page must send `pso_id` values, never the row uuid, for template, slot usage rule, activity type and SLA type. Test in Task 8.

---

### Task 1: Tests run on an in-memory SQLite database

The suite currently runs against whatever `.env` points at (the MySQL dev database) with no `RefreshDatabase`, so any test that creates rows pollutes dev data. The new tests all need a fresh database.

**Files:**
- Modify: `phpunit.xml` (uncomment the two DB lines)
- Modify: `tests/Pest.php` (apply `RefreshDatabase` to Feature tests)

- [ ] **Step 1: Switch the test database**

In `phpunit.xml`, replace

```xml
        <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
        <!-- <env name="DB_DATABASE" value=":memory:"/> -->
```

with

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
```

- [ ] **Step 2: Refresh the database for Feature tests**

In `tests/Pest.php`, change the `pest()->extend(...)` call to:

```php
pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
```

- [ ] **Step 3: Run the whole suite**

Run: `php artisan test`
Expected: PASS. If a migration uses MySQL-only syntax (check `2025_05_04_085639_create_health_tables.php`, the Telescope and Pulse migrations), guard that statement with `if (DB::getDriverName() === 'mysql')` and re-run. If a test asserted against rows that only exist in the dev database, it was already wrong; fix it to create its own rows with factories. Record anything you had to change in the commit message.

- [ ] **Step 4: Commit**

```bash
git add phpunit.xml tests/Pest.php
git commit -m "test: run the suite on an in-memory sqlite database with RefreshDatabase"
```

---

### Task 2: Enums and the object registry copy

**Files:**
- Create: `app/Enums/ReferenceEntityType.php`
- Create: `app/Enums/ReferenceValueOrigin.php`
- Modify: `app/Classes/PSOObjectRegistry.php` (add the six entries, same as pso-services Task 1)
- Test: `tests/Unit/ReferenceEntityTypeTest.php`

**Interfaces:**
- Produces: `ReferenceEntityType` (string backed: `region`, `skill`, `task_type`, `slot_usage_rule`, `appointment_template`, `sla_type`) with `psoEntity(): string` (wire table name), `registryKey(): string`, `getLabel(): string`, `pluralLabel(): string`, `slug(): string` (URL slug for the resource), `attributeKeys(): array` (task_type: `['priority', 'base_value', 'base_duration']`, others `[]`), `navigationIcon(): Heroicon`. `ReferenceValueOrigin` (`pso`, `manual`) with `getLabel()` and `getColor()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Classes\PSOObjectRegistry;
use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;

it('maps every entity type onto its PSO wire table', function () {
    expect(collect(ReferenceEntityType::cases())->mapWithKeys(fn (ReferenceEntityType $type) => [$type->value => $type->psoEntity()])->all())
        ->toBe([
            'region' => 'Region',
            'skill' => 'Skill',
            'task_type' => 'Activity_Type',
            'slot_usage_rule' => 'Slot_Usage_Rule',
            'appointment_template' => 'Appointment_Template',
            'sla_type' => 'SLA_Type',
        ]);
});

it('only task type carries attributes', function () {
    expect(ReferenceEntityType::TaskType->attributeKeys())->toBe(['priority', 'base_value', 'base_duration']);

    foreach (ReferenceEntityType::cases() as $type) {
        if ($type !== ReferenceEntityType::TaskType) {
            expect($type->attributeKeys())->toBe([]);
        }
    }
});

it('resolves a wire table name back to the entity type', function () {
    expect(ReferenceEntityType::fromPsoEntity('SLA_Type'))->toBe(ReferenceEntityType::SlaType)
        ->and(ReferenceEntityType::fromPsoEntity('Activity'))->toBeNull();
});

it('has a registry entry for every entity type', function () {
    foreach (ReferenceEntityType::cases() as $type) {
        expect(PSOObjectRegistry::get($type->registryKey())['entity'])->toBe($type->psoEntity());
    }
});

it('labels and colours origins', function () {
    expect(ReferenceValueOrigin::Pso->getLabel())->toBe('PSO')
        ->and(ReferenceValueOrigin::Manual->getLabel())->toBe('Manual')
        ->and(ReferenceValueOrigin::Pso->getColor())->toBe('info')
        ->and(ReferenceValueOrigin::Manual->getColor())->toBe('gray');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ReferenceEntityTypeTest.php`
Expected: FAIL, enum class not found.

- [ ] **Step 3: Write the enums**

`app/Enums/ReferenceEntityType.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ReferenceEntityType: string implements HasLabel
{
    case Region = 'region';
    case Skill = 'skill';
    case TaskType = 'task_type';
    case SlotUsageRule = 'slot_usage_rule';
    case AppointmentTemplate = 'appointment_template';
    case SlaType = 'sla_type';

    /** The dsScheduleData table this type is read from. */
    public function psoEntity(): string
    {
        return match ($this) {
            self::Region => 'Region',
            self::Skill => 'Skill',
            self::TaskType => 'Activity_Type',
            self::SlotUsageRule => 'Slot_Usage_Rule',
            self::AppointmentTemplate => 'Appointment_Template',
            self::SlaType => 'SLA_Type',
        };
    }

    public static function fromPsoEntity(string $entity): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->psoEntity() === $entity) {
                return $type;
            }
        }

        return null;
    }

    /** Key of this type's entry in App\Classes\PSOObjectRegistry. */
    public function registryKey(): string
    {
        return match ($this) {
            self::TaskType => 'activity_type',
            default => $this->value,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Region => 'Region',
            self::Skill => 'Skill',
            self::TaskType => 'Task Type',
            self::SlotUsageRule => 'Slot Usage Rule',
            self::AppointmentTemplate => 'Appointment Template',
            self::SlaType => 'SLA Type',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::SlaType => 'SLA Types',
            default => $this->getLabel().'s',
        };
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->value).'s';
    }

    /** JSON attribute keys stored for this type; only task type has any. */
    public function attributeKeys(): array
    {
        return match ($this) {
            self::TaskType => ['priority', 'base_value', 'base_duration'],
            default => [],
        };
    }

    public function navigationIcon(): Heroicon
    {
        return match ($this) {
            self::Region => Heroicon::OutlinedMap,
            self::Skill => Heroicon::OutlinedWrenchScrewdriver,
            self::TaskType => Heroicon::OutlinedTag,
            self::SlotUsageRule => Heroicon::OutlinedClock,
            self::AppointmentTemplate => Heroicon::OutlinedCalendarDays,
            self::SlaType => Heroicon::OutlinedShieldCheck,
        };
    }
}
```

`app/Enums/ReferenceValueOrigin.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReferenceValueOrigin: string implements HasColor, HasLabel
{
    case Pso = 'pso';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pso => 'PSO',
            self::Manual => 'Manual',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pso => 'info',
            self::Manual => 'gray',
        };
    }
}
```

- [ ] **Step 4: Add the six registry entries**

In `app/Classes/PSOObjectRegistry.php`, append to the array in `all()` the same six entries as the pso-services plan Task 1 (`region`, `skill`, `activity_type`, `slot_usage_rule`, `appointment_template`, `sla_type`, each with `label`, `entity`, `reference_value => true` and `attributes`). Copy them verbatim from `~/Herd/pso-services/app/Classes/V2/PSOObjectRegistry.php` once that task has landed, or from that plan's Step 3 if not.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/ReferenceEntityTypeTest.php`
Expected: PASS, 5 tests. Also run `php artisan test --filter=GenericDelete` and `--filter=CustomException` if such tests exist, since they read the registry.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ReferenceEntityType.php app/Enums/ReferenceValueOrigin.php app/Classes/PSOObjectRegistry.php tests/Unit/ReferenceEntityTypeTest.php
git commit -m "feat: reference entity type and origin enums, registry entries for the six tables"
```

---

### Task 3: Schema flip: new table, Dataset columns, Customer and Task re-scoped, old tables gone

This is the one task that cannot be split without leaving the app broken in between: the old models are referenced by Customer and Task.

**Files:**
- Create: `database/migrations/2026_10_10_000001_create_dataset_reference_values_table.php`
- Create: `app/Models/DatasetReferenceValue.php`
- Create: `app/Models/Scopes/DatasetOwnedModel.php`
- Create: `database/factories/DatasetReferenceValueFactory.php`
- Modify: `app/Models/Dataset.php`, `app/Models/Customer.php`, `app/Models/Task.php`
- Modify: `database/factories/CustomerFactory.php`, `database/factories/TaskFactory.php`
- Delete: `app/Models/Region.php`, `Skill.php`, `TaskType.php`, `SlotUsageRule.php`, `AppointmentTemplate.php`; `database/factories/RegionFactory.php`, `SkillFactory.php`, `TaskTypeFactory.php`; migrations `2025_04_29_02_create_regions_table.php`, `2025_04_29_03_create_customers_table.php`, `2025_04_29_05_create_task_types_table.php`, `2025_04_29_06_create_tasks_table.php`, `2025_04_29_07_create_skills_table.php`, `2025_05_01_114018_create_appointment_templates_table.php`, `2025_05_01_114511_create_slot_usage_rules_table.php`
- Delete (they stop compiling, Task 5 recreates them): `app/Filament/Resources/RegionResource.php`, `SkillResource.php`, `TaskTypeResource.php`, `SlotUsageRuleResource.php`, `AppointmentTemplateResource.php` and their `Pages/` folders
- Test: `tests/Feature/DatasetReferenceValueSchemaTest.php`

**Interfaces:**
- Produces: model `DatasetReferenceValue` (uuid id; `dataset_id`, `entity_type` cast `ReferenceEntityType`, `pso_id`, `label`, `attributes` array, `origin` cast `ReferenceValueOrigin`, `last_seen_at` datetime, `is_locally_modified` bool) with `dataset()`, `isMissingFromPso(): bool`, `scopeOfType(Builder, ReferenceEntityType)`. `Dataset::referenceValues()`, `Dataset::referenceValuesOf(ReferenceEntityType): HasMany`, `Dataset::customers()`, `Dataset::tasks()`, `Dataset::scopeOwnedByCurrentUser()`, columns `last_synced_at` (datetime), `last_sync_summary` (array). `Customer` has `dataset_id`, `dataset()`, `region()` to `DatasetReferenceValue`. `Task` has `dataset_id` (filled from its customer on create), `dataset()`, `taskType()` to `DatasetReferenceValue`. Scope `DatasetOwnedModel` restricts to datasets whose environment belongs to the logged-in user.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('drops the old base data tables and creates the generic one', function () {
    foreach (['regions', 'skills', 'task_types', 'slot_usage_rules', 'appointment_templates'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    expect(Schema::hasTable('dataset_reference_values'))->toBeTrue()
        ->and(Schema::hasColumns('datasets', ['last_synced_at', 'last_sync_summary']))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'dataset_id'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'dataset_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'user_id'))->toBeFalse();
});

it('allows the same pso id in two types of one dataset but not twice in one type', function () {
    $dataset = Dataset::factory()->create();

    DatasetReferenceValue::factory()->for($dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => 'NORTH']);
    DatasetReferenceValue::factory()->for($dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'NORTH']);

    expect(fn () => DatasetReferenceValue::factory()->for($dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => 'NORTH']))
        ->toThrow(QueryException::class);
});

it('scopes reference values, customers and tasks to the logged-in user through the dataset', function () {
    $me = User::factory()->create();
    $someoneElse = User::factory()->create();
    $mine = Dataset::factory()->for(Environment::factory()->for($me))->create(['name' => 'NORTH']);
    $theirs = Dataset::factory()->for(Environment::factory()->for($someoneElse))->create(['name' => 'NORTH']);

    DatasetReferenceValue::factory()->for($mine)->create(['pso_id' => 'MINE']);
    DatasetReferenceValue::factory()->for($theirs)->create(['pso_id' => 'THEIRS']);
    $myCustomer = Customer::factory()->for($mine)->create();
    Customer::factory()->for($theirs)->create();
    Task::factory()->for($myCustomer)->create();
    Task::factory()->for(Customer::factory()->for($theirs))->create();

    $this->actingAs($me);

    expect(DatasetReferenceValue::pluck('pso_id')->all())->toBe(['MINE'])
        ->and(Customer::count())->toBe(1)
        ->and(Task::count())->toBe(1)
        ->and(Dataset::ownedByCurrentUser()->pluck('id')->all())->toBe([$mine->id]);
});

it('fills a task duration, base value and friendly id prefix from the task type attributes', function () {
    $dataset = Dataset::factory()->create();
    $this->actingAs($dataset->environment->user);
    $taskType = DatasetReferenceValue::factory()->for($dataset)->create([
        'entity_type' => ReferenceEntityType::TaskType,
        'pso_id' => 'INSTALL',
        'label' => 'Install',
        'attributes' => ['priority' => 5, 'base_value' => 1500, 'base_duration' => 90],
    ]);
    $customer = Customer::factory()->for($dataset)->create();

    $task = Task::create(['customer_id' => $customer->id, 'task_type_id' => $taskType->id, 'status' => 'IGNORE']);

    expect($task->duration)->toBe(90)
        ->and((float) $task->base_value)->toBe(1500.0)
        ->and($task->dataset_id)->toBe($dataset->id)
        ->and($task->friendly_id)->toStartWith('T-I_');
});

it('reports a pso row as missing when it was not seen by the last sync', function () {
    $dataset = Dataset::factory()->create(['last_synced_at' => now()]);
    $seen = DatasetReferenceValue::factory()->for($dataset)->create(['origin' => ReferenceValueOrigin::Pso, 'last_seen_at' => now()->addSecond()]);
    $missing = DatasetReferenceValue::factory()->for($dataset)->create(['origin' => ReferenceValueOrigin::Pso, 'last_seen_at' => now()->subDay()]);
    $manual = DatasetReferenceValue::factory()->for($dataset)->create(['origin' => ReferenceValueOrigin::Manual, 'last_seen_at' => null]);

    expect($seen->isMissingFromPso())->toBeFalse()
        ->and($missing->isMissingFromPso())->toBeTrue()
        ->and($manual->isMissingFromPso())->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/DatasetReferenceValueSchemaTest.php`
Expected: FAIL (table assertions and missing classes).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_10_000001_create_dataset_reference_values_table.php`:

```php
<?php

use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the user-owned base data tables with one dataset-scoped table
     * and re-parents customers and tasks onto datasets. Existing rows in the
     * dropped tables are test data with no value and are not carried over.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['tasks', 'customers', 'task_types', 'skills', 'regions', 'slot_usage_rules', 'appointment_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        Schema::table('datasets', static function (Blueprint $table) {
            $table->dateTime('last_synced_at')->nullable();
            $table->json('last_sync_summary')->nullable();
        });

        Schema::create('dataset_reference_values', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->string('pso_id', 64);
            $table->string('label');
            $table->json('attributes')->nullable();
            $table->string('origin', 16);
            $table->dateTime('last_seen_at')->nullable();
            $table->boolean('is_locally_modified')->default(false);
            $table->timestamps();

            $table->unique(['dataset_id', 'entity_type', 'pso_id']);
            $table->index(['dataset_id', 'entity_type']);
        });

        Schema::create('customers', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('address');
            $table->string('city');
            $table->string('country');
            $table->string('postcode');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('long', 10, 7)->nullable();
            $table->foreignUuid('region_id')->nullable()->constrained('dataset_reference_values')->nullOnDelete();
            $table->string('status');
            $table->timestamps();

            $table->index('region_id');
        });

        Schema::create('tasks', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('task_type_id')->nullable()->constrained('dataset_reference_values')->nullOnDelete();
            $table->string('friendly_id');
            $table->decimal('base_value', 12, 2)->nullable();
            $table->dateTime('appt_window_start')->nullable();
            $table->dateTime('appt_window_finish')->nullable();
            $table->integer('duration')->nullable();
            $table->string('status')->default(TaskStatus::IGNORE->value);
            $table->timestamps();

            $table->unique(['dataset_id', 'friendly_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('dataset_reference_values');
        Schema::table('datasets', static function (Blueprint $table) {
            $table->dropColumn(['last_synced_at', 'last_sync_summary']);
        });
    }
};
```

Deviation from the spec, deliberate: `down()` does not recreate the old tables, because their migration files are deleted in this task and nothing would fill them.

- [ ] **Step 4: Delete the old files**

```bash
git rm app/Models/Region.php app/Models/Skill.php app/Models/TaskType.php app/Models/SlotUsageRule.php app/Models/AppointmentTemplate.php
git rm database/factories/RegionFactory.php database/factories/SkillFactory.php database/factories/TaskTypeFactory.php
git rm database/migrations/2025_04_29_02_create_regions_table.php database/migrations/2025_04_29_03_create_customers_table.php database/migrations/2025_04_29_05_create_task_types_table.php database/migrations/2025_04_29_06_create_tasks_table.php database/migrations/2025_04_29_07_create_skills_table.php database/migrations/2025_05_01_114018_create_appointment_templates_table.php database/migrations/2025_05_01_114511_create_slot_usage_rules_table.php
git rm -r app/Filament/Resources/RegionResource.php app/Filament/Resources/RegionResource app/Filament/Resources/SkillResource.php app/Filament/Resources/SkillResource app/Filament/Resources/TaskTypeResource.php app/Filament/Resources/TaskTypeResource app/Filament/Resources/SlotUsageRuleResource.php app/Filament/Resources/SlotUsageRuleResource app/Filament/Resources/AppointmentTemplateResource.php app/Filament/Resources/AppointmentTemplateResource
```

Then `grep -rn "Models\\\\Region\|Models\\\\Skill\|Models\\\\TaskType\|Models\\\\SlotUsageRule\|Models\\\\AppointmentTemplate\|TaskType::\|Region::" app database tests` and note every hit: `Customer.php`, `Task.php`, `CustomerFactory.php`, `TaskFactory.php` are fixed in this task; `AppointmentBooking.php` is fixed in Task 8 (leave a `// Task 8` comment on each `use` you cannot yet remove, and if the class fails to autoload, stub the two selects with `->options([])` until Task 8).

- [ ] **Step 5: Write the scope**

`app/Models/Scopes/DatasetOwnedModel.php`:

```php
<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a dataset-owned model to datasets whose environment belongs to
 * the logged-in user. Replaces UserOwnedModel for everything scoped by dataset.
 */
class DatasetOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereHas('dataset.environment', static fn (Builder $environment) => $environment->where('user_id', auth()->id()));
    }
}
```

- [ ] **Step 6: Write the model**

`app/Models/DatasetReferenceValue.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Scopes\DatasetOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One identifier PSO will accept in a request (a region, skill, task type,
 * slot usage rule, appointment template or SLA type), scoped to a dataset.
 * Rows come from a PSO sync (origin pso) or are typed in (origin manual);
 * either kind is editable. A user edit on a pso row sets is_locally_modified
 * so the next sync leaves its fields alone.
 *
 * @mixin Builder
 */
class DatasetReferenceValue extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'dataset_id', 'entity_type', 'pso_id', 'label', 'attributes', 'origin', 'last_seen_at', 'is_locally_modified',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => ReferenceEntityType::class,
            'origin' => ReferenceValueOrigin::class,
            'attributes' => 'array',
            'last_seen_at' => 'datetime',
            'is_locally_modified' => 'boolean',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new DatasetOwnedModel);

        // A user edit on a PSO-sourced row protects it from the next sync.
        // The sync itself writes with saveQuietly(), so this never fires for it.
        static::updating(static function (self $row): void {
            if ($row->origin === ReferenceValueOrigin::Pso && $row->isDirty(['pso_id', 'label', 'attributes'])) {
                $row->is_locally_modified = true;
            }
        });
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function scopeOfType(Builder $query, ReferenceEntityType $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    /** True for a PSO row the last sync of its dataset did not see. */
    public function isMissingFromPso(): bool
    {
        $lastSynced = $this->dataset?->last_synced_at;

        return $this->origin === ReferenceValueOrigin::Pso
            && $lastSynced !== null
            && ($this->last_seen_at === null || $this->last_seen_at->lt($lastSynced));
    }

    /** Read one field of the attributes JSON (priority, base_value, base_duration). */
    public function attribute(string $key): mixed
    {
        return data_get($this->attributeValues(), $key);
    }

    private function attributeValues(): array
    {
        return (array) ($this->getAttribute('attributes') ?? []);
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['pso_id', 'label', 'attributes', 'origin', 'is_locally_modified']);
    }
}
```

Note: Eloquent has an internal `$attributes` property, so the column named `attributes` must always be read with `getAttribute('attributes')` or the cast accessor `$row->attributes` (which Eloquent resolves to the cast column, not the internal array). Keep the `attribute(string $key)` helper as the one way consumers read a field.

- [ ] **Step 7: Factory**

`database/factories/DatasetReferenceValueFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatasetReferenceValueFactory extends Factory
{
    protected $model = DatasetReferenceValue::class;

    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            'entity_type' => ReferenceEntityType::Region,
            'pso_id' => strtoupper(fake()->unique()->lexify('??????')),
            'label' => fake()->words(2, true),
            'attributes' => null,
            'origin' => ReferenceValueOrigin::Pso,
            'last_seen_at' => now(),
            'is_locally_modified' => false,
        ];
    }

    public function manual(): static
    {
        return $this->state(['origin' => ReferenceValueOrigin::Manual, 'last_seen_at' => null]);
    }

    public function taskType(int $priority = 5, float $baseValue = 1000, int $baseDuration = 60): static
    {
        return $this->state([
            'entity_type' => ReferenceEntityType::TaskType,
            'attributes' => ['priority' => $priority, 'base_value' => $baseValue, 'base_duration' => $baseDuration],
        ]);
    }
}
```

- [ ] **Step 8: Dataset model**

In `app/Models/Dataset.php` add casts and relations:

```php
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_sync_summary' => 'array',
        ];
    }

    public function referenceValues(): HasMany
    {
        return $this->hasMany(DatasetReferenceValue::class);
    }

    public function referenceValuesOf(ReferenceEntityType $type): HasMany
    {
        return $this->referenceValues()->where('entity_type', $type)->orderBy('label');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Datasets whose environment belongs to the logged-in user. */
    public function scopeOwnedByCurrentUser(Builder $query): Builder
    {
        return $query->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', auth()->id()));
    }
```

Add the imports (`App\Enums\ReferenceEntityType`, `Illuminate\Database\Eloquent\Relations\HasMany`). Leave `getForm()` as it is.

- [ ] **Step 9: Customer model**

In `app/Models/Customer.php`:
- Replace `use App\Models\Scopes\UserOwnedModel;` with `use App\Models\Scopes\DatasetOwnedModel;` and the scope line in `booted()` with `static::addGlobalScope(new DatasetOwnedModel);`.
- Add `'dataset_id'` to `$fillable`.
- `region()` becomes `return $this->belongsTo(DatasetReferenceValue::class, 'region_id');`.
- Add `public function dataset(): BelongsTo { return $this->belongsTo(Dataset::class); }`.
- Remove `user()`.
- In `getForm()`, replace the `region_id` select with a placeholder that compiles and is finished in Task 7:

```php
                    Forms\Components\Select::make('region_id')
                        ->label('Region')
                        ->options([]) // replaced in Task 7 with the dataset's regions
                        ->searchable(),
```

- [ ] **Step 10: Task model**

In `app/Models/Task.php`:
- Swap the scope import and `addGlobalScope` to `DatasetOwnedModel` as above; remove `user()`.
- `taskType()` becomes `return $this->belongsTo(DatasetReferenceValue::class, 'task_type_id');`.
- Add `dataset()` as on Customer.
- Replace the `creating` closure body with:

```php
        static::creating(static function (self $task): void {
            if (empty($task->dataset_id) && $task->customer_id) {
                $task->dataset_id = Customer::withoutGlobalScopes()->whereKey($task->customer_id)->value('dataset_id');
            }

            $taskType = $task->task_type_id
                ? DatasetReferenceValue::withoutGlobalScopes()->find($task->task_type_id)
                : null;

            if ($taskType) {
                $task->duration ??= $taskType->attribute('base_duration');
                $task->base_value ??= $taskType->attribute('base_value');
            }

            if (empty($task->friendly_id)) {
                $prefix = strtoupper(substr($taskType?->label ?: 'X', 0, 1));
                do {
                    $friendlyId = 'T-'.$prefix.'_'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } while (self::withoutGlobalScopes()->where('dataset_id', $task->dataset_id)->where('friendly_id', $friendlyId)->exists());
                $task->friendly_id = $friendlyId;
            }
        });
```

- In `getForm()`, replace the `task_type_id` select with `Forms\Components\Select::make('task_type_id')->label('Task Type')->options([])->searchable()->required()` (finished in Task 7).

- [ ] **Step 11: Factories**

`CustomerFactory::definition()`: replace `'region_id' => Region::factory()` with `'dataset_id' => Dataset::factory(), 'region_id' => null`, and `'status' => fake()->word()` with `'status' => 'ACTIVE'` (check `App\Enums\Status` for the real case value). Add a `withRegion()` state:

```php
    public function withRegion(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            $customer->update(['region_id' => DatasetReferenceValue::factory()->for($customer->dataset)->create()->id]);
        });
    }
```

`TaskFactory::definition()` (it currently references a `CustomerTaskType` that no longer exists):

```php
        return [
            'customer_id' => Customer::factory(),
            'task_type_id' => null,
            'duration' => fake()->numberBetween(30, 240),
            'base_value' => fake()->numberBetween(1000, 5000),
            'status' => TaskStatus::IGNORE,
            'appt_window_start' => null,
            'appt_window_finish' => null,
        ];
```

Customer and Task use `HasUuids`; the `for()` helper in the tests relies on the `dataset()` and `customer()` relations existing.

- [ ] **Step 12: Run the tests**

Run: `php artisan test tests/Feature/DatasetReferenceValueSchemaTest.php`
Expected: PASS, 5 tests. Then `php artisan test` for the whole suite; anything that referenced the deleted models must be updated or, if it belongs to Task 5, 7 or 8, temporarily marked `->skip('rebuilt in Task N')`.

- [ ] **Step 13: Commit**

```bash
git add -A app database tests/Feature/DatasetReferenceValueSchemaTest.php
git commit -m "feat: dataset-scoped reference values table; customers and tasks re-parented onto datasets"
```

---

### Task 4: Sync: pso-services client, upserter and queued job

**Files:**
- Create: `app/Support/ReferenceValues/ReferenceValuesClient.php`
- Create: `app/Support/ReferenceValues/ReferenceValuesFetchException.php`
- Create: `app/Support/ReferenceValues/ReferenceValuesUpserter.php`
- Create: `app/Jobs/SyncDatasetReferenceValuesJob.php`
- Modify: `config/psott.php` (add `reference_values.timeout`)
- Test: `tests/Feature/ReferenceValuesUpserterTest.php`, `tests/Feature/SyncDatasetReferenceValuesJobTest.php`

**Interfaces:**
- Consumes: `DatasetReferenceValue`, `Dataset`, `Environment` (Task 3); `config('psott.pso-services-api')`, `config('psott.pso-services-api-version')`; the pso-services response `data.tables.<Entity>[] = {id, description, priority?, base_value?, base_duration?}`.
- Produces: `ReferenceValuesClient::fetch(Environment $environment, string $datasetName): array` returning `tables` (keyed by wire entity), throwing `ReferenceValuesFetchException` with a user-readable message. `ReferenceValuesUpserter::apply(Dataset $dataset, array $tables, CarbonImmutable $now): array` returning the summary `['<entity_type value>' => ['created' => n, 'updated' => n, 'kept' => n, 'missing' => n], ...]`. `SyncDatasetReferenceValuesJob::dispatch(string $datasetId)`; cache key `SyncDatasetReferenceValuesJob::lockKey(string $datasetId)` = `"dataset-reference-sync:{$datasetId}"`. The job records on the dataset: `last_synced_at`, and `last_sync_summary = ['status' => 'succeeded'|'failed', 'finished_at' => iso, 'error' => string|null, 'counts' => <upserter summary>]`.

- [ ] **Step 1: Write the upserter tests**

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;

function emptyTables(array $overrides = []): array
{
    return array_replace([
        'Region' => [], 'Skill' => [], 'Activity_Type' => [],
        'Slot_Usage_Rule' => [], 'Appointment_Template' => [], 'SLA_Type' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->dataset = Dataset::factory()->create();
    $this->now = CarbonImmutable::parse('2026-10-10 12:00:00');
});

it('creates pso rows for ids it has not seen, with task type attributes', function () {
    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'R1', 'description' => 'Regina']],
        'Activity_Type' => [['id' => 'INSTALL', 'description' => 'Install', 'priority' => 5, 'base_value' => 1000.5, 'base_duration' => 90]],
    ]), $this->now);

    $region = DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'R1')->first();
    $taskType = DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'INSTALL')->first();

    expect($region->entity_type)->toBe(ReferenceEntityType::Region)
        ->and($region->label)->toBe('Regina')
        ->and($region->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($region->last_seen_at->eq($this->now))->toBeTrue()
        ->and($taskType->attribute('priority'))->toBe(5)
        ->and($taskType->attribute('base_value'))->toBe(1000.5)
        ->and($taskType->attribute('base_duration'))->toBe(90)
        ->and($summary['region'])->toBe(['created' => 1, 'updated' => 0, 'kept' => 0, 'missing' => 0])
        ->and($summary['task_type']['created'])->toBe(1);
});

it('overwrites label and attributes of an unmodified pso row', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->taskType(1, 500, 30)->create(['pso_id' => 'INSTALL', 'label' => 'Old']);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Activity_Type' => [['id' => 'INSTALL', 'description' => 'New', 'priority' => 7, 'base_value' => 2000, 'base_duration' => 120]],
    ]), $this->now);

    $row->refresh();
    expect($row->label)->toBe('New')
        ->and($row->attribute('priority'))->toBe(7)
        ->and($row->is_locally_modified)->toBeFalse()
        ->and($summary['task_type']['updated'])->toBe(1);
});

it('keeps the fields of a locally modified row and only bumps last_seen_at', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create([
        'pso_id' => 'R1', 'label' => 'My label', 'is_locally_modified' => true, 'last_seen_at' => $this->now->subDay(),
    ]);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'R1', 'description' => 'PSO label']],
    ]), $this->now);

    $row->refresh();
    expect($row->label)->toBe('My label')
        ->and($row->is_locally_modified)->toBeTrue()
        ->and($row->last_seen_at->eq($this->now))->toBeTrue()
        ->and($summary['region']['kept'])->toBe(1);
});

it('leaves rows that PSO no longer returns untouched and counts them as missing', function () {
    $gone = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'GONE', 'last_seen_at' => $this->now->subDay()]);
    $manual = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'MINE']);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables(), $this->now);

    expect($gone->refresh()->last_seen_at->eq($this->now->subDay()))->toBeTrue()
        ->and($manual->refresh()->origin)->toBe(ReferenceValueOrigin::Manual)
        ->and(DatasetReferenceValue::withoutGlobalScopes()->count())->toBe(2)
        ->and($summary['region']['missing'])->toBe(1);
});

it('promotes a manual row to pso when its id appears, overwriting unless it was edited', function () {
    $plain = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'A', 'label' => 'typed']);
    $edited = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'B', 'label' => 'typed and edited', 'is_locally_modified' => true]);

    app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'A', 'description' => 'From PSO'], ['id' => 'B', 'description' => 'From PSO']],
    ]), $this->now);

    expect($plain->refresh()->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($plain->label)->toBe('From PSO')
        ->and($edited->refresh()->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($edited->label)->toBe('typed and edited');
});

it('does not touch rows of another dataset', function () {
    $other = DatasetReferenceValue::factory()->create(['pso_id' => 'R1', 'label' => 'Other dataset']);

    app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables(['Region' => [['id' => 'R1', 'description' => 'Mine']]]), $this->now);

    expect($other->refresh()->label)->toBe('Other dataset');
});

it('ignores tables it does not know and rows without an id', function () {
    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Resources' => [['id' => 'TECH']],
        'Region' => [['description' => 'no id'], ['id' => '', 'description' => 'blank id']],
    ]), $this->now);

    expect(DatasetReferenceValue::withoutGlobalScopes()->count())->toBe(0)
        ->and($summary)->not->toHaveKey('resources');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/ReferenceValuesUpserterTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the upserter**

`app/Support/ReferenceValues/ReferenceValuesUpserter.php`:

```php
<?php

namespace App\Support\ReferenceValues;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies one sync result to a dataset's reference values.
 *
 * Rules (spec 5.2): unknown id -> create as pso; known and not locally
 * modified -> overwrite label/attributes; known and locally modified ->
 * bump last_seen_at only; absent from the response -> untouched, counted
 * as missing. Nothing is ever deleted.
 */
class ReferenceValuesUpserter
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tables keyed by wire entity name
     * @return array<string, array{created: int, updated: int, kept: int, missing: int}> keyed by ReferenceEntityType value
     */
    public function apply(Dataset $dataset, array $tables, CarbonImmutable $now): array
    {
        $summary = [];

        DB::transaction(function () use ($dataset, $tables, $now, &$summary): void {
            foreach (ReferenceEntityType::cases() as $type) {
                $summary[$type->value] = $this->applyType($dataset, $type, $tables[$type->psoEntity()] ?? [], $now);
            }
        });

        return $summary;
    }

    private function applyType(Dataset $dataset, ReferenceEntityType $type, array $rows, CarbonImmutable $now): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'kept' => 0, 'missing' => 0];

        $existing = DatasetReferenceValue::withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->where('entity_type', $type)
            ->get()
            ->keyBy('pso_id');

        $seen = [];

        foreach ($rows as $row) {
            $psoId = trim((string) ($row['id'] ?? ''));
            if ($psoId === '') {
                continue;
            }
            $seen[$psoId] = true;

            $label = trim((string) ($row['description'] ?? '')) ?: $psoId;
            $attributes = $this->attributesFor($type, $row);

            /** @var DatasetReferenceValue|null $current */
            $current = $existing->get($psoId);

            if ($current === null) {
                DatasetReferenceValue::withoutGlobalScopes()->create([
                    'dataset_id' => $dataset->id,
                    'entity_type' => $type,
                    'pso_id' => $psoId,
                    'label' => $label,
                    'attributes' => $attributes,
                    'origin' => ReferenceValueOrigin::Pso,
                    'last_seen_at' => $now,
                    'is_locally_modified' => false,
                ]);
                $counts['created']++;

                continue;
            }

            if ($current->is_locally_modified) {
                $current->forceFill(['origin' => ReferenceValueOrigin::Pso, 'last_seen_at' => $now])->saveQuietly();
                $counts['kept']++;

                continue;
            }

            $current->forceFill([
                'origin' => ReferenceValueOrigin::Pso,
                'label' => $label,
                'attributes' => $attributes,
                'last_seen_at' => $now,
            ])->saveQuietly();
            $counts['updated']++;
        }

        $counts['missing'] = $existing
            ->filter(static fn (DatasetReferenceValue $row): bool => $row->origin === ReferenceValueOrigin::Pso && ! isset($seen[$row->pso_id]))
            ->count();

        return $counts;
    }

    private function attributesFor(ReferenceEntityType $type, array $row): ?array
    {
        $keys = $type->attributeKeys();
        if ($keys === []) {
            return null;
        }

        $attributes = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            $attributes[$key] = match ($key) {
                'priority', 'base_duration' => is_numeric($value) ? (int) $value : null,
                'base_value' => is_numeric($value) ? (float) $value : null,
                default => $value,
            };
        }

        return $attributes;
    }
}
```

Note: the `missing` count is taken from `$existing` as loaded before the loop, so a `manual` row promoted in this run is already `pso` in the database but still `manual` in `$existing`, which is what keeps it out of the missing count. The "manual to pso" test and the "missing" test pin this.

- [ ] **Step 4: Run the upserter tests**

Run: `php artisan test tests/Feature/ReferenceValuesUpserterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit the upserter**

```bash
git add app/Support/ReferenceValues/ReferenceValuesUpserter.php tests/Feature/ReferenceValuesUpserterTest.php
git commit -m "feat: upsert synced reference values under the four sync rules"
```

- [ ] **Step 6: Write the job tests**

```php
<?php

use App\Jobs\SyncDatasetReferenceValuesJob;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 2]);
    $this->environment = Environment::factory()->create([
        'base_url' => 'https://sate-pso-tst.ifs.cloud',
        'account_id' => 'SATE',
        'username' => 'GOGH_INT',
        'password' => Crypt::encryptString('secret'),
    ]);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
});

function servicesResponse(array $tables = []): array
{
    return ['data' => [
        'dataset_id' => 'NORTH',
        'fetched_at' => '2026-10-10T12:00:00Z',
        'tables' => array_replace(['Region' => [], 'Skill' => [], 'Activity_Type' => [], 'Slot_Usage_Rule' => [], 'Appointment_Template' => [], 'SLA_Type' => []], $tables),
    ], 'status' => 200];
}

it('calls pso-services with the environment headers and upserts the tables', function () {
    Http::fake(['https://pso-services.test/api/2/dataset/reference-values' => Http::response(servicesResponse([
        'Region' => [['id' => 'R1', 'description' => 'Regina']],
    ]))]);

    (new SyncDatasetReferenceValuesJob($this->dataset->id))->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('baseUrl', 'https://sate-pso-tst.ifs.cloud')
        && $request->hasHeader('accountId', 'SATE')
        && $request->hasHeader('datasetId', 'NORTH')
        && $request->hasHeader('username', 'GOGH_INT')
        && $request->hasHeader('password', 'secret'));

    $this->dataset->refresh();
    expect(DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'R1')->exists())->toBeTrue()
        ->and($this->dataset->last_synced_at)->not->toBeNull()
        ->and($this->dataset->last_sync_summary['status'])->toBe('succeeded')
        ->and($this->dataset->last_sync_summary['counts']['region']['created'])->toBe(1);
});

it('records a failure with the services message and leaves last_synced_at alone', function () {
    Http::fake(['*' => Http::response(['message' => ['error' => 'Unexpected response from the PSO gateway.', 'details' => 'root is <html>'], 'status' => 502], 502)]);

    (new SyncDatasetReferenceValuesJob($this->dataset->id))->handle();

    $this->dataset->refresh();
    expect($this->dataset->last_synced_at)->toBeNull()
        ->and($this->dataset->last_sync_summary['status'])->toBe('failed')
        ->and($this->dataset->last_sync_summary['error'])->toContain('Unexpected response from the PSO gateway');
});

it('records a connection failure in plain words', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28'));

    (new SyncDatasetReferenceValuesJob($this->dataset->id))->handle();

    expect($this->dataset->refresh()->last_sync_summary['error'])->toContain('could not be reached');
});

it('releases the sync lock when it finishes, success or failure', function () {
    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);
    Http::fake(['*' => Http::response(servicesResponse())]);

    (new SyncDatasetReferenceValuesJob($this->dataset->id))->handle();
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeFalse();

    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);
    Http::fake(['*' => Http::response('boom', 500)]);

    (new SyncDatasetReferenceValuesJob($this->dataset->id))->handle();
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeFalse();
});

it('does nothing when the dataset no longer exists', function () {
    Http::fake();

    (new SyncDatasetReferenceValuesJob('00000000-0000-0000-0000-000000000000'))->handle();

    Http::assertNothingSent();
});
```

- [ ] **Step 7: Run to verify failure**

Run: `php artisan test tests/Feature/SyncDatasetReferenceValuesJobTest.php`
Expected: FAIL, class not found.

- [ ] **Step 8: Config, client, exception, job**

`config/psott.php`: add

```php
    'reference_values' => [
        // The gateway returns the whole dataset body; pso-services streams and parses it.
        'timeout' => (int) env('REFERENCE_VALUES_TIMEOUT', 600),
    ],
```

`app/Support/ReferenceValues/ReferenceValuesFetchException.php`:

```php
<?php

namespace App\Support\ReferenceValues;

use RuntimeException;

class ReferenceValuesFetchException extends RuntimeException {}
```

`app/Support/ReferenceValues/ReferenceValuesClient.php`:

```php
<?php

namespace App\Support\ReferenceValues;

use App\Models\Environment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls pso-services GET /dataset/reference-values for one environment and
 * dataset. Kept apart from PSOInteractionsTrait::sendToPSONew() because that
 * helper uses the short default timeout and this call takes tens of seconds.
 */
class ReferenceValuesClient
{
    /**
     * @return array<string, array<int, array<string, mixed>>> the six tables keyed by wire entity name
     *
     * @throws ReferenceValuesFetchException
     */
    public function fetch(Environment $environment, string $datasetName): array
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('psott.reference_values.timeout', 600))
                ->connectTimeout(10)
                ->withHeaders([
                    'baseUrl' => $environment->base_url,
                    'accountId' => $environment->account_id,
                    'datasetId' => $datasetName,
                    'username' => $environment->username,
                    'password' => Crypt::decryptString($environment->password),
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('Reference values: pso-services could not be reached', ['url' => $url, 'message' => $exception->getMessage()]);

            throw new ReferenceValuesFetchException('The PSO services API could not be reached (timed out or unreachable).', previous: $exception);
        }

        if (! $response->successful()) {
            throw new ReferenceValuesFetchException($this->errorMessage($response->json(), $response->status()));
        }

        $tables = $response->json('data.tables');
        if (! is_array($tables)) {
            throw new ReferenceValuesFetchException('The PSO services API answered without the expected tables.');
        }

        return $tables;
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'dataset/reference-values';
    }

    private function errorMessage(mixed $body, int $status): string
    {
        $error = data_get($body, 'message.error') ?? data_get($body, 'message');
        $details = data_get($body, 'message.details');

        $text = is_string($error) ? $error : "The PSO services API returned {$status}.";
        if (is_string($details) && $details !== '') {
            $text .= ' '.$details;
        }

        return $text;
    }
}
```

`app/Jobs/SyncDatasetReferenceValuesJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Support\ReferenceValues\ReferenceValuesClient;
use App\Support\ReferenceValues\ReferenceValuesFetchException;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDatasetReferenceValuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    public function __construct(public string $datasetId) {}

    public static function lockKey(string $datasetId): string
    {
        return "dataset-reference-sync:{$datasetId}";
    }

    public function handle(ReferenceValuesClient $client, ReferenceValuesUpserter $upserter): void
    {
        // No user is logged in on the queue, and Environment carries the
        // UserOwnedModel global scope (user_id = auth()->id(), i.e. null here),
        // so the environment must be loaded without that scope.
        $dataset = Dataset::query()->find($this->datasetId);
        $environment = $dataset?->environment()->withoutGlobalScopes()->first();
        if ($dataset === null || $environment === null) {
            Cache::forget(self::lockKey($this->datasetId));

            return;
        }

        try {
            $now = CarbonImmutable::now();
            $tables = $client->fetch($environment, $dataset->name);
            $counts = $upserter->apply($dataset, $tables, $now);

            $dataset->forceFill([
                'last_synced_at' => $now,
                'last_sync_summary' => ['status' => 'succeeded', 'finished_at' => $now->toIso8601String(), 'error' => null, 'counts' => $counts],
            ])->save();
        } catch (ReferenceValuesFetchException $exception) {
            $this->recordFailure($dataset, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Reference values sync failed', ['dataset_id' => $dataset->id, 'message' => $exception->getMessage()]);
            $this->recordFailure($dataset, 'The sync failed unexpectedly: '.$exception->getMessage());
        } finally {
            Cache::forget(self::lockKey($this->datasetId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(self::lockKey($this->datasetId));
    }

    private function recordFailure(Dataset $dataset, string $message): void
    {
        $dataset->forceFill([
            'last_sync_summary' => array_merge(
                ['counts' => data_get($dataset->last_sync_summary, 'counts')],
                ['status' => 'failed', 'finished_at' => CarbonImmutable::now()->toIso8601String(), 'error' => $message],
            ),
        ])->save();
    }
}
```

Note: `Dataset` has no user scope, so the job (which runs with no authenticated user) can find it; `Environment` does have one, hence the `withoutGlobalScopes()` above. The upserter uses `withoutGlobalScopes()` on `DatasetReferenceValue` for the same reason. The "does nothing when the dataset no longer exists" test also covers the lock release on that path.

- [ ] **Step 9: Run the job tests**

Run: `php artisan test tests/Feature/SyncDatasetReferenceValuesJobTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 10: Commit**

```bash
git add app/Support/ReferenceValues app/Jobs/SyncDatasetReferenceValuesJob.php config/psott.php tests/Feature/SyncDatasetReferenceValuesJobTest.php
git commit -m "feat: queued sync of a dataset's reference values from pso-services"
```

---

### Task 5: The six Filament resources on one abstract resource

**Files:**
- Create: `app/Filament/Resources/DatasetReferenceValueResource.php` (abstract)
- Create: `app/Filament/Resources/DatasetReferenceValueResource/Pages/ListDatasetReferenceValues.php` (abstract)
- Create: `app/Filament/Resources/RegionResource.php` + `RegionResource/Pages/ListRegions.php`; likewise `SkillResource`/`ListSkills`, `TaskTypeResource`/`ListTaskTypes`, `SlotUsageRuleResource`/`ListSlotUsageRules`, `AppointmentTemplateResource`/`ListAppointmentTemplates`, `SlaTypeResource`/`ListSlaTypes`
- Test: `tests/Feature/DatasetReferenceValueResourceTest.php`

**Interfaces:**
- Consumes: `DatasetReferenceValue`, `Dataset::ownedByCurrentUser()`, `ReferenceEntityType`, `ReferenceValueOrigin`.
- Produces: `DatasetReferenceValueResource::entityType(): ReferenceEntityType` (abstract static), `DatasetReferenceValueResource::formSchema(ReferenceEntityType $type, bool $lockDataset = false): array` (reused by Task 6 relation managers and Task 7/8 create-option forms), and `DatasetReferenceValueResource::resetToPsoAction(): Action`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\Resources\RegionResource\Pages\ListRegions;
use App\Filament\Resources\SlaTypeResource\Pages\ListSlaTypes;
use App\Filament\Resources\TaskTypeResource\Pages\ListTaskTypes;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists only its own entity type and only the current user datasets', function () {
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1']);
    $skill = DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'S1']);
    $otherUsersRegion = DatasetReferenceValue::factory()->create(['pso_id' => 'R-OTHER']);

    Livewire::test(ListRegions::class)
        ->assertCanSeeTableRecords([$region])
        ->assertCanNotSeeTableRecords([$skill, $otherUsersRegion]);
});

it('creates a manual row of its type in the chosen dataset', function () {
    Livewire::test(ListSlaTypes::class)
        ->callAction(TestAction::make('create'), data: [
            'dataset_id' => $this->dataset->id,
            'pso_id' => 'Appointment',
            'label' => 'Appointment SLA',
        ])
        ->assertHasNoActionErrors();

    $row = DatasetReferenceValue::first();
    expect($row->entity_type)->toBe(ReferenceEntityType::SlaType)
        ->and($row->origin)->toBe(ReferenceValueOrigin::Manual)
        ->and($row->last_seen_at)->toBeNull();
});

it('refuses a duplicate pso id within the same dataset and type', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1']);

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('create'), data: ['dataset_id' => $this->dataset->id, 'pso_id' => 'R1', 'label' => 'dup'])
        ->assertHasActionErrors(['pso_id']);
});

it('stores task type attributes from the three extra fields', function () {
    Livewire::test(ListTaskTypes::class)
        ->callAction(TestAction::make('create'), data: [
            'dataset_id' => $this->dataset->id, 'pso_id' => 'INSTALL', 'label' => 'Install',
            'attributes' => ['priority' => 5, 'base_value' => 1500, 'base_duration' => 90],
        ])
        ->assertHasNoActionErrors();

    expect(DatasetReferenceValue::first()->attribute('base_duration'))->toBe(90);
});

it('marks a pso row locally modified when edited and clears it with Reset to PSO', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1', 'label' => 'Regina']);

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['label' => 'Regina (west)'])
        ->assertHasNoActionErrors();
    expect($row->refresh()->is_locally_modified)->toBeTrue();

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('resetToPso')->table($row));
    expect($row->refresh()->is_locally_modified)->toBeFalse();
});

it('shows the missing badge for a pso row the last sync did not see', function () {
    $this->dataset->update(['last_synced_at' => now()]);
    $missing = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'GONE', 'last_seen_at' => now()->subDay()]);

    Livewire::test(ListRegions::class)
        ->assertCanSeeTableRecords([$missing])
        ->assertSee('Missing from PSO');
});
```

If `Filament\Actions\Testing\TestAction` is not present in the installed Filament version, use the older `->callTableAction('edit', $row, data: [...])` and `->callAction('create', data: [...])` forms instead.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/DatasetReferenceValueResourceTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Write the abstract resource**

`app/Filament/Resources/DatasetReferenceValueResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Shared behaviour of the six "Base Data" resources. Each subclass names its
 * entity type, slug and icon; everything else lives here.
 */
abstract class DatasetReferenceValueResource extends Resource
{
    protected static ?string $model = DatasetReferenceValue::class;

    protected static string|UnitEnum|null $navigationGroup = 'Base Data';

    abstract public static function entityType(): ReferenceEntityType;

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::entityType()->slug();
    }

    public static function getModelLabel(): string
    {
        return static::entityType()->getLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::entityType()->pluralLabel();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return static::entityType()->navigationIcon();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('entity_type', static::entityType());
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema(static::formSchema(static::entityType()));
    }

    /**
     * The create/edit form for one entity type. With $lockDataset the dataset
     * select is omitted (relation managers and create-option forms supply it).
     */
    public static function formSchema(ReferenceEntityType $type, bool $lockDataset = false): array
    {
        $fields = [];

        if (! $lockDataset) {
            $fields[] = Forms\Components\Select::make('dataset_id')
                ->label('Dataset')
                ->options(static fn (): array => Dataset::ownedByCurrentUser()->with('environment')->get()
                    ->mapWithKeys(static fn (Dataset $dataset): array => [$dataset->id => "{$dataset->environment->name} / {$dataset->name}"])
                    ->all())
                ->required()
                ->searchable()
                ->disabledOn('edit')
                ->dehydrated();
        }

        $fields[] = Forms\Components\TextInput::make('pso_id')
            ->label('PSO ID')
            ->helperText('The identifier PSO expects in a request.')
            ->required()
            ->maxLength(32)
            ->rules([
                static fn (Get $get, ?DatasetReferenceValue $record) => Rule::unique('dataset_reference_values', 'pso_id')
                    ->where('dataset_id', $record?->dataset_id ?? $get('dataset_id'))
                    ->where('entity_type', $type->value)
                    ->ignore($record?->id),
            ]);

        $fields[] = Forms\Components\TextInput::make('label')
            ->label('Label')
            ->helperText('Display name. Defaults to the PSO ID when left blank.')
            ->maxLength(255);

        if ($type === ReferenceEntityType::TaskType) {
            $fields[] = Forms\Components\TextInput::make('attributes.priority')->label('Priority')->numeric()->integer()->minValue(1)->maxValue(10);
            $fields[] = Forms\Components\TextInput::make('attributes.base_value')->label('Base value')->numeric()->minValue(0);
            $fields[] = Forms\Components\TextInput::make('attributes.base_duration')->label('Base duration (minutes)')->numeric()->integer()->minValue(1);
        }

        return $fields;
    }

    public static function table(Table $table): Table
    {
        $type = static::entityType();

        $columns = [
            Tables\Columns\TextColumn::make('dataset.name')->label('Dataset')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('pso_id')->label('PSO ID')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
        ];

        if ($type === ReferenceEntityType::TaskType) {
            $columns[] = Tables\Columns\TextColumn::make('attributes.priority')->label('Priority');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_value')->label('Base value');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_duration')->label('Duration (min)');
        }

        $columns[] = Tables\Columns\TextColumn::make('origin')->badge();
        $columns[] = Tables\Columns\TextColumn::make('status')
            ->label('')
            ->badge()
            ->state(static fn (DatasetReferenceValue $record): ?string => match (true) {
                $record->isMissingFromPso() => 'Missing from PSO',
                $record->is_locally_modified => 'Edited',
                default => null,
            })
            ->color(static fn (?string $state): string => $state === 'Missing from PSO' ? 'danger' : 'warning');
        $columns[] = Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->sortable()->toggleable();

        return $table
            ->columns($columns)
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('dataset_id')
                    ->label('Dataset')
                    ->options(static fn (): array => Dataset::ownedByCurrentUser()->pluck('name', 'id')->all()),
                SelectFilter::make('origin')->options(ReferenceValueOrigin::class),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                static::resetToPsoAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function resetToPsoAction(): Action
    {
        return Action::make('resetToPso')
            ->label('Reset to PSO')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(static fn (DatasetReferenceValue $record): bool => $record->origin === ReferenceValueOrigin::Pso && $record->is_locally_modified)
            ->requiresConfirmation()
            ->modalDescription('The next sync will overwrite this row with the values from PSO.')
            ->action(static function (DatasetReferenceValue $record): void {
                $record->forceFill(['is_locally_modified' => false])->saveQuietly();
            });
    }
}
```

- [ ] **Step 4: Abstract list page**

`app/Filament/Resources/DatasetReferenceValueResource/Pages/ListDatasetReferenceValues.php`:

```php
<?php

namespace App\Filament\Resources\DatasetReferenceValueResource\Pages;

use App\Enums\ReferenceValueOrigin;
use App\Filament\Resources\DatasetReferenceValueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

abstract class ListDatasetReferenceValues extends ListRecords
{
    protected function getHeaderActions(): array
    {
        /** @var class-string<DatasetReferenceValueResource> $resource */
        $resource = static::$resource;

        return [
            CreateAction::make()
                ->slideOver()
                ->mutateDataUsing(static function (array $data) use ($resource): array {
                    $data['entity_type'] = $resource::entityType();
                    $data['origin'] = ReferenceValueOrigin::Manual;
                    $data['label'] = filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'];

                    return $data;
                }),
        ];
    }
}
```

- [ ] **Step 5: The six subclasses**

Each resource is this shape; repeat for `Skill` (`ReferenceEntityType::Skill`), `TaskType` (`TaskType`), `SlotUsageRule` (`SlotUsageRule`), `AppointmentTemplate` (`AppointmentTemplate`), `SlaType` (`SlaType`):

`app/Filament/Resources/RegionResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\RegionResource\Pages\ListRegions;

class RegionResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::Region;
    }

    public static function getPages(): array
    {
        return ['index' => ListRegions::route('/')];
    }
}
```

`app/Filament/Resources/RegionResource/Pages/ListRegions.php`:

```php
<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\RegionResource;

class ListRegions extends ListDatasetReferenceValues
{
    protected static string $resource = RegionResource::class;
}
```

Filament discovers resources by scanning `app/Filament/Resources`; the abstract class is skipped because it is abstract. If the panel complains about the abstract resource, add it to the panel's ignored list or move it to `app/Filament/Support/`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/DatasetReferenceValueResourceTest.php`
Expected: PASS, 6 tests. Then open the app (`herd open` or the Herd URL) and confirm six "Base Data" entries appear with badges.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources tests/Feature/DatasetReferenceValueResourceTest.php
git commit -m "feat: six Base Data resources on one dataset reference value resource"
```

---

### Task 6: Dataset page: navigation, summary, Sync from PSO, relation managers

**Files:**
- Modify: `app/Filament/Resources/DatasetResource.php` (register navigation, infolist, relations)
- Modify: `app/Filament/Resources/DatasetResource/Pages/ViewDataset.php` (sync action, polling)
- Create: `app/Filament/Resources/DatasetResource/RelationManagers/ReferenceValuesRelationManager.php` (abstract) and six subclasses `RegionsRelationManager`, `SkillsRelationManager`, `TaskTypesRelationManager`, `SlotUsageRulesRelationManager`, `AppointmentTemplatesRelationManager`, `SlaTypesRelationManager`
- Create: `app/Filament/Resources/DatasetResource/RelationManagers/CustomersRelationManager.php`
- Modify: `app/Filament/Resources/EnvironmentResource/RelationManagers/DatasetsRelationManager.php` (an "Open" record action)
- Test: `tests/Feature/DatasetSyncActionTest.php`

**Interfaces:**
- Consumes: `SyncDatasetReferenceValuesJob` (Task 4), `DatasetReferenceValueResource::formSchema()` (Task 5), `Dataset::last_sync_summary`.
- Produces: header action `sync` on `ViewDataset`; the page re-renders every 5 seconds while the lock key exists.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Jobs\SyncDatasetReferenceValuesJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('queues a sync for the dataset and takes the lock', function () {
    Queue::fake();

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->callAction(TestAction::make('sync'))
        ->assertNotified();

    Queue::assertPushed(SyncDatasetReferenceValuesJob::class, fn ($job) => $job->datasetId === $this->dataset->id);
    expect(Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id)))->toBeTrue();
});

it('refuses a second sync while one is running', function () {
    Queue::fake();
    Cache::put(SyncDatasetReferenceValuesJob::lockKey($this->dataset->id), true, 600);

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->callAction(TestAction::make('sync'))
        ->assertNotified('A sync is already running for this dataset.');

    Queue::assertNothingPushed();
});

it('shows the last sync result', function () {
    $this->dataset->update([
        'last_synced_at' => now(),
        'last_sync_summary' => ['status' => 'succeeded', 'error' => null, 'finished_at' => now()->toIso8601String(), 'counts' => [
            'region' => ['created' => 3, 'updated' => 0, 'kept' => 1, 'missing' => 2],
        ]],
    ]);

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('Succeeded')
        ->assertSee('3 created')
        ->assertSee('2 missing');
});

it('does not open another user dataset', function () {
    $other = Dataset::factory()->create();

    Livewire::test(ViewDataset::class, ['record' => $other->getRouteKey()])->assertNotFound();
});
```

The last test requires a scoped query on `DatasetResource`; see Step 3.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/DatasetSyncActionTest.php`
Expected: FAIL (`sync` action not found; "Succeeded" not seen; other user's dataset opens).

- [ ] **Step 3: DatasetResource**

In `app/Filament/Resources/DatasetResource.php`:

- Change `protected static bool $shouldRegisterNavigation = false;` to `true`, add `protected static string|UnitEnum|null $navigationGroup = 'Base Data';` and `protected static ?int $navigationSort = -1;` so it sits first.
- Scope the resource to the user's datasets:

```php
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedByCurrentUser();
    }
```

- Add the infolist (imports: `Filament\Infolists\Components\TextEntry`, `Filament\Schemas\Components\Section`):

```php
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dataset')->schema([
                TextEntry::make('environment.name')->label('Environment'),
                TextEntry::make('name')->label('Dataset ID'),
                TextEntry::make('rota')->label('Rota ID'),
            ])->columns(3),
            Section::make('Last sync from PSO')
                ->schema([
                    TextEntry::make('last_sync_summary.status')
                        ->label('Result')
                        ->badge()
                        ->formatStateUsing(static fn (?string $state): string => $state ? ucfirst($state) : 'Never synced')
                        ->color(static fn (?string $state): string => match ($state) { 'succeeded' => 'success', 'failed' => 'danger', default => 'gray' }),
                    TextEntry::make('last_synced_at')->label('Last successful sync')->dateTime()->placeholder('Never'),
                    TextEntry::make('last_sync_summary.error')->label('Error')->color('danger')->visible(static fn ($state): bool => filled($state))->columnSpanFull(),
                    TextEntry::make('last_sync_summary.counts')
                        ->label('Counts')
                        ->columnSpanFull()
                        ->visible(static fn ($state): bool => filled($state))
                        ->formatStateUsing(static fn (array $state): string => collect($state)
                            ->map(static fn (array $counts, string $type): string => ReferenceEntityType::from($type)->pluralLabel()
                                .": {$counts['created']} created, {$counts['updated']} updated, {$counts['kept']} kept (edited), {$counts['missing']} missing")
                            ->implode("\n"))
                        ->html(false),
                ])->columns(2),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RegionsRelationManager::class,
            RelationManagers\SkillsRelationManager::class,
            RelationManagers\TaskTypesRelationManager::class,
            RelationManagers\SlotUsageRulesRelationManager::class,
            RelationManagers\AppointmentTemplatesRelationManager::class,
            RelationManagers\SlaTypesRelationManager::class,
            RelationManagers\CustomersRelationManager::class,
        ];
    }
```

If `TextEntry::formatStateUsing` receives the array already cast, keep it; if it receives JSON, `json_decode` first. The counts entry should render one line per type; use `->listWithLineBreaks()` on an array state if the formatted string loses its newlines.

- [ ] **Step 4: ViewDataset**

```php
<?php

namespace App\Filament\Resources\DatasetResource\Pages;

use App\Filament\Resources\DatasetResource;
use App\Jobs\SyncDatasetReferenceValuesJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ViewDataset extends ViewRecord
{
    protected static string $resource = DatasetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sync from PSO')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Pulls the whole dataset from the PSO gateway and refreshes the regions, skills, task types, slot usage rules, appointment templates and SLA types. Rows you edited keep your values. Takes up to a few minutes on a large dataset.')
                ->action(function (): void {
                    $lockKey = SyncDatasetReferenceValuesJob::lockKey($this->record->id);

                    if (! Cache::add($lockKey, true, 600)) {
                        Notification::make()->title('A sync is already running for this dataset.')->warning()->send();

                        return;
                    }

                    SyncDatasetReferenceValuesJob::dispatch($this->record->id);

                    Notification::make()->title('Sync queued')->body('This page refreshes itself while the sync runs.')->success()->send();
                }),
            Actions\EditAction::make(),
        ];
    }

    public function isSyncRunning(): bool
    {
        return Cache::has(SyncDatasetReferenceValuesJob::lockKey($this->record->id));
    }
}
```

Polling: add to the resource's view (create `resources/views/filament/resources/dataset-resource/pages/view-dataset.blade.php` extending the default view page, or set `protected string $view` to it) a wrapper `<div @if($this->isSyncRunning()) wire:poll.5s @endif>` around `{{ $this->infolist }}`; or, simpler and acceptable, add `wire:poll.5s` on the whole page via `protected ?string $pollingInterval = '5s';` if the installed Filament exposes one for view pages. Pick whichever works in the installed version; the test does not check polling.

- [ ] **Step 5: Relation managers**

`app/Filament/Resources/DatasetResource/RelationManagers/ReferenceValuesRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Models\DatasetReferenceValue;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class ReferenceValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'referenceValues';

    abstract public static function entityType(): ReferenceEntityType;

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return static::entityType()->pluralLabel();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(DatasetReferenceValueResource::formSchema(static::entityType(), lockDataset: true));
    }

    public function table(Table $table): Table
    {
        $type = static::entityType();

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('entity_type', $type))
            ->recordTitleAttribute('label')
            ->columns(array_filter([
                Tables\Columns\TextColumn::make('pso_id')->label('PSO ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
                $type === ReferenceEntityType::TaskType ? Tables\Columns\TextColumn::make('attributes.priority')->label('Priority') : null,
                $type === ReferenceEntityType::TaskType ? Tables\Columns\TextColumn::make('attributes.base_value')->label('Base value') : null,
                $type === ReferenceEntityType::TaskType ? Tables\Columns\TextColumn::make('attributes.base_duration')->label('Duration (min)') : null,
                Tables\Columns\TextColumn::make('origin')->badge(),
                Tables\Columns\TextColumn::make('status')->label('')->badge()
                    ->state(static fn (DatasetReferenceValue $record): ?string => match (true) {
                        $record->isMissingFromPso() => 'Missing from PSO',
                        $record->is_locally_modified => 'Edited',
                        default => null,
                    })
                    ->color(static fn (?string $state): string => $state === 'Missing from PSO' ? 'danger' : 'warning'),
            ]))
            ->defaultSort('label')
            ->headerActions([
                CreateAction::make()->slideOver()->mutateDataUsing(static function (array $data) use ($type): array {
                    $data['entity_type'] = $type;
                    $data['origin'] = ReferenceValueOrigin::Manual;
                    $data['label'] = filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'];

                    return $data;
                }),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DatasetReferenceValueResource::resetToPsoAction(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
```

Six subclasses, each only:

```php
<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;

class RegionsRelationManager extends ReferenceValuesRelationManager
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::Region;
    }
}
```

`CustomersRelationManager`: `$relationship = 'customers'`, columns `name`, `city`, `region.label` (label "Region"), `status`, `tasks_count` (`->counts('tasks')`), record action `Action::make('open')->url(fn (Customer $record) => CustomerResource::getUrl('view', ['record' => $record]))`. No create here; customers are created from their own resource (Task 7).

- [ ] **Step 6: Open link from the environment page**

In `DatasetsRelationManager::table()`, add before `DeleteAction::make()`:

```php
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(static fn (Dataset $record): string => DatasetResource::getUrl('view', ['record' => $record])),
```

with the matching imports.

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/DatasetSyncActionTest.php tests/Feature/DatasetReferenceValueResourceTest.php`
Expected: PASS. Open a dataset in the browser: six tabs plus Customers, the sync button, the summary section.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/DatasetResource.php app/Filament/Resources/DatasetResource app/Filament/Resources/EnvironmentResource/RelationManagers/DatasetsRelationManager.php resources/views tests/Feature/DatasetSyncActionTest.php
git commit -m "feat: dataset page with Sync from PSO, sync summary and reference value tabs"
```

---

### Task 7: Customer and Task forms on the dataset

**Files:**
- Modify: `app/Models/Customer.php` (`getForm()`), `app/Models/Task.php` (`getForm()`)
- Modify: `app/Filament/Resources/CustomerResource.php`, `CustomerResource/Pages/CreateCustomer.php`, `CustomerResource/RelationManagers/TasksRelationManager.php`
- Modify: `app/Filament/Resources/TaskResource.php`, `TaskResource/Pages/CreateTask.php`
- Delete: `app/Filament/Resources/CreateUserOwnedResource.php` if nothing else uses it (`grep -rn CreateUserOwnedResource app`); Environment's create page may still use it, in which case keep it.
- Create: `app/Rules/ReferenceValueInDataset.php`
- Test: `tests/Feature/CustomerDatasetScopingTest.php`

**Interfaces:**
- Consumes: `Dataset::ownedByCurrentUser()`, `DatasetReferenceValue::ofType()`, `DatasetReferenceValueResource::formSchema($type, lockDataset: true)`.
- Produces: `ReferenceValueInDataset(string|null $datasetId, ReferenceEntityType $type)` validation rule; a static helper `DatasetReferenceValue::optionsFor(?string $datasetId, ReferenceEntityType $type): array` returning `[uuid => "label (pso_id)"]` for selects that store the row id, and `DatasetReferenceValue::psoIdOptionsFor(?string $datasetId, ReferenceEntityType $type): array` returning `[pso_id => "label (pso_id)"]` for selects that store the PSO id (Task 8).

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->otherDataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

function customerForm(array $overrides = []): array
{
    return array_replace([
        'name' => 'Jane Test', 'status' => 'ACTIVE',
        'address' => '1 Main St', 'city' => 'Regina', 'country' => 'Canada', 'postcode' => 'S4P',
    ], $overrides);
}

it('creates a customer in a dataset with a region from that dataset', function () {
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create();

    Livewire::test(CreateCustomer::class)
        ->fillForm(customerForm(['dataset_id' => $this->dataset->id, 'region_id' => $region->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::first()->dataset_id)->toBe($this->dataset->id);
});

it('refuses a region that belongs to another dataset', function () {
    $foreignRegion = DatasetReferenceValue::factory()->for($this->otherDataset)->create();

    Livewire::test(CreateCustomer::class)
        ->fillForm(customerForm(['dataset_id' => $this->dataset->id, 'region_id' => $foreignRegion->id]))
        ->call('create')
        ->assertHasFormErrors(['region_id']);
});

it('offers only the chosen dataset regions, labelled with their pso id', function () {
    $mine = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'NORTH', 'label' => 'North']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'FIBRE']);
    DatasetReferenceValue::factory()->for($this->otherDataset)->create(['pso_id' => 'SOUTH']);

    expect(DatasetReferenceValue::optionsFor($this->dataset->id, ReferenceEntityType::Region))->toBe([$mine->id => 'North (NORTH)'])
        ->and(DatasetReferenceValue::psoIdOptionsFor($this->dataset->id, ReferenceEntityType::Region))->toBe(['NORTH' => 'North (NORTH)'])
        ->and(DatasetReferenceValue::optionsFor(null, ReferenceEntityType::Region))->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/CustomerDatasetScopingTest.php`
Expected: FAIL.

- [ ] **Step 3: Option helpers on the model**

Add to `DatasetReferenceValue`:

```php
    /** [row uuid => "label (PSO ID)"] for selects that store the row. */
    public static function optionsFor(?string $datasetId, ReferenceEntityType $type): array
    {
        if (blank($datasetId)) {
            return [];
        }

        return static::query()->where('dataset_id', $datasetId)->ofType($type)->orderBy('label')->get()
            ->mapWithKeys(static fn (self $row): array => [$row->id => "{$row->label} ({$row->pso_id})"])
            ->all();
    }

    /** [PSO ID => "label (PSO ID)"] for selects whose value goes straight into a PSO request. */
    public static function psoIdOptionsFor(?string $datasetId, ReferenceEntityType $type): array
    {
        if (blank($datasetId)) {
            return [];
        }

        return static::query()->where('dataset_id', $datasetId)->ofType($type)->orderBy('label')->get()
            ->mapWithKeys(static fn (self $row): array => [$row->pso_id => "{$row->label} ({$row->pso_id})"])
            ->all();
    }
```

- [ ] **Step 4: The rule**

`app/Rules/ReferenceValueInDataset.php`:

```php
<?php

namespace App\Rules;

use App\Enums\ReferenceEntityType;
use App\Models\DatasetReferenceValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ReferenceValueInDataset implements ValidationRule
{
    public function __construct(private readonly ?string $datasetId, private readonly ReferenceEntityType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $matches = DatasetReferenceValue::query()
            ->whereKey($value)
            ->where('dataset_id', $this->datasetId)
            ->ofType($this->type)
            ->exists();

        if (! $matches) {
            $fail("The selected {$this->type->getLabel()} does not belong to the chosen dataset.");
        }
    }
}
```

- [ ] **Step 5: Customer form**

In `Customer::getForm()`, add as the first field of the "Customer Details" section:

```php
                    Forms\Components\Select::make('dataset_id')
                        ->label('Dataset')
                        ->options(static fn (): array => Dataset::ownedByCurrentUser()->with('environment')->get()
                            ->mapWithKeys(static fn (Dataset $dataset): array => [$dataset->id => "{$dataset->environment->name} / {$dataset->name}"])->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(static fn (Set $set) => $set('region_id', null))
                        ->disabledOn('edit')
                        ->dehydrated(),
```

and replace the region placeholder with:

```php
                    Forms\Components\Select::make('region_id')
                        ->label('Region')
                        ->options(static fn (Get $get): array => DatasetReferenceValue::optionsFor($get('dataset_id'), ReferenceEntityType::Region))
                        ->searchable()
                        ->disabled(static fn (Get $get): bool => blank($get('dataset_id')))
                        ->rules([static fn (Get $get) => new ReferenceValueInDataset($get('dataset_id'), ReferenceEntityType::Region)])
                        ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::Region, lockDataset: true))
                        ->createOptionUsing(static function (Get $get, array $data): string {
                            return DatasetReferenceValue::create([
                                'dataset_id' => $get('dataset_id'),
                                'entity_type' => ReferenceEntityType::Region,
                                'pso_id' => $data['pso_id'],
                                'label' => filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'],
                                'origin' => ReferenceValueOrigin::Manual,
                            ])->id;
                        }),
```

Imports: `App\Enums\ReferenceEntityType`, `App\Enums\ReferenceValueOrigin`, `App\Filament\Resources\DatasetReferenceValueResource`, `App\Rules\ReferenceValueInDataset`.

`CreateCustomer` extends `CreateRecord` (not `CreateUserOwnedResource`). In `CustomerResource::table()` add a `dataset.name` column (label "Dataset") and a `SelectFilter::make('dataset_id')` as in Task 5; change any `region.name` column to `region.label`.

- [ ] **Step 6: Task form**

`Task::getForm()` becomes:

```php
    public static function getForm(?string $datasetId = null): array
    {
        return [
            Forms\Components\Select::make('task_type_id')
                ->label('Task Type')
                ->required()
                ->searchable()
                ->options(static fn (Get $get, ?Task $record): array => DatasetReferenceValue::optionsFor(
                    $datasetId ?? $record?->dataset_id ?? Customer::query()->whereKey($get('customer_id'))->value('dataset_id'),
                    ReferenceEntityType::TaskType,
                ))
                ->rules([static fn (Get $get, ?Task $record) => new ReferenceValueInDataset(
                    $datasetId ?? $record?->dataset_id ?? Customer::query()->whereKey($get('customer_id'))->value('dataset_id'),
                    ReferenceEntityType::TaskType,
                )]),
            Forms\Components\Select::make('status')->required()
                ->enum(TaskStatus::class)
                ->options(TaskStatus::class),
            Forms\Components\TextInput::make('duration')->label('Duration (minutes)')->numeric()->helperText('Blank uses the task type base duration.'),
        ];
    }
```

`TaskResource::form()` prepends a `Select::make('customer_id')->relationship('customer', 'name')->required()->live()` before `Task::getForm()`, and `CreateTask` extends `CreateRecord`. `TasksRelationManager` (on Customer) uses `Task::getForm($this->getOwnerRecord()->dataset_id)` and its `CreateAction` mutates `$data['dataset_id'] = $this->getOwnerRecord()->dataset_id` and `$data['customer_id'] = $this->getOwnerRecord()->id`; replace its `taskType.name` column with `taskType.label`.

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/CustomerDatasetScopingTest.php` then `php artisan test`.
Expected: PASS. Check the Customer create form in the browser: picking a dataset enables Region.

- [ ] **Step 8: Commit**

```bash
git add -A app tests/Feature/CustomerDatasetScopingTest.php
git commit -m "feat: customers and tasks pick a dataset; regions and task types come from it"
```

---

### Task 8: Consumers: appointment booking and activity generator read the dataset

**Files:**
- Modify: `app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php`
- Modify: `app/Filament/Pages/Activity/GenerateActivities.php`
- Test: `tests/Feature/AppointmentBookingPayloadTest.php`, `tests/Unit/GenerateActivitiesPayloadTest.php`

**Interfaces:**
- Consumes: `DatasetReferenceValue::psoIdOptionsFor()`, `DatasetReferenceValueResource::formSchema($type, lockDataset: true)`, `Task::dataset_id`, `FormTrait::$selectedEnvironment` / `$selectedDataset` (dataset **name**) on the activity page.
- Produces: `AppointmentBooking::setDataPayload()` sends `activityTypeId` = task type `pso_id`, `slaTypeId` from the form (default `Appointment` when that SLA type exists in the dataset), `appointmentTemplateId` and `slotUsageRuleId` as PSO ids. `GenerateActivities::currentDataset(): ?Dataset`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AppointmentBookingPayloadTest.php`:

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\TaskResource\Pages\AppointmentBooking;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);

    $this->taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL', 'label' => 'Install job']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'STANDARD']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlotUsageRule, 'pso_id' => 'AM']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment']);
    DatasetReferenceValue::factory()->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'OTHER-DATASET']);

    $customer = Customer::factory()->for($this->dataset)->create(['lat' => 50.4, 'long' => -104.6]);
    $this->task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);
});

it('offers templates, slot usage rules and sla types from the task dataset only, keyed by pso id', function () {
    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()]);

    expect($component->instance()->templateOptions())->toBe(['STANDARD' => 'STANDARD (STANDARD)'])
        ->and($component->instance()->slotUsageRuleOptions())->toHaveKey('AM')
        ->and($component->instance()->slaTypeOptions())->toHaveKey('Appointment');
});

it('defaults the sla type to Appointment when the dataset has it', function () {
    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSet('task_data.slaTypeId', 'Appointment');
});

it('builds the payload with pso ids, never row uuids', function () {
    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->set('task_data.slotUsageRuleId', 'AM')
        ->set('task_data.slaTypeId', 'Appointment')
        ->set('task_data.sla_start', '2026-10-10T08:00');

    $payload = callProtectedMethod($component->instance(), 'setDataPayload');

    expect($payload['data']['activityTypeId'])->toBe('INSTALL')
        ->and($payload['data']['appointmentTemplateId'])->toBe('STANDARD')
        ->and($payload['data']['slotUsageRuleId'])->toBe('AM')
        ->and($payload['data']['slaTypeId'])->toBe('Appointment')
        ->and($payload['data']['priority'])->toBe(5)
        ->and(json_encode($payload))->not->toContain($this->taskType->id);
});
```

`tests/Unit/GenerateActivitiesPayloadTest.php`:

```php
<?php

use App\Filament\Pages\Activity\GenerateActivities;

it('sends skills and regions as flat lists of pso ids', function () {
    $page = new GenerateActivities;
    $page->activity_data = [
        'activity_type_id' => 'INSTALL', 'sla_type_id' => 'Appointment', 'base_value' => 3000, 'duration' => 60, 'priority' => 5,
        'latitude' => 50.4, 'longitude' => -104.6,
        'skills' => ['FIBRE', '', 'COPPER'], 'regions' => ['NORTH'],
        'relative_day' => 0, 'relative_day_end' => 7,
    ];

    $payload = callProtectedMethod($page, 'generateActivitiesPayload');

    expect(data_get($payload, 'data.skills'))->toBe(['FIBRE', 'COPPER'])
        ->and(data_get($payload, 'data.regions'))->toBe(['NORTH']);
});
```

Read `FormTrait::buildPayload()` first to confirm the payload key path (`data.skills` or top level) and adjust the assertion to the real shape.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/AppointmentBookingPayloadTest.php tests/Unit/GenerateActivitiesPayloadTest.php`
Expected: FAIL.

- [ ] **Step 3: AppointmentBooking**

Remove the `use App\Models\AppointmentTemplate;` and `use App\Models\SlotUsageRule;` imports. Add imports for `App\Enums\ReferenceEntityType`, `App\Enums\ReferenceValueOrigin`, `App\Filament\Resources\DatasetReferenceValueResource`, `App\Models\DatasetReferenceValue`.

Add public option methods:

```php
    public function templateOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::AppointmentTemplate);
    }

    public function slotUsageRuleOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::SlotUsageRule);
    }

    public function slaTypeOptions(): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->record->dataset_id, ReferenceEntityType::SlaType);
    }

    private function createManualReferenceValue(ReferenceEntityType $type, array $data): string
    {
        return DatasetReferenceValue::create([
            'dataset_id' => $this->record->dataset_id,
            'entity_type' => $type,
            'pso_id' => $data['pso_id'],
            'label' => filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'],
            'origin' => ReferenceValueOrigin::Manual,
        ])->pso_id;
    }
```

In `mount()` (where `sla_start` is defaulted), add:

```php
        if (array_key_exists('Appointment', $this->slaTypeOptions())) {
            $this->task_data['slaTypeId'] = 'Appointment';
        }
```

Replace the two selects in `taskForm()` with three:

```php
                        Select::make('appointmentTemplateId')
                            ->label('Appointment Template')
                            ->options(fn (): array => $this->templateOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::AppointmentTemplate, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::AppointmentTemplate, $data))
                            ->createOptionModalHeading('Create Template')
                            ->required(),
                        Select::make('slotUsageRuleId')
                            ->label('Slot Usage Rule')
                            ->options(fn (): array => $this->slotUsageRuleOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::SlotUsageRule, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::SlotUsageRule, $data))
                            ->createOptionModalHeading('Create new Slot Usage Rule'),
                        Select::make('slaTypeId')
                            ->label('SLA Type')
                            ->options(fn (): array => $this->slaTypeOptions())
                            ->searchable()
                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::SlaType, lockDataset: true))
                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::SlaType, $data))
                            ->createOptionModalHeading('Create SLA Type')
                            ->required(),
```

In `setDataPayload()`: `'priority' => data_get($this->record, 'taskType.attributes.priority')`, `'activityTypeId' => data_get($this->record, 'taskType.pso_id')`, `'slaTypeId' => data_get($this->task_data, 'slaTypeId')`; and the slot usage block becomes `if (data_get($this->task_data, 'slotUsageRuleId')) { $data['data']['slotUsageRuleId'] = data_get($this->task_data, 'slotUsageRuleId'); }` (the existing `Arr::add($data, ...)` discards its return value, so the rule was never sent; this fixes that too). Also change `'taskType.name'` in the infolist heading to `'taskType.label'`.

- [ ] **Step 4: GenerateActivities**

Add:

```php
    public function currentDataset(): ?Dataset
    {
        return $this->selectedEnvironment?->datasets->firstWhere('name', $this->selectedDataset);
    }

    private function referenceOptions(ReferenceEntityType $type): array
    {
        return DatasetReferenceValue::psoIdOptionsFor($this->currentDataset()?->id, $type);
    }

    private function createManualReferenceValue(ReferenceEntityType $type, array $data): string
    {
        $dataset = $this->currentDataset();
        if ($dataset === null) {
            return $data['pso_id'];
        }

        return DatasetReferenceValue::create([
            'dataset_id' => $dataset->id,
            'entity_type' => $type,
            'pso_id' => $data['pso_id'],
            'label' => filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'],
            'origin' => ReferenceValueOrigin::Manual,
        ])->pso_id;
    }
```

Replace the `activity_type_id` and `sla_type_id` `TextInput`s with `Select`s using `->options(fn () => $this->referenceOptions(ReferenceEntityType::TaskType))` / `SlaType`, `->searchable()`, `->required()`, `->createOptionForm(DatasetReferenceValueResource::formSchema($type, lockDataset: true))`, `->createOptionUsing(fn (array $data) => $this->createManualReferenceValue($type, $data))`, `->hint(fn () => $this->currentDataset() ? null : 'Pick an environment and dataset above to see its values.')`. Keep the labels and prefix icons.

Replace the two repeaters:

```php
                                Forms\Components\Repeater::make('skills')
                                    ->simple(
                                        Select::make('skill')
                                            ->options(fn (): array => $this->referenceOptions(ReferenceEntityType::Skill))
                                            ->searchable()
                                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::Skill, lockDataset: true))
                                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::Skill, $data))
                                    )->addActionLabel('Add Skill')->defaultItems(0),
                                Forms\Components\Repeater::make('regions')
                                    ->simple(
                                        Select::make('region')
                                            ->options(fn (): array => $this->referenceOptions(ReferenceEntityType::Region))
                                            ->searchable()
                                            ->createOptionForm(DatasetReferenceValueResource::formSchema(ReferenceEntityType::Region, lockDataset: true))
                                            ->createOptionUsing(fn (array $data): string => $this->createManualReferenceValue(ReferenceEntityType::Region, $data))
                                    )->addActionLabel('Add Region')->defaultItems(0),
```

In `generateActivitiesPayload()`, a simple repeater's state is a flat list, so `pluck('skill')` always produced nulls and skills were never sent. Replace the two lines with:

```php
        $skills = collect($this->activity_data['skills'] ?? [])->filter()->values()->all();
        $regions = collect($this->activity_data['regions'] ?? [])->filter()->values()->all();
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/AppointmentBookingPayloadTest.php tests/Unit/GenerateActivitiesPayloadTest.php`
Expected: PASS. Then `php artisan test` for the suite; remove any `->skip('rebuilt in Task N')` markers left from Task 3.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/TaskResource/Pages/AppointmentBooking.php app/Filament/Pages/Activity/GenerateActivities.php tests/Feature/AppointmentBookingPayloadTest.php tests/Unit/GenerateActivitiesPayloadTest.php
git commit -m "feat: appointment booking and activity generator read ids from the dataset"
```

---

### Task 9: Golden check against a real export, README, final sweep

**Files:**
- Create: `tests/Feature/ReferenceValuesGoldenTest.php`
- Modify: `README.md`

- [ ] **Step 1: Golden test, skipped unless a sample is present**

The parser lives in pso-services, so this repo's golden check runs the upserter on a JSON file produced by the endpoint (or by hand from a LOAD) and asserts counts against a sidecar file. Both files live outside the repo (they carry customer identifiers):

```php
<?php

use App\Enums\ReferenceEntityType;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;

it('upserts a real dataset export to the expected counts', function () {
    $samplePath = env('REFERENCE_VALUES_SAMPLE_JSON');
    if (! $samplePath || ! is_file($samplePath)) {
        $this->markTestSkipped('Set REFERENCE_VALUES_SAMPLE_JSON to a pso-services reference-values response saved to disk.');
    }
    $expectedPath = preg_replace('/\.json$/', '.expected.json', $samplePath);
    if (! is_file($expectedPath)) {
        $this->markTestSkipped("Missing {$expectedPath} with per-type expected counts.");
    }

    $tables = json_decode(file_get_contents($samplePath), true)['data']['tables'];
    $expected = json_decode(file_get_contents($expectedPath), true);
    $dataset = Dataset::factory()->create();

    app(ReferenceValuesUpserter::class)->apply($dataset, $tables, CarbonImmutable::now());

    foreach (ReferenceEntityType::cases() as $type) {
        expect(DatasetReferenceValue::withoutGlobalScopes()->where('dataset_id', $dataset->id)->ofType($type)->count())
            ->toBe($expected[$type->value], $type->value);
    }
});
```

To produce the sample once pso-services is deployed: `curl` the endpoint with the headers from the pso-services plan and save the body as `~/pso-data/SATE/reference-values.json`, then write `reference-values.expected.json` with the six counts by eye from PSO.

- [ ] **Step 2: README**

Add a section after "PSO Sys File Compare":

```markdown
## Base Data and datasets

Regions, skills, task types, slot usage rules, appointment templates and SLA types are stored per **dataset**
(an environment can hold several), in one table, and are what the request-building pages offer in their
selects. Open a dataset (Base Data > Datasets, or from its environment) and press **Sync from PSO** to pull
them from the PSO gateway through pso-services (`GET /api/v2/dataset/reference-values`). The gateway returns
the whole dataset body, so a sync takes up to a few minutes on a large dataset and runs on the queue; a queue
worker must be running. Rows you edit keep your values on later syncs (badge "Edited"; "Reset to PSO" hands
them back). Rows PSO stops returning are flagged "Missing from PSO" and are never deleted automatically.
Customers and their tasks belong to a dataset too, and only see that dataset's regions and task types.

Task type rows carry priority, base value and base duration (minutes) as fallbacks for tasks that do not set
their own, following PSO's precedence: Activity_Status, then Activity, then Activity_Type.
```

- [ ] **Step 3: Full suite, migrate fresh, browse**

Run: `php artisan test` (expect PASS), then on the dev database `php artisan migrate` (the migration drops and recreates the tables; the dev data is the "old trashy data" and may go), then click through: Base Data entries, a dataset's Sync button (with pso-services running locally), a customer with a region, a task's appointment booking page.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/ReferenceValuesGoldenTest.php README.md
git commit -m "docs: base data and datasets; golden check for the reference values upsert"
```

Then open a pull request from `feat/dataset-reference-values` with the spec summary as the body.
