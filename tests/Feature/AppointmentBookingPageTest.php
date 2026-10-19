<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\AppointmentBooking;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\PsoServicesFake;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'America/Toronto'));

    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['password' => Crypt::encryptString('secret')]);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['settings' => [
        'time_zone' => 'America/Toronto',
        'appointment_sla_type_id' => 'Appointment',
        'default_booking_sla_type_id' => 'Appointment',
    ]]);
    $this->actingAs($this->user);

    $this->taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL', 'label' => 'Install job']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'STANDARD', 'label' => 'Standard']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlotUsageRule, 'pso_id' => 'AM']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment']);
    DatasetReferenceValue::factory()->create(['entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'OTHER-DATASET']);

    $customer = Customer::factory()->for($this->dataset)->create(['lat' => 50.4, 'long' => -104.6]);
    $this->task = Task::factory()->for($customer)->create(['task_type_id' => $this->taskType->id]);
});

function bookingOffer(int $id, string $start, bool $valid = true): array
{
    $from = CarbonImmutable::parse($start);

    return [
        'id' => $id,
        'offerValue' => $valid ? 100 : 0,
        'available' => $valid,
        'prospectiveResourceId' => 'RES-1',
        'prospectiveAllocationStart' => $from->toIso8601String(),
        'windowStartDatetime' => $from->toIso8601String(),
        'windowEndDatetime' => $from->addHours(4)->toIso8601String(),
        'windowDayEnglish' => $from->format('D, M j, Y'),
        'windowStartTime' => $from->format('g:i A'),
        'windowEndTime' => $from->addHours(4)->format('g:i A'),
        'isBestOffer' => false,
    ];
}

function bookingOffersResponse(array $valid, array $invalid = [], string $requestId = 'REQ-1', bool $survey = false): array
{
    return ['data' => [
        'survey' => $survey,
        'appointmentOffers' => [
            'appointmentRequestId' => $requestId,
            'summary' => count($valid).' valid offers out of '.(count($valid) + count($invalid)).' returned.',
            'bestOffer' => $valid[0] ?? [],
            'validOffers' => $valid,
            'invalidOffers' => $invalid,
            'allOfferValues' => [...$valid, ...$invalid],
        ],
    ]];
}

/** The appointment endpoint calls only, without the session logons. */
function appointmentCalls(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/api/v2/appointment'))
        ->values()
        ->all();
}

it('offers templates, slot usage rule sets and sla types from the task dataset only, keyed by pso id', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse([], survey: true))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()]);

    expect($component->instance()->templateOptions())->toBe(['STANDARD' => 'Standard (STANDARD)'])
        ->and($component->instance()->slotUsageRuleOptions())->toHaveKey('AM')
        ->and($component->instance()->slaTypeOptions())->toHaveKey('Appointment');
});

it('shows the resolved appointment and booking sla types with where they came from', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse([], survey: true))]);

    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSee('Appointment SLA type')
        ->assertSee('Appointment (from the dataset)')
        ->assertSee('Booking SLA type');
});

it('builds the payload with pso ids, never row uuids', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse([], survey: true))]);

    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->set('task_data.slotUsageRuleId', 'AM')
        ->call('selectDay', '2026-10-22');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/api/v2/appointment') || data_get($request->data(), 'data.surveyTemplate')) {
            return false;
        }

        return data_get($request->data(), 'data.activityTypeId') === 'INSTALL'
            && data_get($request->data(), 'data.appointmentTemplateId') === 'STANDARD'
            && data_get($request->data(), 'data.slotUsageRuleId') === 'AM'
            && data_get($request->data(), 'data.slaTypeId') === 'Appointment'
            && data_get($request->data(), 'data.priority') === 5
            && ! str_contains($request->body(), $this->taskType->id);
    });
});

it('locks the environment and dataset to the task', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse([], survey: true))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSet('environment_data.environment_id', $this->environment->id)
        ->assertSet('environment_data.dataset_id', $this->dataset->name)
        ->assertSet('environment_data.send_to_pso', true)
        ->assertSet('selectedDataset', $this->dataset->name)
        ->assertFormFieldDisabled('environment_id', 'env_form')
        ->assertFormFieldDisabled('dataset_id', 'env_form');

    expect($component->instance()->selectedEnvironment->is($this->environment))->toBeTrue()
        ->and($component->instance()->environment_payload_data()['environment'])->toMatchArray([
            'datasetId' => $this->dataset->name,
            'baseUrl' => $this->environment->base_url,
            'accountId' => $this->environment->account_id,
            'sendToPso' => true,
        ]);
});

