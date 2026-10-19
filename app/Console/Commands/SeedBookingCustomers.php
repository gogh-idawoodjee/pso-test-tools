<?php

namespace App\Console\Commands;

use App\Enums\ReferenceEntityType;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\Booking\SampleCustomers;
use Illuminate\Console\Command;

class SeedBookingCustomers extends Command
{
    protected $signature = 'booking:seed-customers
        {environment : environment name}
        {dataset : dataset name}
        {--count=12 : how many sample customers to create}
        {--fresh : replace the sample customers this command made earlier}';

    protected $description = 'Create sample customers in New York, Toronto, London and Los Angeles for a dataset so the appointment booking page can be tried';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        if ($count < 1) {
            $this->error('--count must be at least 1.');

            return self::FAILURE;
        }

        $datasets = Dataset::query()->withoutGlobalScopes()
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) $this->argument('dataset'))])
            ->whereHas('environment', function ($environment) {
                $wanted = (string) $this->argument('environment');
                // The environment's name or its slug (what the URL shows), either case.
                $environment->withoutGlobalScopes()->where(fn ($q) => $q
                    ->whereRaw('lower(name) = ?', [mb_strtolower($wanted)])
                    ->orWhereRaw('lower(slug) = ?', [mb_strtolower($wanted)]));
            })
            ->get();

        if ($datasets->isEmpty()) {
            $this->error('No dataset "'.$this->argument('dataset').'" found in environment "'.$this->argument('environment').'".');

            return self::FAILURE;
        }
        if ($datasets->count() > 1) {
            $this->error('There is more than one dataset matches "'.$this->argument('dataset').'" in "'.$this->argument('environment').'" (different users share these names). Nothing was created.');

            return self::FAILURE;
        }

        $dataset = $datasets->first();
        $customers = Customer::query()->withoutGlobalScopes()->where('dataset_id', $dataset->id);
        // Slugs are "sample-<dataset id>-<n>": match that exact prefix (a uuid holds no LIKE wildcards).
        $samples = static fn () => (clone $customers)->where('slug', 'like', SampleCustomers::slugPrefix($dataset->id).'%');

        if ($this->option('fresh')) {
            $removable = $samples();
            $kept = (clone $removable)->whereHas('tasks', fn ($tasks) => $tasks->withoutGlobalScopes())->count();
            $removed = (clone $removable)->whereDoesntHave('tasks', fn ($tasks) => $tasks->withoutGlobalScopes())->delete();
            $this->line("Removed {$removed} sample customers.".($kept > 0 ? " Kept {$kept} that have tasks." : ''));
        } elseif ((clone $customers)->exists()) {
            $this->warn("Dataset {$dataset->name} already has customers, so nothing was created. ".($samples()->exists()
                ? 'Use --fresh to replace the sample ones.'
                : 'Use --fresh to add sample customers alongside them.'));

            return self::SUCCESS;
        }

        $regions = DatasetReferenceValue::query()->withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->where('entity_type', ReferenceEntityType::Region)
            ->orderBy('pso_id')
            ->get();
        if ($regions->isEmpty()) {
            $this->line('Note: No regions are synced for this dataset yet, so the customers have no region.');
        }

        // Slugs are built from the dataset id and position, so start after the highest one a --fresh run kept.
        $offset = $samples()->pluck('slug')
            ->map(static fn (string $slug): int => (int) substr((string) strrchr($slug, '-'), 1))->max() ?? 0;

        for ($i = 0; $i < $count; $i++) {
            $attributes = SampleCustomers::at($i + $offset, $dataset->id);
            $region = $regions->isEmpty() ? null : $regions[$i % $regions->count()];
            $customer = Customer::query()->withoutGlobalScopes()->create($attributes + [
                'dataset_id' => $dataset->id,
                'region_id' => $region?->id,
            ]);
            $this->line("Created {$customer->name}, {$customer->address}, {$customer->city}".($region ? " ({$region->pso_id})" : ''));
        }

        $this->info("Created {$count} customers in {$dataset->name}.");

        return self::SUCCESS;
    }
}
