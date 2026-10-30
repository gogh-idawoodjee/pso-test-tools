<?php

namespace App\Support\DatasetBuilder\Spec;

/** One resource type: its weekly shift pattern (local times), its break and its costs. */
readonly class ResourceTypeSpec
{
    /**
     * @param  list<int>  $shiftDays  ISO weekdays, 1 (Monday) to 7 (Sunday)
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $shiftDays,
        public string $shiftStart,
        public string $shiftEnd,
        public string $breakDuration,
        public string $breakEarliestOffset,
        public string $breakLatestOffset,
        public string $startEnd,
        public string $travelTo,
        public string $travelFrom,
        public string $maxTravel,
        public float $costPh,
        public float $costPhot,
        public float $costKm,
        public int $utilisation,
    ) {}

    public function startsAtDepot(): bool
    {
        return $this->startEnd === 'depot';
    }
}
