<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'task_type_id' => null,
            'duration' => fake()->numberBetween(30, 240),
            'base_value' => fake()->numberBetween(1000, 5000),
            'status' => TaskStatus::IGNORE,
            'appt_window_start' => null,
            'appt_window_finish' => null,
        ];
    }
}
