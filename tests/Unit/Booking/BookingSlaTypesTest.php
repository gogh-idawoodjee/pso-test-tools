<?php

use App\Enums\ReferenceEntityType;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use App\Support\Booking\BookingSlaTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bookingSlaTask(array $settings = [], array $taskTypeAttributes = []): Task
{
    $user = User::factory()->create();
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create(['settings' => $settings]);
    test()->actingAs($user);

    foreach (['Appointment', 'Standard', 'Priority'] as $psoId) {
        DatasetReferenceValue::factory()->for($dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => $psoId]);
    }

    $taskType = DatasetReferenceValue::factory()->for($dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL']);
    if ($taskTypeAttributes !== []) {
        $taskType->update(['attributes' => array_merge($taskType->attributes, $taskTypeAttributes)]);
    }

    $customer = Customer::factory()->for($dataset)->create(['lat' => 43.65, 'long' => -79.38]);

    return Task::factory()->for($customer)->create(['task_type_id' => $taskType->id]);
}

it('uses the task type booking sla type over the dataset default', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask(
        ['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'Standard'],
        ['booking_sla_type_id' => 'Priority'],
    ));

    expect($sla->appointmentSlaTypeId)->toBe('Appointment')
        ->and($sla->appointmentSource)->toBe('dataset')
        ->and($sla->bookingSlaTypeId)->toBe('Priority')
        ->and($sla->bookingSource)->toBe('task type')
        ->and($sla->complete())->toBeTrue()
        ->and($sla->missingMessage())->toBeNull();
});

it('falls back to the dataset default when the task type has no booking sla type', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask(
        ['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'Standard'],
    ));

    expect($sla->bookingSlaTypeId)->toBe('Standard')
        ->and($sla->bookingSource)->toBe('dataset')
        ->and($sla->complete())->toBeTrue();
});

it('treats a task type sla type that no longer exists as unset', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask(
        ['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'Standard'],
        ['booking_sla_type_id' => 'Gone'],
    ));

    expect($sla->bookingSlaTypeId)->toBe('Standard')
        ->and($sla->bookingSource)->toBe('dataset');
});

it('reports both sla types missing in one message', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask());

    expect($sla->appointmentSlaTypeId)->toBeNull()
        ->and($sla->bookingSlaTypeId)->toBeNull()
        ->and($sla->appointmentSource)->toBe('missing')
        ->and($sla->bookingSource)->toBe('missing')
        ->and($sla->complete())->toBeFalse()
        ->and($sla->missingMessage())->toBe('Set the appointment SLA type on the dataset. Set a booking SLA type on the task type or the dataset.');
});

it('names only the missing appointment sla type', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask(['default_booking_sla_type_id' => 'Standard']));

    expect($sla->complete())->toBeFalse()
        ->and($sla->missingMessage())->toBe('Set the appointment SLA type on the dataset.');
});

it('names only the missing booking sla type', function () {
    $sla = BookingSlaTypes::for(bookingSlaTask(['appointment_sla_type_id' => 'Appointment']));

    expect($sla->complete())->toBeFalse()
        ->and($sla->missingMessage())->toBe('Set a booking SLA type on the task type or the dataset.');
});
