<?php

use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
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
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
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

it('renders first and leaves the survey to wire:init', function () {
    Http::fake();

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->assertSeeHtml('wire:init="loadSurvey"')
        ->assertSee('Asking PSO for slots');

    Http::assertNothingSent();
    expect($component->get('dayStates'))->toHaveCount(21)
        ->and(array_unique(array_values($component->get('dayStates'))))->toBe(['unknown'])
        ->and($component->get('screen'))->toBe('month')
        ->and($component->get('pageError'))->toBeNull()
        ->and($component->html())->not->toMatch('/wire:click="selectDay\(\'2026-10-22\'\)"[^>]*\sdisabled[\s>]/');
});

it('loads the survey on open and marks the days that have slots', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse(
        [bookingOffer(1, '2026-10-22T08:00:00-04:00'), bookingOffer(2, '2026-10-23T23:30:00-04:00')],
        [bookingOffer(3, '2026-10-24T08:00:00-04:00', valid: false)],
        survey: true,
    ))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])->call('loadSurvey');

    $states = $component->get('dayStates');
    expect($states)->toHaveCount(21)
        ->and(array_key_first($states))->toBe('2026-10-19')
        ->and(array_key_last($states))->toBe('2026-11-08')
        ->and($states['2026-10-22'])->toBe('slots')
        ->and($states['2026-10-23'])->toBe('slots')   // 23:30 Toronto is the 24th in UTC: the day is the dataset's
        ->and($states['2026-10-24'])->toBe('none')
        ->and($component->get('pageError'))->toBeNull()
        ->and($component->get('screen'))->toBe('month');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/appointment')
        && data_get($request->data(), 'data.surveyTemplate') === true
        && data_get($request->data(), 'data.appointmentBaseDateTime') === '2026-10-19T04:00:00'
        && data_get($request->data(), 'environment.token') === 'tok-abc'
        && data_get($request->data(), 'environment.datasetId') === $this->dataset->name);
});

it('shows the services error and no dots when the survey fails', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['message' => 'The appointment template could not be found.'], 422)]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('loadSurvey')
        ->assertSee('The appointment template could not be found.');

    expect($component->get('pageError'))->toBe('The appointment template could not be found.')
        ->and(array_unique(array_values($component->get('dayStates'))))->toBe(['unknown'])
        ->and($component->get('dayStates'))->toHaveCount(21);
});

it('disables the request when the SLA types are not set', function () {
    Http::fake();
    $this->dataset->update(['settings' => ['time_zone' => 'America/Toronto']]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('loadSurvey')
        ->assertSee('Set the appointment SLA type');

    expect($component->get('pageError'))->toContain('Set the appointment SLA type')
        ->and($component->html())->toMatch('/wire:click="selectDay\(\'2026-10-22\'\)"[^>]*\sdisabled[\s>]/');
    Http::assertNothingSent();

    $component->set('task_data.appointmentTemplateId', 'STANDARD')->call('selectDay', '2026-10-22');
    Http::assertNothingSent();
    expect($component->get('screen'))->toBe('month');
});

it('requests the chosen day with the real template and opens the agenda', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([bookingOffer(1, '2026-10-22T08:00:00-04:00')], requestId: 'SURVEY-1', survey: true))
        ->push(bookingOffersResponse([bookingOffer(7, '2026-10-22T08:00:00-04:00')], requestId: 'REQ-22')),
    ]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('loadSurvey')
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->call('selectDay', '2026-10-22')
        ->assertSee('Back to month');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v2/appointment')
        && data_get($request->data(), 'data.appointmentTemplateDatetime') === '2026-10-22T04:00:00'
        && data_get($request->data(), 'data.appointmentTemplateId') === 'STANDARD'
        && ! data_get($request->data(), 'data.surveyTemplate'));

    expect($component->get('screen'))->toBe('agenda')
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
        ->call('loadSurvey')
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

/**
 * Fakes the appointment endpoints by method: a POST without an offer id is a request (offers), a POST with one is
 * the check, PATCH is the accept, DELETE the decline.
 */
