<?php

namespace App\Support\DatasetBuilder;

/**
 * Streams a dsScheduleData LOAD; refuses the entities that are PSO's own
 * output, and, given an allow-list (SLIM, when the dataset has modelling
 * data), anything outside it.
 */
class DsScheduleDataWriter extends XmlRowWriter
{
    public const string NAMESPACE = 'http://360Scheduling.com/Schema/dsScheduleData.xsd';

    /** PSO's own output: never part of a LOAD. */
    private const array NEVER = ['Plan', 'Plan_Resource', 'Plan_Route', 'Allocation', 'Allocation_Data', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Event'];

    /** A LOAD beside modelling data: everything else is RAM's (stage 1R cheat sheet, slim mode; stage 2 adds the activities and their locations). */
    public const array SLIM = [
        'Input_Reference', 'Source_Data', 'Source_Data_Parameter', 'Location', 'Location_Region', 'Shift', 'Shift_Break',
        'Activity', 'Activity_Skill', 'Activity_SLA', 'Activity_Status',
    ];

    /** @param  list<string>|null  $allowed  the only entities that may be written, or null for any */
    public function __construct(string $path, private readonly ?array $allowed = null)
    {
        parent::__construct($path);
    }

    protected function root(): string
    {
        return 'dsScheduleData';
    }

    protected function namespace(): string
    {
        return self::NAMESPACE;
    }

    protected function refusal(string $entity): ?string
    {
        if (in_array($entity, self::NEVER, true)) {
            return "{$entity} is PSO output and never goes into a LOAD.";
        }

        return $this->allowed !== null && ! in_array($entity, $this->allowed, true)
            ? "{$entity} belongs to the modelling data, so it stays out of this LOAD."
            : null;
    }
}
