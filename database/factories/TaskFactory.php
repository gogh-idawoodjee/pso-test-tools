<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Customer;
use App\Models\Task;
use App\Models\TaskStatus;

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
        return [
            'appt_window_start' => fake()->dateTime(),
            'appt_window_finish' => fake()->dateTime(),
            'duration' => fake()->numberBetween(-10000, 10000),
            'type' => fake()->word(),
            'task_status_id' => TaskStatus::factory(),
            'customer_id' => Customer::factory(),
        ];
    }
}
