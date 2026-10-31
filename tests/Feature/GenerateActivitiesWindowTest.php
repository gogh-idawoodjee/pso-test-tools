<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Pages\Activity\GenerateActivities;
use App\Models\DatasetReferenceValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;

beforeEach(function () {
    ['environment' => $this->environment, 'dataset' => $this->dataset] = fakePsoPagePayloadSetup($this);
    $this->dataset->update(['settings' => ['time_zone' => 'America/Toronto']]);

    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::TaskType, 'pso_id' => 'INSTALL']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment']);

    // Saturday 31 October 2026, 12:00 UTC: 08:00 in Toronto, the day before daylight saving ends.
    CarbonImmutable::setTestNow('2026-10-31 12:00:00 UTC');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The page with the required fields filled through the form, so its defaults stay in place. */
function activityPage(object $test, array $set = []): Testable
{
    $component = psoPageWithEnvironment(GenerateActivities::class, $test->environment);

    foreach (array_merge([
        'activity_type_id' => 'INSTALL', 'sla_type_id' => 'Appointment', 'latitude' => 43.65, 'longitude' => -79.38,
    ], $set) as $path => $value) {
        $component->set("activity_data.{$path}", $value);
    }

    return $component;
}

function sentActivity(Testable $component): array
{
    $component->call('createActivity')->assertHasNoErrors();

    return sentPsoRequest()->data()['data'];
}

it('defaults to 08:00 to 17:00 today in the dataset zone, sent with its offset', function () {
    $data = sentActivity(activityPage($this));

    expect($data['slaStart'])->toBe('2026-10-31T08:00:00-04:00')
        ->and($data['slaEnd'])->toBe('2026-10-31T17:00:00-04:00')
        ->and($data)->not->toHaveKeys(['timeZone', 'windowSize', 'relativeDay', 'relativeDayEnd']);
});

it('keeps local times across the end of daylight saving', function () {
    $data = sentActivity(activityPage($this, ['relative_day' => 0, 'relative_day_end' => 1]));

    expect($data['slaStart'])->toBe('2026-10-31T08:00:00-04:00')
        ->and($data['slaEnd'])->toBe('2026-11-01T17:00:00-05:00');
});

it('counts days from the date in the activity zone, not the app or UTC date', function () {
    // 03:00 UTC on the 31st is still the 30th in Vancouver.
    CarbonImmutable::setTestNow('2026-10-31 03:00:00 UTC');

    $data = sentActivity(activityPage($this, ['time_zone' => 'America/Vancouver']));

    expect($data['slaStart'])->toBe('2026-10-30T08:00:00-07:00');
});

it('uses the chosen zone over the dataset zone, with editable times', function () {
    $data = sentActivity(activityPage($this, [
        'time_zone' => 'America/Regina', 'relative_day' => 2, 'relative_day_end' => 3, 'day_start_time' => '07:30', 'day_end_time' => '19:00',
    ]));

    expect($data['slaStart'])->toBe('2026-11-02T07:30:00-06:00')
        ->and($data['slaEnd'])->toBe('2026-11-03T19:00:00-06:00');
});

it('falls back to UTC when the dataset has no zone', function () {
    $this->dataset->update(['settings' => []]);

    $data = sentActivity(activityPage($this));

    expect($data['slaStart'])->toBe('2026-10-31T08:00:00+00:00');
});

it('counts days from the input date time when one is set', function () {
    $data = sentActivity(activityPage($this, ['input_datetime' => '2026-12-01 10:00:00', 'relative_day_end' => 1]));

    expect($data['slaStart'])->toBe('2026-12-01T08:00:00-05:00')
        ->and($data['slaEnd'])->toBe('2026-12-02T17:00:00-05:00');
});

it('sends a single appointment slot on its day', function () {
    $data = sentActivity(activityPage($this, ['window_mode' => 'slot', 'slot_day' => 1, 'slot_start' => '13:00', 'slot_end' => '17:00']));

    expect($data['slaStart'])->toBe('2026-11-01T13:00:00-05:00')
        ->and($data['slaEnd'])->toBe('2026-11-01T17:00:00-05:00');
});

it('refuses a window that ends before it starts', function () {
    activityPage($this, ['window_mode' => 'slot', 'slot_start' => '12:00', 'slot_end' => '08:00'])
        ->call('createActivity')
        ->assertHasErrors(['activity_data.slot_end']);

    Http::assertNothingSent();
});

it('sends the base value as it is, or the per-hour rate times the duration', function () {
    expect(sentActivity(activityPage($this, ['base_value' => 2500]))['baseValue'])->toEqual(2500);
});

it('multiplies a per-hour base value by the duration in hours', function () {
    $data = sentActivity(activityPage($this, ['base_value' => 100, 'base_value_per_hour' => true, 'duration' => 90]));

    expect($data['baseValue'])->toEqual(150);
});

it('refuses a base value of 0, which PSO never schedules', function () {
    activityPage($this, ['base_value' => 0])->call('createActivity')->assertHasErrors(['activity_data.base_value']);

    Http::assertNothingSent();
});

it('defaults the description to the type and address, or the coordinates', function () {
    expect(sentActivity(activityPage($this, ['address' => '1 Main St, Regina']))['description'])->toBe('INSTALL : 1 Main St, Regina');
});

it('uses the coordinates in the default description without an address', function () {
    expect(sentActivity(activityPage($this))['description'])->toBe('INSTALL : 43.65, -79.38');
});

it('sends a typed description as it is', function () {
    expect(sentActivity(activityPage($this, ['description' => 'Fibre install']))['description'])->toBe('Fibre install');
});

it('sends skills and regions picked through the repeaters as flat lists of pso ids', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'FIBRE']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => 'NORTH']);

    $component = activityPage($this);
    foreach (['skills' => 'skill', 'regions' => 'region'] as $repeater => $field) {
        $component->call('mountAction', 'add', [], ['schemaComponent' => "activity_form.{$repeater}"]);
        $key = array_key_first($component->get("activity_data.{$repeater}"));
        $component->set("activity_data.{$repeater}.{$key}.{$field}", $field === 'skill' ? 'FIBRE' : 'NORTH');
    }

    $data = sentActivity($component);

    expect($data['skills'])->toBe(['FIBRE'])
        ->and($data['regions'])->toBe(['NORTH']);
});
