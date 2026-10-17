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

/**
 * A small export that exercises every rule. With region V1 and the window 2026-10-05 to 2026-10-09 (UTC) the
 * kept ids are: resources R1; shifts S1; activities A1 (L1 is in V1) and A3 (L3 only through Location_Region);
 * locations L1, L3, LS1 (R1 and S1 start there), LT (Resource_Type); availability AV1 (R1's region
 * availability) and AV3 (R1's skill availability, dated 2025: never filtered by date).
 *
 * @return array<string, list<array<string, string>>>
 */
function trimDataset(): array
{
    return [
        'Input_Reference' => [['id' => 'IR1', 'datetime' => '2026-10-04T08:00:00', 'dataset_id' => 'NORTH']],
        'Region' => [['id' => 'V1', 'description' => 'Regina'], ['id' => 'V2', 'description' => 'Saskatoon']],
        'Location' => [
            ['id' => 'L1', 'region_id' => 'V1'], ['id' => 'L2', 'region_id' => 'V2'], ['id' => 'L3'],
            ['id' => 'L4', 'region_id' => 'V1'], ['id' => 'LS1'], ['id' => 'LT'],
        ],
        'Location_Region' => [['location_id' => 'L3', 'region_id' => 'V1'], ['location_id' => 'L2', 'region_id' => 'V2']],
        'Resource_Type' => [['id' => 'RT1', 'location_id_start' => 'LT', 'location_id_end' => 'LT']],
        'Resources' => [
            ['id' => 'R1', 'first_name' => 'Ada', 'surname' => 'Lovelace', 'location_id_start' => 'LS1'],
            ['id' => 'R2', 'first_name' => 'Alan', 'surname' => 'Turing'],
        ],
        'Resource_Region' => [['resource_id' => 'R1', 'region_id' => 'V1'], ['resource_id' => 'R2', 'region_id' => 'V2']],
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'R1', 'start_datetime' => '2026-10-06T08:00:00', 'end_datetime' => '2026-10-06T16:00:00', 'location_id_start' => 'LS1'],
            ['id' => 'S2', 'resource_id' => 'R2', 'start_datetime' => '2026-10-06T08:00:00', 'end_datetime' => '2026-10-06T16:00:00'],
            ['id' => 'S3', 'resource_id' => 'R1', 'start_datetime' => '2026-10-20T08:00:00', 'end_datetime' => '2026-10-20T16:00:00'],
        ],
        'Shift_Break' => [['shift_id' => 'S1', 'duration' => '30'], ['shift_id' => 'S2', 'duration' => '30']],
        'Availability' => [
            ['id' => 'AV1', 'resource_id' => 'R1', 'datetime_start' => '2026-10-06T08:00:00'],
            ['id' => 'AV2', 'resource_id' => 'R2', 'datetime_start' => '2026-10-06T08:00:00'],
            ['id' => 'AV3', 'resource_id' => 'R1', 'datetime_start' => '2025-01-01T08:00:00'],
            ['id' => 'AV4', 'datetime_start' => '2026-10-06T08:00:00'],
        ],
        'Resource_Region_Availability' => [['resource_id' => 'R1', 'availability_id' => 'AV1'], ['resource_id' => 'R2', 'availability_id' => 'AV2']],
        'Resource_Skill_Availability' => [['resource_id' => 'R1', 'availability_id' => 'AV3']],
        'Activity_Type' => [['id' => 'T1', 'description' => 'Install'], ['id' => 'T2', 'description' => 'Repair']],
        'Activity' => [
            ['id' => 'A1', 'location_id' => 'L1', 'activity_type_id' => 'T1'],
            ['id' => 'A2', 'location_id' => 'L2', 'activity_type_id' => 'T1'],
            ['id' => 'A3', 'location_id' => 'L3', 'activity_type_id' => 'T2'],
            ['id' => 'A4', 'location_id' => 'L1', 'activity_type_id' => 'T1'],
        ],
        'Activity_SLA' => [
            ['activity_id' => 'A1', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
            ['activity_id' => 'A2', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
            ['activity_id' => 'A3', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-07T08:00:00', 'datetime_end' => '2026-10-07T12:00:00'],
            ['activity_id' => 'A4', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-20T08:00:00', 'datetime_end' => '2026-10-20T12:00:00'],
            ['activity_id' => 'A4', 'sla_type_id' => 'COMMIT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
        ],
        'Activity_Status' => [['activity_id' => 'A1', 'status_id' => '30', 'resource_id' => 'R2'], ['activity_id' => 'A2', 'status_id' => '0']],
        'Activity_Group' => [['activity_id1' => 'A1', 'activity_id2' => 'A3'], ['activity_id1' => 'A1', 'activity_id2' => 'A2']],
        'Activity_Skill' => [['activity_id' => 'A1', 'skill_id' => 'K1'], ['activity_id' => 'A2', 'skill_id' => 'K1']],
        'Plan' => [['id' => 'P1', 'dataset_id' => 'NORTH']],
        'Plan_Travel' => [['plan_id' => 'P1', 'resource_id' => 'R1'], ['plan_id' => 'P1', 'resource_id' => 'R2']],
        'Allocation' => [['activity_id' => 'A1', 'resource_id' => 'R1'], ['activity_id' => 'A2', 'resource_id' => 'R2']],
    ];
}
