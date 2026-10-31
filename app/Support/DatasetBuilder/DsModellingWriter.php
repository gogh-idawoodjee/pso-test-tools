<?php

namespace App\Support\DatasetBuilder;

/**
 * Streams a DsModelling (RAM, .arp) document. Since the 1R FIX on #60 it
 * carries the master rota (header, membership periods, alterations, items and
 * their breaks): PSO only hands a resource to scheduling when it has rota
 * content. Every other rota table, and the version history, stays the
 * planner's and PSO's own.
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

    /** The rota tables generated data may carry. */
    public const array ROTA = ['RAM_Rota', 'RAM_Rota_Membership_Period', 'RAM_Rota_Alteration', 'RAM_Rota_Item', 'RAM_Rota_Item_Break'];

    protected function refusal(string $entity): ?string
    {
        if (str_starts_with($entity, 'RAM_Rota') && ! in_array($entity, self::ROTA, true)) {
            return "{$entity} is the planner's own rota data and never goes into generated modelling data.";
        }

        return $entity === 'RAM_Version_History' ? 'RAM_Version_History is written by PSO.' : null;
    }
}
