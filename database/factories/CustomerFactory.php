<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Customer;
use App\Models\Region;

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
            'region_id' => Region::factory(),
            'status' => fake()->word(),
        ];
    }
}
