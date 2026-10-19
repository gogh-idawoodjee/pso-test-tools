<?php

use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\TasksRelationManager;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL', 'label' => 'Install']);
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => 'NORTH']);
    $this->customer = Customer::factory()->for($this->dataset)->create(['lat' => 43.6, 'long' => -79.3, 'region_id' => $region->id]);
});

it('creates a task from the customer page as a shell with no status to pick, and the table renders it as not booked', function () {
    expect(collect(Task::getForm($this->dataset->id))->map(fn ($field) => $field->getName())->all())->not->toContain('status');

    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
        ->callAction(TestAction::make('create')->table(), ['task_type_id' => $this->taskType->id])
        ->assertHasNoFormErrors()
        ->assertSee('Not booked');

    $task = Task::withoutGlobalScopes()->sole();
    expect($task->status)->toBe(TaskStatus::IGNORE)
        ->and($task->regions)->toBe(['NORTH'])
        ->and($task->duration)->toBe(90)
        ->and($task->friendly_id)->toStartWith('T-I_');
});
