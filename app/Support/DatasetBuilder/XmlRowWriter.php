<?php

namespace App\Support\DatasetBuilder;

use RuntimeException;
use XMLWriter;

/**
 * Streams a PSO data document to a file: one element per row, one child element
 * per field, in the order given. A null or empty field is left out rather than
 * sent empty. The caller writes the entities in its cheat sheet's emit order;
 * a subclass names the root, its namespace and the entities it refuses.
 */
abstract class XmlRowWriter
{
    private XMLWriter $xml;

    private int $rows = 0;

    /** @var array<string, int> entity => rows written, in first-written order */
    private array $counts = [];

    public function __construct(private readonly string $path)
    {
        $xml = new XMLWriter;

        if (! $xml->openUri($path)) {
            throw new RuntimeException("The dataset file could not be opened for writing: {$path}");
        }

        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($this->root());
        $xml->writeAttribute('xmlns', $this->namespace());

        $this->xml = $xml;
    }

    abstract protected function root(): string;

    abstract protected function namespace(): string;

    /** Why $entity may never be written, or null when it may. */
    abstract protected function refusal(string $entity): ?string;

    /** @param array<string, string|null> $fields */
    public function row(string $entity, array $fields): void
    {
        $refusal = $this->refusal($entity);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $this->xml->startElement($entity);

        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->xml->writeElement($name, $value);
        }

        $this->xml->endElement();
        $this->counts[$entity] = ($this->counts[$entity] ?? 0) + 1;

        // Flush every so often so memory stays flat, whatever the row count.
        if (++$this->rows % 500 === 0) {
            $this->xml->flush();
        }
    }

    /** @return array<string, int> entity => rows written so far */
    public function counts(): array
    {
        return $this->counts;
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