function appointmentEndpointFake(mixed $slotAvailable = true, ?int $acceptStatus = null, int $deleteStatus = 200): array
{
    $offers = bookingOffersResponse(
        [
            [...bookingOffer(7, '2026-10-22T08:00:00-04:00'), 'isBestOffer' => true],
            bookingOffer(8, '2026-10-22T13:00:00-04:00'),
        ],
        [bookingOffer(9, '2026-10-22T16:00:00-04:00', valid: false)],
        requestId: 'REQ-22',
    );
    // allOfferValues carries only these five keys, its window times UTC without an offset (as services sends them).
    $offers['data']['appointmentOffers']['allOfferValues'] = array_map(
        static fn (array $offer): array => [
            'id' => $offer['id'],
            'offerValue' => $offer['offerValue'],
            'windowStartDatetime' => CarbonImmutable::parse($offer['windowStartDatetime'])->utc()->format('Y-m-d\TH:i:s'),
            'windowEndDatetime' => CarbonImmutable::parse($offer['windowEndDatetime'])->utc()->format('Y-m-d\TH:i:s'),
            'prospectiveResourceId' => $offer['prospectiveResourceId'],
        ],
        $offers['data']['appointmentOffers']['allOfferValues'],
    );

    return [...PsoServicesFake::session(), '*/api/v2/appointment*' => static function (Request $request) use ($offers, $slotAvailable, $acceptStatus, $deleteStatus) {
        return match ($request->method()) {
            'PATCH' => $acceptStatus !== null ? Http::response(['message' => $acceptStatus === 406 ? 'The appointment request is no longer open.' : 'PSO could not accept the offer.'], $acceptStatus) : Http::response(['data' => ['acceptedAppointmentSummary' => [
                'appointmentRequestId' => 'REQ-22',
                'activityId' => 'ACT-1',
                'resourceId' => 'RES-1',
                'assignmentStart' => '2026-10-22T12:00:00',
                'assignmentFinish' => '2026-10-22T13:30:00',
                'selectedDate' => '2026-10-22',
                'selectedWindow' => '8:00 AM - 12:00 PM',
                'bookingSlaTypeId' => 'Appointment',
                'previousSlaTypeId' => data_get($request->data(), 'data.previousSlaTypeId'),
                'temporaryActivityDeleted' => true,
            ]]]),
            'DELETE' => $deleteStatus === 200 ? Http::response(['data' => ['message' => 'Declined.']]) : Http::response(['error' => 'Internal server error.'], $deleteStatus),
            default => data_get($request->data(), 'data.appointmentOfferId') !== null
                ? Http::response(['data' => ['appointment_summary' => ['isSlotAvailable' => $slotAvailable]]])
                : Http::response($offers),
        };
    }];
}

function openAgenda(Task $task, ?bool $blocking = null): Testable
{
    return Livewire::test(AppointmentBooking::class, ['record' => $task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->set('blockingOverride', $blocking)
        ->call('selectDay', '2026-10-22');
}

/** Method and path of the appointment calls after the day request. */
function callsAfterDayRequest(): array
{
    return array_slice(array_map(fn (Request $request) => $request->method().' '.parse_url($request->url(), PHP_URL_PATH), appointmentCalls()), 1);
}

it('accepts straight away on a blocking request', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->call('book', 7);

    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('booked')
        ->and($component->get('booking.activityId'))->toBe('ACT-1');

    // Assignment 12:00 to 13:30 UTC shows in the dataset zone, like the window.
    $component->assertSee('ACT-1')
        ->assertSee('Thursday October 22nd 08:00-12:00')
        ->assertSee('Thursday October 22nd 08:00-09:30')
        ->assertDontSee('2026-10-22T12:00:00')
        ->assertDontSeeHtml('wire:click="book(');

    $task = $this->task->fresh();
    expect($task->appointment_request_id)->toBe('REQ-22')
        ->and($task->booking_sla_type_id)->toBe('Appointment')
        ->and($task->booked_at)->not->toBeNull()
        ->and($task->status)->toBe(TaskStatus::UNALLOCATED)
        ->and($task->appt_window_start->utc()->toDateTimeString())->toBe('2026-10-22 12:00:00')
        ->and($task->appt_window_finish->utc()->toDateTimeString())->toBe('2026-10-22 16:00:00');
});

it('shows the booked window in the dataset zone when it differs from the app zone', function () {
    config(['app.timezone' => 'America/Toronto']);
    $this->dataset->update(['settings' => [
        'time_zone' => 'America/Regina',
        'appointment_sla_type_id' => 'Appointment',
        'default_booking_sla_type_id' => 'Appointment',
    ]]);

    // Offsetless window times are UTC: 14:00 to 18:00 UTC is 08:00 to 12:00 in Regina (UTC-6, no DST).
    $offer = [...bookingOffer(7, '2026-10-22T14:00:00Z'), 'windowStartDatetime' => '2026-10-22T14:00:00', 'windowEndDatetime' => '2026-10-22T18:00:00'];
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => static fn (Request $request) => $request->method() === 'PATCH'
        ? Http::response(['data' => ['acceptedAppointmentSummary' => [
            'appointmentRequestId' => 'REQ-22',
            'activityId' => 'ACT-1',
            'assignmentStart' => '2026-10-22T14:00:00',
            'assignmentFinish' => '2026-10-22T15:30:00',
            'bookingSlaTypeId' => 'Appointment',
        ]]])
        : Http::response(bookingOffersResponse([$offer], requestId: 'REQ-22')),
    ]);

    $component = openAgenda($this->task)->call('book', 7);

    expect($component->get('screen'))->toBe('booked');
    $component->assertSee('Thursday October 22nd 08:00-12:00')
        ->assertSee('Thursday October 22nd 08:00-09:30')
        ->assertDontSee('Thursday October 22nd 10:00-14:00');

    $task = $this->task->fresh();
    expect($task->appt_window_start->utc()->toDateTimeString())->toBe('2026-10-22 14:00:00')
        ->and($task->appt_window_finish->utc()->toDateTimeString())->toBe('2026-10-22 18:00:00');
});