it('loads the survey on open and marks the days that have slots', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse(
        [bookingOffer(1, '2026-10-22T08:00:00-04:00'), bookingOffer(2, '2026-10-23T23:30:00-04:00')],
        [bookingOffer(3, '2026-10-24T08:00:00-04:00', valid: false)],
        survey: true,
    ))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()]);

    $states = $component->get('dayStates');
    expect($states)->toHaveCount(21)
        ->and(array_key_first($states))->toBe('2026-10-19')
        ->and(array_key_last($states))->toBe('2026-11-08')
        ->and($states['2026-10-22'])->toBe('slots')
        ->and($states['2026-10-23'])->toBe('slots')   // 23:30 Toronto is the 24th in UTC: the day is the dataset's
        ->and($states['2026-10-24'])->toBe('none')
        ->and($component->get('pageError'))->toBeNull()
        ->and($component->get('view'))->toBe('month');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/appointment')
        && data_get($request->data(), 'data.surveyTemplate') === true
        && data_get($request->data(), 'data.appointmentBaseDateTime') === '2026-10-19T04:00:00'
        && data_get($request->data(), 'environment.token') === 'tok-abc'
        && data_get($request->data(), 'environment.datasetId') === $this->dataset->name);
});

it('shows the services error and no dots when the survey fails', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['message' => 'The appointment template could not be found.'], 422)]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSee('The appointment template could not be found.');

    expect($component->get('pageError'))->toBe('The appointment template could not be found.')
        ->and(array_unique(array_values($component->get('dayStates'))))->toBe(['unknown'])
        ->and($component->get('dayStates'))->toHaveCount(21);
});

it('disables the request when the SLA types are not set', function () {
    Http::fake();
    $this->dataset->update(['settings' => ['time_zone' => 'America/Toronto']]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSee('Set the appointment SLA type');

    expect($component->get('pageError'))->toContain('Set the appointment SLA type');
    Http::assertNothingSent();

    $component->set('task_data.appointmentTemplateId', 'STANDARD')->call('selectDay', '2026-10-22');
    Http::assertNothingSent();
    expect($component->get('view'))->toBe('month');
});

it('requests the chosen day with the real template and opens the agenda', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([bookingOffer(1, '2026-10-22T08:00:00-04:00')], requestId: 'SURVEY-1', survey: true))
        ->push(bookingOffersResponse([bookingOffer(7, '2026-10-22T08:00:00-04:00')], requestId: 'REQ-22')),
    ]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->call('selectDay', '2026-10-22')
        ->assertSee('Back to month');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v2/appointment')
        && data_get($request->data(), 'data.appointmentTemplateDatetime') === '2026-10-22T04:00:00'
        && data_get($request->data(), 'data.appointmentTemplateId') === 'STANDARD'
        && ! data_get($request->data(), 'data.surveyTemplate') !== null);

    expect($component->get('view'))->toBe('agenda')
        ->and($component->get('selectedDay'))->toBe('2026-10-22')
        ->and($component->get('appointmentRequestId'))->toBe('REQ-22')
        ->and($component->get('offers.validOffers.0.id'))->toBe(7)
        ->and($component->get('countdownExpiresAt'))->toBe(now()->addMinutes(5)->toIso8601String())
        ->and($component->get('dayStates')['2026-10-22'])->toBe('slots');
});

it('releases the previous day\'s offers before requesting another day', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([], requestId: 'SURVEY-1', survey: true))
        ->push(bookingOffersResponse([bookingOffer(7, '2026-10-22T08:00:00-04:00')], requestId: 'REQ-22'))
        ->push(['data' => ['message' => 'Declined.']])
        ->push(bookingOffersResponse([bookingOffer(8, '2026-10-23T08:00:00-04:00')], requestId: 'REQ-23')),
    ]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->call('selectDay', '2026-10-22')
        ->call('selectDay', '2026-10-23');

    $calls = array_map(fn (Request $request) => $request->method().' '.parse_url($request->url(), PHP_URL_PATH), appointmentCalls());

    expect($calls)->toBe([
        'POST /api/v2/appointment',
        'POST /api/v2/appointment',
        'DELETE /api/v2/appointment/REQ-22',
        'POST /api/v2/appointment',
    ])
        ->and($component->get('appointmentRequestId'))->toBe('REQ-23');
});

it('shows the Rebook action on a booked task', function () {
    Livewire::test(EditTask::class, ['record' => $this->task->getRouteKey()])
        ->assertActionHasLabel('bookAppointment', 'Appointment booking')
        ->assertActionHasUrl('bookAppointment', TaskResource::getUrl('bookAppointment', ['record' => $this->task]));

    $this->task->forceFill(['appointment_request_id' => 'REQ-OLD'])->save();

    Livewire::test(EditTask::class, ['record' => $this->task->getRouteKey()])
        ->assertActionHasLabel('bookAppointment', 'Rebook');
});
