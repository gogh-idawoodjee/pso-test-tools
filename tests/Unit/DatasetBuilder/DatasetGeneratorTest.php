<?php

use App\Support\DatasetBuilder\ActivityFactory;
use App\Support\DatasetBuilder\DatasetGenerator;
use App\Support\DatasetBuilder\DatasetSpecRepository;
use App\Support\DatasetBuilder\DsModellingWriter;
use App\Support\DatasetBuilder\DsScheduleDataWriter;
use App\Support\DatasetBuilder\GeneratedDataset;
use App\Support\DatasetBuilder\ModellingData;
use App\Support\DatasetBuilder\ShiftFactory;
use App\Support\DatasetBuilder\Spec\DatasetSpec;
use Illuminate\Support\Facades\File;

const DATASET_BUILDER_EMIT_ORDER = [
    'Input_Reference', 'Region', 'Location', 'Location_Region', 'Skill', 'Resource_Type', 'Rule', 'Rule_Collection', 'Rule_Collection_Rule',
    'Rule_Parameter', 'SLA_Type', 'Activity_Type', 'Appointment_Template', 'Appointment_Template_Item', 'Resources', 'Resource_Region', 'Resource_Skill', 'Availability', 'Resource_Region_Availability',
    'Shift', 'Shift_Break', 'Activity', 'Activity_Skill', 'Activity_SLA', 'Availability', 'Activity_Status',
];

/** The LOAD beside modelling data, in emit order. */
const DATASET_BUILDER_SLIM_EMIT_ORDER = [
    'Input_Reference', 'Source_Data', 'Source_Data_Parameter', 'Location', 'Location_Region',
    'Activity', 'Activity_Skill', 'Activity_SLA', 'Availability', 'Activity_Status',
];

const DATASET_BUILDER_RAM_EMIT_ORDER = [
    'RAM_Update', 'RAM_Division', 'RAM_Location', 'RAM_Skill', 'RAM_Resource_Type', 'RAM_Rule', 'RAM_Rule_Collection',
    'RAM_Rule_Collection_Rule', 'RAM_Rule_Parameter', 'RAM_Resource', 'RAM_Resource_Division', 'RAM_Time_Pattern',
    'RAM_Resource_Division_Avail', 'RAM_Resource_Skill',
    'RAM_Shift_Category', 'RAM_Shift_Template', 'RAM_Shift_Template_Item', 'RAM_Shift_Template_Item_Break',
    'RAM_Rota', 'RAM_Rota_Membership_Period', 'RAM_Rota_Alteration', 'RAM_Rota_Item', 'RAM_Rota_Item_Break',
    'RAM_SLA_Type', 'RAM_Activity_Type', 'RAM_Appointment_Template', 'RAM_Appointment_Template_Item',
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

/** The stage 1 LOAD, with everything in it: the spec with modelling off. */
function generateStage1Dataset(string $id, string $now = '2026-10-30T15:30:00Z', ?string $directory = null, ?Closure $change = null): GeneratedDataset
{
    $data = json_decode((string) file_get_contents(resource_path("datasets/{$id}.json")), true);
    $data['modelling'] = false;

    return generateDataset($id, $now, directory: $directory ?? datasetBuilderDirectory()."/{$id}-stage1", spec: DatasetSpec::fromArray($change ? $change($data) : $data));
}

/** @return list<array<string, string>> the .arp's rota items, as the Shift rows they replace: id, resource, instants, split rule */
function datasetShifts(GeneratedDataset $result): array
{
    return array_map(static fn (array $item): array => [
        'id' => $item['id'],
        'resource_id' => $item['ram_resource_id'],
        'start_datetime' => $item['start_datetime'],
        'end_datetime' => $item['end_datetime'],
        'split_allowed' => $item['split_allowed'],
        'split_priority' => $item['split_priority'],
    ], datasetEntity($result->modellingPath, 'RAM_Rota_Item'));
}

/** @return list<string> the entity names in a file, once each, in order */
function datasetEntityNames(string $path): array
{
    return array_values(array_unique(array_column(datasetRows($path), 'entity')));
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
    $stage1 = generateStage1Dataset($id);

    expect($result->summary['resources'])->toBe($resources)
        ->and(datasetEntity($result->modellingPath, 'RAM_Resource'))->toHaveCount($resources)
        ->and(datasetEntity($stage1->path, 'Resources'))->toHaveCount($resources)
        ->and(datasetEntity($stage1->path, 'Resource_Region'))->toHaveCount($resources);
})->with([['DC_US', 36], ['DC_CA', 12]]);

it('gives every resource the shifts its type works over the horizon', function (string $id) {
    $result = generateDataset($id);
    $types = collect($result->summary['types'])->keyBy('type');

    // Day -7 (Fri 2026-10-23) to day 100 (Sun 2027-02-07) is 108 days: 15 weeks plus a Fri, Sat and Sun.
    expect($types['FIELD_TECH']['min'])->toBe(76)->and($types['FIELD_TECH']['max'])->toBe(76)
        ->and($types['SENIOR_TECH']['min'])->toBe(76)->and($types['SENIOR_TECH']['max'])->toBe(76)
        ->and($types['INSTALLER']['min'])->toBe(60)->and($types['INSTALLER']['max'])->toBe(60)
        ->and($types['CONTRACTOR']['min'])->toBe(77)->and($types['CONTRACTOR']['max'])->toBe(77);

    $shifts = datasetShifts($result);

    expect($shifts)->toHaveCount($result->summary['shifts'])
        ->and(datasetEntity($result->modellingPath, 'RAM_Rota_Item_Break'))->toHaveCount(count($shifts));
})->with(['DC_US', 'DC_CA']);

it('starts shifts at the same local time across the DST change, so the UTC time moves', function (string $id, string $resource, string $friday, string $monday) {
    $shifts = collect(datasetShifts(generateDataset($id)))->where('resource_id', $resource)->keyBy(static fn (array $shift): string => substr($shift['id'], 3));

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

    foreach (datasetShifts($result) as $shift) {
        $monday = (new DateTimeImmutable(substr($shift['id'], 3)))->format('N') === '1';

        expect($shift['split_priority'])->toBe($monday ? '0' : '1');
    }

    expect($result->summary['monday_shifts_at_zero'])->toBe($result->summary['monday_shifts'])
        ->and($result->summary['other_shifts_at_one'])->toBe($result->summary['other_shifts']);
});

