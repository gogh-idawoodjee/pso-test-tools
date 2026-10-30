<?php

namespace App\Support\DatasetBuilder\Spec;

/** One resource of a seed spec; `sequence` is its 1-based place in the spec, used in shift ids. */
readonly class ResourceSpec
{
    /**
     * @param  list<string>  $skills
     * @param  list<string>  $extraRegions
     */
    public function __construct(
        public int $sequence,
        public string $id,
        public string $firstName,
        public string $surname,
        public string $region,
        public string $resourceType,
        public array $skills,
        public array $extraRegions,
        public ?int $utilisation,
    ) {}
}
