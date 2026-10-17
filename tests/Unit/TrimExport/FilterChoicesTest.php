<?php

use App\Support\TrimExport\FilterChoices;

beforeEach(function () {
    $this->choices = new FilterChoices(
        regions: ['V1' => 'V1 - Regina', 'V2' => 'V2 - Saskatoon'],
        resources: ['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing', 'R3' => 'R3'],
        activityTypes: ['T1' => 'T1 - Install (2)', 'T2' => 'T2 - Repair (1)'],
        activities: ['A1' => 'A1 - T1', 'A2' => 'A2 - T1', 'A3' => 'A3 - T2'],
        resourceRegions: ['R1' => ['V1'], 'R2' => ['V2', 'V1'], 'R3' => ['V2']],
        activityTypeOf: ['A1' => 'T1', 'A2' => 'T1', 'A3' => 'T2'],
    );
});

it('offers only the resources of the chosen regions, or every resource when none is chosen', function () {
    expect($this->choices->resourcesIn(['V1']))->toBe(['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing'])
        ->and($this->choices->resourcesIn([]))->toHaveCount(3);
});

it('offers only the activities of the chosen types, or every activity when none is chosen', function () {
    expect($this->choices->activitiesOf(['T2']))->toBe(['A3' => 'A3 - T2'])
        ->and($this->choices->activitiesOf([]))->toHaveCount(3);
});

it('finds options by id or name, ignoring case, at most fifty', function () {
    $many = [];
    foreach (range(1, 60) as $n) {
        $many["A{$n}"] = "A{$n} - T1";
    }

    expect(FilterChoices::matching($this->choices->resources, 'turing'))->toBe(['R2' => 'R2 - Alan Turing'])
        ->and(FilterChoices::matching($many, 'a'))->toHaveCount(FilterChoices::SEARCH_LIMIT)
        ->and(FilterChoices::matching($many, 'A60'))->toBe(['A60' => 'A60 - T1']);
});

it('labels only the chosen ids that are among the options', function () {
    expect(FilterChoices::labelsOf($this->choices->resourcesIn(['V1']), ['R1', 'R3']))->toBe(['R1' => 'R1 - Ada Lovelace']);
});
