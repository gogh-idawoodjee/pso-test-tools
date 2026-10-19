<?php

use App\Enums\ReferenceEntityType;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use App\Support\Booking\BookingPayloads;
use App\Support\Booking\BookingSlaTypes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($user))->create(['settings' => [
        'time_zone' => 'America/Toronto',
        'appointment_sla_type_id' => 'Appointment',
        'default_booking_sla_type_id' => 'Standard',
    ]]);

    foreach (['Appointment', 'Standard'] as $psoId) {
        DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => $psoId]);
    }

    $this->taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL']);
    $this->customer = Customer::factory()->for($this->dataset)->create(['lat' => 43.65, 'long' => -79.38]);
    $this->task = Task::factory()->for($this->customer)->create([
        'task_type_id' => $this->taskType->id,
        'regions' => ['TORONTO'],
        'skills' => ['FIBRE', 'COPPER'],
        'base_value' => 1500.75,
        'duration' => 90,
    ]);

    $this->payloads = new BookingPayloads($this->task, BookingSlaTypes::for($this->task));
    $this->baseDay = CarbonImmutable::parse('2026-10-22', 'America/Toronto');
});

it('sends appointmentBaseDateTime as UTC midnight of the base day without an offset', function () {
    $data = $this->payloads->survey($this->baseDay)['data'];

    expect($data['appointmentBaseDateTime'])->toBe('2026-10-22T04:00:00')
        ->and($data['appointmentTemplateDatetime'])->toBe('2026-10-22T04:00:00')
        ->and($data['timezone'])->toBe('America/Toronto');
});

it('sends the sla window as 21 days from the base day', function () {
    $data = $this->payloads->day($this->baseDay, 'STANDARD', null, null, null)['data'];

    expect($data['slaStart'])->toBe('2026-10-22T04:00:00')
        ->and($data['slaEnd'])->toBe('2026-11-12T05:00:00')
        ->and($data['appointmentTemplateDuration'])->toBe(21);
});

it('builds a survey request without a template id', function () {
    $data = $this->payloads->survey($this->baseDay)['data'];

    expect($data)->not->toHaveKey('appointmentTemplateId')
        ->and($data['surveyTemplate'])->toBeTrue()
        ->and($data['activityId'])->toBe($this->task->friendly_id)
        ->and($data['activityTypeId'])->toBe('INSTALL')
        ->and($data['duration'])->toBe(90)
        ->and($data['baseValue'])->toBe(1501)
        ->and($data['priority'])->toBe(5)
        ->and($data['lat'])->toBe(43.65)
        ->and($data['long'])->toBe(-79.38)
        ->and($data['regions'])->toBe(['TORONTO'])
        ->and($data['skills'])->toBe(['FIBRE', 'COPPER'])
        ->and($data['slaTypeId'])->toBe('Appointment');
});

it('builds a day request with template, rule set, blocking and input datetime only when given', function () {
    $bare = $this->payloads->day($this->baseDay, 'STANDARD', null, null, null)['data'];

    expect($bare['appointmentTemplateId'])->toBe('STANDARD')
        ->and($bare)->not->toHaveKeys(['slotUsageRuleId', 'blocking', 'inputDatetime', 'surveyTemplate']);

    $full = $this->payloads->day($this->baseDay, 'STANDARD', 'AM', false, '2026-10-21T13:30:00')['data'];

    expect($full['slotUsageRuleId'])->toBe('AM')
        ->and($full['blocking'])->toBeFalse()
        ->and($full['inputDatetime'])->toBe('2026-10-21T13:30:00');
});

it('sends empty lists when the task has no regions or skills', function () {
    $task = Task::factory()->for($this->customer)->create(['task_type_id' => $this->taskType->id, 'regions' => null, 'skills' => null]);
    $data = (new BookingPayloads($task, BookingSlaTypes::for($task)))->survey($this->baseDay)['data'];

    expect($data['regions'])->toBe([])
        ->and($data['skills'])->toBe([]);
});

it('never contains a reference value uuid', function () {
    $json = json_encode([
        $this->payloads->survey($this->baseDay),
        $this->payloads->day($this->baseDay, 'STANDARD', 'AM', true, null),
        $this->payloads->accept(42, 'OLD'),
    ]);

    $uuids = DatasetReferenceValue::withoutGlobalScopes()->pluck('id')->push($this->task->id, $this->customer->id, $this->dataset->id);

    foreach ($uuids as $uuid) {
        expect($json)->not->toContain($uuid);
    }
});

it('refuses to build a request without coordinates', function () {
    $customer = Customer::factory()->for($this->dataset)->create(['lat' => null, 'long' => -79.38]);
    $task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);

    expect(fn () => (new BookingPayloads($task, BookingSlaTypes::for($task)))->survey($this->baseDay))
        ->toThrow(RuntimeException::class, 'The customer has no coordinates.');
});

it('builds accept and check payloads', function () {
    expect($this->payloads->accept(42, null))->toBe(['data' => ['appointmentOfferId' => 42, 'bookingSlaTypeId' => 'Standard']])
        ->and($this->payloads->accept(42, 'OLD'))->toBe(['data' => ['appointmentOfferId' => 42, 'bookingSlaTypeId' => 'Standard', 'previousSlaTypeId' => 'OLD']])
        ->and($this->payloads->check(42))->toBe(['data' => ['appointmentOfferId' => 42]]);
});
