<?php

namespace App\Support\DatasetBuilder\Spec;

/**
 * Stage 4's splittable PROJECT work, from the seed's `stage4` block: how many
 * projects a metro or rural region gets a week, over how many weeks, the
 * duration range (minutes), the required and optional skills, the start
 * weekday weights, the activity's split_minimum_priority and the SLA windows.
 */
readonly class Stage4Spec
{
    public const string TYPE = 'PROJECT';

    /**
     * @param  array{metro: int, rural: int}  $perRegionPerWeek
     * @param  array{min: int, max: int}  $duration  minutes
     * @param  list<string>  $requiredSkills
     * @param  array<string, float>  $optionalSkills  skill => probability
     * @param  array<int, float>  $startDayWeights  ISO weekday => weight
     * @param  array{in: string, out: string, start_based: bool}  $sla
     */
    public function __construct(
        public array $perRegionPerWeek,
        public int $weeks,
        public array $duration,
        public array $requiredSkills,
        public array $optionalSkills,
        public array $startDayWeights,
        public int $splitMinimumPriority,
        public array $sla,
    ) {}
}