it('writes the cheat sheet entities only, in its emit order, with every reference resolved, when there is no modelling data', function (string $id) {
    $path = generateStage1Dataset($id)->path;
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
    $path = generateStage1Dataset('DC_US')->path;

    expect(array_values(array_filter(datasetEntity($path, 'Availability'), static fn (array $row): bool => ! isset($row['activity_id']))))->toBe([
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
    $resources = collect(datasetEntity(generateStage1Dataset('DC_US')->path, 'Resources'))->keyBy('id');

    expect($resources['R-CHI-001']['location_id_start'])->toBe('H-R-CHI-001')
        ->and($resources->firstWhere('resource_type_id', 'INSTALLER')['location_id_start'])->toStartWith('D-');
});

it('leaves everything the type carries off the resource, and utilisation off the shift', function () {
    $path = generateStage1Dataset('DC_US')->path;

    foreach (datasetEntity($path, 'Resources') as $resource) {
        expect(array_keys($resource))->toBe(['id', 'first_name', 'surname', 'resource_type_id', 'location_id_start', 'location_id_end', 'rule_collection_id', 'memo']);
    }

    foreach (datasetEntity($path, 'Shift') as $shift) {
        expect($shift)->not->toHaveKey('utilisation');
    }

    expect(datasetEntity($path, 'Resource_Type')[1])->toMatchArray(['id' => 'SENIOR_TECH', 'utilisation' => '90', 'cost_ph' => '65', 'resource_class_id' => 'STANDARD']);
});

it('puts a seed override on the resource in both files, and nothing else', function () {
    $override = static function (array $data): array {
        $data['resources'][0]['overrides'] = ['utilisation' => 80, 'travel_to' => 'PT45M'];

        return $data;
    };
    $result = generateDataset('DC_US', spec: DatasetSpec::fromArray($override(json_decode((string) file_get_contents(resource_path('datasets/DC_US.json')), true))));

    $load = collect(datasetEntity(generateStage1Dataset('DC_US', change: $override)->path, 'Resources'))->keyBy('id');
    $ram = collect(datasetEntity($result->modellingPath, 'RAM_Resource'))->keyBy('id');

    expect($load['R-CHI-001'])->toMatchArray(['travel_to' => 'PT45M', 'utilisation' => '80'])
        ->and(array_keys($load['R-CHI-001']))->toBe(['id', 'first_name', 'surname', 'resource_type_id', 'location_id_start', 'location_id_end', 'travel_to', 'utilisation', 'rule_collection_id', 'memo'])
        ->and($ram['R-CHI-001'])->toMatchArray(['travel_to' => 'PT45M', 'utilisation' => '80'])
        ->and($load['R-CHI-002'])->not->toHaveKey('utilisation')
        ->and($ram['R-CHI-002'])->not->toHaveKey('utilisation');
});

it('writes no Object_Group in either mode', function (string $id) {
    expect(datasetEntity(generateDataset($id)->path, 'Object_Group'))->toBe([])
        ->and(datasetEntity(generateStage1Dataset($id)->path, 'Object_Group'))->toBe([]);
})->with(['DC_US', 'DC_CA']);

it('keeps homes out of the exclude polygons (the lakes)', function (string $id, string $region) {
    $spec = app(DatasetSpecRepository::class)->load($id);

    foreach (datasetEntity(generateDataset($id)->modellingPath, 'RAM_Location') as $location) {
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
        ->and(datasetEntity($reseeded->modellingPath, 'RAM_Location'))->not->toBe(datasetEntity($first->modellingPath, 'RAM_Location'))
        ->and(datasetShifts($reseeded))->toBe(datasetShifts($first));
});

it('takes day 0 from each region\'s own date, not from the UTC date', function () {
    // 03:30Z on Sat 31 Oct is still Friday evening in Los Angeles (20:30), so its day 0 is Fri 30 Oct and day -7 is Fri 23 Oct.
    $summary = generateDataset('DC_US', now: '2026-10-31T03:30:00Z')->summary;
    $lax = collect($summary['regions'])->firstWhere('region', 'LAX');

    expect($lax['first_local'])->toStartWith('Fri 2026-10-23');
});

it('writes a slim LOAD beside the modelling data: Input_Reference, Source_Data RAM, activity locations and activities, no shifts', function (string $id) {
    $result = generateDataset($id);
    $path = $result->path;
    $stage1 = generateStage1Dataset($id)->path;

    expect(datasetEntityNames($path))->toBe(DATASET_BUILDER_SLIM_EMIT_ORDER)
        ->and(datasetEntity($path, 'Shift'))->toBe([])
        ->and(datasetEntity($path, 'Shift_Break'))->toBe([])
        ->and(datasetEntity($path, 'Activity'))->toBe(datasetEntity($stage1, 'Activity'))
        ->and(datasetEntity($path, 'Input_Reference'))->toBe(datasetEntity($stage1, 'Input_Reference'));

    // No entity in both files: every RAM entity's LOAD counterpart is missing from the LOAD.
    $counterparts = [
        'RAM_Division' => 'Region', 'RAM_Skill' => 'Skill', 'RAM_Resource_Type' => 'Resource_Type',
        'RAM_Rule' => 'Rule', 'RAM_Rule_Collection' => 'Rule_Collection', 'RAM_Rule_Collection_Rule' => 'Rule_Collection_Rule',
        'RAM_Rule_Parameter' => 'Rule_Parameter', 'RAM_Resource' => 'Resources', 'RAM_Resource_Division' => 'Resource_Region',
        'RAM_Resource_Division_Avail' => 'Resource_Region_Availability', 'RAM_Resource_Skill' => 'Resource_Skill',
        'RAM_SLA_Type' => 'SLA_Type', 'RAM_Activity_Type' => 'Activity_Type', 'RAM_Appointment_Template' => 'Appointment_Template',
        'RAM_Appointment_Template_Item' => 'Appointment_Template_Item', 'RAM_Rota_Item' => 'Shift', 'RAM_Rota_Item_Break' => 'Shift_Break',
    ];
    $ram = datasetEntityNames($result->modellingPath);

    expect(array_intersect(array_map(static fn (string $entity): string => $counterparts[$entity] ?? $entity, $ram), datasetEntityNames($path)))->toBe([])
        ->and(array_intersect($ram, datasetEntityNames($path)))->toBe([])
        // Availability in the slim LOAD is only ever an activity's accepted appointment; a resource's window is RAM_Time_Pattern.
        ->and(array_filter(datasetEntity($path, 'Availability'), static fn (array $row): bool => ! isset($row['activity_id'])))->toBe([])
        // Location and RAM_Location both exist, but never for the same place: activity locations are scheduling data, homes and depots RAM's.
        ->and(array_intersect(array_column(datasetEntity($path, 'Location'), 'id'), array_column(datasetEntity($result->modellingPath, 'RAM_Location'), 'id')))->toBe([]);

    $resources = array_column(datasetEntity($result->modellingPath, 'RAM_Resource'), 'id');

    expect(array_diff(array_column(datasetShifts($result), 'resource_id'), $resources))->toBe([]);
})->with(['DC_US', 'DC_CA']);

it('refuses anything RAM carries in a slim LOAD', function () {
    File::ensureDirectoryExists(datasetBuilderDirectory());
    $writer = new DsScheduleDataWriter(datasetBuilderDirectory().'/slim.xml', DsScheduleDataWriter::SLIM);

    expect(fn () => $writer->row('Resources', ['id' => 'x']))->toThrow(RuntimeException::class, 'belongs to the modelling data');
});

it('points the slim LOAD at RAM, rota_id the dataset id', function (string $id) {
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

it('models the same resources, locations, skills, regions and types as the stage 1 LOAD', function (string $id) {
    $result = generateDataset($id);
    $load = generateStage1Dataset($id)->path;
    $ram = $result->modellingPath;
    $ids = static fn (string $path, string $entity, string $field = 'id'): array => array_column(datasetEntity($path, $entity), $field);
    $sorted = static function (array $values): array {
        sort($values);

        return $values;
    };

    expect($sorted($ids($ram, 'RAM_Resource')))->toBe($sorted($ids($load, 'Resources')))
        ->and($sorted($ids($ram, 'RAM_Location')))->toBe($sorted(array_values(array_filter($ids($load, 'Location'), static fn (string $id): bool => ! str_starts_with($id, 'L-')))))
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

    // Each extra region's dated membership: the same window as the stage 1 Availability.
    $availability = collect(datasetEntity($load, 'Availability'))->keyBy('id');
    $patterns = collect(datasetEntity($ram, 'RAM_Time_Pattern'))->keyBy('id');
    $links = datasetEntity($load, 'Resource_Region_Availability');

    expect(datasetEntity($ram, 'RAM_Resource_Division_Avail'))->toHaveCount(count($links));

    foreach (datasetEntity($ram, 'RAM_Resource_Division_Avail') as $avail) {
        $pattern = $patterns[$avail['ram_time_pattern_id']];
        $window = $availability["AV-{$avail['ram_resource_id']}-{$avail['ram_division_id']}"];
        $start = new DateTimeImmutable($pattern['base_time']);

        expect($pattern['base_time'])->toBe($window['datetime_start'])
            ->and($start->add(new DateInterval($pattern['duration'])) >= new DateTimeImmutable($window['datetime_end'])->modify('-1 hour'))->toBeTrue()
            ->and("{$avail['ram_resource_id']} {$avail['ram_division_id']}")->toBeIn(array_map(static fn (array $row): string => "{$row['resource_id']} {$row['region_id']}", $links));
    }

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
    $shifts = collect(datasetShifts($result))->groupBy('resource_id');
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
    'DC_US' => ['DC_US', ['RAM_SLA_Type' => 5, 'RAM_Activity_Type' => 6, 'RAM_Appointment_Template' => 10, 'RAM_Appointment_Template_Item' => 14, 'RAM_Update' => 1, 'RAM_Division' => 6, 'RAM_Location' => 34, 'RAM_Resource' => 36, 'RAM_Resource_Division' => 38, 'RAM_Time_Pattern' => 2, 'RAM_Resource_Division_Avail' => 2, 'RAM_Resource_Type' => 4, 'RAM_Shift_Category' => 4, 'RAM_Shift_Template' => 4, 'RAM_Shift_Template_Item' => 19, 'RAM_Shift_Template_Item_Break' => 19]],
    'DC_CA' => ['DC_CA', ['RAM_SLA_Type' => 5, 'RAM_Activity_Type' => 6, 'RAM_Appointment_Template' => 3, 'RAM_Appointment_Template_Item' => 4, 'RAM_Update' => 1, 'RAM_Division' => 2, 'RAM_Location' => 11, 'RAM_Resource' => 12, 'RAM_Resource_Division' => 13, 'RAM_Time_Pattern' => 1, 'RAM_Resource_Division_Avail' => 1, 'RAM_Resource_Type' => 4, 'RAM_Shift_Category' => 4, 'RAM_Shift_Template' => 4, 'RAM_Shift_Template_Item' => 19, 'RAM_Shift_Template_Item_Break' => 19]],
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

it('writes only the five master rota tables into the .arp, never the planner\'s other rota data', function () {
    File::ensureDirectoryExists(datasetBuilderDirectory());
    $writer = new DsModellingWriter(datasetBuilderDirectory().'/never.arp');

    expect(fn () => $writer->row('RAM_Rota_Shift_Request', ['id' => 'x']))->toThrow(RuntimeException::class)
        ->and(fn () => $writer->row('RAM_Version_History', ['id' => 'x']))->toThrow(RuntimeException::class);
});

it('bounds each extra region in RAM with a time pattern from day -7 in that division\'s zone', function () {
    $path = generateDataset('DC_US')->modellingPath;

    expect(datasetEntity($path, 'RAM_Time_Pattern')[0])->toBe(['id' => 'TP-R-DEN-001-MTN', 'base_time' => '2026-10-23T06:00:00+00:00', 'duration' => 'P108D'])
        ->and(datasetEntity($path, 'RAM_Resource_Division_Avail')[0])->toBe([
            'id' => 'RDA-R-DEN-001-MTN',
            'ram_resource_id' => 'R-DEN-001',
            'ram_division_id' => 'MTN',
            'ram_time_pattern_id' => 'TP-R-DEN-001-MTN',
            'override_priority' => '1',
        ])
        ->and(collect(datasetEntity($path, 'RAM_Resource_Division'))->where('ram_resource_id', 'R-DEN-001')->pluck('ram_division_id')->values()->all())->toBe(['DEN', 'MTN']);
});

it('models the activity reference data in the .arp, PROJECT the only splittable type', function () {
    $path = generateDataset('DC_CA')->modellingPath;
    $activityTypes = collect(datasetEntity($path, 'RAM_Activity_Type'))->keyBy('id');

    expect($activityTypes->keys()->all())->toBe(['REPAIR', 'MAINT', 'INSTALL', 'SURVEY', 'PROJECT', 'EMERGENCY'])
        ->and($activityTypes['PROJECT'])->toBe([
            'id' => 'PROJECT',
            'description' => 'Project work',
            'duration' => 'PT12H',
            'base_value' => '1000',
            'supply_locations' => 'false',
            'split_allowed' => 'true',
            'interrupt' => 'false',
            'minimum_split' => 'PT2H',
            'cost_of_split' => '200',
            'priority' => '1',
            'on_location_duration_factor' => '1',
        ])
        ->and($activityTypes->where('split_allowed', 'true')->keys()->all())->toBe(['PROJECT'])
        ->and($activityTypes['INSTALL']['duration'])->toBe('PT2H15M');

    $slaTypes = collect(datasetEntity($path, 'RAM_SLA_Type'))->keyBy('id');

    expect($slaTypes->keys()->all())->toBe(['APPOINTMENT', 'IN_SLA', 'OUT_SLA', 'PROJECT', 'EMERGENCY'])
        ->and($slaTypes['IN_SLA'])->toBe([
            'id' => 'IN_SLA',
            'description' => 'Inside agreed window',
            'start_proportion' => '1',
            'end_proportion' => '1',
            'curve_shape' => '1',
            'activity_ageing_factor' => '1',
            'generate_jeopardy_exceptions' => 'true',
            'ram_sla_mechanism_type_id' => 'POWER_BASED',
        ])
        ->and($slaTypes['APPOINTMENT']['curve_shape'])->toBe('0.9')
        ->and($slaTypes['OUT_SLA']['start_proportion'])->toBe('0.95')
        ->and($slaTypes['OUT_SLA']['generate_jeopardy_exceptions'])->toBe('false')
        ->and($slaTypes['EMERGENCY']['activity_ageing_factor'])->toBe('1.1');

    $templates = collect(datasetEntity($path, 'RAM_Appointment_Template'))->keyBy('id');
    $items = collect(datasetEntity($path, 'RAM_Appointment_Template_Item'))->groupBy('ram_appointment_template_id');

    expect($templates->keys()->all())->toBe(['TOR-AMPM', 'TOR-ALLDAY', 'ONT_RURAL-ALLDAY'])
        ->and($templates['TOR-AMPM'])->toBe([
            'id' => 'TOR-AMPM',
            'description' => 'Toronto Metro AM/PM weekdays',
            'day_pattern' => 'YYYYYNN',
            'number_of_slots' => '10',
            'exclude_to_template_start' => 'false',
            'start_based' => 'true',
            'offer_expiry_window' => 'PT3M',
            'default_template' => 'false',
            'blocking' => 'false',
            'time_zone' => 'America/Toronto',
        ])
        ->and($templates['ONT_RURAL-ALLDAY']['number_of_slots'])->toBe('5')
        ->and($items['TOR-AMPM']->all())->toBe([
            ['ram_appointment_template_id' => 'TOR-AMPM', 'slot_start_time' => 'PT8H', 'slot_end_time' => 'PT12H'],
            ['ram_appointment_template_id' => 'TOR-AMPM', 'slot_start_time' => 'PT12H', 'slot_end_time' => 'PT17H'],
        ])
        ->and($items['TOR-ALLDAY']->all())->toBe([['ram_appointment_template_id' => 'TOR-ALLDAY', 'slot_start_time' => 'PT8H', 'slot_end_time' => 'PT17H']]);
});

it('makes each region\'s templates in its own zone, metros AM/PM and all day, rurals all day', function () {
    $templates = collect(datasetEntity(generateDataset('DC_US')->modellingPath, 'RAM_Appointment_Template'));

    expect($templates->pluck('time_zone', 'id')->all())->toBe([
        'CHI-AMPM' => 'America/Chicago', 'CHI-ALLDAY' => 'America/Chicago',
        'LAX-AMPM' => 'America/Los_Angeles', 'LAX-ALLDAY' => 'America/Los_Angeles',
        'DFW-AMPM' => 'America/Chicago', 'DFW-ALLDAY' => 'America/Chicago',
        'DEN-AMPM' => 'America/Denver', 'DEN-ALLDAY' => 'America/Denver',
        'MTN-ALLDAY' => 'America/Denver', 'IOWA-ALLDAY' => 'America/Chicago',
    ]);
});

it('puts the scheduling-side reference data in the LOAD when there is no modelling data', function () {
    $path = generateStage1Dataset('DC_CA')->path;

    expect(datasetEntity($path, 'SLA_Type')[1])->toBe([
        'id' => 'IN_SLA',
        'description' => 'Inside agreed window',
        'revenue' => '1000',
        'penalty' => '1000',
        'cost' => '0',
        'early_sla_benefit' => '0',
        'mechanism_type' => 'POWER_BASED',
        'start_proportion' => '1',
        'end_proportion' => '1',
        'curve_shape' => '1',
        'activity_ageing_factor' => '1',
        'generate_jeopardy_exceptions' => 'true',
    ])
        ->and(collect(datasetEntity($path, 'Activity_Type'))->firstWhere('id', 'PROJECT'))->toBe([
            'id' => 'PROJECT',
            'description' => 'Project work',
            'duration' => 'PT12H',
            'base_value' => '1000',
            'split_allowed' => 'true',
            'min_split' => 'PT2H',
            'interrupt' => 'false',
            'cost_of_split' => '200',
            'priority' => '1',
            'on_location_duration_factor' => '1',
            'remote_fulfillment' => 'false',
            'ignore_days_old' => '1000',
        ])
        ->and(datasetEntity($path, 'Appointment_Template')[0])->toBe([
            'id' => 'TOR-AMPM',
            'description' => 'Toronto Metro AM/PM weekdays',
            'day_pattern' => 'YYYYYNN',
            'number_of_slots' => '10',
            'appointment_window_length' => 'PT4H',
            'template_start_day_of_week' => '1',
            'template_start_time_of_day' => 'PT8H',
            'exclude_to_template_start' => 'false',
            'time_zone' => 'America/Toronto',
            'start_based' => 'true',
            'offer_expiry_window' => 'PT3M',
            'default_template' => 'false',
            'blocking' => 'false',
        ])
        ->and(datasetEntity($path, 'Appointment_Template_Item')[1])->toBe([
            'appointment_template_id' => 'TOR-AMPM',
            'slot_start_time' => 'PT12H',
            'slot_end_time' => 'PT17H',
            'slot_usage_window_start' => 'PT8H',
            'slot_usage_window_end' => 'PT17H',
        ])
        ->and(datasetEntity($path, 'Appointment_Template'))->toHaveCount(3)
        ->and(datasetEntity($path, 'Appointment_Template_Item'))->toHaveCount(4);
});

/** @return array<string, list<array<string, string>>> entity => rows for one activity id */
function activityRows(string $path): array
{
    $rows = [];

    foreach (datasetRows($path) as $row) {
        $id = $row['fields']['activity_id'] ?? ($row['entity'] === 'Activity' ? $row['fields']['id'] : null);

        if ($id !== null) {
            $rows[$id][$row['entity']][] = $row['fields'];
        }
    }

    return $rows;
}

it('makes round(2 x serving resources) jobs a working day per region, split by the mix, emergencies on the first day only', function (string $id, int $total) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);
    $activities = collect(datasetEntity($result->path, 'Activity'))->where('appointed', 'false')->where('activity_type_id', '!=', 'PROJECT');

    expect($activities)->toHaveCount($total)->and($result->summary['activities'])->toBe($total);

    foreach ($spec->regions as $region) {
        $perDay = (int) round(2 * $spec->servingResources($region->id));
        $days = $result->summary['activity_days'][$region->id] ?? [];
        $byType = ActivityFactory::largestRemainder($perDay * 5, $spec->stage2->mix);

        expect(array_sum(array_map('array_sum', $days)))->toBe($perDay * 5);

        if ($perDay === 0) {
            continue;
        }

        expect($days)->toHaveCount(5);

        foreach (['REPAIR', 'MAINT', 'EMERGENCY'] as $type) {
            expect(array_sum(array_column($days, $type)))->toBe($byType[$type]);
        }

        expect(array_slice(array_column($days, 'EMERGENCY'), 1))->toBe([])
            ->and(array_values($days)[0]['EMERGENCY'] ?? 0)->toBe($byType['EMERGENCY']);
    }
})->with([['DC_US', 370], ['DC_CA', 125]]);

it('gives every activity one location in its region, one status, two touching SLA rows and at most one skill two residents hold', function (string $id) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);
    $path = $result->path;
    $now = new DateTimeImmutable('2026-10-30T15:30:00Z');
    $locations = collect(datasetEntity($path, 'Location'))->keyBy('id');
    $locationRegions = collect(datasetEntity($path, 'Location_Region'))->groupBy('location_id');

    expect(datasetEntity($path, 'Location_Region'))->toHaveCount(count(datasetEntity($path, 'Activity')));

    foreach (activityRows($path) as $activityId => $rows) {
        $activity = $rows['Activity'][0];

        if ($activity['appointed'] === 'true' || $activity['activity_type_id'] === 'PROJECT') {
            continue;
        }

        $region = substr($activityId, 2, -7);
        $type = $activity['activity_type_id'];
        $minutes = (new DateTimeImmutable('2000-01-01'))->add(new DateInterval($activity['duration']))->getTimestamp() / 60 - (new DateTimeImmutable('2000-01-01'))->getTimestamp() / 60;
        $range = $spec->stage2->durations[$type];

        expect($rows['Activity'])->toHaveCount(1)
            ->and($rows['Activity_Status'])->toBe([[
                'activity_id' => $activityId, 'status_id' => '0', 'visit_id' => '1', 'fixed' => 'false',
                'date_time_status' => $activity['date_time_created'], 'date_time_stamp' => $activity['date_time_created'],
            ]])
            ->and(count($rows['Activity_Skill'] ?? []))->toBeLessThanOrEqual(1)
            ->and($activity['location_id'])->toBe('L-'.substr($activityId, 2, -6).sprintf('%05d', (int) substr($activityId, -6)))
            ->and($locationRegions[$activity['location_id']]->all())->toBe([['region_id' => $region, 'location_id' => $activity['location_id']]])
            ->and($spec->regions[$region]->excludes((float) $locations[$activity['location_id']]['latitude'], (float) $locations[$activity['location_id']]['longitude']))->toBeFalse()
            ->and($minutes % 15)->toBe(0)
            ->and($minutes)->toBeGreaterThanOrEqual(15)
            ->and($minutes)->toBeLessThanOrEqual((int) ceil($range['max'] / 15) * 15)
            ->and((int) $activity['base_value'])->toBe((int) round(1000 * $minutes / 60 * ($type === 'EMERGENCY' ? 4 : 1)))
            ->and($activity['description'])->toBe("{$type} : {$locations[$activity['location_id']]['address_line1']}")
            ->and($activity['date_time_open'])->toBe($activity['date_time_created']);

        $created = new DateTimeImmutable($activity['date_time_created']);
        $dayMinusOne = ShiftFactory::dayZero($now, new DateTimeZone($spec->regions[$region]->timeZone))->modify('-1 day');

        expect($created <= $now)->toBeTrue()
            ->and($created >= $now->modify('-72 hours'))->toBeTrue()
            ->and($created >= $dayMinusOne)->toBeTrue();

        [$primary, $secondary] = $rows['Activity_SLA'];

        expect($rows['Activity_SLA'])->toHaveCount(2)
            ->and($primary['sla_type_id'])->toBe($type === 'EMERGENCY' ? 'EMERGENCY' : 'IN_SLA')
            ->and($primary['priority'])->toBe('1')
            ->and($secondary)->toMatchArray(['sla_type_id' => 'OUT_SLA', 'priority' => '2', 'start_based' => 'true'])
            ->and($primary['start_based'])->toBe('true')
            ->and($secondary['datetime_start'])->toBe($primary['datetime_end'])
            ->and($primary['datetime_start'] < $primary['datetime_end'])->toBeTrue()
            ->and($secondary['datetime_start'] < $secondary['datetime_end'])->toBeTrue()
            ->and(new DateTimeImmutable($primary['datetime_start']) >= $now)->toBeTrue();

        // The priority 1 window ends at 17:00 in the region.
        expect((new DateTimeImmutable($primary['datetime_end']))->setTimezone(new DateTimeZone($spec->regions[$region]->timeZone))->format('H:i'))->toBe('17:00');

        foreach ($rows['Activity_Skill'] ?? [] as $skill) {
            $holders = count(array_filter($spec->servingResourceList($region), static fn ($resource): bool => in_array($skill['skill_id'], $resource->skills, true)));

            expect($holders)->toBeGreaterThanOrEqual(2)
                ->and($skill['skill_id'])->toBeIn($spec->stage2->skills[$type]['pool'])
                ->and($skill['share_with_corequisites'])->toBe('false');
        }
    }
})->with(['DC_US', 'DC_CA']);

it('takes each region\'s working days in its own zone: a 17:00 Toronto Friday run starts Toronto on Monday, Los Angeles on Friday', function () {
    $now = new DateTimeImmutable('2026-10-30T21:00:00Z'); // Fri 17:00 Toronto, 14:00 Los Angeles
    $ca = app(DatasetSpecRepository::class)->load('DC_CA');
    $us = app(DatasetSpecRepository::class)->load('DC_US');
    $dates = static fn (array $days): array => array_map(static fn (DateTimeImmutable $day): string => $day->format('D Y-m-d'), $days);

    expect($dates(ActivityFactory::workingDays($ca, $ca->regions['TOR'], $now)))->toBe(['Mon 2026-11-02', 'Tue 2026-11-03', 'Wed 2026-11-04', 'Thu 2026-11-05', 'Fri 2026-11-06'])
        ->and($dates(ActivityFactory::workingDays($us, $us->regions['LAX'], $now)))->toBe(['Fri 2026-10-30', 'Mon 2026-11-02', 'Tue 2026-11-03', 'Wed 2026-11-04', 'Thu 2026-11-05']);
});

it('keeps 17:00 local on both sides of the DST change in a 10-day MAINT window', function () {
    $path = generateDataset('DC_CA')->path;
    $maint = collect(activityRows($path))->first(static fn (array $rows): bool => $rows['Activity'][0]['activity_type_id'] === 'MAINT' && str_starts_with($rows['Activity_SLA'][0]['datetime_start'], '2026-10-30'));
    [$primary, $secondary] = $maint['Activity_SLA'];

    // Fri 30 Oct, 11:30 Toronto (EDT, -4) is now; ten days on is Mon 9 Nov, 17:00 EST (-5) = 22:00Z.
    expect($primary['datetime_start'])->toBe('2026-10-30T15:30:00+00:00')
        ->and($primary['datetime_end'])->toBe('2026-11-09T22:00:00+00:00')
        ->and($secondary['datetime_end'])->toBe('2026-11-16T22:00:00+00:00');
});

it('leaves the .arp exactly as it was before activities: they are scheduling data', function () {
    $data = json_decode((string) file_get_contents(resource_path('datasets/DC_CA.json')), true);
    unset($data['stage2'], $data['stage3'], $data['stage4']);
    $without = generateDataset('DC_CA', directory: datasetBuilderDirectory().'/without', spec: DatasetSpec::fromArray($data));
    $with = generateDataset('DC_CA', directory: datasetBuilderDirectory().'/with');

    expect(file_get_contents($with->modellingPath))->toBe(file_get_contents($without->modellingPath))
        ->and(datasetEntity($without->path, 'Activity'))->toBe([]);
});

it('splits a total by largest remainder, exactly', function () {
    expect(ActivityFactory::largestRemainder(90, ['REPAIR' => 0.55, 'MAINT' => 0.30, 'EMERGENCY' => 0.15]))->toBe(['REPAIR' => 50, 'MAINT' => 27, 'EMERGENCY' => 13])
        ->and(ActivityFactory::largestRemainder(13, [1, 1, 1, 1, 1]))->toBe([3, 3, 3, 2, 2])
        ->and(ActivityFactory::largestRemainder(0, ['A' => 1]))->toBe(['A' => 0]);
});

it('books round(1 x serving resources) appointments a day per region over ten appointment days, split by the mix', function (string $id, int $total) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);

    expect(collect(datasetEntity($result->path, 'Activity'))->where('appointed', 'true'))->toHaveCount($total)
        ->and($result->summary['appointments'])->toBe($total);

    foreach ($spec->regions as $region) {
        $perDay = (int) round($spec->servingResources($region->id));
        $days = $result->summary['appointment_days'][$region->id];

        expect($days)->toHaveCount(10);

        foreach ($days as $types) {
            expect(array_map('array_sum', $types))->toBe(array_filter(ActivityFactory::largestRemainder($perDay, $spec->stage3->mix)));
        }
    }
})->with([['DC_US', 370], ['DC_CA', 130]]);

it('gives every appointment one APPOINTMENT SLA row, one accepted-appointment Availability and date_time_earliest, all on a template slot', function (string $id) {
    $path = generateDataset($id)->path;
    $spec = app(DatasetSpecRepository::class)->load($id);
    $now = new DateTimeImmutable('2026-10-30T15:30:00Z');
    $checked = 0;

    foreach (activityRows($path) as $activityId => $rows) {
        $activity = $rows['Activity'][0];

        if ($activity['appointed'] !== 'true') {
            expect($rows)->not->toHaveKey('Availability');

            continue;
        }

        $region = $spec->regions[substr($activityId, 2, -7)];
        $zone = new DateTimeZone($region->timeZone);
        [$sla] = $rows['Activity_SLA'];
        [$availability] = $rows['Availability'];
        $status = $rows['Activity_Status'][0];
        $start = new DateTimeImmutable($sla['datetime_start']);
        $local = $start->setTimezone($zone);
        $end = (new DateTimeImmutable($sla['datetime_end']))->setTimezone($zone);
        $minutes = (new DateTimeImmutable('2000-01-01'))->add(new DateInterval($activity['duration']))->getTimestamp() / 60 - (new DateTimeImmutable('2000-01-01'))->getTimestamp() / 60;
        $type = $activity['activity_type_id'];

        expect($rows['Activity_SLA'])->toHaveCount(1)
            ->and($sla)->toMatchArray(['sla_type_id' => 'APPOINTMENT', 'priority' => '1', 'start_based' => 'true'])
            ->and($rows['Availability'])->toHaveCount(1)
            ->and($availability)->toBe([
                'id' => "AP-{$activityId}-1",
                'datetime_start' => $sla['datetime_start'],
                'datetime_end' => $sla['datetime_end'],
                'activity_id' => $activityId,
                'start_based' => 'true',
                'availability_type' => 'ACCEPTED_APPOINTMENT',
            ])
            ->and(strlen($availability['id']))->toBeLessThanOrEqual(32)
            ->and($status['date_time_earliest'])->toBe($sla['datetime_start'])
            ->and($status['status_id'])->toBe('0')
            ->and($start >= $now->modify('+1 hour'))->toBeTrue()
            ->and($local->format('N'))->toBeLessThanOrEqual(5)
            ->and($type)->toBeIn(['INSTALL', 'SURVEY'])
            ->and((int) $activity['base_value'])->toBe((int) round(1000 * $minutes / 60))
            ->and($minutes % 15)->toBe(0);

        // The slot is one of the region's template items, in local time.
        $slots = [];

        foreach ($region->appointmentTemplates as $kind) {
            foreach ($spec->appointmentTemplateKinds[$kind]['items'] as $item) {
                $midnight = $local->setTime(0, 0);
                $slots[] = $midnight->add(new DateInterval($item['start']))->format('H:i').'-'.$midnight->add(new DateInterval($item['end']))->format('H:i');
            }
        }

        expect($local->format('H:i').'-'.$end->format('H:i'))->toBeIn($slots);

        $created = new DateTimeImmutable($activity['date_time_created']);
        $ago = ($start->getTimestamp() - $created->getTimestamp()) / 60;

        expect($created <= $now)->toBeTrue()
            ->and($created == $now || ($ago >= 1440 && $ago <= 30240))->toBeTrue()
            ->and($status['date_time_status'])->toBe($activity['date_time_created']);

        if ($type === 'SURVEY') {
            expect($rows)->not->toHaveKey('Activity_Skill');
        } else {
            $skill = $rows['Activity_Skill'][0]['skill_id'];

            expect($skill)->toBeIn(['FIBRE', 'ELEC'])
                ->and(count(array_filter($spec->servingResourceList($region->id), static fn ($resource): bool => in_array($skill, $resource->skills, true))))->toBeGreaterThanOrEqual(2);
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
})->with(['DC_US', 'DC_CA']);

it('counts today only for slots an hour or more away: Toronto starts Monday, Los Angeles books Friday afternoon only', function () {
    $now = new DateTimeImmutable('2026-10-30T15:30:00Z'); // Fri 11:30 Toronto, 08:30 Los Angeles
    $ca = app(DatasetSpecRepository::class)->load('DC_CA');
    $us = app(DatasetSpecRepository::class)->load('DC_US');
    $tor = ActivityFactory::appointmentDays($ca, $ca->regions['TOR'], $now);
    $lax = ActivityFactory::appointmentDays($us, $us->regions['LAX'], $now);

    expect($tor[0]['day']->format('D Y-m-d'))->toBe('Mon 2026-11-02')
        ->and(end($tor)['day']->format('D Y-m-d'))->toBe('Fri 2026-11-13')
        ->and($lax[0]['day']->format('D Y-m-d'))->toBe('Fri 2026-10-30')
        ->and(array_keys($lax[0]['slots']))->toBe(['AMPM'])
        ->and(array_map(static fn (array $slot): string => $slot['start']->format('H:i'), $lax[0]['slots']['AMPM']))->toBe(['12:00'])
        ->and(array_keys($lax[1]['slots']))->toBe(['AMPM', 'ALLDAY']);
});

it('puts a Monday 2 November Toronto AM slot at 13:00Z, after the DST change', function () {
    $rows = collect(activityRows(generateDataset('DC_CA')->path))
        ->first(static fn (array $rows): bool => isset($rows['Availability']) && $rows['Availability'][0]['datetime_start'] === '2026-11-02T13:00:00+00:00' && $rows['Availability'][0]['datetime_end'] === '2026-11-02T17:00:00+00:00');

    expect($rows)->not->toBeNull()
        ->and($rows['Activity'][0]['id'])->toStartWith('A-TOR-');
});

it('gives MTN, served only through extra regions, two SLA jobs a working day and one all-day appointment a day', function () {
    $result = generateDataset('DC_US');
    $spec = app(DatasetSpecRepository::class)->load('DC_US');

    expect($spec->servingResources('MTN'))->toBe(1.0)
        // round(2 x 1.0) = 2 a day, so 10 over the five days; its emergency sits on day 1, as everywhere.
        ->and(array_sum(array_map('array_sum', $result->summary['activity_days']['MTN'])))->toBe(10)
        ->and($result->summary['activity_days']['MTN'])->toHaveCount(5)
        ->and(collect($result->summary['appointment_days']['MTN'])->map(static fn (array $types): array => array_merge(...array_values($types)))->unique()->values()->all())->toBe([['ALLDAY' => 1]]);
});

it('takes the priority 1 SLA type from the activity type\'s primary_sla_type', function () {
    $data = json_decode((string) file_get_contents(resource_path('datasets/DC_CA.json')), true);
    $data['activity_types']['REPAIR']['primary_sla_type'] = 'PROJECT';
    $path = generateDataset('DC_CA', spec: DatasetSpec::fromArray($data))->path;
    $activities = collect(datasetEntity($path, 'Activity'))->keyBy('id');

    foreach (datasetEntity($path, 'Activity_SLA') as $sla) {
        if ($sla['priority'] === '1' && $activities[$sla['activity_id']]['activity_type_id'] === 'REPAIR') {
            expect($sla['sla_type_id'])->toBe('PROJECT');
        }
    }
});

it('carries every stage 1 shift as a rota item with the same instants, and the Monday rule per item', function (string $id) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);
    $stage1 = collect(datasetEntity(generateStage1Dataset($id)->path, 'Shift'))->keyBy('id');
    $stage1Breaks = collect(datasetEntity(generateStage1Dataset($id, directory: datasetBuilderDirectory()."/{$id}-stage1b")->path, 'Shift_Break'))->keyBy('id');
    $items = collect(datasetEntity($result->modellingPath, 'RAM_Rota_Item'));
    $breaks = collect(datasetEntity($result->modellingPath, 'RAM_Rota_Item_Break'));
    $templates = collect(datasetEntity($result->modellingPath, 'RAM_Shift_Template'))->pluck('description', 'id');
    $types = collect($spec->resources)->mapWithKeys(static fn ($resource): array => [$resource->id => $resource->resourceType]);

    expect($items)->toHaveCount($stage1->count())
        ->and($items->pluck('id')->sort()->values()->all())->toBe($stage1->keys()->sort()->values()->all())
        ->and(datasetEntity($result->modellingPath, 'RAM_Rota'))->toBe([['id' => $id, 'ram_rota_type_id' => 'MASTER', 'modified' => 'false']]);

    foreach ($items as $item) {
        $shift = $stage1[$item['id']];
        $type = $types[$item['ram_resource_id']];

        expect($item)->toBe([
            'ram_rota_id' => $id,
            'id' => $shift['id'],
            'ram_resource_id' => $shift['resource_id'],
            'start_datetime' => $shift['start_datetime'],
            'end_datetime' => $shift['end_datetime'],
            'ram_shift_category_id' => $type,
            'ram_shift_template_id' => $type,
            'split_allowed' => 'true',
            'split_priority' => $shift['split_priority'],
            'description' => $templates[$type],
            'modified' => 'false',
        ]);
    }

    expect($breaks)->toHaveCount($items->count());

    foreach ($breaks as $break) {
        $old = $stage1Breaks[$break['id']];

        expect($break)->toBe([
            'ram_rota_id' => $id,
            'id' => $old['id'],
            'ram_rota_item_id' => $old['shift_id'],
            'duration' => $old['duration'],
            'earliest_start' => $old['earliest_start_offset'],
            'latest_start' => $old['latest_start_offset'],
        ]);
    }

    $memberships = collect(datasetEntity($result->modellingPath, 'RAM_Rota_Membership_Period'));
    $alterations = collect(datasetEntity($result->modellingPath, 'RAM_Rota_Alteration'));

    expect($memberships->pluck('ram_resource_id')->all())->toBe(array_map(static fn ($resource): string => $resource->id, $spec->resources))
        ->and($alterations->pluck('id')->all())->toBe(array_map(static fn ($resource): string => $resource->id, $spec->resources))
        ->and($memberships->pluck('id')->every(static fn (string $value): bool => str_starts_with($value, 'RMP-')))->toBeTrue();
})->with(['DC_US', 'DC_CA']);

it('spans each resource\'s rota from day -7 00:00 to midnight after day 100, in its home zone', function () {
    $path = generateDataset('DC_CA')->modellingPath;
    $tor = collect(datasetEntity($path, 'RAM_Rota_Membership_Period'))->firstWhere('ram_resource_id', 'R-TOR-001');
    $item = collect(datasetEntity($path, 'RAM_Rota_Item'))->firstWhere('id', '00120261102');

    expect($tor)->toBe([
        'ram_rota_id' => 'DC_CA',
        'id' => 'RMP-R-TOR-001',
        'ram_resource_id' => 'R-TOR-001',
        'start_datetime' => '2026-10-23T04:00:00+00:00',
        'end_datetime' => '2027-02-08T05:00:00+00:00',
    ])
        ->and(collect(datasetEntity($path, 'RAM_Rota_Alteration'))->firstWhere('id', 'R-TOR-001'))->toBe([
            'ram_rota_id' => 'DC_CA', 'id' => 'R-TOR-001', 'ram_resource_id' => 'R-TOR-001',
            'start_datetime' => '2026-10-23T04:00:00+00:00', 'end_datetime' => '2027-02-08T05:00:00+00:00',
        ])
        // Clark Kent, SENIOR_TECH: Mon 2 Nov 07:30 EST.
        ->and($item['start_datetime'])->toBe('2026-11-02T12:30:00+00:00')
        ->and($item['split_priority'])->toBe('0')
        ->and($item['description'])->toBe('Senior Technician Mon-Fri 07:30-16:00');
});

it('gives projects only to regions with two or more serving holders of the skills: none to MTN, COMMERCIAL only to DFW and IOWA', function () {
    $us = app(DatasetSpecRepository::class)->load('DC_US');
    $ca = app(DatasetSpecRepository::class)->load('DC_CA');
    $skills = static fn (DatasetSpec $spec, string $region): ?array => ActivityFactory::projectSkills($spec, $spec->regions[$region]);
    $both = ['required' => ['COMMERCIAL'], 'optional' => ['SAFETY_CERT']];
    $commercial = ['required' => ['COMMERCIAL'], 'optional' => []];

    expect($skills($us, 'MTN'))->toBeNull()
        ->and($skills($us, 'DFW'))->toBe($commercial)
        ->and($skills($us, 'IOWA'))->toBe($commercial)
        ->and($skills($us, 'CHI'))->toBe($both)
        ->and($skills($us, 'LAX'))->toBe($both)
        ->and($skills($us, 'DEN'))->toBe($both)
        ->and($skills($ca, 'TOR'))->toBe($both)
        ->and($skills($ca, 'ONT_RURAL'))->toBe($both);
});

it('makes 2 projects a week per metro and 1 per rural region over 2 weeks, split-ready, end-based, staffable', function (string $id, int $total) {
    $result = generateDataset($id);
    $spec = app(DatasetSpecRepository::class)->load($id);
    $now = new DateTimeImmutable('2026-10-30T15:30:00Z');
    $projects = collect(activityRows($result->path))->filter(static fn (array $rows): bool => $rows['Activity'][0]['activity_type_id'] === 'PROJECT');

    expect($projects)->toHaveCount($total)->and($result->summary['projects'])->toBe($total);

    foreach ($projects as $activityId => $rows) {
        $activity = $rows['Activity'][0];
        $region = $spec->regions[substr($activityId, 2, -7)];
        $zone = new DateTimeZone($region->timeZone);
        $minutes = (new DateTimeImmutable('2000-01-01'))->add(new DateInterval($activity['duration']))->getTimestamp() / 60 - (new DateTimeImmutable('2000-01-01'))->getTimestamp() / 60;
        $skills = array_column($rows['Activity_Skill'], 'skill_id');
        $holders = array_filter($spec->servingResourceList($region->id), static fn ($resource): bool => array_diff($skills, $resource->skills) === []);
        [$primary, $secondary] = $rows['Activity_SLA'];
        $start = (new DateTimeImmutable($primary['datetime_start']))->setTimezone($zone);

        expect($activity)->toMatchArray(['split_allowed' => 'true', 'split_minimum_priority' => '1', 'appointed' => 'false', 'activity_class_id' => 'CALL'])
            ->and(array_keys($activity))->toBe(['id', 'activity_class_id', 'activity_type_id', 'location_id', 'description', 'duration', 'base_value', 'split_allowed', 'split_minimum_priority', 'appointed', 'do_on_site', 'auto_duration_enabled', 'remote_fulfillment', 'date_time_created', 'date_time_open', 'memo'])
            ->and($minutes)->toBeGreaterThanOrEqual(600)->toBeLessThanOrEqual(960)
            ->and($minutes % 15)->toBe(0)
            ->and((int) $activity['base_value'])->toBe((int) round(1000 * $minutes / 60))
            ->and($skills[0])->toBe('COMMERCIAL')
            ->and(count($holders))->toBeGreaterThanOrEqual(2)
            ->and($rows['Activity_SLA'])->toHaveCount(2)
            ->and($primary)->toMatchArray(['sla_type_id' => 'PROJECT', 'priority' => '1', 'start_based' => 'false'])
            ->and($secondary)->toMatchArray(['sla_type_id' => 'OUT_SLA', 'priority' => '2', 'start_based' => 'false'])
            ->and(array_keys($primary))->toBe(['sla_type_id', 'activity_id', 'priority', 'start_based', 'datetime_start', 'datetime_end'])
            ->and($secondary['datetime_start'])->toBe($primary['datetime_end'])
            ->and($start->format('H:i'))->toBe('08:00')
            ->and((int) $start->format('N'))->toBeLessThanOrEqual(5)
            ->and($start > $now)->toBeTrue()
            ->and((new DateTimeImmutable($primary['datetime_end']))->setTimezone($zone)->format('H:i'))->toBe('17:00')
            ->and($rows['Activity_Status'][0])->toMatchArray(['status_id' => '0', 'visit_id' => '1'])
            ->and($rows['Activity_Status'][0])->not->toHaveKey('duration_override')
            ->and($rows)->not->toHaveKey('Availability');

        if (in_array('SAFETY_CERT', $skills, true)) {
            expect($region->id)->not->toBeIn(['DFW', 'IOWA']);
        }
    }
})->with([['DC_US', 18], ['DC_CA', 6]]);

it('starts a Friday Toronto project at 08:00 EST and ends its PROJECT window ten days on at 17:00', function () {
    $friday = collect(activityRows(generateDataset('DC_CA')->path))
        ->filter(static fn (array $rows): bool => $rows['Activity'][0]['activity_type_id'] === 'PROJECT' && str_starts_with($rows['Activity'][0]['id'], 'A-TOR-'))
        ->first(static fn (array $rows): bool => (new DateTimeImmutable($rows['Activity_SLA'][0]['datetime_start']))->setTimezone(new DateTimeZone('America/Toronto'))->format('D') === 'Fri');

    expect($friday)->not->toBeNull();

    $start = (new DateTimeImmutable($friday['Activity_SLA'][0]['datetime_start']))->setTimezone(new DateTimeZone('America/Toronto'));
    $end = (new DateTimeImmutable($friday['Activity_SLA'][0]['datetime_end']))->setTimezone(new DateTimeZone('America/Toronto'));

    expect($start->format('H:i T'))->toBe('08:00 EST')
        ->and($end->format('D H:i'))->toBe('Mon 17:00')
        ->and($start->diff($end)->days)->toBe(10);
});
