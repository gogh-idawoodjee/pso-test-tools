<?php

namespace Database\Factories;

use App\Enums\Tool;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ToolRunFactory extends Factory
{
    protected $model = ToolRun::class;

    public function definition(): array
    {
        return [
            'tool' => Tool::LoadAudit,
            'run_date' => today(),
            'inputs' => [],
            'started_by_user_id' => User::factory(),
            'status' => ToolRunStatus::Succeeded,
            'result' => ['ok' => true],
            'warnings' => [],
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (ToolRun $run): void {
            if ($run->scope_type === null || $run->scope_id === null) {
                $dataset = Dataset::factory()->create();
                $run->scope_type = $dataset->getMorphClass();
                $run->scope_id = $dataset->getKey();
            }
        });
    }
}
