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
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'STANDARD', 'label' => 'Standard']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlotUsageRule, 'pso_id' => 'AM']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment']);
    DatasetReferenceValue::factory()->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'OTHER-DATASET']);

    $customer = Customer::factory()->for($this->dataset)->create(['lat' => 50.4, 'long' => -104.6]);
    $this->task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);
});

it('offers templates, slot usage rule sets and sla types from the task dataset only, keyed by pso id', function () {
    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()]);

    expect($component->instance()->templateOptions())->toBe(['STANDARD' => 'Standard (STANDARD)'])
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
