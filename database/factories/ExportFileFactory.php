<?php

namespace Database\Factories;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExportFileFactory extends Factory
{
    protected $model = ExportFile::class;

    public function definition(): array
    {
        return [
            'kind' => ExportKind::Load,
            'uploaded_by_user_id' => User::factory(),
            'original_filename' => 'load.xml',
            'stored_path' => 'exports/dataset/'.fake()->uuid().'/'.fake()->uuid().'.xml',
            'file_size_bytes' => 1024,
            'content_type' => 'xml',
            'status' => ExportFileStatus::Ready,
            'summary' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (ExportFile $file): void {
            if ($file->scope_type === null || $file->scope_id === null) {
                $dataset = Dataset::factory()->create();
                $file->scope_type = $dataset->getMorphClass();
                $file->scope_id = $dataset->getKey();
            }
        });
    }

    public function sys(): static
    {
        return $this->state(['kind' => ExportKind::Sys, 'original_filename' => 'sys.xml']);
    }

    public function uploading(): static
    {
        return $this->state(['status' => ExportFileStatus::Uploading]);
    }

    public function purged(): static
    {
        return $this->state(['status' => ExportFileStatus::Purged]);
    }

    public function failed(): static
    {
        return $this->state(['status' => ExportFileStatus::Failed, 'error_message' => 'The file could not be read.']);
    }
}
