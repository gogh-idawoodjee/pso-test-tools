<?php

use App\Filament\Pages\TravelAnalyzer;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('fills from/to address and lat/long fields with two different NYC spots', function () {
    $test = Livewire::test(TravelAnalyzer::class)
        ->callAction(TestAction::make('random_nyc_spots')->schemaComponent(true, 'travel_form'));

    $addressFrom = $test->get('data.address_from');
    $addressTo = $test->get('data.address_to');

    expect($addressFrom)->not->toBeEmpty()
        ->and($addressTo)->not->toBeEmpty()
        ->and($addressFrom)->not->toBe($addressTo)
        ->and($test->get('data.lat_from'))->not->toBeNull()
        ->and($test->get('data.long_from'))->not->toBeNull()
        ->and($test->get('data.lat_to'))->not->toBeNull()
        ->and($test->get('data.long_to'))->not->toBeNull();
});

it('always picks two distinct restaurants across repeated draws', function () {
    foreach (range(1, 20) as $_) {
        $test = Livewire::test(TravelAnalyzer::class)
            ->callAction(TestAction::make('random_nyc_spots')->schemaComponent(true, 'travel_form'));

        expect($test->get('data.address_from'))->not->toBe($test->get('data.address_to'));
    }
});
