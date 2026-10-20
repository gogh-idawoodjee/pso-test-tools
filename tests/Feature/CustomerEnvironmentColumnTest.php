<?php

use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows each customer environment and filters the list by it', function () {
    $north = Dataset::factory()->for(Environment::factory()->for($this->user)->create(['name' => 'SaskTel test']))->create();
    $south = Dataset::factory()->for(Environment::factory()->for($this->user)->create(['name' => 'Rogers test']))->create();
    $jane = Customer::factory()->for($north)->create(['name' => 'Jane North']);
    $raj = Customer::factory()->for($south)->create(['name' => 'Raj South']);

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$jane, $raj])
        ->assertSee('SaskTel test')
        ->assertSee('Rogers test')
        ->filterTable('environment', $north->environment_id)
        ->assertCanSeeTableRecords([$jane])
        ->assertCanNotSeeTableRecords([$raj]);
});
