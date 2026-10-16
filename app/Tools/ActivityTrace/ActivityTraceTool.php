<?php

namespace App\Tools\ActivityTrace;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use App\Tools\UnfiledLabel;
use Filament\Forms\Components\TextInput;
use RuntimeException;

/**
 * Runs pso-tools/activity-trace/trace_activity.py against the day's audit messages (and, when given, a
 * LOAD and an IFS event log) for one activity or work task, and keeps the page and files it wrote.
 */
class ActivityTraceTool implements Tool
{
    /** The script's exit code for "that activity is not in the audits": its stderr line is the message. */
    private const EXIT_NOT_FOUND = 2;

    public static function id(): ToolId
    {
        return ToolId::AppointmentTimeline;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Audits];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Load, ExportKind::EventLog];
    }

    public function optionFields(): array
    {
        return [
            TextInput::make('options.activity_id')
                ->label('Activity id')
                ->helperText('The PSO activity id. Give this or the work task id.')
                ->requiredWithout('options.work_task_id')
                ->maxLength(100),
            TextInput::make('options.work_task_id')
                ->label('Work task id')
                ->helperText('The IFS work task id, when you do not know the activity id.')
                ->maxLength(100),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['audits']);
        // With no dataset zone the script gets no --tz (its page then says times are UTC), and an IFS zone only when one was set.
        $ifsTimeZone = match (true) {
            ! $context->scope instanceof Dataset => null,
            $context->timeZoneIsDefault => $context->scope->settings->ifsTimeZone ?? null,
            default => $context->scope->ifsTimeZone(),
        };

        $argv = ActivityTraceCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZoneIsDefault ? null : $context->timeZone, $ifsTimeZone,
            $context->scope instanceof Dataset ? $context->scope->name : UnfiledLabel::for($context, ExportKind::Audits),
        );
        $timeout = (int) config('tools.activity_trace.timeout_seconds', 840);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The trace', self::EXIT_NOT_FOUND);

        $read = static fn (string $name): string|false => @file_get_contents($workDir.'/'.$name);
        $html = $read(ActivityTraceCommand::OUT_HTML);
        $json = $read(ActivityTraceCommand::OUT_JSON);
        $booking = $read(ActivityTraceCommand::OUT_BOOKING_CSV);
        $history = $read(ActivityTraceCommand::OUT_HISTORY_CSV);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || $html === false || $html === '' || $booking === false || $history === false) {
            throw new RuntimeException('The trace finished but wrote no results.');
        }

        $counts = ['red' => 0, 'amber' => 0, 'green' => 0];
        foreach ($data['findings'] ?? [] as $finding) {
            $key = strtolower((string) ($finding['severity'] ?? ''));
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        $warnings = [];
        $line = null;
        foreach (preg_split('/\r?\n/', $script->stdout) as $out) {
            if (str_starts_with($out, 'warning:')) {
                $warnings[] = trim(substr($out, strlen('warning:')));
            } elseif (str_starts_with($out, 'summary:')) {
                $line = trim(substr($out, strlen('summary:')));
            }
        }

        // The script resolves the activity from a work task id, so its own answer comes first.
        $pick = static fn (mixed ...$values): ?string => collect($values)
            ->map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '')
            ->first(static fn (string $value): bool => $value !== '');
        $activityId = $pick($data['meta']['activity_id'] ?? null, $options['activity_id'] ?? null);
        $workTaskId = $pick($options['work_task_id'] ?? null, $data['meta']['work_task_id'] ?? null);
        $subject = $activityId !== null ? "Activity {$activityId}" : ($workTaskId !== null ? "Work task {$workTaskId}" : null);
        if ($subject !== null) {
            $line = $line !== null && $line !== '' ? "{$subject}: {$line}" : $subject;
        }

        return new ToolResult(
            result: ['html' => $html, 'data' => $data, 'booking_csv' => $booking, 'history_csv' => $history],
            warnings: $warnings,
            summary: ['line' => $line, 'counts' => $counts, 'activity_id' => $activityId, 'work_task_id' => $workTaskId],
        );
    }
}
