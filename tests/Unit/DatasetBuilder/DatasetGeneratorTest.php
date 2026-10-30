<?php

use App\Support\DatasetBuilder\DatasetGenerator;
use App\Support\DatasetBuilder\DatasetSpecRepository;
use App\Support\DatasetBuilder\GeneratedDataset;
use Illuminate\Support\Facades\File;

const DATASET_BUILDER_EMIT_ORDER = [
    'Input_Reference', 'Region', 'Location', 'Skill', 'Resource_Type', 'Rule', 'Rule_Collection', 'Rule_Collection_Rule',
    'Rule_Parameter', 'Resources', 'Resource_Region', 'Resource_Skill', 'Availability', 'Resource_Region_Availability',
    'Shift', 'Shift_Break', 'Object_Group',
];

function datasetBuilderDirectory(): string
{
    return storage_path('framework/testing/dataset-builder');
}

function generateDataset(string $id, string $now = '2026-10-30T15:30:00Z', ?int $seed = null, ?string $directory = null): GeneratedDataset
{
    $spec = app(DatasetSpecRepository::class)->load($id);

    if ($seed !== null) {
        $spec = $spec->withSeed($seed);
    }

    return (new DatasetGenerator)->generate($spec, new DateTimeImmutable($now), $directory ?? datasetBuilderDirectory()."/{$id}");
}

/** @return list<array{entity: string, fields: array<string, string>}> */
function datasetRows(string $path): array
{
    $rows = [];

    foreach (simplexml_load_file($path)->children() as $row) {
        $fields = [];

        foreach ($row->children() as $field) {
            $fields[$field->getName()] = (string) $field;
        }

        $rows[] = ['entity' => $row->getName(), 'fields' => $fields];
    }

    return $rows;
}

/** @return list<array<string, string>> */
function datasetEntity(string $path, string $entity): array
{
    return array_values(array_map(
        static fn (array $row): array => $row['fields'],
        array_filter(datasetRows($path), static fn (array $row): bool => $row['entity'] === $entity),
    ));
}

afterEach(function () {
    File::deleteDirectory(datasetBuilderDirectory());
});

it('generates both specs with 36 and 12 resources', function (string $id, int $resources) {
    $result = generateDataset($id);

    expect($result->summary['resources'])->toBe($resources)
        ->and(datasetEntity($result->path, 'Resources'))->toHaveCount($resources)
        ->and(datasetEntity($result->path, 'Object_Group'))->toHaveCount($resources)
        ->and(datasetEntity($result->path, 'Resource_Region'))->toHaveCount($resources);
})->with([['DC_US', 36], ['DC_CA', 12]]);

it('gives every resource the shifts its type works over the horizon', function (string $id) {
    $result = generateDataset($id);
    $types = collect($result->summary['types'])->keyBy('type');

    // Day -7 (Fri 2026-10-23) to day 100 (Sun 2027-02-07) is 108 days: 15 weeks plus a Fri, Sat and Sun.
    expect($types['FIELD_TECH']['min'])->toBe(76)->and($types['FIELD_TECH']['max'])->toBe(76)
        ->and($types['SENIOR_TECH']['min'])->toBe(76)->and($types['SENIOR_TECH']['max'])->toBe(76)
        ->and($types['INSTALLER']['min'])->toBe(60)->and($types['INSTALLER']['max'])->toBe(60)
        ->and($types['CONTRACTOR']['min'])->toBe(77)->and($types['CONTRACTOR']['max'])->toBe(77);

    $shifts = datasetEntity($result->path, 'Shift');

    expect($shifts)->toHaveCount($result->summary['shifts'])
        ->and(datasetEntity($result->path, 'Shift_Break'))->toHaveCount(count($shifts));
})->with(['DC_US', 'DC_CA']);

