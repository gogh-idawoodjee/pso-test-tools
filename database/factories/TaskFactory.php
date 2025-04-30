<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\CustomerTaskType;
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
        return [
            'appt_window_finish' => fake()->dateTime(),
            'appt_window_start' => fake()->dateTime(),
            'duration' => fake()->numberBetween(-10000, 10000),
            'status' => fake()->word(),
            'type' => fake()->word(),
            'customer_task_type_id' => CustomerTaskType::factory(),
        ];
    }
}
