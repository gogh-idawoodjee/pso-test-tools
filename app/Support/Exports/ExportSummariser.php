<?php

namespace App\Support\Exports;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\ScheduleDataSummaryExtractor;
use App\Support\SysCompare\VersionAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Reads an uploaded export from a local scratch path and keeps only counts, ids
 * and times. Everything is streamed: XMLReader for xml, ZipArchive members for
 * zips. SYS credentials and audit contact fields are never read into the result.
 */
class ExportSummariser
{
    public function summarise(ExportFile $file, string $localPath, string $timeZone): ExportSummary
    {
        $kind = $file->kind;

        if ($kind === ExportKind::Audits) {
            return $this->summariseAudits($file, $localPath, $timeZone);
        }

        [$path, $format] = $file->content_type === 'zip'
            ? $this->largestMember($localPath)
            : [$localPath, $file->content_type];

        $root = $this->detectRoot($path, $format);
        $expected = $kind->expectedRoot();
        if ($root !== $expected) {
            $found = $root ?? 'unknown';

            throw new InvalidExportException("This file is a <{$found}> export, not a {$kind->getLabel()} (<{$expected}>). Upload it under the right kind.");
        }

        return $kind === ExportKind::Sys
            ? $this->summariseSys($path)
            : $this->summariseSchedule($path, $format, $root, $timeZone);
    }

    private function summariseSchedule(string $path, string $format, string $root, string $timeZone): ExportSummary
    {
        $extracted = ScheduleDataSummaryExtractor::extract($path, $format);
        $reference = $extracted['input_reference_datetime'];

        return new ExportSummary([
            'root' => $root,
            'dataset_id' => $extracted['dataset_id'],
            'input_reference_datetime' => $reference?->toIso8601String(),
            'export_date' => $reference?->setTimezone($timeZone)->format('Y-m-d'),
            'activity_count' => $extracted['activity_count'],
            'resource_count' => $extracted['resource_count'],
        ]);
    }

    /** Counts the tables we need and reads the version ids; users and credentials are skipped unread. */
    private function summariseSys(string $path): ExportSummary
    {
        $reader = $this->openReader($path);
        $parameters = 0;
        $exceptionTypes = 0;
        $version = null;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }

                match ($reader->localName) {
                    'Profile_Parameter' => $parameters++,
                    'Org_Schedule_Exception_Type' => $exceptionTypes++,
                    default => null,
                };

                if ($reader->localName === 'System_Version') {
                    $candidate = $this->childText($reader, 'version_id');
                    if ($candidate !== null && ($version === null || VersionAnalyzer::compareVersions($candidate, $version) >= 0)) {
                        $version = $candidate;
                    }
                }

                $reader->next();
            }
        } finally {
            $reader->close();
        }

        return new ExportSummary([
            'root' => 'DsSystemData',
            'pso_version' => $version,
            'parameter_count' => $parameters,
            'exception_type_count' => $exceptionTypes,
        ]);
    }

    private function summariseAudits(ExportFile $file, string $zipPath, string $timeZone): ExportSummary
    {
        if ($file->content_type !== 'zip') {
            throw new InvalidExportException('Audit messages must be uploaded as a zip of xml files.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidExportException('The zip could not be opened. It may be damaged or incomplete.');
        }

        $messages = 0;
        $appointmentRequests = 0;
        $first = null;
        $last = null;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (! $this->isReadableMember($name, 'xml') || str_contains($name, '#')) {
                    continue;
                }

                $messages++;
                $reader = $this->openReader('zip://'.$zipPath.'#'.$name);
                $names = [];
                $time = null;

                try {
                    while (@$reader->read()) {
                        if ($reader->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }

                        $names[$reader->depth] = $reader->localName;
                        $parent = $names[$reader->depth - 1] ?? null;

                        if ($reader->localName === 'Appointment_Request') {
                            $appointmentRequests++;
                        }

                        if ($time === null && (
                            ($parent === 'Plan' && $reader->localName === 'output_datetime')
                            || ($parent === 'Input_Reference' && $reader->localName === 'datetime')
                        )) {
                            $time = $this->parseTime($reader->readString(), $timeZone);
                        }
                    }
                } finally {
                    $reader->close();
                }

                if ($time !== null) {
                    $first = $first === null || $time->lt($first) ? $time : $first;
                    $last = $last === null || $time->gt($last) ? $time : $last;
                }
            }
        } finally {
            $zip->close();
        }

        if ($messages === 0) {
            throw new InvalidExportException('The zip holds no .xml audit messages.');
        }

        return new ExportSummary([
            'message_count' => $messages,
            'first_message_at' => $first?->toIso8601String(),
            'last_message_at' => $last?->toIso8601String(),
            'appointment_request_count' => $appointmentRequests,
        ]);
    }

    /**
     * Copies the largest .xml or .json member of a zip next to it and returns its path and format.
     *
     * @return array{0: string, 1: string}
     */
    private function largestMember(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidExportException('The zip could not be opened. It may be damaged or incomplete.');
        }

        try {
            $bestIndex = null;
            $bestSize = -1;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (! $this->isReadableMember($name) || $stat === false) {
                    continue;
                }
                if ($stat['size'] > $bestSize) {
                    $bestSize = $stat['size'];
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                throw new InvalidExportException('The zip holds no .xml or .json file.');
            }

            $format = strtolower(pathinfo((string) $zip->getNameIndex($bestIndex), PATHINFO_EXTENSION));
            $target = dirname($zipPath).'/member-'.Str::uuid().'.'.$format;
            $source = $zip->getStreamIndex($bestIndex);
            $destination = fopen($target, 'wb');
            try {
                stream_copy_to_stream($source, $destination);
            } finally {
                fclose($destination);
                fclose($source);
            }

            return [$target, $format];
        } finally {
            $zip->close();
        }
    }

    private function isReadableMember(string $name, ?string $only = null): bool
    {
        if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains(basename($name), '._')) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $only !== null ? $extension === $only : in_array($extension, ['xml', 'json'], true);
    }

    /** The root element (xml) or first key (json), normalised to the names we compare against. */
    private function detectRoot(string $path, string $format): ?string
    {
        if ($format === 'json') {
            $head = (string) file_get_contents($path, false, null, 0, 4096);
            $name = preg_match('/^\s*\{\s*"([^"]+)"/', $head, $matches) === 1 ? $matches[1] : null;
        } else {
            $reader = $this->openReader($path);
            $name = null;
            try {
                while (@$reader->read()) {
                    if ($reader->nodeType === XMLReader::ELEMENT) {
                        $name = $reader->localName;

                        break;
                    }
                }
            } finally {
                $reader->close();
            }
        }

        return match (strtolower((string) $name)) {
            'dsscheduledata', 'scheduledata' => 'dsScheduleData',
            'dssystemdata', 'systemdata' => 'DsSystemData',
            default => $name,
        };
    }

    private function openReader(string $path): XMLReader
    {
        $reader = new XMLReader;
        if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidExportException('The file could not be read as XML. It may be damaged or incomplete.');
        }

        return $reader;
    }

    private function childText(XMLReader $reader, string $name): ?string
    {
        $node = $reader->expand();
        if ($node === false) {
            return null;
        }
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === $name || $child->localName === $name) {
                return trim($child->textContent) !== '' ? trim($child->textContent) : null;
            }
        }

        return null;
    }

    private function parseTime(string $value, string $timeZone): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), $timeZone);
        } catch (Throwable) {
            return null;
        }
    }
}
