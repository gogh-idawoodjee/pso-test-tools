<?php

use App\Support\DatasetBuilder\DatasetGenerator;
use App\Support\DatasetBuilder\DatasetSpecRepository;
use App\Support\DatasetBuilder\DsModellingWriter;
use App\Support\DatasetBuilder\GeneratedDataset;
use App\Support\DatasetBuilder\ModellingData;
use App\Support\DatasetBuilder\Spec\DatasetSpec;
use Illuminate\Support\Facades\File;

const DATASET_BUILDER_EMIT_ORDER = [
    'Input_Reference', 'Region', 'Location', 'Skill', 'Resource_Type', 'Rule', 'Rule_Collection', 'Rule_Collection_Rule',
    'Rule_Parameter', 'Resources', 'Resource_Region', 'Resource_Skill', 'Availability', 'Resource_Region_Availability',
    'Shift', 'Shift_Break', 'Object_Group', 'Source_Data', 'Source_Data_Parameter',
];

const DATASET_BUILDER_RAM_EMIT_ORDER = [
    'RAM_Update', 'RAM_Division', 'RAM_Location', 'RAM_Skill', 'RAM_Resource_Type', 'RAM_Rule', 'RAM_Rule_Collection',
    'RAM_Rule_Collection_Rule', 'RAM_Rule_Parameter', 'RAM_Resource', 'RAM_Resource_Division', 'RAM_Resource_Skill',
    'RAM_Shift_Category', 'RAM_Shift_Template', 'RAM_Shift_Template_Item', 'RAM_Shift_Template_Item_Break',
];

function datasetBuilderDirectory(): string
{
    return storage_path('framework/testing/dataset-builder');
}

function generateDataset(string $id, string $now = '2026-10-30T15:30:00Z', ?int $seed = null, ?string $directory = null, ?DatasetSpec $spec = null): GeneratedDataset
{
    $spec ??= app(DatasetSpecRepository::class)->load($id);

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
        ->and($resources->firstWhere('resource_type_id', 'INSTALLER')['location_id_start'])->toStartWith('D-');
});

it('leaves everything the type carries off the resource, and utilisation off the shift', function () {
    $path = generateDataset('DC_US')->path;

    foreach (datasetEntity($path, 'Resources') as $resource) {
        expect(array_keys($resource))->toBe(['id', 'first_name', 'surname', 'resource_type_id', 'location_id_start', 'location_id_end', 'rule_collection_id', 'memo']);
    }

    foreach (datasetEntity($path, 'Shift') as $shift) {
        expect($shift)->not->toHaveKey('utilisation');
    }

    expect(datasetEntity($path, 'Resource_Type')[1])->toMatchArray(['id' => 'SENIOR_TECH', 'utilisation' => '90', 'cost_ph' => '65', 'resource_class_id' => 'STANDARD']);
});

it('puts a seed override on the resource in both files, and nothing else', function () {
    $data = json_decode((string) file_get_contents(resource_path('datasets/DC_US.json')), true);
    $data['resources'][0]['overrides'] = ['utilisation' => 80, 'travel_to' => 'PT45M'];
    $result = generateDataset('DC_US', spec: DatasetSpec::fromArray($data));

    $load = collect(datasetEntity($result->path, 'Resources'))->keyBy('id');
    $ram = collect(datasetEntity($result->modellingPath, 'RAM_Resource'))->keyBy('id');

    expect($load['R-CHI-001'])->toMatchArray(['travel_to' => 'PT45M', 'utilisation' => '80'])
        ->and(array_keys($load['R-CHI-001']))->toBe(['id', 'first_name', 'surname', 'resource_type_id', 'location_id_start', 'location_id_end', 'travel_to', 'utilisation', 'rule_collection_id', 'memo'])
        ->and($ram['R-CHI-001'])->toMatchArray(['travel_to' => 'PT45M', 'utilisation' => '80'])
        ->and($load['R-CHI-002'])->not->toHaveKey('utilisation')
        ->and($ram['R-CHI-002'])->not->toHaveKey('utilisation');
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
        ->and(file_get_contents($first->modellingPath))->toBe(file_get_contents($second->modellingPath))
        ->and(datasetEntity($reseeded->path, 'Location'))->not->toBe(datasetEntity($first->path, 'Location'))
        ->and(datasetEntity($reseeded->path, 'Shift'))->toBe(datasetEntity($first->path, 'Shift'));
});

