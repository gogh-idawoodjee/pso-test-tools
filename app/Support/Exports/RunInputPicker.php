<?php

namespace App\Support\Exports;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which export files a tool run can be started with: the scope's ready files per
 * kind (a dataset tool also sees its environment's SYS files), newest first, and
 * the set that belongs together (the newest LOAD's day) preselected.
 */
class RunInputPicker
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @return array<string, array<string, string>> kind value => [export file id => label]
     */
    public function optionsFor(Model $scope, ToolId $toolId): array
    {
        $options = [];

        foreach ($this->kindsOf($toolId) as $kind) {
            $options[$kind->value] = $this->candidates($scope, $kind)
                ->mapWithKeys(fn (ExportFile $file): array => [$file->id => $this->labelFor($file, $scope)])
                ->all();
        }

        return $options;
    }

    /**
     * Options with the raw-file purge countdown appended, for places that show one file set at a time.
     *
     * @return array<string, array<string, string>> kind value => [export file id => label]
     */
    public function optionsWithExpiryFor(Model $scope, ToolId $toolId): array
    {
        $options = [];

        foreach ($this->kindsOf($toolId) as $kind) {
            $options[$kind->value] = $this->candidates($scope, $kind)
                ->mapWithKeys(fn (ExportFile $file): array => [$file->id => $this->labelFor($file, $scope).', '.$this->purgeCountdown($file)])
                ->all();
        }

        return $options;
    }

    public function purgeCountdown(ExportFile $file): string
    {
        $minutes = (int) floor(now()->diffInMinutes($file->expires_at, false));

        return $minutes <= 0 ? 'Purging soon' : 'Purges in '.intdiv($minutes, 60).' h '.($minutes % 60).' m';
    }

    /**
     * @return array<string, string> kind value => export file id
     */
    public function defaultsFor(Model $scope, ToolId $toolId): array
    {
        $kinds = $this->kindsOf($toolId);
        $candidates = [];
        foreach ($kinds as $kind) {
            $candidates[$kind->value] = $this->candidates($scope, $kind);
        }

        $anchor = $candidates[ExportKind::Load->value] ?? collect();
        if ($anchor->isEmpty()) {
            $anchor = collect($candidates)->flatten(1)->reject(static fn (ExportFile $file): bool => $file->kind === ExportKind::Sys)
                ->sortByDesc('created_at')->values();
        }
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');
        $day = $anchor->first()?->exportDate($timeZone);

        $defaults = [];
        foreach ($candidates as $kindValue => $files) {
            $match = $day !== null ? $files->first(static fn (ExportFile $file): bool => $file->exportDate($timeZone) === $day) : null;

            if ($match === null && ($kindValue === ExportKind::Sys->value || $day === null)) {
                $match = $files->first();
            }

            if ($match !== null) {
                $defaults[$kindValue] = $match->id;
            }
        }

        return $defaults;
    }

    /** @return list<ExportKind> */
    public function kindsOf(ToolId $toolId): array
    {
        $tool = $this->registry->for($toolId);

        return [...$tool->requiredKinds(), ...$tool->optionalKinds()];
    }

    /** @return Collection<int, ExportFile> */
    private function candidates(Model $scope, ExportKind $kind): Collection
    {
        [$scopeType, $scopeId] = $scope instanceof Dataset && ! $kind->allows(Dataset::class)
            ? [(new Environment)->getMorphClass(), $scope->environment_id]
            : [$scope->getMorphClass(), (string) $scope->getKey()];

        return ExportFile::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('kind', $kind)
            ->where('status', ExportFileStatus::Ready)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function labelFor(ExportFile $file, Model $scope): string
    {
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');
        $summary = $file->summary ?? [];
        $detail = match (true) {
            isset($summary['allocation_count']) => number_format((int) $summary['allocation_count']).' allocations',
            isset($summary['activity_count']) => number_format((int) $summary['activity_count']).' activities',
            isset($summary['pso_version']) => 'version '.$summary['pso_version'],
            isset($summary['message_count']) => number_format((int) $summary['message_count']).' messages',
            isset($summary['event_count']) => number_format((int) $summary['event_count']).' events',
            default => null,
        };

        return trim($file->created_at->setTimezone($timeZone)->format('j M H:i').'  '.$file->original_filename.($detail ? "  ({$detail})" : ''));
    }
}
