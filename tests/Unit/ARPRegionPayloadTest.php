<?php

use App\Filament\Pages\Modelling\ARPRegion;

it('builds the regions array from repeater rows', function () {
    $data = (new ARPRegion)->buildRegionsData([
        'regions' => [
            ['region_id' => 'NORTH'],
            ['region_id' => 'SOUTH'],
        ],
    ]);

    expect($data)->toBe(['regions' => ['NORTH', 'SOUTH']]);
});

it('includes descriptions only when every row has one filled in', function () {
    $data = (new ARPRegion)->buildRegionsData([
        'regions' => [
            ['region_id' => 'NORTH', 'description' => 'Northern zone'],
            ['region_id' => 'SOUTH', 'description' => 'Southern zone'],
        ],
    ]);

    expect($data['descriptions'])->toBe(['Northern zone', 'Southern zone']);
});

it('omits descriptions entirely when any row is missing one', function () {
    $data = (new ARPRegion)->buildRegionsData([
        'regions' => [
            ['region_id' => 'NORTH', 'description' => 'Northern zone'],
            ['region_id' => 'SOUTH', 'description' => ''],
        ],
    ]);

    expect($data)->not->toHaveKey('descriptions');
});

it('includes regionParent, regionCategory and send only when provided', function () {
    $data = (new ARPRegion)->buildRegionsData([
        'regions' => [['region_id' => 'NORTH']],
        'region_parent' => 'CANADA',
        'region_category' => 'PROVINCE',
        'send' => false,
    ]);

    expect($data)->toBe([
        'regions' => ['NORTH'],
        'regionParent' => 'CANADA',
        'regionCategory' => 'PROVINCE',
        'send' => false,
    ]);
});