it('starts shifts at the same local time across the DST change, so the UTC time moves', function (string $id, string $resource, string $friday, string $monday) {
    $shifts = collect(datasetEntity(generateDataset($id)->path, 'Shift'))->where('resource_id', $resource)->keyBy(static fn (array $shift): string => substr($shift['id'], 3));

    expect($shifts['20261030']['start_datetime'])->toBe($friday)
        ->and($shifts['20261102']['start_datetime'])->toBe($monday);
})->with([
    'Chicago FIELD_TECH' => ['DC_US', 'R-CHI-003', '2026-10-30T13:00:00+00:00', '2026-11-02T14:00:00+00:00'],
    'Los Angeles FIELD_TECH' => ['DC_US', 'R-LAX-003', '2026-10-30T15:00:00+00:00', '2026-11-02T16:00:00+00:00'],
    'Toronto FIELD_TECH' => ['DC_CA', 'R-TOR-003', '2026-10-30T12:00:00+00:00', '2026-11-02T13:00:00+00:00'],
]);

it('shows the DST change as two start bands in the summary', function () {
    $bands = collect(generateDataset('DC_US')->summary['bands'])->where('region', 'CHI')->where('type', 'FIELD_TECH')->values();

    expect($bands->map(static fn (array $band): string => "{$band['local']} {$band['utc']} {$band['from']} {$band['to']}")->all())->toBe([
        '08:00 13:00Z Fri 2026-10-23 Fri 2026-10-30',
        '08:00 14:00Z Mon 2026-11-02 Fri 2027-02-05',
    ]);
});

it('puts Monday shifts at split priority 0 and every other shift at 1', function () {
    $result = generateDataset('DC_US');

    foreach (datasetEntity($result->path, 'Shift') as $shift) {
        $monday = (new DateTimeImmutable(substr($shift['id'], 3)))->format('N') === '1';

        expect($shift['split_priority'])->toBe($monday ? '0' : '1');
    }

    expect($result->summary['monday_shifts_at_zero'])->toBe($result->summary['monday_shifts'])
        ->and($result->summary['other_shifts_at_one'])->toBe($result->summary['other_shifts']);
});

it('writes the cheat sheet entities only, in its emit order, with every reference resolved', function (string $id) {
    $path = generateDataset($id)->path;
    $rows = datasetRows($path);
    $sequence = [];

    foreach ($rows as $row) {
        if (end($sequence) !== $row['entity']) {
            $sequence[] = $row['entity'];
        }
    }

    expect($sequence)->toBe(DATASET_BUILDER_EMIT_ORDER);

    $ids = static fn (string $entity, string $field = 'id'): array => array_column(datasetEntity($path, $entity), $field);
    $locations = $ids('Location');
    $resources = $ids('Resources');
    $shifts = $ids('Shift');

    foreach (datasetEntity($path, 'Resources') as $resource) {
        expect($locations)->toContain($resource['location_id_start'], $resource['location_id_end'])
            ->and($ids('Resource_Type'))->toContain($resource['resource_type_id']);
    }

    expect(array_diff($ids('Shift', 'resource_id'), $resources))->toBe([])
        ->and(array_diff($ids('Shift_Break', 'shift_id'), $shifts))->toBe([])
        ->and(array_diff($ids('Resource_Skill', 'skill_id'), $ids('Skill')))->toBe([])
        ->and(array_diff($ids('Resource_Region', 'region_id'), $ids('Region')))->toBe([])
        ->and(array_diff($ids('Resource_Region_Availability', 'availability_id'), $ids('Availability')))->toBe([])
        ->and(array_unique($shifts))->toHaveCount(count($shifts))
        ->and(array_unique($locations))->toHaveCount(count($locations));

    foreach ($rows as $row) {
        foreach ($row['fields'] as $name => $value) {
            expect($value)->not->toBe('');

            if (str_ends_with($name, 'id')) {
                expect(strlen($value))->toBeLessThanOrEqual(32);
            }
        }
    }

    foreach (datasetEntity($path, 'Shift') as $shift) {
        expect($shift['end_datetime'] > $shift['start_datetime'])->toBeTrue();
    }
})->with(['DC_US', 'DC_CA']);

