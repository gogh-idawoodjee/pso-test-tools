<?php

use App\Filament\Pages\Modelling\ARPResource;

it('builds resourceTypeId, lat and long from repeater rows', function () {
    $data = (new ARPResource)->buildResourcesData([
        'resource_type_id' => 'FIELD_TECH',
        'resources' => [
            ['latitude' => '43.65107', 'longitude' => '-79.347015'],
            ['latitude' => '45.5017', 'longitude' => '-73.5673'],
        ],
    ]);

    expect($data)->toBe([
        'resourceTypeId' => 'FIELD_TECH',
        'lat' => ['43.65107', '45.5017'],
        'long' => ['-79.347015', '-73.5673'],
    ]);
});

it('includes names and ids only when every row has them filled in', function () {
    $data = (new ARPResource)->buildResourcesData([
        'resource_type_id' => 'FIELD_TECH',
        'resources' => [
            ['latitude' => '1', 'longitude' => '1', 'name' => 'John Smith', 'resource_id' => 'RES-001'],
            ['latitude' => '2', 'longitude' => '2', 'name' => 'Jane Doe', 'resource_id' => 'RES-002'],
        ],
    ]);

    expect($data['names'])->toBe(['John Smith', 'Jane Doe'])
        ->and($data['ids'])->toBe(['RES-001', 'RES-002']);
});

it('omits names and ids entirely when any row is missing them', function () {
    $data = (new ARPResource)->buildResourcesData([
        'resource_type_id' => 'FIELD_TECH',
        'resources' => [
            ['latitude' => '1', 'longitude' => '1', 'name' => 'John Smith'],
            ['latitude' => '2', 'longitude' => '2'],
        ],
    ]);

    expect($data)->not->toHaveKey('names')
        ->and($data)->not->toHaveKey('ids');
});

it('includes skills and regions only when provided', function () {
    $data = (new ARPResource)->buildResourcesData([
        'resource_type_id' => 'FIELD_TECH',
        'resources' => [['latitude' => '1', 'longitude' => '1']],
        'skills' => ['ELECTRICAL'],
        'regions' => ['NORTH'],
    ]);

    expect($data['skills'])->toBe(['ELECTRICAL'])
        ->and($data['regions'])->toBe(['NORTH']);
});

it('omits skills and regions when not provided', function () {
    $data = (new ARPResource)->buildResourcesData([
        'resource_type_id' => 'FIELD_TECH',
        'resources' => [['latitude' => '1', 'longitude' => '1']],
    ]);

    expect($data)->not->toHaveKey('skills')
        ->and($data)->not->toHaveKey('regions');
});