it('checks first on a non-blocking request and books when appointed', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task, blocking: false)->call('book', 8);

    expect(callsAfterDayRequest())->toBe(['POST /api/v2/appointment/REQ-22', 'PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('booked');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/REQ-22')
        && data_get($request->data(), 'data.appointmentOfferId') === 8);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && data_get($request->data(), 'data.appointmentOfferId') === 8
        && data_get($request->data(), 'data.bookingSlaTypeId') === 'Appointment'
        && ! array_key_exists('previousSlaTypeId', (array) data_get($request->data(), 'data')));
});

it('does not book when the check says the slot is taken', function () {
    Http::fake(appointmentEndpointFake(slotAvailable: false));

    $component = openAgenda($this->task, blocking: false)->call('book', 8);

    expect(callsAfterDayRequest())->toBe(['POST /api/v2/appointment/REQ-22'])
        ->and($component->get('slotMessage'))->toBe('That slot was taken, pick another')
        ->and($component->get('screen'))->toBe('agenda')
        ->and($this->task->fresh()->appointment_request_id)->toBeNull();

    $component->assertSee('That slot was taken, pick another')->assertSeeHtml('wire:click="book(7)"');
});

it('sends previousSlaTypeId on a rebooking', function () {
    Http::fake(appointmentEndpointFake());
    $this->task->forceFill(['appointment_request_id' => 'REQ-OLD', 'booking_sla_type_id' => 'OLD'])->save();

    openAgenda($this->task)->call('book', 7);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && data_get($request->data(), 'data.previousSlaTypeId') === 'OLD'
        && data_get($request->data(), 'data.bookingSlaTypeId') === 'Appointment');
    expect($this->task->fresh()->booking_sla_type_id)->toBe('Appointment');
});

it('refuses to book after the offers expired', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->call('markExpired')->call('book', 7);

    expect(callsAfterDayRequest())->toBe([])
        ->and($component->get('expired'))->toBeTrue()
        ->and($component->get('screen'))->toBe('agenda');

    $component->assertSee('Offers have expired, search again');
});

it('books at most once', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->call('book', 7)->call('book', 7)->call('book', 8);

    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('booked');
});

it('releases the offers and returns to the month', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->assertSee('Release offers')->call('releaseOffers');

    expect(callsAfterDayRequest())->toBe(['DELETE /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('month')
        ->and($component->get('appointmentRequestId'))->toBeNull()
        ->and($component->get('selectedDay'))->toBeNull();
});

it('renders unavailable offers greyed in detail mode', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task);

    // Call centre: the valid offers only, best marked.
    $component->assertSet('mode', 'callcentre')
        ->assertSee(['8:00 AM to 12:00 PM', '1:00 PM to 5:00 PM', 'Best offer'])
        ->assertDontSee(['4:00 PM to 8:00 PM', 'Not available'])
        ->assertSeeHtml('wire:click="book(7)"')
        ->assertSeeHtml('wire:click="book(8)"')
        ->assertDontSeeHtml('wire:click="book(9)"');

    $component->set('mode', 'detail')
        ->assertSee(['4:00 PM to 8:00 PM', 'Value', '100', 'Resource', 'RES-1', 'Allocated', '8:00 AM', 'Not available'])
        ->assertSeeHtml('data-offer="9" data-valid="false"')
        ->assertSeeHtml('data-offer="7" data-valid="true"')
        ->assertDontSeeHtml('wire:click="book(9)"');
});

