<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;

/** The seed specs shipped in resources/datasets/{id}.json. */
class DatasetSpecRepository
{
    public function __construct(private readonly ?string $directory = null) {}

    /** @return array<string, string> id => "id: description" for every spec file that reads */
    public function options(): array
    {
        $options = [];

        foreach (glob($this->directory().'/*.json') ?: [] as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            $data = json_decode((string) file_get_contents($path), true);
            $description = is_array($data) && is_string($data['description'] ?? null) ? $data['description'] : 'unreadable spec';
            $options[$id] = "{$id}: {$description}";
        }

        ksort($options);

        return $options;
    }

    /** @throws InvalidDatasetSpec */
    public function load(string $id): DatasetSpec
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $id) !== 1) {
            throw new InvalidDatasetSpec("No seed spec called {$id}.");
        }

        return DatasetSpec::fromFile($this->directory()."/{$id}.json");
    }

    private function directory(): string
    {
        return $this->directory ?? resource_path('datasets');
    }
}
