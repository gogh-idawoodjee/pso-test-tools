<?php

namespace App\Console\Commands;

use App\Models\Dataset;
use App\Support\DatasetSettings;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SeedDatasetSettings extends Command
{
    protected $signature = 'datasets:seed-settings
        {dataset : dataset id or "ENV_SLUG/NAME"}
        {file : path to a pso-tools account JSON}
        {--time-zone= : IANA time zone for the dataset}';

    protected $description = 'Load a pso-tools account file (for example SATE.json) into a dataset\'s settings';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("The file {$path} does not exist.");

            return self::FAILURE;
        }

        $dataset = $this->findDataset((string) $this->argument('dataset'));
        if ($dataset === null) {
            $this->error('No dataset matches "'.$this->argument('dataset').'". Use its id or ENV_SLUG/NAME.');

            return self::FAILURE;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            $this->error("The file {$path} is not valid JSON.");

            return self::FAILURE;
        }

        try {
            $values = DatasetSettings::fromAccountFile($json)->toArray();
            if ($this->option('time-zone')) {
                $values['time_zone'] = (string) $this->option('time-zone');
            }
            $dataset->settings = DatasetSettings::fromArray($values);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dataset->save();
        $this->info("Settings saved on {$dataset->name}: ".implode(', ', array_keys($json)));

        return self::SUCCESS;
    }

    private function findDataset(string $reference): ?Dataset
    {
        $query = Dataset::query()->withoutGlobalScopes();

        if (str_contains($reference, '/')) {
            [$environmentSlug, $name] = explode('/', $reference, 2);

            return $query->where('name', $name)
                ->whereHas('environment', static fn ($environment) => $environment->withoutGlobalScopes()->where('slug', $environmentSlug))
                ->first();
        }

        return $query->whereKey($reference)->first();
    }
}
