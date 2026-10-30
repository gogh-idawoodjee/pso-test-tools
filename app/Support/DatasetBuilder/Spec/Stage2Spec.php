<?php

namespace App\Support\DatasetBuilder\Spec;

/**
 * Stage 2's unallocated SLA work, from the seed's `stage2` block: how many jobs
 * per home-region resource per working day, over how many working days, the
 * type mix, and per type its duration range (minutes), SLA windows and skill
 * pool. Keyed by activity type, in mix order.
 */
readonly class Stage2Spec
{
    /** The working days are the weekdays on which this type's shift has not ended yet. */
    public const string WORKING_DAY_TYPE = 'FIELD_TECH';

    /** An SLA window that ends at 17:00 local on the target day itself. */
    public const string SAME_DAY = 'same_day';

    /**
     * @param  array<string, float>  $mix  type => share
     * @param  array<string, array{min: int, max: int}>  $durations  type => minutes
     * @param  array<string, array{in: string, out: string}>  $slaWindows  type => ISO durations (`in` may be same_day)
     * @param  array<string, array{pool: list<string>, p: float}>  $skills
     */
    public function __construct(
        public float $jobsPerResourcePerDay,
        public int $workingDays,
        public array $mix,
        public array $durations,
        public array $slaWindows,
        public array $skills,
    ) {}
}
