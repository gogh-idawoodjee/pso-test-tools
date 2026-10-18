<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\UnusableRunInputException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The Plan travels runs a Travel tuning report can be built from: the finished runs of one scope (a dataset, or a
 * user's Unfiled runs), newest first. Only a run checked with Google has the Google columns the report compares
 * against: its summary's `google` is set (a run without the check, or whose Google step failed, keeps it null).
 * finished() and rows() skip the global scope and name the scope themselves, so a job can use them. check() is not job-safe:
 * its SYS lookup goes through RunInputPicker::optionsForKind(), which needs the signed-in owner.
 */
class SourceRuns
{
    public const MAX_RUNS = 10;

    public const NO_POLYGONS = 'This SYS export has no Polygon table';

    public function __construct(private RunInputPicker $picker, private SysPolygons $polygons) {}

    /** @return Collection<int, ToolRun> without their results */
    public function finished(Model $scope): Collection
    {
        return ToolRun::query()->withoutGlobalScopes()->withoutResult()
            ->where('scope_type', $scope->getMorphClass())
            ->where('scope_id', (string) $scope->getKey())
            ->where('tool', ToolId::PlanTravels)
            ->where('status', ToolRunStatus::Succeeded)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public static function hasGoogle(ToolRun $run): bool
    {
        return is_array($run->summary['google'] ?? null);
    }

    /** @return list<array{id: string, range: string, made: string, legs: int, flagged: int, google: bool}> */
    public function rows(Model $scope): array
    {
        $timeZone = $scope instanceof Dataset ? $scope->timeZone() : (string) config('app.timezone', 'UTC');

        return $this->finished($scope)->map(static fn (ToolRun $run): array => [
            'id' => (string) $run->id,
            'range' => self::range($run),
            'made' => $run->created_at->copy()->setTimezone($timeZone)->format('j M Y H:i'),
            'legs' => (int) ($run->summary['leg_count'] ?? 0),
            'flagged' => (int) ($run->summary['review_count'] ?? 0),
            'google' => self::hasGoogle($run),
        ])->values()->all();
    }

    /** "5 Oct 2026 to 9 Oct 2026, made 13 Oct 2026 22:35, 3,369 legs, 10 flagged" */
    public static function optionLabel(array $row): string
    {
        return "{$row['range']}, made {$row['made']}, ".number_format($row['legs']).' legs, '.number_format($row['flagged']).' flagged'
            .($row['google'] ? '' : ' (Run plan travels again with Google on)');
    }

    /**
     * @param  list<array<string, mixed>>  $picked  rows in order: run_id, label, sys_file_id
     * @return list<array{run_id: string, label: string, sys_file_id: ?string}>
     *
     * @throws UnusableRunInputException
     */
    public function check(Model $scope, array $picked): array
    {
        $picked = array_values(array_filter($picked, 'is_array'));
        if (count($picked) < 2) {
            throw new UnusableRunInputException(0, 'Pick at least two plan travels runs: the baseline first, then each tuned run.');
        }
        if (count($picked) > self::MAX_RUNS) {
            throw new UnusableRunInputException(0, 'Pick at most '.self::MAX_RUNS.' plan travels runs for one report.');
        }

        $runs = $this->finished($scope)->keyBy(static fn (ToolRun $run): string => (string) $run->id);
        $sysOptions = $this->picker->optionsForKind($scope, ExportKind::Sys);
        $checked = [];
        $seen = [];

        foreach ($picked as $index => $row) {
            $number = $index + 1;
            $runId = trim((string) ($row['run_id'] ?? ''));
            if ($runId === '') {
                throw new UnusableRunInputException($number, "Row {$number}: choose a plan travels run.");
            }
            $run = $runs->get($runId);
            if ($run === null) {
                throw new UnusableRunInputException($number, "Row {$number}: that plan travels run is no longer available here. Choose another.");
            }
            if (! self::hasGoogle($run)) {
                throw new UnusableRunInputException($number, "Row {$number}: that plan travels run has no Google columns. Run plan travels again with Google on.");
            }
            if (isset($seen[$runId])) {
                throw new UnusableRunInputException($number, "Row {$number} is the same plan travels run as row {$seen[$runId]}. Pick each run once.");
            }
            $seen[$runId] = $number;

            $sysId = trim((string) ($row['sys_file_id'] ?? ''));
            if ($sysId !== '') {
                if (! array_key_exists($sysId, $sysOptions)) {
                    throw new UnusableRunInputException($number, "Row {$number}: the SYS export you chose is no longer available. Choose or upload another.");
                }
                $problem = $this->sysError(ExportFile::query()->withoutGlobalScopes()->findOrFail($sysId));
                if ($problem !== null) {
                    throw new UnusableRunInputException($number, "{$problem} (row {$number}). Choose another, or None.");
                }
            }

            $checked[] = ['run_id' => $runId, 'label' => self::label($row['label'] ?? null, $index), 'sys_file_id' => $sysId === '' ? null : $sysId];
        }

        return $checked;
    }

    /** Why a SYS export cannot give a run its polygons, or null when it can. */
    public function sysError(ExportFile $file): ?string
    {
        try {
            return $this->polygons->count($file) === 0 ? self::NO_POLYGONS : null;
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }

    /** What the user typed (one line, at most 60 characters), else Baseline, Run 1, Run 2 (the script's defaults). */
    public static function label(mixed $typed, int $index): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) $typed));

        return $label === '' ? self::defaultLabel($index) : mb_substr($label, 0, 60);
    }

    public static function defaultLabel(int $index): string
    {
        return $index === 0 ? 'Baseline' : "Run {$index}";
    }

    private static function range(ToolRun $run): string
    {
        $format = static fn (mixed $date): ?string => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? Carbon::parse($date)->format('j M Y') : null;
        $first = $format($run->summary['first_date'] ?? null);
        $last = $format($run->summary['last_date'] ?? null);

        return match (true) {
            $first === null => 'Dates not known',
            $last === null || $last === $first => $first,
            default => "{$first} to {$last}",
        };
    }
}
