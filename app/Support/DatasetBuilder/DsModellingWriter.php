<?php

namespace App\Support\DatasetBuilder;

/**
 * Streams a DsModelling (RAM, .arp) document. Refuses the rota tables and the
 * version history: the planner owns those and their ids, and the rule is no
 * shifts in RAM (docs/dataset-builder/stage1r-ram-entity-cheatsheet.md).
 */
class DsModellingWriter extends XmlRowWriter
{
    public const string NAMESPACE = 'http://360Scheduling.com/Schema/DsModelling.xsd';

    protected function root(): string
    {
        return 'DsModelling';
    }

    protected function namespace(): string
    {
        return self::NAMESPACE;
    }

    protected function refusal(string $entity): ?string
    {
        if (str_starts_with($entity, 'RAM_Rota')) {
            return "{$entity} is the planner's own rota data and never goes into generated modelling data.";
        }

        return $entity === 'RAM_Version_History' ? 'RAM_Version_History is written by PSO.' : null;
    }
}
