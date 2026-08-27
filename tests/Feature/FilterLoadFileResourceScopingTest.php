<?php

use App\Filament\Pages\FilterLoadFile;

it('keeps resources that belong to at least one selected region', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'resourceRegionMap', [
        1 => ['NORTH'],
        2 => ['SOUTH'],
        3 => ['NORTH', 'SOUTH'],
    ]);

    $result = callProtectedMethod($page, 'resourceIdsWithinRegions', [[1, 2, 3], ['NORTH']]);

    expect($result)->toBe([1, 3]);
});

it('returns all given resource ids unchanged when no regions are selected', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'resourceRegionMap', [1 => ['NORTH']]);

    $result = callProtectedMethod($page, 'resourceIdsWithinRegions', [[1, 2], []]);

    expect($result)->toBe([1, 2]);
});

it('drops resources with no overlapping region, even when unmapped', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'resourceRegionMap', [1 => ['NORTH']]);

    $result = callProtectedMethod($page, 'resourceIdsWithinRegions', [[1, 99], ['SOUTH']]);

    expect($result)->toBe([]);
});
