<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DOMNode;
use Throwable;
use XMLReader;

/**
 * Pulls a handful of display-only fields out of an uploaded schedule data
 * file: the Input_Reference block's dataset_id/datetime, and how many
 * Activity/Resources entries the file contains. None of this feeds the
 * actual gateway upload — the raw bytes are gzipped and sent unchanged
 * regardless of whether this extraction succeeds.
 *
 * XML is read with XMLReader, a streaming pull-parser — this stays safe at
 * any file size, since it only ever builds a DOM for the small
 * Input_Reference element itself, never the whole document. JSON has no
 * equivalent streaming reader in PHP core, so it's decoded in full and
 * capped at MAX_JSON_PARSE_BYTES to avoid risking the worker's memory
 * limit on a 100MB+ payload for what is purely a cosmetic enhancement.
 */
class ScheduleDataSummaryExtractor
{
    private const int MAX_JSON_PARSE_BYTES = 20 * 1024 * 1024; // 20MB

    /**
     * @return array{dataset_id: ?string, input_reference_datetime: ?CarbonImmutable, activity_count: int, resource_count: int}
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

        $datasetId = null;
        $datetime = null;
        $activityCount = 0;
        $resourceCount = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                match ($reader->localName) {
                    'Activity' => $activityCount++,
                    'Resources' => $resourceCount++,
                    default => null,
                };

                if ($reader->localName === 'Input_Reference') {
                    $node = $reader->expand();
                    $datasetId = $node instanceof DOMNode ? self::childText($node, 'dataset_id') : null;
                    $datetime = $node instanceof DOMNode ? self::childText($node, 'datetime') : null;
                }
            }
        } finally {
            $reader->close();
        }

        return [
            'dataset_id' => $datasetId,
            'input_reference_datetime' => self::parseDatetime($datetime),
            'activity_count' => $activityCount,
            'resource_count' => $resourceCount,
        ];
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
        if (filesize($filePath) > self::MAX_JSON_PARSE_BYTES) {
            return self::empty();
        }

        $decoded = json_decode(file_get_contents($filePath), true);
        $data = is_array($decoded) ? ($decoded['dsScheduleData'] ?? []) : [];

        $inputReference = $data['Input_Reference'] ?? null;

        if (is_array($inputReference) && array_is_list($inputReference)) {
            $inputReference = $inputReference[0] ?? null;
        }

        return [
            'dataset_id' => is_array($inputReference) ? ($inputReference['dataset_id'] ?? null) : null,
            'input_reference_datetime' => self::parseDatetime(is_array($inputReference) ? ($inputReference['datetime'] ?? null) : null),
            'activity_count' => is_array($data['Activity'] ?? null) ? count($data['Activity']) : 0,
            'resource_count' => is_array($data['Resources'] ?? null) ? count($data['Resources']) : 0,
        ];
    }

    private static function parseDatetime(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{dataset_id: null, input_reference_datetime: null, activity_count: int, resource_count: int}
     */
    private static function empty(): array
    {
        return [
            'dataset_id' => null,
            'input_reference_datetime' => null,
            'activity_count' => 0,
            'resource_count' => 0,
        ];
    }
}
