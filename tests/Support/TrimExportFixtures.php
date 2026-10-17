<?php

use App\Support\TrimExport\Readers\TableReader;
use App\Support\TrimExport\Segment;
use App\Support\TrimExport\Writers\TableWriter;

/** Writes $contents to a temporary file and returns its path. */
function trimFixtureFile(string $contents, string $extension = 'xml'): string
{
    $path = tempnam(sys_get_temp_dir(), 'trim_').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @param iterable<Segment> $segments */
function joinSegments(iterable $segments): string
{
    $bytes = '';
    foreach ($segments as $segment) {
        $bytes .= $segment->prefix.$segment->body;
    }

    return $bytes;
}

/**
 * @param  iterable<Segment>  $segments
 * @return list<string> "type table" for each segment
 */
function segmentShape(iterable $segments): array
{
    $shape = [];
    foreach ($segments as $segment) {
        $shape[] = trim($segment->type.' '.$segment->table);
    }

    return $shape;
}

/**
 * A pretty-printed dsScheduleData XML file of these tables, in the given order.
 *
 * @param  array<string, list<array<string, string>>>  $tables
 */
function trimXml(array $tables): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".'<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">';
    foreach ($tables as $table => $rows) {
        foreach ($rows as $row) {
            $xml .= "\n  <{$table}>";
            foreach ($row as $field => $value) {
                $xml .= "\n    <{$field}>".htmlspecialchars((string) $value, ENT_XML1).'</'.$field.'>';
            }
            $xml .= "\n  </{$table}>";
        }
    }

    return trimFixtureFile($xml."\n</dsScheduleData>\n", 'xml');
}

/**
 * The same tables as pretty-printed JSON. A table named in $singleObjects that has one row is written as a
 * plain object, the older dsScheduleData shape.
 *
 * @param  array<string, list<array<string, string|int>>>  $tables
 * @param  list<string>  $singleObjects
 */
function trimJson(array $tables, array $singleObjects = [], string $wrapper = 'dsScheduleData'): string
{
    $data = [];
    foreach ($tables as $table => $rows) {
        $data[$table] = in_array($table, $singleObjects, true) && count($rows) === 1 ? $rows[0] : $rows;
    }

    return trimFixtureFile(json_encode([$wrapper => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 'json');
}

/**
 * Every row of an output file by table, each as field => string value, read with DOM or json_decode (never
 * with the code under test). Tables with no rows are left out, so XML and JSON results compare equal.
 *
 * @return array<string, list<array<string, string>>>
 */
function trimmedRows(string $path, string $format): array
{
    $tables = [];

    if ($format === 'xml') {
        $document = new DOMDocument;
        if (! @$document->load($path, LIBXML_NONET)) {
            throw new RuntimeException('Not well-formed XML: '.$path);
        }
        foreach ($document->documentElement->childNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }
            $row = [];
            foreach ($rowNode->childNodes as $fieldNode) {
                if ($fieldNode instanceof DOMElement) {
                    $row[$fieldNode->localName] = $fieldNode->textContent;
                }
            }
            $tables[$rowNode->localName][] = $row;
        }

        return $tables;
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    foreach (reset($decoded) ?: [] as $table => $rows) {
        if (! is_array($rows)) {
            continue;
        }
        foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
            $tables[$table][] = array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $row);
        }
    }

    return $tables;
}

/**
 * Reads $source and writes it to $target, keeping a row when $keepRow says so and a table when $keepTable does.
 *
 * @param  Closure(Segment): bool|null  $keepRow
 * @param  Closure(string): bool|null  $keepTable
 */
function trimCopy(TableReader $reader, TableWriter $writer, string $source, string $target, ?Closure $keepRow = null, ?Closure $keepTable = null): int
{
    $writer->open($target);
    foreach ($reader->segments($source) as $segment) {
        $tableKept = $keepTable === null || $segment->table === '' || $keepTable($segment->table);
        $keep = match ($segment->type) {
            Segment::ROW => $tableKept && ($keepRow === null || $keepRow($segment)),
            Segment::TABLE_OPEN, Segment::TABLE_CLOSE => $tableKept,
            default => true,
        };
        $writer->write($segment, $keep);
    }

    return $writer->close();
}
