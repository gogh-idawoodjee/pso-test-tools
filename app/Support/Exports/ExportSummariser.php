<?php

namespace App\Support\Exports;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\ScheduleData\JsonTokenReader;
use App\Support\ScheduleDataSummaryExtractor;
use App\Support\SysCompare\VersionAnalyzer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
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
    /** @param  (Closure(int $read, int $of): void)|null  $onProgress  told after each audit message, the only kind slow enough to watch */
    public function summarise(ExportFile $file, string $localPath, string $timeZone, ?Closure $onProgress = null): ExportSummary
    {
        $kind = $file->kind;
        // An event log is a spreadsheet (csv or xlsx), read by its own extension, never sniffed as an export.
        if ($kind === ExportKind::EventLog) {
            return $this->summariseEventLog($localPath, (string) $file->content_type, $timeZone);
        }

        if ($kind === ExportKind::PsoEvents || $kind === ExportKind::AppointmentHistory) {
            return $this->summariseSystemDayFile($kind, $localPath, $timeZone);
        }

        // Zips are often misnamed, so what the bytes are wins over what the file was called.
        $contentType = ExportFormat::sniff($localPath) ?? $file->content_type;

        if ($kind === ExportKind::Audits) {
            return $this->summariseAudits($contentType, $localPath, $onProgress);
        }

        [$path, $format] = $contentType === 'zip'
            ? array_slice(ExportFormat::largestMember($localPath), 0, 2)
            : [$localPath, $contentType];

        $root = $this->detectRoot($path, $format);
        $expected = $kind->expectedRoot();
        if ($root !== $expected) {
            $found = $root ?? 'unknown';

            throw new InvalidExportException("This file is a <{$found}> export, not a {$kind->getLabel()} (<{$expected}>). Upload it under the right kind.");
        }

        return $kind === ExportKind::Sys
            ? $this->summariseSys($path)
            : $this->summariseSchedule($path, $format, $root, $timeZone, $kind);
    }

    private function summariseSchedule(string $path, string $format, string $root, string $timeZone, ExportKind $kind): ExportSummary
    {
        // A plan upload must carry a Plan block; one streaming pass serves this check and the plan-only summary below.
        // A JSON LOAD skips that pass unless it turns out to have no Input_Reference.
        $readPlan = fn (): ?array => $format === 'xml' ? $this->readPlanBlock($path) : $this->readJsonPlanBlock($path);
        $plan = $format === 'xml' || $kind === ExportKind::Plan ? $readPlan() : null;
        if ($kind === ExportKind::Plan && $plan === null) {
            throw new InvalidExportException('This file has no Plan block, so it is not a plan export. Upload it as LOAD or End of day instead.');
        }

        $extracted = ScheduleDataSummaryExtractor::extract($path, $format);
        $reference = $extracted['input_reference_datetime'];

        // The extractor reports zeros for a file it could not read; a ready row must never say that.
        if ($extracted['dataset_id'] === null && $reference === null) {
            // A plan export has no Input_Reference: its dataset and reference time sit on the Plan block.
            if ($plan === null && $format === 'json' && $kind !== ExportKind::Plan) {
                $plan = $readPlan();
            }

            if ($plan !== null) {
                if ($kind === ExportKind::Load) {
                    throw new InvalidExportException('This file is a plan export (it holds a Plan and its allocations, but no activities or resources), not a LOAD. Upload it under Plan or End of day.');
                }

                $planReference = $this->parseTime((string) $plan['input_reference_date_time']);

                return new ExportSummary([
                    'root' => $root,
                    'dataset_id' => $plan['dataset_id'],
                    'input_reference_datetime' => $planReference?->toIso8601String(),
                    'export_date' => $planReference?->setTimezone($timeZone)->format('Y-m-d'),
                    'allocation_count' => $plan['allocation_count'],
                    'table_counts' => $extracted['table_counts'],
                ]);
            }

            throw new InvalidExportException('This file could not be summarised. The file has no Input_Reference and no Plan block. Check that it is a complete PSO export.');
        }

        return new ExportSummary([
            'root' => $root,
            'dataset_id' => $extracted['dataset_id'],
            'input_reference_datetime' => $reference?->toIso8601String(),
            'export_date' => $reference?->setTimezone($timeZone)->format('Y-m-d'),
            'activity_count' => $extracted['activity_count'],
            'resource_count' => $extracted['resource_count'],
            'table_counts' => $extracted['table_counts'],
        ]);
    }

    /**
     * The JSON twin of readPlanBlock(), streamed with JsonTokenReader so a file of any size stays in flat
     * memory. Null unless the wrapper (dsScheduleData or ScheduleData) has a Plan member that is a non-empty
     * object or a non-empty array; the dataset id and input reference time come from the first Plan row.
     *
     * @return array{dataset_id: ?string, input_reference_date_time: ?string, allocation_count: int}|null
     */
    private function readJsonPlanBlock(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $depth = 0;
        $previous = '';
        $wrapperPending = false;
        $wrapperFound = false;
        $insideWrapper = false;
        $entity = null;
        $entityIsArray = false;
        $planOpened = false;
        $planFound = false;
        $readingPlan = false;
        $wantedField = null;
        $fields = ['dataset_id' => null, 'input_reference_date_time' => null];
        $allocations = 0;

        try {
            foreach (app(JsonTokenReader::class)->tokens($handle) as $token) {
                if (strspn($token[0], " \t\r\n") === 1) {
                    continue;
                }

                $before = $previous;
                $previous = $token;

                // The first token inside the Plan value says whether it is empty.
                if ($planOpened) {
                    $planOpened = false;
                    $planFound = $token !== '}' && $token !== ']';
                    $readingPlan = $planFound;
                }

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
                    } elseif ($insideWrapper && $depth === 2 && $entity !== null) {
                        $entityIsArray = $token === '[';

                        if ($entity === 'Allocation' && ! $entityIsArray) {
                            $allocations++;
                        } elseif ($entity === 'Plan' && ! $planFound) {
                            $planOpened = true;
                        }
                    } elseif ($insideWrapper && $depth === 3 && $entityIsArray && $token === '{' && $entity === 'Allocation') {
                        $allocations++;
                    }

                    $depth++;

                    continue;
                }

                if ($token === '}' || $token === ']') {
                    $depth--;

                    if ($insideWrapper && $depth === 2) {
                        $readingPlan = false;
                    } elseif ($insideWrapper && $depth === 1) {
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
                        $entity = is_string($key) ? $key : null;
                        $entityIsArray = false;
                    } elseif ($readingPlan && (($depth === 3 && ! $entityIsArray) || ($depth === 4 && $entityIsArray))) {
                        $wantedField = array_key_exists((string) $key, $fields) ? (string) $key : null;
                    }
                }
            }
        } catch (Throwable $e) {
            // A truncated or corrupt file reads as "no Plan block"; keep the real reason in the log.
            Log::warning('Plan block scan failed: '.$e->getMessage(), ['path' => basename($path)]);

            return null;
        } finally {
            fclose($handle);
        }

        return $planFound ? [...$fields, 'allocation_count' => $allocations] : null;
    }

    /**
     * Streams a plan export: counts Allocation rows and reads the dataset id and input
     * reference time off the Plan block. Null when the file has no Plan block.
     *
     * @return array{dataset_id: ?string, input_reference_date_time: ?string, allocation_count: int}|null
     */
    private function readPlanBlock(string $path): ?array
    {
        $reader = $this->openReader($path);
        $allocations = 0;
        $plan = null;

        try {
            $moved = @$reader->read();

            while ($moved) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    $moved = @$reader->read();

                    continue;
                }

                if ($reader->localName === 'Allocation') {
                    $allocations++;
                } elseif ($reader->localName === 'Plan' && $plan === null) {
                    $plan = [
                        'dataset_id' => $this->childText($reader, 'dataset_id'),
                        'input_reference_date_time' => $this->childText($reader, 'input_reference_date_time'),
                    ];
                }

                $moved = @$reader->next();
            }
        } finally {
            $reader->close();
        }

        return $plan === null ? null : [...$plan, 'allocation_count' => $allocations];
    }

    /** Counts the tables we need and reads the version ids; users and credentials are skipped unread. */
    private function summariseSys(string $path): ExportSummary
    {
        $reader = $this->openReader($path);
        $parameters = 0;
        $exceptionTypes = 0;
        $polygons = 0;
        $version = null;

        try {
            $moved = @$reader->read();

            while ($moved) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    $moved = @$reader->read();

                    continue;
                }

                match ($reader->localName) {
                    'Profile_Parameter' => $parameters++,
                    'Org_Schedule_Exception_Type' => $exceptionTypes++,
                    // Travel tuning refuses a SYS export with no polygons; counted here so a pick needs no second read.
                    'Polygon' => $polygons++,
                    default => null,
                };

                if ($reader->localName === 'System_Version') {
                    $candidate = $this->childText($reader, 'version_id');
                    if ($candidate !== null && ($version === null || VersionAnalyzer::compareVersions($candidate, $version) >= 0)) {
                        $version = $candidate;
                    }
                }

                // next() lands on the following sibling, which must then be looked at, not read past.
                $moved = @$reader->next();
            }
        } finally {
            $reader->close();
        }

        return new ExportSummary([
            'root' => 'DsSystemData',
            'pso_version' => $version,
            'parameter_count' => $parameters,
            'exception_type_count' => $exceptionTypes,
            'polygon_count' => $polygons,
        ]);
    }

    /** The events JSON or appointment history CSV, either bare or as the largest matching member of a zip. */
    private function summariseSystemDayFile(ExportKind $kind, string $localPath, string $timeZone): ExportSummary
    {
        $extension = $kind === ExportKind::PsoEvents ? 'json' : 'csv';
        $path = ExportFormat::sniff($localPath) === 'zip' ? $this->zipMemberUrl($localPath, $extension) : $localPath;

        return $kind === ExportKind::PsoEvents
            ? $this->summarisePsoEvents($path)
            : $this->summariseAppointmentHistory($path, $timeZone);
    }

    /** A zip:// url for the largest member with this extension, after the same size cap as other zip members. */
    private function zipMemberUrl(string $zipPath, string $extension): string
    {
        return 'zip://'.$zipPath.'#'.ExportFormat::largestMemberName($zipPath, $extension)[0];
    }

    /** Whole-file json_decode is fine here: a day of PSO system events is a few MB. Times are UTC ISO strings. */
    private function summarisePsoEvents(string $path): ExportSummary
    {
        $events = json_decode((string) @file_get_contents($path), true);
        if (! is_array($events) || ! array_is_list($events)) {
            throw new InvalidExportException('This file is not a JSON array of PSO system events.');
        }

        $count = 0;
        $first = null;
        $last = null;
        $byMessageId = [];
        $datasets = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $count++;

            $messageId = (string) ($event['messageId'] ?? '');
            if ($messageId !== '') {
                $byMessageId[$messageId] = ($byMessageId[$messageId] ?? 0) + 1;
            }

            $datasetId = trim((string) ($event['externalTaskId'] ?? ''));
            if ($datasetId !== '') {
                $datasets[$datasetId] = true;
            }

            $loggedAt = trim((string) ($event['loggedAt'] ?? ''));
            $time = $this->parseTime(str_starts_with($loggedAt, '\\d') ? substr($loggedAt, 2) : $loggedAt);
            if ($time !== null) {
                $first = $first === null || $time < $first ? $time : $first;
                $last = $last === null || $time > $last ? $time : $last;
            }
        }

        ksort($byMessageId, SORT_NATURAL);
        $datasets = array_map('strval', array_keys($datasets));
        sort($datasets);

        return new ExportSummary([
            'row_count' => $count,
            'first_at' => $first?->utc()->toIso8601String(),
            'last_at' => $last?->utc()->toIso8601String(),
            'by_message_id' => $byMessageId,
            'datasets' => $datasets,
        ]);
    }

    /** Streams the CSV with a header map. Request Time has no zone: it is read in $timeZone and kept as UTC. */
    private function summariseAppointmentHistory(string $path, string $timeZone): ExportSummary
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidExportException('The file could not be read.');
        }

        $count = 0;
        $failed = 0;
        $first = null;
        $last = null;
        $datasets = [];

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $columns = [];
            foreach (is_array($header) ? $header : [] as $position => $heading) {
                $columns[strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading)))] = $position;
            }

            if (! isset($columns['request time'], $columns['success'])) {
                throw new InvalidExportException('This appointment history has no Request Time or Success column.');
            }

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $count++;

                if (strtolower(trim((string) ($row[$columns['success']] ?? ''))) === 'false') {
                    $failed++;
                }

                if (isset($columns['dataset'])) {
                    $datasetId = trim((string) ($row[$columns['dataset']] ?? ''));
                    if ($datasetId !== '') {
                        $datasets[$datasetId] = true;
                    }
                }

                $value = trim((string) ($row[$columns['request time']] ?? ''));
                if ($value === '') {
                    continue;
                }
                try {
                    $time = CarbonImmutable::parse($value, $timeZone)->utc();
                } catch (Throwable) {
                    continue;
                }
                $first = $first === null || $time < $first ? $time : $first;
                $last = $last === null || $time > $last ? $time : $last;
            }
        } finally {
            fclose($handle);
        }

        $datasets = array_map('strval', array_keys($datasets));
        sort($datasets);

        return new ExportSummary([
            'row_count' => $count,
            'first_at' => $first?->toIso8601String(),
            'last_at' => $last?->toIso8601String(),
            'failed_count' => $failed,
            'datasets' => $datasets,
        ]);
    }

    /** Reads only the Created Date column. $timeZone is the zone the log was written in; the summary keeps UTC. */
    private function summariseEventLog(string $path, string $extension, string $timeZone): ExportSummary
    {
        $count = 0;
        $first = null;
        $last = null;

        foreach ($this->eventLogDates($path, $extension, $timeZone) as $time) {
            $count++;
            $first = $first === null || $time < $first ? $time : $first;
            $last = $last === null || $time > $last ? $time : $last;
        }

        if ($count === 0) {
            throw new InvalidExportException('No usable Created Date column was found in this event log.');
        }

        return new ExportSummary([
            'event_count' => $count,
            'first_event' => $first->setTimezone('UTC')->toIso8601String(),
            'last_event' => $last->setTimezone('UTC')->toIso8601String(),
        ]);
    }

    /** @return \Generator<int, CarbonImmutable> one entry per row with a readable Created Date */
    private function eventLogDates(string $path, string $extension, string $timeZone): \Generator
    {
        $column = null;

        foreach ($extension === 'csv' ? $this->csvRows($path) : $this->xlsxRows($path) as $row) {
            if ($column === null) {
                foreach ($row as $position => $heading) {
                    if (strtolower(trim((string) $heading)) === 'created date') {
                        $column = $position;

                        break;
                    }
                }

                continue;
            }

            $time = $this->eventTime($row[$column] ?? null, $timeZone);
            if ($time !== null) {
                yield $time;
            }
        }
    }

    private function eventTime(mixed $value, string $timeZone): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (is_numeric($value) && (float) $value >= 30000 && (float) $value <= 80000) {
                // Excel serial date (OADate): days since 1899-12-30, the fraction being the wall-clock time of day.
                $seconds = min(86399, (int) round(((float) $value - floor((float) $value)) * 86400));

                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $timeZone)
                    ->addDays((int) floor((float) $value))
                    ->setTime(intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            }

            return CarbonImmutable::parse($value, $timeZone);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return \Generator<int, array<int, string>> */
    private function csvRows(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidExportException('The file could not be read.');
        }

        try {
            $first = true;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($first && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $first = false;

                yield array_map(static fn ($cell): string => (string) $cell, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return \Generator<int, array<int, string>> rows of the first worksheet, cells keyed by column position */
    private function xlsxRows(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidExportException('The spreadsheet could not be opened. It may be damaged or incomplete.');
        }

        try {
            $sheetName = $this->firstSheetName($zip);
            if ($zip->statName($sheetName) === false) {
                throw new InvalidExportException('The spreadsheet has no worksheet to read.');
            }

            // Both members are capped like any other zip member; the sheet is then streamed, not loaded whole.
            $capMegabytes = (float) config('exports.max_member_megabytes', 600);
            foreach (['xl/sharedStrings.xml', $sheetName] as $member) {
                $stat = $zip->statName($member);
                if ($stat !== false && $stat['size'] > $capMegabytes * 1024 * 1024) {
                    throw new InvalidExportException('The spreadsheet is larger than '.round($capMegabytes).' MB uncompressed and could not be read here. Save the event log as csv and upload that instead.');
                }
            }

            $strings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $shared = @simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);
                foreach ($shared === false ? [] : $shared->si as $item) {
                    $text = '';
                    foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                        $text .= (string) $part;
                    }
                    $strings[] = $text;
                }
            }

            $reader = $this->openReader('zip://'.$path.'#'.$sheetName);
            try {
                while (@$reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                        continue;
                    }

                    $row = [];
                    $node = simplexml_import_dom($reader->expand(new \DOMDocument));
                    $next = 0;
                    foreach ($node->children() as $cell) {
                        $reference = (string) $cell['r'];
                        $position = $reference !== '' ? $this->columnIndex($reference) : $next;
                        $next = $position + 1;
                        $raw = (string) $cell->v;
                        $type = (string) $cell['t'];
                        $row[$position] = match ($type) {
                            's' => $strings[(int) $raw] ?? '',
                            'inlineStr' => (string) $cell->is->t,
                            default => $raw,
                        };
                    }

                    yield $row;
                }
            } finally {
                $reader->close();
            }
        } finally {
            $zip->close();
        }
    }

    private function firstSheetName(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $relationships !== false) {
            $book = @simplexml_load_string($workbook, 'SimpleXMLElement', LIBXML_NONET);
            $rels = @simplexml_load_string($relationships, 'SimpleXMLElement', LIBXML_NONET);
            $sheet = $book === false ? null : ($book->xpath('//*[local-name()="sheet"]')[0] ?? null);
            $id = $sheet === null ? null : (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if ($id !== null && $rels !== false) {
                foreach ($rels->Relationship as $relationship) {
                    if ((string) $relationship['Id'] === $id) {
                        // An absolute Target ("/xl/worksheets/sheet1.xml", as openpyxl writes) is from the zip root;
                        // a relative one is from xl/.
                        $target = (string) $relationship['Target'];
                        $name = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                        if ($zip->statName($name) !== false) {
                            return $name;
                        }
                    }
                }
            }
        }

        if ($zip->statName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name) === 1) {
                return $name;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** "C12" gives 2. */
    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Counts the messages and finds the span of their times. Each message is walked table by
     * table: only Plan and Input_Reference are opened (for the time), every other table is
     * skipped whole, so a day of audits is read in a fraction of the time a full walk takes.
     *
     * @param  (Closure(int $read, int $of): void)|null  $onProgress
     */
    private function summariseAudits(string $contentType, string $zipPath, ?Closure $onProgress = null): ExportSummary
    {
        if ($contentType !== 'zip') {
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
            $members = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (ExportFormat::isReadableMember($name, 'xml') && ! str_contains($name, '#')) {
                    $members[] = $name;
                }
            }
            $total = count($members);

            foreach ($members as $name) {
                $messages++;
                $reader = $this->openReader('zip://'.$zipPath.'#'.$name);
                $time = null;

                try {
                    $moved = @$reader->read();
                    while ($moved) {
                        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                            $moved = @$reader->read();

                            continue;
                        }

                        $table = $reader->localName;
                        if ($table === 'Appointment_Request') {
                            $appointmentRequests++;
                        }

                        if ($time === null && ($table === 'Plan' || $table === 'Input_Reference')) {
                            $time = $this->timeInside($reader, $table === 'Plan' ? 'output_datetime' : 'datetime');
                            $moved = @$reader->read();

                            continue;
                        }

                        // Past this table and on to the next, without visiting its rows.
                        $moved = @$reader->next();
                    }
                } finally {
                    $reader->close();
                }

                if ($time !== null) {
                    $first = $first === null || $time->lt($first) ? $time : $first;
                    $last = $last === null || $time->gt($last) ? $time : $last;
                }

                $onProgress?->__invoke($messages, $total);
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

    /** The time in the named child of the table the reader is on, or null once the table ends without one. */
    private function timeInside(XMLReader $reader, string $childName): ?CarbonImmutable
    {
        $tableDepth = $reader->depth;
        if ($reader->isEmptyElement) {
            return null;
        }

        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $tableDepth) {
                return null;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $tableDepth + 1 && $reader->localName === $childName) {
                return $this->parseTime($reader->readString());
            }
        }

        return null;
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

    /** PSO writes exported times in UTC; a time without an offset is UTC, never the app or dataset time zone. */
    private function parseTime(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
