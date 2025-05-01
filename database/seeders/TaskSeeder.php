<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Customer;
use App\Models\TaskType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $taskTypes  = TaskType::all();
        $startRange = Carbon::now()->subWeek();    // one week ago
        $endRange   = Carbon::now()->addMonth();   // one month from now

        // Seed 5 tasks per customer
        Customer::all()->each(function (Customer $customer) use ($startRange, $endRange, $taskTypes) {
            foreach (range(1, 5) as $ignored) {
                // random timestamp between startRange and endRange
                $timestamp = random_int($startRange->timestamp, $endRange->timestamp);
                $date      = Carbon::createFromTimestamp($timestamp, 'America/Toronto')->format('Y-m-d');

                // pick morning or afternoon
                if (random_int(0, 1) === 1) {
                    $start  = Carbon::createFromFormat('Y-m-d H:i', "{$date} 08:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                } else {
                    $start  = Carbon::createFromFormat('Y-m-d H:i', "{$date} 12:00", 'America/Toronto');
                    $finish = Carbon::createFromFormat('Y-m-d H:i', "{$date} 17:00", 'America/Toronto');
                }

                $taskType   = $taskTypes->random();
                $prefix     = strtoupper(substr($taskType->name ?? 'X', 0, 1));
                $friendlyId = 'T-' . $prefix . '-' . str_pad((string) random_int(0, 99_999), 5, '0', STR_PAD_LEFT);

                Task::create([
                    'id'                  => Str::uuid()->toString(),
                    'friendly_id'         => $friendlyId,
                    'customer_id'         => $customer->id,
                    'task_type_id'        => $taskType->id,
                    'appt_window_start'   => $start,
                    'appt_window_finish'  => $finish,
                    'status'              => collect(TaskStatus::cases())->random()->value,
                ]);
            }
        });
    }
}
