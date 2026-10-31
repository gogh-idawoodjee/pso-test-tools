<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Pages\Activity\GenerateActivities;
use App\Models\DatasetReferenceValue;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['environment' => $this->environment, 'dataset' => $this->dataset] = fakePsoPagePayloadSetup($this);

    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::TaskType, 'pso_id' => 'INSTALL']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment']);
});

function submitActivity(object $test, array $extra = []): void
{
    psoPageWithEnvironment(GenerateActivities::class, $test->environment)
        ->set('activity_data', array_merge([
            'activity_type_id' => 'INSTALL',
            'sla_type_id' => 'Appointment',
            'base_value' => 3000,
            'duration' => 60,
            'priority' => 2,
            'latitude' => 50.4,
            'longitude' => -104.6,
            'relative_day' => 0,
            'relative_day_end' => 7,
        ], $extra))
        ->call('createActivity')
        ->assertHasNoErrors();
}

it('POSTs /activity with the required keys and the environment block', function () {
    submitActivity($this);

    $request = sentPsoRequest();
    expect($request->method())->toBe('POST')
        ->and($request->url())->toMatch('#/api/(v\d+/)?activity$#')
        ->and(data_get($request->data(), 'environment.datasetId'))->toBe('NORTH')
        ->and(data_get($request->data(), 'environment.baseUrl'))->toBe('https://example.test')
        ->and(data_get($request->data(), 'environment.accountId'))->toBe('ACC1')
        ->and(data_get($request->data(), 'data.activityTypeId'))->toBe('INSTALL')
        ->and(data_get($request->data(), 'data.slaTypeId'))->toBe('Appointment')
        ->and(data_get($request->data(), 'data.baseValue'))->toEqual(3000)
        ->and(data_get($request->data(), 'data.duration'))->toEqual(60)
        ->and(data_get($request->data(), 'data.priority'))->toEqual(2)
        ->and(data_get($request->data(), 'data.lat'))->toEqual(50.4)
        ->and(data_get($request->data(), 'data.long'))->toEqual(-104.6)
        ->and(data_get($request->data(), 'data.slaStart'))->toMatch('/^\d{4}-\d{2}-\d{2}T08:00:00\+00:00$/')
        ->and(data_get($request->data(), 'data.description'))->toBe('INSTALL : 50.4, -104.6')
        ->and(data_get($request->data(), 'data.slaEnd'))->toMatch('/^\d{4}-\d{2}-\d{2}T17:00:00\+00:00$/');
});

it('omits inputDatetime and the optional keys when left blank', function () {
    submitActivity($this, ['input_datetime' => null]);

    $data = sentPsoRequest()->data();
    expect($data['data'])->not->toHaveKey('inputDatetime')
        ->and($data['data'])->not->toHaveKey('activityId')
        ->and($data['data'])->not->toHaveKey('skills')
        ->and($data['data'])->not->toHaveKey('regions');
});

it('omits inputDatetime when it is an empty string', function () {
    submitActivity($this, ['input_datetime' => '']);

    expect(sentPsoRequest()->data()['data'])->not->toHaveKey('inputDatetime');
});

it('sends inputDatetime as an ISO 8601 atom string with a capital T and a UTC offset when set', function () {
    submitActivity($this, ['input_datetime' => '2026-03-05 09:30:00']);

    expect(data_get(sentPsoRequest()->data(), 'data.inputDatetime'))->toMatch('/^2026-03-05T09:30:00[+-]\d{2}:\d{2}$/');
});

it('sends the optional activityId, skills and regions when provided', function () {
    submitActivity($this, ['activity_id' => 'ACT-1', 'skills' => ['FIBRE'], 'regions' => ['NORTH']]);

    $data = sentPsoRequest()->data()['data'];
    expect($data['activityId'])->toBe('ACT-1')
        ->and($data['skills'])->toBe(['FIBRE'])
        ->and($data['regions'])->toBe(['NORTH']);
});

it('sends nothing when a required field is missing', function () {
    psoPageWithEnvironment(GenerateActivities::class, $this->environment)
        ->set('activity_data', ['sla_type_id' => 'Appointment'])
        ->call('createActivity')
        ->assertHasErrors();

    Http::assertNothingSent();
});
