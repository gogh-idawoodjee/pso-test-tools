<?php

use App\Enums\ReferenceEntityType;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['name' => 'SaskTel Test']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
});

function seeded(Dataset $dataset)
{
    return Customer::withoutGlobalScopes()->where('dataset_id', $dataset->id)->get();
}

it('creates customers in the dataset with Saskatchewan coordinates and round-robin regions', function () {
    foreach (['REGINA', 'SASKATOON'] as $pso) {
        DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Region, 'pso_id' => $pso]);
    }

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 5])
        ->expectsOutputToContain('Created 5 customers')
        ->assertSuccessful();

    $customers = seeded($this->dataset);
    expect($customers)->toHaveCount(5)
        ->and($customers->pluck('slug')->sort()->values()->all())->toBe(array_map(fn (int $n) => "sample-{$this->dataset->id}-{$n}", [1, 2, 3, 4, 5]));
    foreach ($customers as $customer) {
        expect((float) $customer->lat)->toBeBetween(49, 60)
            ->and((float) $customer->long)->toBeBetween(-110, -101)
            ->and($customer->country)->toBe('Canada');
    }

    $psoIds = DatasetReferenceValue::withoutGlobalScopes()->where('dataset_id', $this->dataset->id)->pluck('pso_id', 'id');
    $regions = $customers->sortBy(fn ($customer) => (int) substr(strrchr($customer->slug, '-'), 1))->map(fn ($customer) => $psoIds[$customer->region_id])->values()->all();
    expect($regions)->toBe(['REGINA', 'SASKATOON', 'REGINA', 'SASKATOON', 'REGINA']);
});

it('leaves the region empty and says so when none are synced', function () {
    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 2])
        ->expectsOutputToContain('No regions are synced')
        ->assertSuccessful();

    expect(seeded($this->dataset)->pluck('region_id')->filter())->toBeEmpty();
});

it('refuses an unknown dataset or environment', function () {
    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NOPE'])->assertFailed();
    $this->artisan('booking:seed-customers', ['environment' => 'Nope', 'dataset' => 'NORTH'])->assertFailed();

    expect(Customer::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an ambiguous dataset', function () {
    Dataset::factory()->for(Environment::factory()->for(User::factory()->create())->create(['name' => 'SaskTel Test']))->create(['name' => 'NORTH']);

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH'])
        ->expectsOutputToContain('more than one')
        ->assertFailed();
});

it('skips when customers exist and replaces seeded ones with --fresh', function () {
    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 3])->assertSuccessful();
    $firstIds = seeded($this->dataset)->pluck('id')->sort()->values()->all();

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 3])
        ->expectsOutputToContain('already has customers')
        ->assertSuccessful();
    expect(seeded($this->dataset)->pluck('id')->sort()->values()->all())->toBe($firstIds);

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 4, '--fresh' => true])->assertSuccessful();
    $after = seeded($this->dataset);
    expect($after)->toHaveCount(4)->and($after->pluck('id')->intersect($firstIds))->toBeEmpty();
});

it('says --fresh adds samples alongside a dataset of real customers', function () {
    Customer::factory()->for($this->dataset)->create();

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 2])
        ->expectsOutputToContain('Use --fresh to add sample customers alongside them.')
        ->assertSuccessful();

    expect(seeded($this->dataset))->toHaveCount(1);
});

it('does not delete real customers or seeded customers that have tasks', function () {
    $real = Customer::factory()->for($this->dataset)->create();
    // A real slug that only starts like a sample one is not a sample of this dataset.
    $lookalike = Customer::factory()->for($this->dataset)->create(['slug' => 'sample-'.substr($this->dataset->id, 0, 8).'-1']);
    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 2, '--fresh' => true])->assertSuccessful();
    expect(Customer::withoutGlobalScopes()->whereKey($real->id)->exists())->toBeTrue()
        ->and(Customer::withoutGlobalScopes()->whereKey($lookalike->id)->exists())->toBeTrue();

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 2, '--fresh' => true])->assertSuccessful();
    $seeded = seeded($this->dataset)->whereNotIn('id', [$real->id, $lookalike->id])->values();
    $taskType = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL']);
    $withTask = $seeded[0];
    Task::factory()->for($withTask)->create(['task_type_id' => $taskType->id]);

    $this->artisan('booking:seed-customers', ['environment' => 'SaskTel Test', 'dataset' => 'NORTH', '--count' => 2, '--fresh' => true])
        ->expectsOutputToContain('Kept 1')
        ->assertSuccessful();

    expect(Customer::withoutGlobalScopes()->whereKey($withTask->id)->exists())->toBeTrue()
        ->and(Customer::withoutGlobalScopes()->whereKey($seeded[1]->id)->exists())->toBeFalse();
});
