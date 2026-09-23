<?php

use App\Filament\Pages\TravelAnalyzer;
use Livewire\Livewire;

it('defaults send_to_pso to true and disables the toggle', function () {
    $test = Livewire::test(TravelAnalyzer::class);

    expect($test->get('environment_data.send_to_pso'))->toBeTrue();

    $test->assertFormFieldIsDisabled('send_to_pso', 'env_form');
});
