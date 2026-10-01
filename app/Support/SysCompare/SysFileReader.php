<?php

namespace App\Support\SysCompare;

use App\Support\SysCompare\Exceptions\InvalidSysFile;
use XMLReader;

/**
 * Streams a DsSystemData export. Only the tables the comparison needs are
 * materialised; every other table (Users, the per-user tables, ...) is skipped
 * and merely counted, so its contents are never held in memory.
 *
 * Element names are matched on their local name, so the default namespace
 * does not matter. Values are kept exactly as stored (whitespace included):
 * an empty element is the empty string, a missing element is simply not set.
 */
class SysFileReader
{
    public const string ROOT_ELEMENT = 'DsSystemData';

    /**
     * @var list<string>
     */
    public const array COMPARED_TABLES = [
        'Profile', 'Profile_Parameter', 'Org_Schedule_Exception_Type', 'Org_Schedule_Exc_Type_Data',
        'Groups', 'Group_Permission', 'Organisation', 'Organisation_Permission', 'Organisation_List',
        'List', 'List_Entry', 'Entry', 'Terminology_Organisation',
        'Travel_Time_Profile', 'Travel_Time_Weighting', 'Travel_Time_Polygon', 'Polygon',
        'System_Version',
    ];

    public function read(string $path, ?string $displayName = null): SysFile
    {
        $fileName = $displayName ?? basename($path);
        $keptTables = array_flip(self::COMPARED_TABLES);

        $previousErrorSetting = libxml_use_internal_errors(true);
        $reader = app(XMLReader::class);

        try {
            if (! is_file($path) || ! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw InvalidSysFile::unreadable($fileName);
            }

            return $this->readDocument($reader, $fileName, (int) filesize($path), $keptTables);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }
    }

    /**
     * @param  array<string, int>  $keptTables
     */
    private function readDocument(XMLReader $reader, string $fileName, int $sizeBytes, array $keptTables): SysFile
    {
        $tables = [];
        $tableCounts = [];

        // Advance to the root element.
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                break;
            }
        }

        if ($reader->nodeType !== XMLReader::ELEMENT) {
            throw InvalidSysFile::unreadable($fileName);
        }

        if ($reader->localName !== self::ROOT_ELEMENT) {
            throw InvalidSysFile::notDsSystemData($fileName);
        }

        $rootIsEmpty = $reader->isEmptyElement;
        $moved = ! $rootIsEmpty && @$reader->read();

        while ($moved) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 0) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                $moved = @$reader->read();

                continue;
            }

            $tableName = $reader->localName;
            $tableCounts[$tableName] = ($tableCounts[$tableName] ?? 0) + 1;

            if (! isset($keptTables[$tableName])) {
                $moved = @$reader->next();

                continue;
            }

            $tables[$tableName][] = $this->readRow($reader);
            $moved = @$reader->next();
        }

        if (libxml_get_errors() !== []) {
            throw InvalidSysFile::unreadable($fileName);
        }

        return new SysFile($fileName, $sizeBytes, $tables, $tableCounts);
    }

    /**
     * @return array<string, string>
     */
    private function readRow(XMLReader $reader): array
    {
        $row = [];

        if ($reader->isEmptyElement) {
            return $row;
        }

        $rowDepth = $reader->depth;

        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $rowDepth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $rowDepth + 1) {
                $row[$reader->localName] = $reader->isEmptyElement ? '' : $reader->readString();
            }
        }

        return $row;
    }
}