it('sends inputDatetime as UTC without an offset, read in the app zone', function () {
    Http::fake(appointmentEndpointFake());

    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->set('task_data.input_datetime', '2026-10-21 09:30')
        ->call('selectDay', '2026-10-22');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && data_get($request->data(), 'data.appointmentTemplateId') === 'STANDARD'
        && data_get($request->data(), 'data.inputDatetime') === '2026-10-21T13:30:00');
});

it('leaves inputDatetime out when the field is blank', function () {
    Http::fake(appointmentEndpointFake());

    openAgenda($this->task);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && data_get($request->data(), 'data.appointmentTemplateId') === 'STANDARD'
        && ! array_key_exists('inputDatetime', (array) data_get($request->data(), 'data')));
});

it('puts an invalid offer from allOfferValues in its day column with its times in the dataset zone', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->set('mode', 'detail');
    $grid = $component->instance()->agendaGrid();

    expect(array_keys($grid['columns']))->toBe(['2026-10-22'])
        ->and($grid['columns']['2026-10-22']['label'])->toBe('Thu Oct 22')
        ->and($grid['axis']['startHour'])->toBe(8)
        ->and($grid['axis']['endHour'])->toBe(20);

    $invalid = collect($grid['columns']['2026-10-22']['blocks'])->firstWhere('id', 9);
    expect($invalid['valid'])->toBeFalse()
        ->and($invalid['times'])->toBe('4:00 PM to 8:00 PM');

    // Detail mode shows the full window with its offset under the block, readable rather than ISO.
    $component->assertSee('4:00 PM to 8:00 PM')->assertSee('(UTC-04:00)')->assertDontSee('2026-10-22T');
});

it('shows the dataset zone and its abbreviation on the selected day in the pill', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)
        ->assertSee('America/Toronto (EDT)')
        ->assertSee('Show in UTC');

    expect($component->instance()->timeZoneLabel())->toBe('America/Toronto (EDT), dataset')
        ->and($component->instance()->displayTimeZone())->toBe('America/Toronto');
});

it('shows every time in UTC when the switch is on', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)
        ->assertSee('8:00 AM to 12:00 PM')
        ->assertSee('8 AM')
        ->set('showUtc', true)
        ->assertSee('UTC')
        ->assertDontSee('America/Toronto (EDT)')
        ->assertSee('12:00 PM to 4:00 PM')
        ->assertDontSee('8:00 AM to 12:00 PM');

    expect($component->instance()->timeZoneLabel())->toBe('UTC')
        ->and($component->instance()->displayTimeZone())->toBe('UTC')
        ->and($component->instance()->agendaGrid()['axis']['startHour'])->toBe(12);

    $component->set('showUtc', false)->assertSee('America/Toronto (EDT)')->assertSee('8:00 AM to 12:00 PM');
});

it('renders one column per day for a week of offers', function () {
    $offers = array_map(
        static fn (int $i): array => bookingOffer(20 + $i, CarbonImmutable::parse('2026-10-22T08:00:00-04:00')->addDays($i)->toIso8601String()),
        range(0, 6),
    );
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse($offers, requestId: 'REQ-W'))]);

    $component = openAgenda($this->task)
        ->assertSeeInOrder(['Thu Oct 22', 'Fri Oct 23', 'Sat Oct 24', 'Sun Oct 25', 'Mon Oct 26', 'Tue Oct 27', 'Wed Oct 28']);

    expect($component->instance()->agendaGrid()['columns'])->toHaveCount(7);
    foreach (range(20, 26) as $id) {
        $component->assertSeeHtml('wire:click="book('.$id.')"');
    }
});

it('reads the string "false" from the check as slot taken', function () {
    Http::fake(appointmentEndpointFake(slotAvailable: 'false'));

    $component = openAgenda($this->task, blocking: false)->call('book', 8);

    expect(callsAfterDayRequest())->toBe(['POST /api/v2/appointment/REQ-22'])
        ->and($component->get('slotMessage'))->toBe('That slot was taken, pick another')
        ->and($component->get('pageError'))->toBeNull();
});

