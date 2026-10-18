<?php

namespace App\Support\Tools;

use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Tools\TravelTuning\SourceRuns;

/**
 * The runs another run was built from (ToolRun::sourceRuns(), Travel tuning), for the Inputs panel: each row's label (decrypted), a
 * link to the source run while it exists, when it was made, and the SYS export named for its polygons. Runs and
 * exports that have gone since are named as gone; the run's own result does not depend on them.
 */
class SourceRunList
{
    /** @return list<array{label: string, url: ?string, made: ?string, polygons: ?string}> */
    public static function for(ToolRun $run): array
    {
        $rows = $run->sourceRuns();
        if ($rows === []) {
            return [];
        }

        $scope = $run->scope;
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');
        $runIds = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['run_id'] ?? ''), $rows)));
        $sysIds = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['sys_file_id'] ?? ''), $rows)));
        $sources = ToolRun::query()->withoutResult()->whereKey($runIds)
            ->where('scope_type', $run->scope_type)->where('scope_id', (string) $run->scope_id)
            ->get()->keyBy(static fn (ToolRun $source): string => (string) $source->id);
        $files = ExportFile::query()->whereKey($sysIds)->get()->keyBy(static fn (ExportFile $file): string => (string) $file->id);

        $list = [];
        foreach ($rows as $index => $row) {
            $source = $sources->get((string) ($row['run_id'] ?? ''));
            $sysId = (string) ($row['sys_file_id'] ?? '');
            $list[] = [
                'label' => filled($row['label'] ?? null) ? (string) $row['label'] : SourceRuns::defaultLabel($index),
                'url' => $source !== null && $scope !== null ? ToolRunPage::urlFor($scope, $source) : null,
                'made' => $source?->created_at?->copy()->setTimezone($timeZone)->format('j M Y H:i'),
                'polygons' => $sysId === '' ? null : ($files->get($sysId)?->original_filename ?? 'SYS export deleted'),
            ];
        }

        return $list;
    }
}
