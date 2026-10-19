<?php

use App\Enums\EventType;
use App\Filament\Pages\Resource\ResourceEvent;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['environment' => $this->environment] = fakePsoPagePayloadSetup($this);
});

function submitResourceEvent(object $test, array $extra = []): void
{
    psoPageWithEnvironment(ResourceEvent::class, $test->environment)
        ->set('resource_data', array_merge([
            'resource_id' => 'RES-1',
            'event_type' => EventType::LOGON->value,
            'event_date_time' => null,
            'input_datetime' => null,
            'latitude' => null,
            'longitude' => null,
        ], $extra))
        ->call('generateEvent')
        ->assertHasNoErrors();
}

it('POSTs /resource/{id}/event with the required keys', function () {
    submitResourceEvent($this);

    $request = sentPsoRequest();
    expect($request->method())->toBe('POST')
        ->and($request->url())->toMatch('#/api/(v\d+/)?resource/RES-1/event$#')
        ->and(data_get($request->data(), 'environment.datasetId'))->toBe('NORTH')
        ->and(data_get($request->data(), 'data.resourceId'))->toBe('RES-1')
        ->and(data_get($request->data(), 'data.eventType'))->toBe('RO');
});

it('omits eventDateTime, inputDatetime, lat and long when left blank', function () {
    submitResourceEvent($this);

    expect(sentPsoRequest()->data()['data'])
        ->not->toHaveKey('eventDateTime')
        ->not->toHaveKey('inputDatetime')
        ->not->toHaveKey('lat')
        ->not->toHaveKey('long');
});

it('sends inputDatetime as an ISO 8601 atom string with a capital T and a UTC offset', function () {
    submitResourceEvent($this, ['input_datetime' => '2026-03-05 09:30:00']);

    expect(data_get(sentPsoRequest()->data(), 'data.inputDatetime'))->toMatch('/^2026-03-05T09:30:00[+-]\d{2}:\d{2}$/');
});

it('keeps eventDateTime and inputDatetime separate when both are set', function () {
    submitResourceEvent($this, ['event_date_time' => '2026-04-01 08:00:00', 'input_datetime' => '2026-03-05 09:30:00']);

    $data = sentPsoRequest()->data()['data'];
    expect($data['eventDateTime'])->toStartWith('2026-04-01')->toContain('08:00')
        ->and($data['inputDatetime'])->toStartWith('2026-03-05T09:30:00')
        ->and($data['eventDateTime'])->not->toBe($data['inputDatetime']);
});

it('keeps eventDateTime when inputDatetime is blank, and the reverse', function () {
    submitResourceEvent($this, ['event_date_time' => '2026-04-01 08:00:00']);
    expect(sentPsoRequest()->data()['data'])->toHaveKey('eventDateTime')->not->toHaveKey('inputDatetime');

    Http::fake(['*' => Http::response(['data' => ['payloadToPso' => []]], 200)]);
    submitResourceEvent($this, ['input_datetime' => '2026-03-05 09:30:00']);
    expect(Http::recorded()->last()[0]->data()['data'])->toHaveKey('inputDatetime')->not->toHaveKey('eventDateTime');
});

it('sends eventDateTime in a format pso-services accepts as a date', function () {
    submitResourceEvent($this, ['event_date_time' => '2026-04-01 08:00:00']);

    expect(strtotime(data_get(sentPsoRequest()->data(), 'data.eventDateTime')))->not->toBeFalse();
});

it('sends lat and long for a GPS fix', function () {
    submitResourceEvent($this, ['event_type' => EventType::GPSFIX->value, 'latitude' => 50.4, 'longitude' => -104.6]);

    $data = sentPsoRequest()->data()['data'];
    expect($data['eventType'])->toBe('FIX')
        ->and($data['lat'])->toEqual(50.4)
        ->and($data['long'])->toEqual(-104.6);
});

it('requires lat and long for a GPS fix and sends nothing without them', function () {
    psoPageWithEnvironment(ResourceEvent::class, $this->environment)
        ->set('resource_data', ['resource_id' => 'RES-1', 'event_type' => EventType::GPSFIX->value])
        ->call('generateEvent')
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('sends nothing when the resource id is missing', function () {
    psoPageWithEnvironment(ResourceEvent::class, $this->environment)
        ->set('resource_data', ['event_type' => EventType::LOGON->value])
        ->call('generateEvent')
        ->assertHasErrors();

    Http::assertNothingSent();
});
