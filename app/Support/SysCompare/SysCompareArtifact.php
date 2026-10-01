<?php

namespace App\Support\SysCompare;

use App\Support\SysCompare\Render\DefinitionsTemplate;
use App\Support\SysCompare\Render\HtmlReport;
use App\Support\SysCompare\Render\XlsxWorkbook;

/**
 * The downloadable outputs of a run.
 */
enum SysCompareArtifact: string
{
    case Report = 'report';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Template = 'template';

    public const string CSV_FILE_NAME = 'PSO_Compare_CSVs.zip';

    public function fileName(): string
    {
        return match ($this) {
            self::Report => HtmlReport::FILE_NAME,
            self::Csv => self::CSV_FILE_NAME,
            self::Xlsx => XlsxWorkbook::FILE_NAME,
            self::Template => DefinitionsTemplate::FILE_NAME,
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Report => 'text/html; charset=UTF-8',
            self::Csv => 'application/zip',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Template => 'text/csv; charset=UTF-8',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Report => 'HTML report',
            self::Csv => 'CSV bundle (.zip)',
            self::Xlsx => 'Excel workbook',
            self::Template => 'Missing definitions template',
        };
    }
}
