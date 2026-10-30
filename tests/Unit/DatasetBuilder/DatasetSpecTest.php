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
    'shift ending before it starts' => [static function (array $data): array {
        $data['resource_types']['FIELD_TECH']['shift']['end'] = '07:00';

        return $data;
    }, 'the shift must end after it starts'],
]);

it('refuses a spec id that is not a plain name', function () {
    expect(fn () => app(DatasetSpecRepository::class)->load('../DC_US'))->toThrow(InvalidDatasetSpec::class, 'No seed spec called');
});
