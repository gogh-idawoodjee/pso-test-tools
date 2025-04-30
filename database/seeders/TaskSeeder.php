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

        // Fetch all task type IDs via collection pluck
        $taskTypeIds = TaskType::all()->pluck('id')->toArray();

        // Seed 5 tasks per customer
        Customer::all()->each(function (Customer $customer) use ($faker, $taskTypeIds) {
            foreach (range(1, 5) as $ignored) {
                // Choose a random date between last week and next month in EST
                $date = $faker->dateTimeBetween('-1 week', '+1 month', 'America/Toronto')->format('Y-m-d');

                // Randomly pick morning (8am-12pm) or afternoon (12pm-5pm)
                if ($faker->boolean) {
                    $start  = Carbon::createFromFormat('Y-m-d H:i', "{$date} 08:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                } else {
                    $start  = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 17:00", 'America/Toronto');
                }

                $duration = $finish->diffInMinutes($start);

                Task::create([
                    'id'                 => Str::uuid()->toString(),
                    'customer_id'        => $customer->id,
                    'task_type_id'       => $faker->randomElement($taskTypeIds),
                    'appt_window_start'  => $start,
                    'appt_window_finish' => $finish,
                    'duration'           => $duration,
                    'status'             => collect(TaskStatus::cases())->random()->value,
                ]);
            }
        });
    }
}
