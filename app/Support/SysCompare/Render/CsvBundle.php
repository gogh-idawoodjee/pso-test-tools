<?php

namespace App\Support\SysCompare\Render;

use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\ComparisonTab;
use RuntimeException;
use ZipArchive;

/**
 * Every row of every area as CSV (not just the differing ones), zipped.
 */
class CsvBundle
{
    public const string DATE_FORMAT = 'Y-m-d H:i';

    /**
     * @return array<string, string> file name => CSV content
     */
    public function files(ComparisonResult $result): array
    {
        $files = [];

        foreach ($result->tabs as $tab) {
            if ($tab->rowCount() > 0) {
                $files[$tab->csvFileName] = $this->tabCsv($tab, $result);
            }
        }

        $files['09_TableCounts.csv'] = $this->tableCountsCsv($result);
        $files['Summary_Tally.csv'] = $this->tallyCsv($result);
        $files['10_Versions.csv'] = $this->versionsCsv($result);

        $history = $this->versionHistoryCsv($result);

        if ($history !== null) {
            $files['11_VersionHistory.csv'] = $history;
        }

        $template = DefinitionsTemplate::csv($result);

        if ($template !== null) {
            $files[DefinitionsTemplate::FILE_NAME] = $template;
        }

        ksort($files);

        return $files;
    }

    /**
     * Writes the zip to $path.
     */
    public function write(ComparisonResult $result, string $path): void
    {
        $zip = app(ZipArchive::class);

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The CSV bundle could not be created.');
        }

        foreach ($this->files($result) as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    private function tabCsv(ComparisonTab $tab, ComparisonResult $result): string
    {
        $headers = [...$tab->keyHeaders, ...$result->environmentNames(), 'Status', ...$tab->extraHeaders];
        $rows = [];

        foreach ($tab->rows() as $row) {
            $rows[] = [
                ...$row->keyValues,
                ...$row->cellValues,
                $row->status,
                ...array_map(static fn (string $header): string => $row->extra[$header] ?? '', $tab->extraHeaders),
            ];
        }

        return CsvWriter::document($headers, $rows);
    }

    private function tableCountsCsv(ComparisonResult $result): string
    {
        $rows = array_map(
            static fn ($row): array => [$row->table, ...$row->counts, $row->scope],
            $result->tableCounts,
        );

        return CsvWriter::document(['Table', ...$result->environmentNames(), 'Scope'], $rows);
    }

    private function tallyCsv(ComparisonResult $result): string
    {
        $baseline = $result->baselineName();
        $others = array_values(array_filter($result->environmentNames(), static fn (string $name): bool => $name !== $baseline));

        $headers = ['Area', 'RowsCompared', ...array_map(static fn (string $name): string => "DiffFrom_{$baseline}_{$name}", $others), 'NotIdenticalAcrossAll'];
        $rows = [];

        foreach ($result->tally as $tally) {
            $rows[] = [
                $tally->area,
                $tally->rowsCompared,
                ...array_map(static fn (string $name): int => $tally->differencesByEnvironment[$name], $others),
                $tally->notIdenticalAcrossAll,
            ];
        }

        return CsvWriter::document($headers, $rows);
    }

    private function versionsCsv(ComparisonResult $result): string
    {
        $rows = [];

        foreach ($result->versions->environments as $version) {
            $rows[] = [
                $version->name,
                $version->current,
                $version->release,
                $version->status,
                $version->upgradedAt?->format(self::DATE_FORMAT) ?? '',
                $version->upgradedFrom,
                $version->upgradedTo,
                $version->patchVersion,
                $version->patchedAt?->format(self::DATE_FORMAT) ?? '',
                $version->daysSinceUpgrade ?? '',
                $version->createdAt?->format(self::DATE_FORMAT) ?? '',
            ];
        }

        return CsvWriter::document(
            ['Environment', 'CurrentVersion', 'Release', 'Status', 'LastUpgradeUtc', 'UpgradedFrom', 'UpgradedTo', 'LastPatchVersion', 'LastPatchUtc', 'DaysSinceUpgrade', 'CreatedUtc'],
            $rows,
        );
    }

    private function versionHistoryCsv(ComparisonResult $result): ?string
    {
        $rows = [];

        foreach ($result->versions->environments as $version) {
            foreach ($version->history as $record) {
                $rows[] = [$version->name, $record->stamp?->format(self::DATE_FORMAT) ?? '', $record->version, $record->type, $record->user];
            }
        }

        return $rows === [] ? null : CsvWriter::document(['Environment', 'StampUtc', 'Version', 'Type', 'User'], $rows);
    }
}
