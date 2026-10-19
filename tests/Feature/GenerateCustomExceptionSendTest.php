<?php

use App\Filament\Pages\GenerateCustomException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['environment' => $this->environment] = fakePsoPagePayloadSetup($this);
});

function submitException(object $test, array $extra = []): void
{
    psoPageWithEnvironment(GenerateCustomException::class, $test->environment)
        ->set('exception_data', array_merge([
            'object_type_id' => 'activity',
            'schedule_exception_type_id' => 42,
            'activity_id' => 'ACT-1',
            'resourceId' => null,
            'label' => 'Custom label',
            'value' => 'Custom value',
            'input_datetime' => null,
        ], $extra))
        ->call('generate_exception')
        ->assertHasNoErrors();
}

it('POSTs /exception for an activity with the required keys', function () {
    submitException($this);

    $request = sentPsoRequest();
    expect($request->method())->toBe('POST')
        ->and($request->url())->toMatch('#/api/(v\d+/)?exception$#')
        ->and(data_get($request->data(), 'environment.datasetId'))->toBe('NORTH')
        ->and(data_get($request->data(), 'data.activityId'))->toBe('ACT-1')
        ->and(data_get($request->data(), 'data.label'))->toBe('Custom label')
        ->and(data_get($request->data(), 'data.value'))->toBe('Custom value')
        ->and(data_get($request->data(), 'data.exceptionTypeId'))->toEqual(42)
        ->and($request->data()['data'])->not->toHaveKey('resourceId');
});

it('POSTs /exception for a resource using resourceId, not activityId', function () {
    submitException($this, ['object_type_id' => 'resource', 'resourceId' => 'RES-1', 'activity_id' => null]);

    $data = sentPsoRequest()->data()['data'];
    expect($data['resourceId'])->toBe('RES-1')
        ->and($data)->not->toHaveKey('activityId');
});

it('omits inputDatetime when left blank', function () {
    submitException($this);

    expect(sentPsoRequest()->data()['data'])->not->toHaveKey('inputDatetime');
});

it('sends inputDatetime as an ISO 8601 atom string with a capital T and a UTC offset', function () {
    submitException($this, ['input_datetime' => '2026-03-05 09:30:00']);

    expect(data_get(sentPsoRequest()->data(), 'data.inputDatetime'))->toMatch('/^2026-03-05T09:30:00[+-]\d{2}:\d{2}$/');
});

it('sends nothing when the object id for the chosen type is missing', function () {
    psoPageWithEnvironment(GenerateCustomException::class, $this->environment)
        ->set('exception_data', ['object_type_id' => 'resource', 'schedule_exception_type_id' => 42, 'label' => 'a', 'value' => 'b'])
        ->call('generate_exception')
        ->assertHasErrors();

    Http::assertNothingSent();
});
