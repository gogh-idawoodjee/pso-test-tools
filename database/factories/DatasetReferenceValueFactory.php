<?php

namespace Database\Factories;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatasetReferenceValueFactory extends Factory
{
    protected $model = DatasetReferenceValue::class;

    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            'entity_type' => ReferenceEntityType::Region,
            'pso_id' => strtoupper(fake()->unique()->lexify('??????')),
            'label' => fake()->words(2, true),
            'attributes' => null,
            'origin' => ReferenceValueOrigin::Pso,
            'last_seen_at' => now(),
            'is_locally_modified' => false,
        ];
    }

    public function manual(): static
    {
        return $this->state(['origin' => ReferenceValueOrigin::Manual, 'last_seen_at' => null]);
    }

    public function taskType(int $priority = 5, float $baseValue = 1000, int $baseDuration = 60): static
    {
        return $this->state([
            'entity_type' => ReferenceEntityType::TaskType,
            'attributes' => ['priority' => $priority, 'base_value' => $baseValue, 'base_duration' => $baseDuration],
        ]);
    }
}
