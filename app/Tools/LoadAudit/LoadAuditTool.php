<?php

namespace App\Tools\LoadAudit;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\PsoMessageText;
use App\Support\Settings\AppSettings;
use App\Support\Tools\ToolRunFiles;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use App\Tools\UnfiledLabel;
use Filament\Forms\Components\TextInput;
use RuntimeException;

/**
 * Runs pso-tools/load-audit/audit_load.py (the reference implementation, never ported) against the run's
 * inputs, in the run's scratch folder, and keeps the page it rendered plus its findings.
 */
class LoadAuditTool implements Tool
{
    public static function id(): ToolId
    {
        return ToolId::LoadAudit;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Plan, ExportKind::Sys, ExportKind::Audits, ExportKind::PsoEvents, ExportKind::AppointmentHistory];
    }

    public function optionFields(): array
    {
        return [
            TextInput::make('options.ifs_release')
                ->label('IFS Cloud release')
                ->placeholder('e.g. 25.2.7')
                ->helperText('Not in any PSO file; used to match release-note fixes.')
                ->regex('/^\d+(\.\d+){1,3}$/')
                ->maxLength(16),
            TextInput::make('options.complete_grace_hours')
                ->label('Finished work kept for (hours)')
                ->helperText('Finished activities younger than this are expected in a LOAD. IFS Cloud keeps them 24 hours.')
                ->integer()
                ->minValue(0)
                ->maxValue(168)
                ->default(static fn (): string|int => app(AppSettings::class)->completeGraceHours()),
            TextInput::make('options.noise_event_ids')
                ->label('Noise event ids')
                ->helperText('Event ids kept in the counts but left out of the lanes and findings. Filled in from Settings; leave blank to treat no event id as noise.')
                ->regex('/^\s*(\d+\s*(,\s*\d+\s*)*)?$/')
                ->default(static fn (): string|int => app(AppSettings::class)->noiseEventIds())
                // Filament turns '' into null on dehydrate; a cleared field must stay '' so it means "no noise ids", not "use the setting".
                ->dehydrateStateUsing(static fn (?string $state): string => (string) $state),
            TextInput::make('options.flag_activities')
                ->label('Flag an appointment with this many activities')
                ->integer()
                ->minValue(1)
                ->maxValue(100000)
                ->default(static fn (): string|int => app(AppSettings::class)->flagActivities()),
            TextInput::make('options.flag_deletions')
                ->label('Flag a resource with this many deletions')
                ->integer()
                ->minValue(1)
                ->maxValue(100000)
                ->default(static fn (): string|int => app(AppSettings::class)->flagDeletions()),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['load']);
        $accounts = $workDir.'/'.LoadAuditCommand::ACCOUNTS_DIR;
        if (! is_dir($accounts)) {
            mkdir($accounts, 0770, true);
        }

        $messagesPath = null;
        $messageTexts = PsoMessageText::asMap();
        if ($messageTexts !== []) {
            $messagesPath = $workDir.'/'.LoadAuditCommand::MESSAGES_FILE;
            if (file_put_contents($messagesPath, json_encode($messageTexts, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('The message texts could not be written for the audit.');
            }
        }

        $argv = LoadAuditCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZone,
            $context->settings?->displayName ?: UnfiledLabel::for($context, ExportKind::Load),
            $messagesPath,
        );
        $timeout = (int) config('tools.load_audit.timeout_seconds', 840);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The audit');

        $json = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_JSON);
        $html = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_HTML);
        $findings = $json === false ? null : json_decode($json, true);
        if (! is_array($findings) || $html === false || $html === '') {
            throw new RuntimeException('The audit finished but wrote no results.');
        }

        $systemDayJson = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_SYSTEM_DAY);
        $systemDay = $systemDayJson === false ? null : json_decode($systemDayJson, true);
        $systemDay = is_array($systemDay) ? $systemDay : null;
        $systemDayWarnings = [];
        $csvStored = false;
        $hasEventFiles = array_intersect_key($inputPaths, array_flip(['audits', 'pso_events', 'appointment_history'])) !== [];
        $inputsCsv = $workDir.'/'.LoadAuditCommand::OUT_SYSTEM_DAY_INPUTS;
        if ($systemDay === null && $hasEventFiles) {
            $systemDayWarnings[] = 'The System day page could not be made from the event files.';
        } elseif ($systemDay !== null) {
            if (! is_file($inputsCsv)) {
                $systemDayWarnings[] = 'The System day inputs CSV was not written.';
            } else {
                try {
                    ToolRunFiles::put($context->run, LoadAuditCommand::OUT_SYSTEM_DAY_INPUTS, $inputsCsv);
                    $csvStored = true;
                } catch (RuntimeException $exception) {
                    $systemDayWarnings[] = $exception->getMessage();
                }
            }
        }

        $counts = ['red' => 0, 'amber' => 0, 'green' => 0];
        foreach ($findings as $finding) {
            $key = strtolower((string) ($finding['severity'] ?? ''));
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        $warnings = [];
        foreach (preg_split('/\r?\n/', $script->stdout) as $line) {
            if (str_starts_with($line, 'warning:')) {
                $warnings[] = trim(substr($line, strlen('warning:')));
            }
        }

        $warnings = [...$warnings, ...$systemDayWarnings];

        return new ToolResult(
            result: ['html' => $html, 'findings' => $findings, 'counts' => $counts, 'system_day' => $systemDay],
            warnings: $warnings,
            summary: ['counts' => $counts, 'system_day' => $systemDay !== null, 'system_day_csv' => $csvStored],
        );
    }
}
