<?php

namespace Database\Factories;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsoGatewayUploadFactory extends Factory
{
    protected $model = PsoGatewayUpload::class;

    public function definition(): array
    {
        return [
            'pso_environment_id' => Environment::factory(),
            'initiated_by_user_id' => User::factory(),
            'stored_path' => 'gateway-uploads/'.fake()->uuid().'.json',
            'original_filename' => fake()->word().'.json',
            'file_size_bytes' => fake()->numberBetween(1000, 1000000),
            'status' => PsoGatewayUploadStatus::QUEUED,
            'queued_at' => now(),
        ];
    }
}
