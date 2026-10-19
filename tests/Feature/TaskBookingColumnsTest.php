<?php

use App\Enums\ReferenceEntityType;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
    $this->region = DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => 'NORTH']);
    $this->taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL']);
});

it('defaults the task regions to the customer region pso id', function () {
    $customer = Customer::factory()->for($this->dataset)->create(['region_id' => $this->region->id]);
    $task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);

    expect($task->fresh()->regions)->toBe(['NORTH']);
});

it('keeps regions given on create', function () {
    $customer = Customer::factory()->for($this->dataset)->create(['region_id' => $this->region->id]);
    $task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id, 'regions' => ['SOUTH']]);

    expect($task->fresh()->regions)->toBe(['SOUTH']);
});

it('stores skills as pso ids', function () {
    $customer = Customer::factory()->for($this->dataset)->create();
    $task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id, 'skills' => ['FIBRE', 'COPPER']]);

    expect($task->fresh()->skills)->toBe(['FIBRE', 'COPPER']);
});

it('is booked once it has an appointment request id', function () {
    $customer = Customer::factory()->for($this->dataset)->create();
    $task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);

    expect($task->isBooked())->toBeFalse();

    $task->update(['appointment_request_id' => 'REQ-1', 'booked_at' => now()]);

    expect($task->fresh()->isBooked())->toBeTrue()->and($task->fresh()->booked_at)->not->toBeNull();
});
