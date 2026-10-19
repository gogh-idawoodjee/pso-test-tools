<?php

use App\Enums\TaskStatus;
use App\Filament\Pages\Activity\UpdateActivityStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['environment' => $this->environment] = fakePsoPagePayloadSetup($this);
});

function submitStatus(object $test, array $extra = []): void
{
    psoPageWithEnvironment(UpdateActivityStatus::class, $test->environment)
        ->set('activity_data', array_merge([
            'activity_id' => 'ACT-9',
            'status' => TaskStatus::COMMITTED->value,
            'resource_id' => 'RES-1',
            'datetimefixed' => null,
            'input_datetime' => null,
            'duration' => null,
        ], $extra))
        ->call('updateTaskStatus')
        ->assertHasNoErrors();
}

it('PATCHes /activity/{id}/status with the required keys and the lower-case services status', function () {
    submitStatus($this);

    $request = sentPsoRequest();
    expect($request->method())->toBe('PATCH')
        ->and($request->url())->toMatch('#/api/(v\d+/)?activity/ACT-9/status$#')
        ->and(data_get($request->data(), 'environment.datasetId'))->toBe('NORTH')
        ->and(data_get($request->data(), 'data.activityId'))->toBe('ACT-9')
        ->and(data_get($request->data(), 'data.resourceId'))->toBe('RES-1')
        ->and(data_get($request->data(), 'data.status'))->toBe('committed');
});

it('maps every selectable status to the value pso-services knows', function (TaskStatus $status, string $expected) {
    submitStatus($this, ['status' => $status->value, 'resource_id' => 'RES-1']);

    expect(data_get(sentPsoRequest()->data(), 'data.status'))->toBe($expected);
})->with([
    'unallocated' => [TaskStatus::UNALLOCATED, 'unallocated'],
    'allocated' => [TaskStatus::ALLOCATED, 'allocated'],
    'on site' => [TaskStatus::ON_SITE, 'onsite'],
    'visit complete' => [TaskStatus::VISIT_COMPLETE, 'visitcomplete'],
]);

it('omits inputDatetime, dateTimeFixed and duration when left blank', function () {
    submitStatus($this);

    expect(sentPsoRequest()->data()['data'])
        ->not->toHaveKey('inputDatetime')
        ->not->toHaveKey('dateTimeFixed')
        ->not->toHaveKey('duration');
});

it('sends inputDatetime as an ISO 8601 atom string with a capital T and a UTC offset', function () {
    submitStatus($this, ['input_datetime' => '2026-03-05 09:30:00']);

    expect(data_get(sentPsoRequest()->data(), 'data.inputDatetime'))->toMatch('/^2026-03-05T09:30:00[+-]\d{2}:\d{2}$/');
});

it('keeps dateTimeFixed and inputDatetime separate when both are set', function () {
    submitStatus($this, ['datetimefixed' => '2026-04-01 08:00:00', 'input_datetime' => '2026-03-05 09:30:00']);

    $data = sentPsoRequest()->data()['data'];
    expect($data['dateTimeFixed'])->toStartWith('2026-04-01T08:00')
        ->and($data['inputDatetime'])->toStartWith('2026-03-05T09:30:00')
        ->and($data['dateTimeFixed'])->not->toBe($data['inputDatetime']);
});

it('keeps dateTimeFixed when inputDatetime is blank, and the reverse', function () {
    submitStatus($this, ['datetimefixed' => '2026-04-01 08:00:00']);
    $only = sentPsoRequest()->data()['data'];
    expect($only)->toHaveKey('dateTimeFixed')->not->toHaveKey('inputDatetime');

    Http::fake(['*' => Http::response(['data' => ['payloadToPso' => []]], 200)]);
    submitStatus($this, ['input_datetime' => '2026-03-05 09:30:00']);
    $reverse = Http::recorded()->last()[0]->data()['data'];
    expect($reverse)->toHaveKey('inputDatetime')->not->toHaveKey('dateTimeFixed');
});

it('sends dateTimeFixed in the format pso-services validates (Y-m-d\TH:i:s)', function () {
    submitStatus($this, ['datetimefixed' => '2026-04-01 08:00:00']);

    expect(data_get(sentPsoRequest()->data(), 'data.dateTimeFixed'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/');
});

it('sends the duration when set', function () {
    submitStatus($this, ['duration' => 45]);

    expect(data_get(sentPsoRequest()->data(), 'data.duration'))->toEqual(45);
});

it('requires a resource for Committed and higher and sends nothing', function () {
    psoPageWithEnvironment(UpdateActivityStatus::class, $this->environment)
        ->set('activity_data', ['activity_id' => 'ACT-9', 'status' => (string) TaskStatus::COMMITTED->value, 'resource_id' => ''])
        ->call('updateTaskStatus')
        ->assertHasErrors(['activity_data.resource_id' => 'required']);

    Http::assertNothingSent();
});
