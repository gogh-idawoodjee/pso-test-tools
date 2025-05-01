<?php

namespace Database\Seeders;

use App\Models\TaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Repair - High',
                'priority' => 1,
                'base_duration' => 60,
                'base_value' => 5000,
            ],
            [
                'name' => 'Repair - Low',
                'priority' => 2,
                'base_duration' => 60,
                'base_value' => 4000,
            ],
            [
                'name' => 'Maintenance',
                'priority' => 2,
                'base_duration' => 45,
                'base_value' => 4000,
            ],
            [
                'name' => 'Install - Short',
                'priority' => 2,
                'base_duration' => 120,
                'base_value' => 4000,
            ],
            [
                'name' => 'Install - Long',
                'priority' => 2,
                'base_duration' => 180,
                'base_value' => 4000,
            ],
        ];

        foreach ($types as $type) {
            TaskType::create([
                'id' => Str::uuid()->toString(),
                'name' => $type['name'],
                'priority' => $type['priority'],
                'base_duration' => $type['base_duration'],
                'base_value' => $type['base_value'],
            ]);
        }
    }
}
