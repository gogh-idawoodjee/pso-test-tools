<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Enums\TaskStatus;
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
    $user = User::factory()->create();
    $this->actingAs($user);
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create();
    $taskType = DatasetReferenceValue::factory()->for($dataset)->create([
        'entity_type' => ReferenceEntityType::TaskType,
        'pso_id' => 'INSTALL',
        'label' => 'Install',
        'attributes' => ['priority' => 5, 'base_value' => 1500, 'base_duration' => 90],
    ]);
    $customer = Customer::factory()->for($dataset)->create();

    $task = Task::create(['customer_id' => $customer->id, 'task_type_id' => $taskType->id, 'status' => TaskStatus::IGNORE]);

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
