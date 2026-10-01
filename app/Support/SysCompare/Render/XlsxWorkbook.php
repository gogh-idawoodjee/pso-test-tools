<?php

namespace App\Support\SysCompare\Render;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\ComparisonRow;
use App\Support\SysCompare\ComparisonTab;
use App\Support\SysCompare\EnvironmentVersion;
use App\Support\SysCompare\VersionReport;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel workbook: one sheet per area, as in the reference workbook.
 *
 * Values only, no formulas, so the numbers show in previewers (Quick Look,
 * email, SharePoint) and nothing from an export can be interpreted as a formula:
 * every text cell is written as an explicit string cell.
 */
class XlsxWorkbook
{
    public const string FILE_NAME = 'PSO_environment_comparison.xlsx';

    private const string FONT = 'Arial';

    private const string HEADER_FILL = '1F3864';

    private const string AMBER = 'FFE699';

    private const string DIFF_STATUS_FILL = 'F4B084';

    private const int MAX_COLUMN_WIDTH = 60;

    private const int EXTRA_COLUMN_WIDTH = 90;

    /** @var array<string, Style> */
    private array $styles = [];

    public function write(ComparisonResult $result, string $path): void
    {
        $options = new Options;

        $summary = $this->summaryRows($result);
        foreach ($summary['merges'] as $rowNumber) {
            $options->mergeCells(0, $rowNumber, 5, $rowNumber);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Summary');
        $sheet->setSheetView((new SheetView)->setShowGridLines(false));
        $sheet->setColumnWidth(40, 1);
        $sheet->setColumnWidth(18, 2, 3, 4, 5);
        $sheet->setColumnWidth(70, 6);
        $writer->addRows($summary['rows']);

        $this->versionsSheet($writer, $result);

        foreach ($result->tabs as $tab) {
            $this->matrixSheet($writer, $tab, $result);
        }

        $this->tableCountsSheet($writer, $result);
        $this->versionHistorySheet($writer, $result);

        $writer->close();
    }

    /**
     * @return array{rows: list<Row>, merges: list<int>} merges are the 1-based rows to merge across A:F
     */
    private function summaryRows(ComparisonResult $result): array
    {
        $rows = [];
        $merges = [];
        $names = $result->environmentNames();
        $baseline = $result->baselineName();

        $add = function (array $cells, bool $merge = false) use (&$rows, &$merges): void {
            $rows[] = new Row($cells);

            if ($merge) {
                $merges[] = count($rows);
            }
        };
        $blank = static fn (): array => [];

        $add([$this->text('PSO system data comparison - '.implode(' / ', $names), 'title')]);
        $add([$this->text('Generated '.$result->generatedAt->format('Y-m-d H:i').' UTC from DsSystemData exports, one per environment. Baseline: '.$baseline.'.', 'note')], true);
        $add($blank());

        $banner = $this->versionBanner($result->versions);
        $add([$this->text($banner[0], $banner[1])], true);
        $add($blank());

        $add([$this->text('Environments', 'subheader'), $this->text('File', 'subheader'), $this->text('', 'subheader'), $this->text('Size', 'subheader'), $this->text('', 'subheader'), $this->text('', 'subheader')]);

        foreach ($result->environments as $environment) {
            $add([
                $this->text($environment->name.($environment->name === $baseline ? ' (baseline)' : ''), 'normal'),
                $this->text($environment->fileName, 'normal'),
                $this->text('', 'normal'),
                $this->text(number_format($environment->sizeBytes / 1048576, 1).' MB', 'normal'),
            ]);
        }

        $add($blank());
        $add([$this->text('Scope', 'subheader'), ...array_map(fn (): Cell => $this->text('', 'subheader'), range(1, 5))]);

        foreach ($this->scopeLines($result) as $line) {
            $add([$this->text($line, 'wrap')], true);
        }

        $add($blank());

        $others = array_values(array_filter($names, static fn (string $name): bool => $name !== $baseline));
        $add([
            $this->text('Rows that differ from '.$baseline, 'subheader'),
            $this->text('Rows compared', 'subheaderCentered'),
            ...array_map(fn (string $name): Cell => $this->text($name, 'subheaderCentered'), $others),
            $this->text('Not identical across all', 'subheaderCentered'),
        ]);

        foreach ($result->tally as $tally) {
            $add([
                $this->text($tally->area, 'normal'),
                $this->number($tally->rowsCompared),
                ...array_map(fn (string $name): Cell => $this->number($tally->differencesByEnvironment[$name]), $others),
                $this->number($tally->notIdenticalAcrossAll),
            ]);
        }

        $totalRows = $result->totalRowsCompared();
        $add([
            $this->text('Total', 'bold'),
            $this->number($totalRows, 'bold'),
            ...array_map(fn (string $name): Cell => $this->number($result->totalDifferencesFor($name), 'bold'), $others),
            $this->number($result->totalNotIdentical(), 'bold'),
        ]);
        $add([
            $this->text('Share of rows differing from '.$baseline, 'normal'),
            $this->text('', 'normal'),
            ...array_map(
                fn (string $name): Cell => $this->text(($totalRows > 0 ? (int) round(100 * $result->totalDifferencesFor($name) / $totalRows) : 0).'%', 'centered'),
                $others,
            ),
        ]);

        $add($blank());
        $add([$this->text('Tabs', 'subheader'), ...array_map(fn (): Cell => $this->text('', 'subheader'), range(1, 5))]);

        foreach ($this->tabDescriptions($result) as $name => $description) {
            $add([$this->text($name, 'normal'), $this->text($description, 'normal')]);
        }

        return ['rows' => $rows, 'merges' => $merges];
    }

    /**
     * @return list<string>
     */
    private function scopeLines(ComparisonResult $result): array
    {
        $lines = [
            'Compared: profile parameters, exception types, groups and group permissions, organisation permissions, org-default list layouts, travel-time setup, profiles, terminology, exception type data, organisation record.',
            'Not compared: Users and all per-user tables. They hold data tied to individual user accounts (saved filters, screen settings, list layouts, and which groups, permissions and parameters each user has), and the set of users differs between environments, so comparing them would mostly show noise. Group membership (who is in which group) is therefore not compared, only what each group is allowed to do.',
            'List and polygon IDs are GUIDs that differ per environment, so lists are matched on content and travel polygons are summarised by count. Group names that differ only by case are treated as one group, and the spelling difference is flagged.',
            'Group permission rows include explicit denies (allow = false), so a row count is not a count of permissions granted. Permissions show allow / allow_edit as T / F.',
            $result->apiKeyValuesDiffer
                ? 'Routing API key values are masked as [API key set]. The key VALUES differ between environments.'
                : 'Routing API key values are masked as [API key set].',
            'Comparison is case- and whitespace-sensitive. A leading or trailing space is shown with the visible marker '.Cells::SPACE_MARK.'. Amber cell = differs from '.$result->baselineName().'; DIFF = not identical across all environments.',
        ];

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function tabDescriptions(ComparisonResult $result): array
    {
        $descriptions = [
            'Versions' => 'Current PSO version, last upgrade and last patch per environment, with a red or green banner if they are not all on the same version.',
            'Parameters' => 'Profile parameter values for every profile (API key masked), with a plain-English definition (marked [Inferred] where it rests only on the parameter name).',
            'Exception Types' => 'Active, attention and activation per exception type and profile.',
            'Group Permissions' => 'Every permission row per group, by environment.',
            'Groups' => 'Group presence, parent, description and permission row counts.',
            'Org Permissions' => 'Organisation-level permissions.',
            'Lists' => 'Org-default list column layouts, position by position.',
            'Travel' => 'Travel calculation settings and the travel-time profile and polygon inventory.',
            'Profiles & Other' => 'Profiles, terminology, exception type data and the organisation record.',
            'Table Counts' => 'Row counts for every table, with a scope note for each.',
            'Version History' => 'Every System_Version record per environment, oldest first.',
        ];

        $present = array_map(static fn (ComparisonTab $tab): string => $tab->title, $result->tabs);

        return array_filter($descriptions, static fn (string $name): bool => in_array($name, [...$present, 'Versions', 'Table Counts', 'Version History'], true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{0: string, 1: string} text and style key
     */
    private function versionBanner(VersionReport $versions): array
    {
        return match ($versions->bannerState()) {
            VersionReport::BANNER_MISMATCH => [
                'NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION. Highest: '.$versions->highestVersion.'. Behind: '
                .implode('; ', array_map(static fn (EnvironmentVersion $version): string => $version->name.' is on '.$version->current, $versions->behind()))
                .'. '.$versions->kindText,
                'bannerBad',
            ],
            VersionReport::BANNER_MATCH => ['All '.$versions->knownCount.' environments are on the same PSO version: '.$versions->highestVersion, 'bannerOk'],
            default => [
                'PSO version could not be compared: System_Version data is missing in '.($versions->missingNames === [] ? 'one or more environments' : implode(', ', $versions->missingNames)).'.',
                'bannerWarn',
            ],
        };
    }

    private function versionsSheet(Writer $writer, ComparisonResult $result): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Versions');
        $sheet->setSheetView((new SheetView)->setShowGridLines(false));
        $sheet->setColumnWidth(16, 1);
        $sheet->setColumnWidth(16, 2);
        $sheet->setColumnWidth(10, 3);
        $sheet->setColumnWidth(11, 4);
        $sheet->setColumnWidth(19, 5);
        $sheet->setColumnWidth(14, 6, 7);
        $sheet->setColumnWidth(17, 8);
        $sheet->setColumnWidth(19, 9);
        $sheet->setColumnWidth(11, 10);
        $sheet->setColumnWidth(19, 11);

        $banner = $this->versionBanner($result->versions);

        $writer->addRow(new Row([$this->text('PSO version by environment', 'title')]));
        $writer->addRow(new Row([$this->text($banner[0], $banner[1])]));
        $writer->addRow(new Row([]));
        $writer->addRow($this->headerRow(['Environment', 'Current version', 'Release', 'Status', 'Last upgrade (UTC)', 'Upgraded from', 'Upgraded to', 'Last patch version', 'Last patch (UTC)', 'Days since upgrade', 'Created (UTC)']));

        foreach ($result->versions->environments as $version) {
            $statusStyle = match ($version->status) {
                EnvironmentVersion::BEHIND => 'versionBad',
                EnvironmentVersion::LATEST => 'versionOk',
                default => 'normal',
            };

            $writer->addRow(new Row([
                $this->text($version->name, 'normal'),
                $this->text($version->current, $statusStyle === 'normal' ? 'normal' : $statusStyle),
                $this->text($version->release, 'normal'),
                $this->text($version->status, $statusStyle),
                $this->text($version->upgradedAt?->format('Y-m-d H:i') ?? '', 'normal'),
                $this->text($version->upgradedFrom, 'normal'),
                $this->text($version->upgradedTo, 'normal'),
                $this->text($version->patchVersion, 'normal'),
                $this->text($version->patchedAt?->format('Y-m-d H:i') ?? '', 'normal'),
                $version->daysSinceUpgrade !== null ? $this->number($version->daysSinceUpgrade) : $this->text('', 'normal'),
                $this->text($version->createdAt?->format('Y-m-d H:i') ?? '', 'normal'),
            ]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow(new Row([$this->text('Taken from the System_Version table; all times are UTC. Current version = highest version recorded (compared numerically, so 6.13.0.9 is older than 6.13.0.67). Last upgrade = most recent "Upgrade from" record. Last patch = most recent "Update" record.', 'note')]));
    }

    private function matrixSheet(Writer $writer, ComparisonTab $tab, ComparisonResult $result): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($tab->title);

        $keyCount = count($tab->keyHeaders);
        $environmentCount = count($result->environments);
        $baselineIndex = $result->baselineIndex;

        $headers = [
            ...$tab->keyHeaders,
            ...array_map(static fn (string $name, int $index): string => $name.($index === $baselineIndex ? ' (baseline)' : ''), $result->environmentNames(), array_keys($result->environmentNames())),
            'Status',
            ...$tab->extraHeaders,
        ];

        $columnCount = count($headers);
        $widths = array_map(static fn (string $header): int => max(10, strlen($header) + 2), $headers);

        $sheet->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn($this->columnLetter($keyCount + 1)));
        $sheet->setAutoFilter(new AutoFilter(0, 1, $columnCount - 1, max(2, $tab->rowCount() + 1)));

        $writer->addRow($this->headerRow($headers));

        foreach ($tab->rows() as $row) {
            $cells = [];

            foreach ($row->keyValues as $columnIndex => $value) {
                $cells[] = $this->text($value, 'wrap');
                $widths[$columnIndex] = max($widths[$columnIndex], strlen($value) + 2);
            }

            foreach ($row->cellValues as $index => $value) {
                $cells[] = $this->text($value, $this->environmentCellStyle($value, $row, $index, $baselineIndex));
                $widths[$keyCount + $index] = max($widths[$keyCount + $index], strlen($value) + 2);
            }

            $cells[] = $this->text($row->status, $row->isDifferent() ? 'statusDiff' : 'bold');

            foreach ($tab->extraHeaders as $header) {
                $value = $row->extra[$header] ?? '';
                $cells[] = $this->text($value, str_starts_with($value, '[Inferred]') || $value === '' ? 'muted' : 'wrap');
            }

            $writer->addRow(new Row($cells));
        }

        foreach ($widths as $columnIndex => $width) {
            $sheet->setColumnWidth(min($width, self::MAX_COLUMN_WIDTH), $columnIndex + 1);
        }

        foreach ($tab->extraHeaders as $extraIndex => $header) {
            $sheet->setColumnWidth(self::EXTRA_COLUMN_WIDTH, $keyCount + $environmentCount + 2 + $extraIndex);
        }
    }

    private function environmentCellStyle(string $value, ComparisonRow $row, int $index, int $baselineIndex): string
    {
        $muted = in_array($value, [Cells::ABSENT, Cells::NULL_TEXT, Cells::NONE], true);
        $differs = $row->differsFromBaseline($index, $baselineIndex);

        return match (true) {
            $differs && $muted => 'amberMuted',
            $differs => 'amber',
            $muted => 'muted',
            default => 'wrap',
        };
    }

    private function tableCountsSheet(Writer $writer, ComparisonResult $result): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Table Counts');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('B'));
        $sheet->setColumnWidth(32, 1);
        $sheet->setColumnWidth(12, ...range(2, count($result->environments) + 1));
        $sheet->setColumnWidth(70, count($result->environments) + 2);

        $writer->addRow($this->headerRow(['Table', ...$result->environmentNames(), 'Scope']));

        foreach ($result->tableCounts as $row) {
            $writer->addRow(new Row([
                $this->text($row->table, 'normal'),
                ...array_map(fn (int $count): Cell => $this->number($count, 'count'), $row->counts),
                $this->text($row->scope, 'normal'),
            ]));
        }
    }

    private function versionHistorySheet(Writer $writer, ComparisonResult $result): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Version History');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $sheet->setColumnWidth(14, 1);
        $sheet->setColumnWidth(20, 2);
        $sheet->setColumnWidth(14, 3);
        $sheet->setColumnWidth(26, 4);
        $sheet->setColumnWidth(16, 5);

        $total = 0;

        foreach ($result->versions->environments as $version) {
            $total += count($version->history);
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, 4, max(2, $total + 1)));
        $writer->addRow($this->headerRow(['Environment', 'When (UTC)', 'Version', 'Type', 'User']));

        foreach ($result->versions->environments as $version) {
            foreach ($version->history as $record) {
                $writer->addRow(new Row([
                    $this->text($version->name, 'normal'),
                    $this->text($record->stamp?->format('Y-m-d H:i:s') ?? '', 'normal'),
                    $this->text($record->version, 'normal'),
                    $this->text($record->type, 'normal'),
                    $this->text($record->user, 'normal'),
                ]));
            }
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function headerRow(array $headers): Row
    {
        return new Row(array_map(fn (string $header): Cell => $this->text($header, 'header'), $headers));
    }

    /**
     * An explicit string cell: never a formula, whatever the text starts with.
     */
    private function text(string $value, string $style): StringCell
    {
        return new StringCell($value, $this->style($style));
    }

    private function number(int $value, string $style = 'centered'): NumericCell
    {
        return new NumericCell($value, $this->style($style));
    }

    private function columnLetter(int $oneBasedIndex): string
    {
        $letter = '';

        while ($oneBasedIndex > 0) {
            $remainder = ($oneBasedIndex - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $oneBasedIndex = intdiv($oneBasedIndex - 1, 26);
        }

        return $letter;
    }

    private function style(string $key): Style
    {
        return $this->styles[$key] ??= $this->buildStyle($key);
    }

    private function buildStyle(string $key): Style
    {
        $style = (new Style)->setFontName(self::FONT)->setFontSize(10)->setCellVerticalAlignment('top');
        $thin = new Border(new BorderPart(Border::BOTTOM, 'BFBFBF', Border::WIDTH_THIN), new BorderPart(Border::TOP, 'BFBFBF', Border::WIDTH_THIN), new BorderPart(Border::LEFT, 'BFBFBF', Border::WIDTH_THIN), new BorderPart(Border::RIGHT, 'BFBFBF', Border::WIDTH_THIN));

        return match ($key) {
            'title' => $style->setFontSize(14)->setFontBold(),
            'note' => $style->setFontSize(9)->setFontItalic()->setFontColor('595959')->setShouldWrapText(),
            'bold' => $style->setFontBold(),
            'centered' => $style->setCellAlignment('center'),
            'count' => $style->setFormat('#,##0'),
            'wrap' => $style->setShouldWrapText()->setBorder($thin),
            'normal' => $style->setBorder($thin),
            'header' => $style->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor(self::HEADER_FILL)->setShouldWrapText()->setBorder($thin),
            'subheader' => $style->setFontBold()->setBackgroundColor('D9E1F2'),
            'subheaderCentered' => $style->setFontBold()->setBackgroundColor('D9E1F2')->setCellAlignment('center'),
            'amber' => $style->setBackgroundColor(self::AMBER)->setShouldWrapText()->setBorder($thin),
            'muted' => $style->setFontItalic()->setFontColor('7F7F7F')->setShouldWrapText()->setBorder($thin),
            'amberMuted' => $style->setFontItalic()->setFontColor('7F7F7F')->setBackgroundColor(self::AMBER)->setShouldWrapText()->setBorder($thin),
            'statusDiff' => $style->setFontBold()->setBackgroundColor(self::DIFF_STATUS_FILL)->setBorder($thin),
            'versionOk' => $style->setFontBold()->setFontColor('006100')->setBackgroundColor('C6EFCE')->setBorder($thin),
            'versionBad' => $style->setFontBold()->setFontColor('9C0006')->setBackgroundColor('F4B6B6')->setBorder($thin),
            'bannerOk' => $style->setFontSize(14)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('2E7D32')->setShouldWrapText(),
            'bannerBad' => $style->setFontSize(14)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('C00000')->setShouldWrapText(),
            'bannerWarn' => $style->setFontSize(12)->setFontBold()->setBackgroundColor(self::AMBER)->setShouldWrapText(),
            default => $style,
        };
    }
}
