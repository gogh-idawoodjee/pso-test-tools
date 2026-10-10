<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Customer;
use App\Enums\Status;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;

class CustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'address' => fake()->word(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'lat' => fake()->latitude(),
            'long' => fake()->randomFloat(0, 0, 9999999999.),
            'name' => fake()->name(),
            'postcode' => fake()->postcode(),
            'dataset_id' => Dataset::factory(),
            'region_id' => null,
            'status' => Status::ACTIVE,
        ];
    }

    public function withRegion(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            $customer->update(['region_id' => DatasetReferenceValue::factory()->for($customer->dataset)->create()->id]);
        });
    }
}