it('writes the Input_Reference from now and the spec, and nothing else', function () {
    expect(datasetEntity(generateDataset('DC_US')->path, 'Input_Reference'))->toBe([[
        'datetime' => '2026-10-30T15:30:00+00:00',
        'id' => '1793374200',
        'input_type' => 'LOAD',
        'organisation_id' => '2',
        'dataset_id' => 'DC_US',
        'schedule_data' => 'CONTINUOUS',
        'load_status' => '0',
        'duration' => 'P7D',
        'appointment_window_duration' => 'P90D',
        'process_type' => 'APPOINTMENT',
    ]]);
});

it('covers the whole horizon with each extra region, in that region\'s time zone', function () {
    $path = generateDataset('DC_US')->path;

    expect(datasetEntity($path, 'Availability'))->toBe([
        ['id' => 'AV-R-DEN-001-MTN', 'datetime_start' => '2026-10-23T06:00:00+00:00', 'datetime_end' => '2027-02-08T07:00:00+00:00'],
        ['id' => 'AV-R-DEN-002-MTN', 'datetime_start' => '2026-10-23T06:00:00+00:00', 'datetime_end' => '2027-02-08T07:00:00+00:00'],
    ])->and(datasetEntity($path, 'Resource_Region_Availability')[0])->toBe([
        'id' => 'RRA-R-DEN-001-MTN',
        'resource_id' => 'R-DEN-001',
        'region_id' => 'MTN',
        'availability_id' => 'AV-R-DEN-001-MTN',
        'override_priority' => '1',
    ]);
});

it('starts depot types at their region depot and home types at their own home', function () {
    $resources = collect(datasetEntity(generateDataset('DC_US')->path, 'Resources'))->keyBy('id');

    expect($resources['R-CHI-001']['location_id_start'])->toBe('H-R-CHI-001')
        ->and($resources->firstWhere('resource_type_id', 'INSTALLER')['location_id_start'])->toStartWith('D-')
        ->and($resources['R-CHI-001']['utilisation'])->toBe('90');
});

it('names object groups {dataset}-{resource}', function () {
    expect(datasetEntity(generateDataset('DC_US')->path, 'Object_Group')[0])
        ->toBe(['group_id' => 'DC_US', 'id' => 'DC_US-R-CHI-001', 'resource_id' => 'R-CHI-001']);
});

it('keeps homes out of the exclude polygons (the lakes)', function (string $id, string $region) {
    $spec = app(DatasetSpecRepository::class)->load($id);

    foreach (datasetEntity(generateDataset($id)->path, 'Location') as $location) {
        expect($spec->regions[$region]->excludes((float) $location['latitude'], (float) $location['longitude']))->toBeFalse();
    }

    // The polygon does cover the water it is there for.
    expect($spec->regions[$region]->excludes(...($id === 'DC_US' ? [41.90, -87.50] : [43.55, -79.40])))->toBeTrue();
})->with([['DC_US', 'CHI'], ['DC_CA', 'TOR']]);

it('writes the same bytes for the same spec, seed and now, and different homes for another seed', function () {
    $first = generateDataset('DC_US', directory: datasetBuilderDirectory().'/a');
    $second = generateDataset('DC_US', directory: datasetBuilderDirectory().'/b');
    $reseeded = generateDataset('DC_US', seed: 7, directory: datasetBuilderDirectory().'/c');

    expect(file_get_contents($first->path))->toBe(file_get_contents($second->path))
        ->and(datasetEntity($reseeded->path, 'Location'))->not->toBe(datasetEntity($first->path, 'Location'))
        ->and(datasetEntity($reseeded->path, 'Shift'))->toBe(datasetEntity($first->path, 'Shift'));
});

it('takes day 0 from each region\'s own date, not from the UTC date', function () {
    // 03:30Z on Sat 31 Oct is still Friday evening in Los Angeles (20:30), so its day 0 is Fri 30 Oct and day -7 is Fri 23 Oct.
    $summary = generateDataset('DC_US', now: '2026-10-31T03:30:00Z')->summary;
    $lax = collect($summary['regions'])->firstWhere('region', 'LAX');

    expect($lax['first_local'])->toStartWith('Fri 2026-10-23');
});
