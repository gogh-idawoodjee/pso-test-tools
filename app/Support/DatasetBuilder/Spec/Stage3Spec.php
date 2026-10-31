<?php

namespace App\Support\DatasetBuilder\Spec;

/**
 * Stage 3's booked appointments, from the seed's `stage3` block: how many per
 * serving resource per working day, over how many working days, the type mix,
 * per type its duration range (minutes) and skill pool, the template kind
 * weights, and how long before its slot an appointment was booked (minutes).
 */
readonly class Stage3Spec
{
    /** A slot today counts only if it starts at least this long after now. */
    public const int TODAY_LEAD_MINUTES = 60;

    /**
     * @param  array<string, float>  $mix  type => share
     * @param  array<string, array{min: int, max: int}>  $durations  type => minutes
     * @param  array<string, array{pool: list<string>, p: float}>  $skills
     * @param  array<string, float>  $kindWeights  template kind => weight
     * @param  array{min: int, max: int}  $bookedAgo  minutes before the slot start
     */
    public function __construct(
        public float $appointmentsPerResourcePerDay,
        public int $workingDays,
        public array $mix,
        public array $durations,
        public array $skills,
        public array $kindWeights,
        public array $bookedAgo,
    ) {}
}