it('reads the string "true" from the check as free and books', function () {
    Http::fake(appointmentEndpointFake(slotAvailable: 'true'));

    $component = openAgenda($this->task, blocking: false)->call('book', 8);

    expect(callsAfterDayRequest())->toBe(['POST /api/v2/appointment/REQ-22', 'PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('booked');
});

it('sends no DELETE when releasing offers that already expired', function () {
    Http::fake(appointmentEndpointFake());

    $component = openAgenda($this->task)->call('markExpired')->call('releaseOffers');

    expect(callsAfterDayRequest())->toBe([])
        ->and($component->get('screen'))->toBe('month')
        ->and($component->get('appointmentRequestId'))->toBeNull()
        ->and($component->get('expired'))->toBeFalse();
});

it('marks the offers expired after an accept services refused with 406 so Book is disabled', function () {
    Http::fake(appointmentEndpointFake(acceptStatus: 406));

    $component = openAgenda($this->task)->call('book', 7);

    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('pageError'))->toBe('The appointment request is no longer open.')
        ->and($component->get('expired'))->toBeTrue()
        ->and($component->get('screen'))->toBe('agenda')
        ->and($component->html())->toMatch('/<button[^>]*\sdisabled="disabled"[^>]*wire:click="book\(8\)"/');

    $component->assertSee('Offers have expired, search again')->call('book', 8);
    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22']);
});

it('keeps the request open after an accept that failed for another reason, so leaving releases it', function () {
    Http::fake(appointmentEndpointFake(acceptStatus: 500));

    $component = openAgenda($this->task)->call('book', 7);

    expect($component->get('pageError'))->toBe('PSO could not accept the offer.')
        ->and($component->get('appointmentRequestId'))->toBe('REQ-22')
        ->and($component->get('expired'))->toBeFalse()
        ->and($component->get('screen'))->toBe('agenda');

    $component->call('backToMonth');

    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22', 'DELETE /api/v2/appointment/REQ-22'])
        ->and($component->get('appointmentRequestId'))->toBeNull();
});

it('sends no DELETE on leaving after an accept services refused with 406', function () {
    Http::fake(appointmentEndpointFake(acceptStatus: 406));

    $component = openAgenda($this->task)->call('book', 7);
    expect($component->get('expired'))->toBeTrue();

    $component->call('backToMonth');

    expect(callsAfterDayRequest())->toBe(['PATCH /api/v2/appointment/REQ-22'])
        ->and($component->get('appointmentRequestId'))->toBeNull();
});

it('releases the offers when the page is left or the tab closed', function () {
    Http::fake(appointmentEndpointFake());

    openAgenda($this->task)
        ->assertSeeHtml(str_replace('/', '\\/', route('tasks.release-offers', $this->task)))
        ->assertSeeHtml('pagehide')
        ->assertSeeHtml('livewire:navigating')
        ->assertSeeHtml('sendBeacon');
});

it('shows on the page when releasing the offers failed', function () {
    Http::fake(appointmentEndpointFake(deleteStatus: 500));

    $component = openAgenda($this->task)->call('backToMonth');

    expect(callsAfterDayRequest())->toBe(['DELETE /api/v2/appointment/REQ-22'])
        ->and($component->get('screen'))->toBe('month')
        ->and($component->get('pageError'))->toBe('The offers could not be released: The PSO services API answered 500.');
    $component->assertSee('The offers could not be released');
});

it('keeps the failed release message when the next day request works', function () {
    Http::fake(appointmentEndpointFake(deleteStatus: 500));

    $component = openAgenda($this->task)->call('selectDay', '2026-10-23');

    expect($component->get('screen'))->toBe('agenda')
        ->and($component->get('pageError'))->toBe('The offers could not be released: The PSO services API answered 500.');
});

it('uses the appointment timeout from config and still reaches services', function () {
    config(['psott.appointment.timeout' => 90]);
    Http::fake(appointmentEndpointFake());

    expect(AppointmentBooking::appointmentTimeout())->toBe(90);

    openAgenda($this->task)->call('book', 7)->assertSet('screen', 'booked');
});

it('keeps the Blocking choice made in the create template form', function () {
    $page = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])->instance();
    $create = new ReflectionMethod($page, 'createManualReferenceValue');

    $psoId = $create->invoke($page, ReferenceEntityType::AppointmentTemplate, ['pso_id' => 'NO-BLOCK', 'label' => '', 'blocking_override' => false]);

    $row = DatasetReferenceValue::query()->where('dataset_id', $this->dataset->id)->where('pso_id', $psoId)->firstOrFail();
    expect($row->blockingDefault())->toBeFalse()
        ->and($row->hasBlockingOverride())->toBeTrue();
});

