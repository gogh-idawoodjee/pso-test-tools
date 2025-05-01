<?php

namespace Database\Seeders;

use App\Models\TaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class TaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        foreach (range(1, 5) as $ignored) {
            $priority = $faker->numberBetween(1, 3);
            $baseValue = match ($priority) {
                1 => 5000,
                2 => 4000,
                3 => 3000,
            };

            TaskType::create([
                'id'            => Str::uuid()->toString(),
                'name'          => ucfirst($faker->unique()->word),
                'priority'      => $priority,
                'base_duration' => $faker->numberBetween(45, 180),
                'base_value'    => $baseValue,
            ]);
        }
    }
}
