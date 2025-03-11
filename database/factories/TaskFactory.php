<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\Customer;
use App\Models\Task;

class TaskFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Task::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $appt_start = fake()->dateTime();
        return [
            'appt_window_finish' => Carbon::parse($appt_start)->addHours(4),
            'appt_window_start' => $appt_start,
            'duration' => fake()->numberBetween(-10000, 10000),
            'status' => fake()->word(),
            'type' => fake()->word(),
            'customer_id' => Customer::factory(),
        ];
    }
}