it('does not raise a success toast for a day request', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([bookingOffer(1, '2026-10-22T08:00:00-04:00')], requestId: 'SURVEY-1', survey: true))
        ->push(bookingOffersResponse([bookingOffer(7, '2026-10-22T08:00:00-04:00')], requestId: 'REQ-22')),
    ]);

    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('loadSurvey')
        ->set('task_data.appointmentTemplateId', 'STANDARD')
        ->call('selectDay', '2026-10-22')
        ->assertSet('screen', 'agenda');

    Notification::assertNotNotified();
});

it('still raises a toast when a request fails', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(['message' => 'boom'], 500)]);

    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])->call('loadSurvey');

    Notification::assertNotified('Error');
});

it('refuses to let the browser set the open request state', function () {
    Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->set('appointmentRequestId', 'FORGED');
})->throws(Exception::class, 'Cannot update locked property: [appointmentRequestId]');

it('shows and bases the agenda in the customer time zone before the dataset one', function () {
    $this->task->customer->update(['time_zone' => 'America/Regina']);
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::response(bookingOffersResponse([bookingOffer(1, '2026-10-22T14:00:00Z')]))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('selectDay', '2026-10-22');

    expect($component->instance()->displayTimeZone())->toBe('America/Regina')
        ->and($component->instance()->timeZoneLabel())->toBe('America/Regina (CST), customer');
    $component->assertSee('8:00 AM to 12:00 PM');
});

it('says when the display zone is UTC only because nothing is set', function () {
    $this->dataset->update(['settings' => ['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'Appointment']]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()]);

    expect($component->instance()->timeZoneLabel())->toBe('UTC (no time zone on the customer or the dataset)');
});

it('asks again from where the last offer ended when Next is pressed on the agenda', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([bookingOffer(1, '2026-10-22T14:00:00Z')], [bookingOffer(2, '2026-10-22T18:00:00Z', false)], 'REQ-1'))
        ->push(['data' => []], 200)
        ->push(bookingOffersResponse([bookingOffer(3, '2026-10-22T22:00:00Z')], [], 'REQ-2'))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('selectDay', '2026-10-22')
        ->call('nextAgenda');

    $calls = appointmentCalls();
    expect($calls)->toHaveCount(3)
        ->and($calls[1]->method())->toBe('DELETE')
        ->and($calls[2]->data()['data']['appointmentTemplateDatetime'])->toBe('2026-10-22T22:00:00')
        ->and($calls[2]->data()['data']['appointmentBaseDateTime'])->toBe('2026-10-22T04:00:00')
        ->and($component->get('appointmentRequestId'))->toBe('REQ-2')
        ->and($component->get('selectedDay'))->toBe('2026-10-22')
        ->and($component->get('cursor'))->toBe('2026-10-23T02:00:00+00:00');
});

it('moves the month view three weeks on from where the survey offers ended, and back to today', function () {
    Http::fake([...PsoServicesFake::session(), '*/api/v2/appointment*' => Http::sequence()
        ->push(bookingOffersResponse([bookingOffer(1, '2026-11-06T12:00:00Z')], [], 'S-1', true))
        ->push(bookingOffersResponse([], [], 'S-2', true))
        ->push(bookingOffersResponse([], [], 'S-3', true))]);

    $component = Livewire::test(AppointmentBooking::class, ['record' => $this->task->getRouteKey()])
        ->call('loadSurvey')
        ->call('nextMonth');

    expect($component->get('windowStart'))->toBe('2026-11-06')
        ->and($component->instance()->windowDays()[0])->toBe('2026-11-06')
        ->and(appointmentCalls()[1]->data()['data']['appointmentBaseDateTime'])->toBe('2026-11-06T05:00:00');
    $component->assertSee('Three weeks from Fri Nov 6')->assertSee('Back to today');

    $component->call('backToToday');

    expect($component->get('windowStart'))->toBeNull()
        ->and($component->instance()->windowDays()[0])->toBe('2026-10-19');
});
