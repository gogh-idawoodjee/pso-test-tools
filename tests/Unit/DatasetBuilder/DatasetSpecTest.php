<?php

use App\Support\DatasetBuilder\DatasetSpecRepository;
use App\Support\DatasetBuilder\InvalidDatasetSpec;
use App\Support\DatasetBuilder\Spec\DatasetSpec;

/** @return array<string, mixed> the shipped DC_US spec as an array, changed by $change */
function datasetSpecArray(?Closure $change = null): array
{
    $data = json_decode((string) file_get_contents(resource_path('datasets/DC_US.json')), true);

    return $change ? $change($data) : $data;
}

it('reads both shipped specs', function (string $id) {
    $spec = app(DatasetSpecRepository::class)->load($id);

    expect($spec->datasetId)->toBe($id)
        ->and($spec->horizonDays)->toBe(100)
        ->and($spec->ruleCollection['parameters'])->toBe([
            'MaximumCommittedActivities' => '2',
            'CommitBreaks' => 'false',
            'CountBreaks' => 'false',
            'LogonRequired' => 'false',
        ]);
})->with(['DC_US', 'DC_CA']);

it('lists the shipped specs for the picker', function () {
    expect(app(DatasetSpecRepository::class)->options())->toBe([
        'DC_CA' => 'DC_CA: DC demo dataset — Canada',
        'DC_US' => 'DC_US: DC demo dataset — United States',
    ]);
});

it('refuses a spec it cannot generate from', function (Closure $change, string $message) {
    expect(fn () => DatasetSpec::fromArray(datasetSpecArray($change)))->toThrow(InvalidDatasetSpec::class, $message);
})->with([
    'horizon shorter than the windows' => [static function (array $data): array {
        $data['horizon_days'] = 70;

        return $data;
    }, 'horizon_days is 70, but the DSE window plus the appointment window is 97 days'],
    'depot type in a region with no depot' => [static function (array $data): array {
        $data['resources'][] = ['id' => 'R-MTN-001', 'first_name' => 'A', 'surname' => 'B', 'region' => 'MTN', 'resource_type' => 'INSTALLER', 'skills' => []];

        return $data;
    }, 'R-MTN-001 is a INSTALLER, which starts at a depot, but region MTN has no depot'],
    'unknown skill' => [static function (array $data): array {
        $data['resources'][0]['skills'][] = 'FLIGHT';

        return $data;
    }, 'skill FLIGHT is not in skills'],
    'unknown region' => [static function (array $data): array {
        $data['resources'][0]['region'] = 'GOTHAM';

        return $data;
    }, 'region GOTHAM is not in regions'],
    'bad time zone' => [static function (array $data): array {
        $data['regions'][0]['time_zone'] = 'Central';

        return $data;
    }, "'Central' is not an IANA time zone"],
    'id over 32 characters' => [static function (array $data): array {
        $data['resources'][0]['id'] = str_repeat('R', 33);

        return $data;
    }, 'longer than 32 characters'],
    'duplicate resource' => [static function (array $data): array {
        $data['resources'][] = $data['resources'][0];

        return $data;
    }, 'appears twice'],
    'utilisation on the resource itself' => [static function (array $data): array {
        $data['resources'][0]['utilisation'] = 80;

        return $data;
    }, 'R-CHI-001: utilisation comes from its resource type; put an exception under overrides.utilisation'],
    'an override that is not a type field' => [static function (array $data): array {
        $data['resources'][0]['overrides'] = ['max_travel' => 'PT2H'];

        return $data;
    }, 'overrides.max_travel is not one of travel_to, travel_from, cost_ph, cost_phot, cost_km, utilisation'],
    'an override of the wrong kind' => [static function (array $data): array {
        $data['resources'][0]['overrides'] = ['travel_to' => 45];

        return $data;
    }, 'overrides.travel_to'],
    'modelling not a boolean' => [static function (array $data): array {
        $data['modelling'] = 'yes';

        return $data;
    }, 'modelling must be true or false'],
    'a type with no colour of its own or from the cheat sheet' => [static function (array $data): array {
        $data['resource_types']['SURVEYOR'] = $data['resource_types']['FIELD_TECH'];
        unset($data['resource_types']['SURVEYOR']['shift_category_colour']);

        return $data;
    }, 'Resource type SURVEYOR: shift_category_colour must be an ARGB colour'],
    'a region asking for a template kind that does not exist' => [static function (array $data): array {
        $data['regions'][0]['appointment_templates'][] = 'EVENINGS';

        return $data;
    }, 'Region CHI: appointment template EVENINGS is not in appointment_templates'],
    'a bad day pattern' => [static function (array $data): array {
        $data['appointment_templates']['AMPM']['day_pattern'] = 'YYYYY';

        return $data;
    }, 'Appointment template AMPM: day_pattern must be seven Y or N'],
    'a slot ending before it starts' => [static function (array $data): array {
        $data['appointment_templates']['ALLDAY']['items'][0]['end'] = 'PT7H';

        return $data;
    }, 'Appointment template ALLDAY items[0]: the slot must end after it starts'],
    'split_allowed not a boolean' => [static function (array $data): array {
        $data['activity_types']['PROJECT']['split_allowed'] = 'yes';

        return $data;
    }, 'Activity type PROJECT split_allowed must be true or false'],
    'shift ending before it starts' => [static function (array $data): array {
        $data['resource_types']['FIELD_TECH']['shift']['end'] = '07:00';

        return $data;
    }, 'the shift must end after it starts'],
]);

it('turns modelling on and takes the cheat sheet colour when a spec leaves them out', function () {
    $spec = DatasetSpec::fromArray(datasetSpecArray(static function (array $data): array {
        unset($data['modelling'], $data['resource_types']['INSTALLER']['shift_category_colour']);

        return $data;
    }));

    expect($spec->modelling)->toBeTrue()
        ->and($spec->resourceTypes['INSTALLER']->shiftCategoryColour)->toBe('#FF2E7D32');
});

it('refuses a spec id that is not a plain name', function () {
    expect(fn () => app(DatasetSpecRepository::class)->load('../DC_US'))->toThrow(InvalidDatasetSpec::class, 'No seed spec called');
});
