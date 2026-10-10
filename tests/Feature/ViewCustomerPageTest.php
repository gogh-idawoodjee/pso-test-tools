<?php

use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

it('renders the customer view page', function () {
    $user = User::factory()->create();
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create();
    $customer = Customer::factory()->for($dataset)->create(['name' => 'Jane Render']);
    $this->actingAs($user);

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertOk()
        ->assertSee('Jane Render');
});