it('takes day 0 from each region\'s own date, not from the UTC date', function () {
    // 03:30Z on Sat 31 Oct is still Friday evening in Los Angeles (20:30), so its day 0 is Fri 30 Oct and day -7 is Fri 23 Oct.
    $summary = generateDataset('DC_US', now: '2026-10-31T03:30:00Z')->summary;
    $lax = collect($summary['regions'])->firstWhere('region', 'LAX');

    expect($lax['first_local'])->toStartWith('Fri 2026-10-23');
});

it('ends the LOAD with Source_Data RAM, rota_id the dataset id', function (string $id) {
    $path = generateDataset($id)->path;

    expect(datasetEntity($path, 'Source_Data'))->toBe([['source_data_type_id' => 'RAM', 'sequence' => '1']])
        ->and(datasetEntity($path, 'Source_Data_Parameter'))->toBe([['source_data_type_id' => 'RAM', 'sequence' => '1', 'parameter_name' => 'rota_id', 'parameter_value' => $id]]);
})->with(['DC_US', 'DC_CA']);

it('writes no .arp and no Source_Data when the spec turns modelling off', function () {
    $data = json_decode((string) file_get_contents(resource_path('datasets/DC_CA.json')), true);
    $data['modelling'] = false;
    $result = generateDataset('DC_CA', spec: DatasetSpec::fromArray($data));

    expect($result->modellingPath)->toBeNull()
        ->and($result->summary['modelling'])->toBeNull()
        ->and(datasetEntity($result->path, 'Source_Data'))->toBe([])
        ->and(glob(dirname($result->path).'/*.arp'))->toBe([]);
});

it('writes the .arp beside the LOAD, in the RAM cheat sheet emit order, with no rota and nothing empty', function (string $id) {
    $result = generateDataset($id);
    $path = $result->modellingPath;

    expect($path)->toBe(dirname($result->path).'/20261030-1530-stage1r.arp')
        ->and(simplexml_load_file($path)->getName())->toBe('DsModelling')
        ->and(simplexml_load_file($path)->getNamespaces())->toBe(['' => 'http://360Scheduling.com/Schema/DsModelling.xsd']);

    $sequence = [];

    foreach (datasetRows($path) as $row) {
        if (end($sequence) !== $row['entity']) {
            $sequence[] = $row['entity'];
        }

        foreach ($row['fields'] as $name => $value) {
            expect($value)->not->toBe('');

            if (str_ends_with($name, 'id')) {
                expect(strlen($value))->toBeLessThanOrEqual(32);
            }
        }
    }

    expect($sequence)->toBe(DATASET_BUILDER_RAM_EMIT_ORDER)
        ->and(datasetEntity($path, 'RAM_Update'))->toBe([[
            'organisation_id' => '2',
            'dataset_id' => $id,
            'id' => '1793374200',
            'ram_update_type_id' => 'LOAD',
            'description' => "{$id} modelling data, generated 2026-10-30 15:30Z",
            'is_master_data' => 'true',
        ]]);
})->with(['DC_US', 'DC_CA']);

