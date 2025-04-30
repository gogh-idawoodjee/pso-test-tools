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

        foreach (range(1, 5) as $_) {
            TaskType::create([
                'id'   => Str::uuid()->toString(),
                'name' => ucfirst($faker->unique()->word),
            ]);
        }
    }
}
