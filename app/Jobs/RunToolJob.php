<?php

namespace App\Jobs;

use App\Enums\ExportKind;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\Contracts\ToolFailedWithResult;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use DateTimeZone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs one tool against the export files named in the run's inputs. Inputs are
 * streamed to a scratch folder first, so a purge cannot pull a file out from
 * under a running tool; the folder is removed whatever happens.
 */
class RunToolJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $toolRunId) {}

    public function handle(ExportStorage $storage, ToolRegistry $registry): void
    {
        $run = ToolRun::withoutGlobalScopes()->find($this->toolRunId);
        if ($run === null || $run->status !== ToolRunStatus::Queued) {
            return;
        }

        $run->forceFill(['status' => ToolRunStatus::Running, 'started_at' => now()])->save();
        $scratch = null;
        $meta = [];

        try {
            $scope = $this->scopeOf($run);
            $timeZoneIsDefault = $scope instanceof Dataset && ! $scope->hasTimeZone();
            if ($timeZoneIsDefault) {
                // Recorded at start, so the run page says times are UTC even when the run fails.
                $meta = ['time_zone_default' => true];
                $run->forceFill(['summary' => ['meta' => $meta]])->save();
            }
            $tool = $registry->for($run->tool);
            $files = $this->resolveInputs($run, $scope, $tool->requiredKinds(), $tool->optionalKinds());

            $scratch = $storage->scratchDirectoryFor($run->id);
            $inputPaths = [];
            foreach ($files as $kind => $file) {
                $inputPaths[$kind] = $storage->readToScratch($file, $scratch);
            }

            $settings = $scope instanceof Dataset ? $scope->settings : null;
            $timeZone = $scope instanceof Dataset ? $scope->timeZone() : self::optionTimeZone($run);

            $output = $tool->run(new ToolContext($run, $scope, $settings, $timeZone, $timeZoneIsDefault), $inputPaths, $run->inputs['options'] ?? []);
            $summary = $output->summary;
            if ($meta !== []) {
                $summary['meta'] = [...(is_array($summary['meta'] ?? null) ? $summary['meta'] : []), ...$meta];
            }

            $run->forceFill([
                'result' => $output->result,
                'warnings' => $output->warnings,
                'summary' => $summary ?: null,
                'progress' => null,
                'run_date' => $run->run_date ?? $this->exportDateOf($files, $timeZone),
                'status' => ToolRunStatus::Succeeded,
                'error_message' => null,
                'finished_at' => now(),
            ])->save();
        } catch (ToolFailedWithResult $exception) {
            $summary = $exception->partial->summary;
            if ($meta !== []) {
                $summary['meta'] = [...(is_array($summary['meta'] ?? null) ? $summary['meta'] : []), ...$meta];
            }
            $run->forceFill([
                'result' => $exception->partial->result ?: null,
                'warnings' => $exception->partial->warnings,
                'summary' => $summary ?: null,
                'run_date' => $run->run_date ?? $this->exportDateOf($files, $timeZone),
            ])->save();

            $this->markFailed($run, $exception->getMessage());
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                Log::warning('Tool run failed', ['tool_run_id' => $run->id, 'error' => $exception->getMessage()]);
            }

            $this->markFailed($run, $exception->getMessage());
        } finally {
            if ($scratch !== null) {
                $storage->removeScratch($scratch);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = ToolRun::withoutGlobalScopes()->find($this->toolRunId);
        if ($run !== null && in_array($run->status, [ToolRunStatus::Queued, ToolRunStatus::Running], true)) {
            $this->markFailed($run, $exception->getMessage());
        }
    }

    private function markFailed(ToolRun $run, string $message): void
    {
        $run->forceFill(['status' => ToolRunStatus::Failed, 'progress' => null, 'error_message' => $message, 'finished_at' => now()])->save();
    }

    private function scopeOf(ToolRun $run): Model
    {
        $scope = match ($run->scope_type) {
            (new Dataset)->getMorphClass() => Dataset::query()->withoutGlobalScopes()->find($run->scope_id),
            (new Environment)->getMorphClass() => Environment::query()->withoutGlobalScopes()->find($run->scope_id),
            (new User)->getMorphClass() => User::query()->find($run->scope_id),
            default => null,
        };

        return $scope ?? throw new RuntimeException('The dataset or environment this run belongs to no longer exists.');
    }

    /** An Unfiled run's own zone (the wizard's Time zone option); missing, blank or unknown is the app zone. */
    private static function optionTimeZone(ToolRun $run): string
    {
        $zone = $run->inputs['options']['time_zone'] ?? null;

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : (string) config('app.timezone', 'UTC');
    }

    /**
     * @param  list<ExportKind>  $required
     * @param  list<ExportKind>  $optional
     * @return array<string, ExportFile> kind value => file
     */
    private function resolveInputs(ToolRun $run, Model $scope, array $required, array $optional): array
    {
        $named = $run->inputs['files'] ?? [];
        $files = [];

        foreach ($required as $kind) {
            if (blank($named[$kind->value] ?? null)) {
                throw new RuntimeException("This run needs a {$kind->getLabel()} export.");
            }
        }

        foreach ([...$required, ...$optional] as $kind) {
            $fileId = $named[$kind->value] ?? null;
            if (blank($fileId)) {
                continue;
            }

            $file = ExportFile::withoutGlobalScopes()->find($fileId);

            if ($file === null || ! $file->isUsable()) {
                throw new RuntimeException("The {$kind->getLabel()} export used by this run is no longer available (exports are kept for ".(int) config('exports.ttl_hours', 12).' hours). Upload it again.');
            }

            if ($file->kind !== $kind || ! $this->belongsTo($file, $scope)) {
                throw new RuntimeException("The file chosen as the {$kind->getLabel()} export does not belong to this run's dataset, environment or unfiled files.");
            }

            $files[$kind->value] = $file;
        }

        return $files;
    }

    /** A file may sit on the run's own scope (a user's id is read back as a string), or on the environment of a dataset run (a SYS). */
    private function belongsTo(ExportFile $file, Model $scope): bool
    {
        if ($file->scope_type === $scope->getMorphClass() && (string) $file->scope_id === (string) $scope->getKey()) {
            return true;
        }

        return $scope instanceof Dataset
            && $file->scope_type === (new Environment)->getMorphClass()
            && $file->scope_id === $scope->environment_id;
    }

    /** @param array<string, ExportFile> $files */
    private function exportDateOf(array $files, string $timeZone): ?string
    {
        $file = $files[ExportKind::Load->value] ?? (array_values($files)[0] ?? null);

        return $file?->exportDate($timeZone);
    }
}