it('models the same resources, locations, skills, regions and types as the LOAD', function (string $id) {
    $result = generateDataset($id);
    $load = $result->path;
    $ram = $result->modellingPath;
    $ids = static fn (string $path, string $entity, string $field = 'id'): array => array_column(datasetEntity($path, $entity), $field);
    $sorted = static function (array $values): array {
        sort($values);

        return $values;
    };

    expect($sorted($ids($ram, 'RAM_Resource')))->toBe($sorted($ids($load, 'Resources')))
        ->and($sorted($ids($ram, 'RAM_Location')))->toBe($sorted($ids($load, 'Location')))
        ->and($sorted($ids($ram, 'RAM_Skill')))->toBe($sorted($ids($load, 'Skill')))
        ->and($sorted($ids($ram, 'RAM_Division')))->toBe($sorted($ids($load, 'Region')))
        ->and($sorted($ids($ram, 'RAM_Resource_Type')))->toBe($sorted($ids($load, 'Resource_Type')));

    // Home divisions match Resource_Region; extra ones the LOAD's time-bounded Resource_Region_Availability.
    $memberships = [
        ...array_map(static fn (array $row): string => "{$row['resource_id']} {$row['region_id']}", datasetEntity($load, 'Resource_Region')),
        ...array_map(static fn (array $row): string => "{$row['resource_id']} {$row['region_id']}", datasetEntity($load, 'Resource_Region_Availability')),
    ];
    $divisions = array_map(static fn (array $row): string => "{$row['ram_resource_id']} {$row['ram_division_id']}", datasetEntity($ram, 'RAM_Resource_Division'));

    expect($sorted($divisions))->toBe($sorted($memberships));

    $loadSkills = array_map(static fn (array $row): string => "{$row['resource_id']} {$row['skill_id']}", datasetEntity($load, 'Resource_Skill'));
    $ramSkills = array_map(static fn (array $row): string => "{$row['ram_resource_id']} {$row['ram_skill_id']}", datasetEntity($ram, 'RAM_Resource_Skill'));

    expect($sorted($ramSkills))->toBe($sorted($loadSkills));

    $loadResources = collect(datasetEntity($load, 'Resources'))->keyBy('id');

    foreach (datasetEntity($ram, 'RAM_Resource') as $resource) {
        expect($resource['ram_resource_type_id'])->toBe($loadResources[$resource['id']]['resource_type_id'])
            ->and($resource['ram_location_id_start'])->toBe($loadResources[$resource['id']]['location_id_start'])
            ->and($resource['ram_location_id_end'])->toBe($loadResources[$resource['id']]['location_id_end']);
    }

    $loadLocations = collect(datasetEntity($load, 'Location'))->keyBy('id');

    foreach (datasetEntity($ram, 'RAM_Location') as $location) {
        expect($location['latitude'])->toBe($loadLocations[$location['id']]['latitude'])
            ->and($location['longitude'])->toBe($loadLocations[$location['id']]['longitude']);
    }
})->with(['DC_US', 'DC_CA']);

it('has templates that reproduce the first week of each type\'s loaded shifts, in the division\'s zone', function (string $id) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);
    $templates = collect(datasetEntity($result->modellingPath, 'RAM_Shift_Template'))->keyBy('id');
    $items = collect(datasetEntity($result->modellingPath, 'RAM_Shift_Template_Item'))->groupBy('ram_shift_template_id');
    $shifts = collect(datasetEntity($result->path, 'Shift'))->groupBy('resource_id');
    $checked = 0;

    foreach ($spec->resources as $resource) {
        $template = $templates[$resource->resourceType];
        $zone = new DateTimeZone($spec->regions[$resource->region]->timeZone);
        $first = null;
        $week = [];

        foreach ($shifts[$resource->id] as $shift) {
            $start = (new DateTimeImmutable($shift['start_datetime']))->setTimezone($zone);
            $first ??= $start->setTime(0, 0);

            if ($start >= $first->modify('+7 days')) {
                break;
            }

            $end = (new DateTimeImmutable($shift['end_datetime']))->setTimezone($zone);
            $week[(int) $start->format('N') - 1] = [
                'time_increment' => ModellingData::clockDuration($start->format('H:i')),
                'duration' => ModellingData::clockDuration($end->format('H:i'), $start->format('H:i')),
            ];
        }

        ksort($week);
        $expected = [];

        foreach ($items[$resource->resourceType] as $item) {
            $expected[(int) $item['day_increment']] = ['time_increment' => $item['time_increment'], 'duration' => $template['duration']];
        }

        ksort($expected);
        expect($week)->toBe($expected);
        $checked++;
    }

    expect($checked)->toBe(count($spec->resources));
})->with(['DC_US', 'DC_CA']);

