<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->otherDataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

function customerForm(array $overrides = []): array
{
    return array_replace([
        'name' => 'Jane Test', 'status' => 'active',
        'address' => '1 Main St', 'city' => 'Regina', 'country' => 'Canada', 'postcode' => 'S4P',
    ], $overrides);
}

it('creates a customer in a dataset with a region from that dataset', function () {
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create();

    Livewire::test(CreateCustomer::class)
        ->fillForm(customerForm(['dataset_id' => $this->dataset->id, 'region_id' => $region->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::first()->dataset_id)->toBe($this->dataset->id);
});

it('refuses a region that belongs to another dataset', function () {
    $foreignRegion = DatasetReferenceValue::factory()->for($this->otherDataset)->create();

    Livewire::test(CreateCustomer::class)
        ->fillForm(customerForm(['dataset_id' => $this->dataset->id, 'region_id' => $foreignRegion->id]))
        ->call('create')
        ->assertHasFormErrors(['region_id']);
});

it('offers only the chosen dataset regions, labelled with their pso id', function () {
    $mine = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'NORTH', 'label' => 'North']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'FIBRE']);
    DatasetReferenceValue::factory()->for($this->otherDataset)->create(['pso_id' => 'SOUTH']);

    expect(DatasetReferenceValue::optionsFor($this->dataset->id, ReferenceEntityType::Region))->toBe([$mine->id => 'North (NORTH)'])
        ->and(DatasetReferenceValue::psoIdOptionsFor($this->dataset->id, ReferenceEntityType::Region))->toBe(['NORTH' => 'North (NORTH)'])
        ->and(DatasetReferenceValue::optionsFor(null, ReferenceEntityType::Region))->toBe([]);
});
