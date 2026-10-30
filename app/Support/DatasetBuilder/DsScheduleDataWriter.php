<?php

namespace App\Support\DatasetBuilder;

/** Streams a dsScheduleData LOAD; refuses the entities that are PSO's own output. */
class DsScheduleDataWriter extends XmlRowWriter
{
    public const string NAMESPACE = 'http://360Scheduling.com/Schema/dsScheduleData.xsd';

    /** PSO's own output: never part of a LOAD. */
    private const array NEVER = ['Plan', 'Plan_Resource', 'Plan_Route', 'Allocation', 'Allocation_Data', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Event'];

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
        return in_array($entity, self::NEVER, true) ? "{$entity} is PSO output and never goes into a LOAD." : null;
    }
}