it('counts what the brief expects in the .arp', function (string $id, array $counts) {
    expect(generateDataset($id)->summary['modelling']['counts'])->toMatchArray($counts);
})->with([
    'DC_US' => ['DC_US', ['RAM_Update' => 1, 'RAM_Division' => 6, 'RAM_Resource' => 36, 'RAM_Resource_Type' => 4, 'RAM_Shift_Category' => 4, 'RAM_Shift_Template' => 4, 'RAM_Shift_Template_Item' => 19, 'RAM_Shift_Template_Item_Break' => 19]],
    'DC_CA' => ['DC_CA', ['RAM_Update' => 1, 'RAM_Division' => 2, 'RAM_Resource' => 12, 'RAM_Resource_Type' => 4, 'RAM_Shift_Category' => 4, 'RAM_Shift_Template' => 4, 'RAM_Shift_Template_Item' => 19, 'RAM_Shift_Template_Item_Break' => 19]],
]);

it('builds the INSTALLER and CONTRACTOR templates the brief spells out', function () {
    $path = generateDataset('DC_US')->modellingPath;
    $templates = collect(datasetEntity($path, 'RAM_Shift_Template'))->keyBy('id');
    $items = collect(datasetEntity($path, 'RAM_Shift_Template_Item'))->groupBy('ram_shift_template_id');
    $breaks = collect(datasetEntity($path, 'RAM_Shift_Template_Item_Break'))->groupBy('ram_shift_template_id');

    expect($templates['INSTALLER'])->toBe([
        'id' => 'INSTALLER',
        'description' => 'Installer (4x10) Mon-Thu 07:00-17:30',
        'ram_shift_category_id' => 'INSTALLER',
        'recurrence_days' => '7',
        'start_day' => '1',
        'duration' => 'PT10H30M',
        'split_allowed' => 'true',
        'split_priority' => '1',
        'precedence' => '1',
        'repeat_preference' => '1',
    ])
        ->and($items['INSTALLER']->pluck('day_increment')->all())->toBe(['0', '1', '2', '3'])
        ->and($items['INSTALLER']->pluck('time_increment')->unique()->all())->toBe(['PT7H'])
        ->and($breaks['INSTALLER']->first())->toBe(['id' => 'INSTALLER-1', 'ram_shift_template_id' => 'INSTALLER', 'sequence' => '1', 'duration' => 'PT45M', 'earliest_start' => 'PT4H', 'latest_start' => 'PT5H30M'])
        ->and($breaks['INSTALLER']->pluck('id')->all())->toBe(['INSTALLER-1', 'INSTALLER-2', 'INSTALLER-3', 'INSTALLER-4'])
        ->and($items['CONTRACTOR']->pluck('day_increment')->all())->toBe(['1', '2', '3', '4', '5'])
        ->and($items['CONTRACTOR']->pluck('sequence')->all())->toBe(['1', '2', '3', '4', '5'])
        ->and($templates['FIELD_TECH']['duration'])->toBe('PT8H30M')
        ->and($items['SENIOR_TECH']->first()['time_increment'])->toBe('PT7H30M')
        ->and(collect(datasetEntity($path, 'RAM_Shift_Category'))->pluck('colour', 'id')->all())->toBe([
            'FIELD_TECH' => '#FF0087C1', 'SENIOR_TECH' => '#FF6A1B9A', 'INSTALLER' => '#FF2E7D32', 'CONTRACTOR' => '#FFEF6C00',
        ]);
});

it('never writes rota data into the .arp', function () {
    File::ensureDirectoryExists(datasetBuilderDirectory());
    $writer = new DsModellingWriter(datasetBuilderDirectory().'/never.arp');

    expect(fn () => $writer->row('RAM_Rota_Item', ['id' => 'x']))->toThrow(RuntimeException::class)
        ->and(fn () => $writer->row('RAM_Version_History', ['id' => 'x']))->toThrow(RuntimeException::class);
});
