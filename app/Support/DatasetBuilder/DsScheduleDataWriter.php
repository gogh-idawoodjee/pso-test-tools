<?php

namespace App\Support\DatasetBuilder;

use RuntimeException;
use XMLWriter;

/**
 * Streams a dsScheduleData document to a file: one element per row, one child
 * element per field, in the order given. A null or empty field is left out
 * rather than sent empty. The caller writes the entities in the cheat sheet's
 * emit order; this only refuses entities that must never go into a LOAD.
 */
class DsScheduleDataWriter
{
    public const string NAMESPACE = 'http://360Scheduling.com/Schema/dsScheduleData.xsd';

    /** PSO's own output: never part of a LOAD. */
    private const array NEVER = ['Plan', 'Plan_Resource', 'Plan_Route', 'Allocation', 'Allocation_Data', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Event'];

    private XMLWriter $xml;

    private int $rows = 0;

    public function __construct(private readonly string $path)
    {
        $xml = new XMLWriter;

        if (! $xml->openUri($path)) {
            throw new RuntimeException("The dataset file could not be opened for writing: {$path}");
        }

        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('dsScheduleData');
        $xml->writeAttribute('xmlns', self::NAMESPACE);

        $this->xml = $xml;
    }

    /** @param array<string, string|null> $fields */
    public function row(string $entity, array $fields): void
    {
        if (in_array($entity, self::NEVER, true)) {
            throw new RuntimeException("{$entity} is PSO output and never goes into a LOAD.");
        }

        $this->xml->startElement($entity);

        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->xml->writeElement($name, $value);
        }

        $this->xml->endElement();

        // Flush every so often so memory stays flat, whatever the row count.
        if (++$this->rows % 500 === 0) {
            $this->xml->flush();
        }
    }

    /** @return int bytes in the finished file */
    public function finish(): int
    {
        $this->xml->endElement();
        $this->xml->endDocument();
        $this->xml->flush();

        clearstatcache(true, $this->path);

        return (int) filesize($this->path);
    }
}
