<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Customer;
use App\Models\TaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;
use Carbon\Carbon;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $taskTypes = TaskType::all();

// Seed 5 tasks per customer
        Customer::all()->each(function (Customer $customer) use ($faker, $taskTypes) {
            foreach (range(1, 5) as $ignored) {
// Random date between last week and next month (EST)
                $date = $faker->dateTimeBetween('-1 week', '+1 month', 'America/Toronto')->format('Y-m-d');

// Morning or afternoon time slot
                if ($faker->boolean) {
                    $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} 08:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                } else {
                    $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 17:00", 'America/Toronto');
                }


                $taskType = $taskTypes->random();
                $prefix = strtoupper(substr($taskType->name ?? 'X', 0, 1)); // fallback to X if null
                $friendlyId = 'T-' . $prefix . '-' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);

                Task::create([
                    'id' => Str::uuid()->toString(),
                    'friendly_id' => $friendlyId,
                    'customer_id' => $customer->id,
                    'task_type_id' => $taskType->id,
                    'appt_window_start' => $start,
                    'appt_window_finish' => $finish,

                    'status' => collect(TaskStatus::cases())->random()->value,
                ]);
            }
        });
    }
}
