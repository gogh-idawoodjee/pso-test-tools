<?php

namespace Database\Seeders;

use App\Models\TaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Generate 5 random TaskTypes
        foreach (range(1, 5) as $ignored) {
            // Priority 1–3
            $priority = random_int(1, 3);

            // Base value based on priority
            $baseValue = match ($priority) {
                1 => 5000,
                2 => 4000,
                3 => 3000,
            };

            TaskType::create([
                // UUID for ID
                'id'            => Str::uuid()->toString(),
                // Random 8-char string, title-cased
                'name'          => Str::of(Str::random(8))->title(),
                'priority'      => $priority,
                // Duration between 45 and 180 minutes
                'base_duration' => random_int(45, 180),
                'base_value'    => $baseValue,
            ]);
        }
    }
}
