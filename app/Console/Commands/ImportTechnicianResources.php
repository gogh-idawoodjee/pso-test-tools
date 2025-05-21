<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Technician;
use Illuminate\Support\Facades\File;

class ImportTechnicianResources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'technicians:import {file : The JSON file to import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import technician resources from JSON file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $filePath = $this->argument('file');

        if (!File::exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Importing technician resources from {$filePath}...");

        try {
            $jsonContent = File::get($filePath);
            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("Invalid JSON in file: " . json_last_error_msg());
                return 1;
            }

            $technician = Technician::importFromJson($data);

            $this->info("Successfully imported technician: {$technician->full_name} (ID: {$technician->resource_id})");

            return 0;
        } catch (\Exception $e) {
            $this->error("Error importing technician resources: " . $e->getMessage());
            return 1;
        }
    }
}
