<?php

namespace App\Support;

use App\Support\ScheduleData\JsonTokenReader;
use Carbon\CarbonImmutable;
use DOMNode;
use Throwable;
use XMLReader;

/**
 * Pulls a handful of fields out of an uploaded schedule data file: the
 * Input_Reference block's dataset_id/datetime, and how many
 * Activity/Resources entries the file contains, for display; and its
 * input_type, duration and appointment_window_duration, which decide whether
 * the gateway upload adds its webhook Broadcast (a LOAD only) and that
 * Broadcast's time filter. A file this cannot read gives nulls, so no
 * Broadcast is added.
 *
 * XML is read with XMLReader, a streaming pull-parser — this stays safe at
 * any file size, since it only ever builds a DOM for the small
 * Input_Reference element itself, never the whole document. JSON is read
 * with the same chunked token reader, so it is safe at any size too.
 */
class ScheduleDataSummaryExtractor
{
    /**
     * @return array{dataset_id: ?string, input_reference_datetime: ?CarbonImmutable, input_type: ?string, duration: ?string, appointment_window_duration: ?string, activity_count: int, resource_count: int, table_counts: array<string, int>}
     */
    public static function extract(string $filePath, string $format): array
    {
        try {
            return $format === 'xml'
                ? self::extractFromXml($filePath)
                : self::extractFromJson($filePath);
        } catch (Throwable) {
            return self::empty();
        }
    }

    private static function extractFromXml(string $filePath): array
    {
        $reader = new XMLReader;

        if (! $reader->open($filePath)) {
            return self::empty();
        }

        $fields = self::emptyFields();
        $activityCount = 0;
        $resourceCount = 0;
        $tableCounts = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                // A table row is an element just inside the root; nested elements of the same name are not rows.
                if ($reader->depth === 1) {
                    $tableCounts[$reader->localName] = ($tableCounts[$reader->localName] ?? 0) + 1;
                }

                match ($reader->localName) {
                    'Activity' => $activityCount++,
                    'Resources' => $resourceCount++,
                    default => null,
                };

                if ($reader->localName === 'Input_Reference') {
                    $node = $reader->expand();

                    foreach (array_keys($fields) as $field) {
                        $fields[$field] = $node instanceof DOMNode ? self::childText($node, $field) : null;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return self::result($fields, $activityCount, $resourceCount, $tableCounts);
    }

    private static function childText(DOMNode $node, string $tagName): ?string
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === $tagName) {
                return $child->textContent !== '' ? $child->textContent : null;
            }
        }

        return null;
    }

    private static function extractFromJson(string $filePath): array
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return self::empty();
        }

        $depth = 0;
        $previous = '';
        $wrapperPending = false;
        $wrapperFound = false;
        $insideWrapper = false;
        $entityName = null;
        $entityIsArray = false;
        $wantedField = null;
        $fields = self::emptyFields();
        $activityCount = 0;
        $resourceCount = 0;
        $tableCounts = [];

        try {
            foreach (app(JsonTokenReader::class)->tokens($handle) as $token) {
                if (strspn($token[0], " \t\r\n") === 1) {
                    continue;
                }

                $before = $previous;
                $previous = $token;

                if ($wantedField !== null) {
                    if ($token === ':') {
                        continue;
                    }

                    if ($token[0] === '"') {
                        $text = json_decode($token);

                        if (is_string($text) && $text !== '') {
                            $fields[$wantedField] ??= $text;
                        }
                    }

                    $wantedField = null;

                    if ($token !== '{' && $token !== '[') {
                        continue;
                    }
                }

                if ($token === '{' || $token === '[') {
                    if ($wrapperPending && $token === '{' && $depth === 1) {
                        $insideWrapper = true;
                        $wrapperFound = true;
                        $wrapperPending = false;
                    } elseif ($insideWrapper && $depth === 2 && $entityName !== null) {
                        $entityIsArray = $token === '[';

                        if ($entityIsArray) {
                            // An empty list is a table with no rows.
                            $tableCounts[$entityName] ??= 0;
                        } else {
                            self::tally($entityName, $activityCount, $resourceCount, $tableCounts);
                        }
                    } elseif ($insideWrapper && $depth === 3 && $entityIsArray && $token === '{') {
                        self::tally($entityName, $activityCount, $resourceCount, $tableCounts);
                    }

                    $depth++;

                    continue;
                }

                if ($token === '}' || $token === ']') {
                    $depth--;

                    if ($insideWrapper && $depth === 1) {
                        $insideWrapper = false;
                    }

                    continue;
                }

                if ($token === ':' || $token === ',') {
                    continue;
                }

                if ($token[0] === '"' && ($before === '{' || $before === ',')) {
                    $key = json_decode($token);

                    if ($depth === 1 && ! $wrapperFound) {
                        $wrapperPending = in_array($key, ['ScheduleData', 'dsScheduleData'], true);
                    } elseif ($insideWrapper && $depth === 2) {
                        $entityName = is_string($key) ? $key : null;
                        $entityIsArray = false;
                    } elseif (
                        $insideWrapper
                        && $entityName === 'Input_Reference'
                        && (($depth === 3 && ! $entityIsArray) || ($depth === 4 && $entityIsArray))
                    ) {
                        $wantedField = is_string($key) && array_key_exists($key, $fields) ? $key : null;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $wrapperFound) {
            return self::empty();
        }

        return self::result($fields, $activityCount, $resourceCount, $tableCounts);
    }

    /**
     * The Input_Reference fields read, each null until found.
     *
     * @return array{dataset_id: null, datetime: null, input_type: null, duration: null, appointment_window_duration: null}
     */
    private static function emptyFields(): array
    {
        return ['dataset_id' => null, 'datetime' => null, 'input_type' => null, 'duration' => null, 'appointment_window_duration' => null];
    }

    /**
     * @param  array<string, ?string>  $fields
     * @param  array<string, int>  $tableCounts
     */
    private static function result(array $fields, int $activityCount, int $resourceCount, array $tableCounts): array
    {
        return [
            'dataset_id' => $fields['dataset_id'],
            'input_reference_datetime' => self::parseDatetime($fields['datetime']),
            'input_type' => $fields['input_type'],
            'duration' => $fields['duration'],
            'appointment_window_duration' => $fields['appointment_window_duration'],
            'activity_count' => $activityCount,
            'resource_count' => $resourceCount,
            'table_counts' => $tableCounts,
        ];
    }

    /** @param array<string, int> $tableCounts */
    private static function tally(?string $entityName, int &$activityCount, int &$resourceCount, array &$tableCounts): void
    {
        if ($entityName === null) {
            return;
        }

        $tableCounts[$entityName] = ($tableCounts[$entityName] ?? 0) + 1;

        match ($entityName) {
            'Activity' => $activityCount++,
            'Resources' => $resourceCount++,
            default => null,
        };
    }

    private static function parseDatetime(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            // PSO writes exported times in UTC; a time without an offset is UTC, never the app time zone.
            return CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{dataset_id: null, input_reference_datetime: null, input_type: null, duration: null, appointment_window_duration: null, activity_count: int, resource_count: int, table_counts: array{}}
     */
    private static function empty(): array
    {
        return [
            'dataset_id' => null,
            'input_reference_datetime' => null,
            'input_type' => null,
            'duration' => null,
            'appointment_window_duration' => null,
            'activity_count' => 0,
            'resource_count' => 0,
            'table_counts' => [],
        ];
    }
}
