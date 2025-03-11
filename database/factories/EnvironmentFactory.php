<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Environment;
use App\Models\User;

class EnvironmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Environment::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'account_id' => fake()->word(),
            'base_url' => fake()->word(),
            'description' => fake()->text(),
            'name' => fake()->name(),
            'password' => fake()->password(),
            'username' => fake()->userName(),
            'user_id' => User::factory(),
        ];
    }
}
