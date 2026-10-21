<?php

namespace App\Tools\LoadAudit;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\PsoMessageText;
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
                ->default(LoadAuditCommand::DEFAULT_GRACE_HOURS),
            TextInput::make('options.noise_event_ids')
                ->label('Noise event ids')
                ->helperText('Event ids kept in the counts but left out of the lanes and findings')
                ->regex('/^\s*(\d+\s*(,\s*\d+\s*)*)?$/')
                ->default((string) config('tools.load_audit.noise_event_ids', '')),
            TextInput::make('options.flag_activities')
                ->label('Flag an appointment with this many activities')
                ->integer()
                ->minValue(1)
                ->maxValue(100000)
                ->default(LoadAuditCommand::DEFAULT_FLAG_ACTIVITIES),
            TextInput::make('options.flag_deletions')
                ->label('Flag a resource with this many deletions')
                ->integer()
                ->minValue(1)
                ->maxValue(100000)
                ->default(LoadAuditCommand::DEFAULT_FLAG_DELETIONS),
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
        if (PsoMessageText::count() > 0) {
            $messagesPath = $workDir.'/'.LoadAuditCommand::MESSAGES_FILE;
            file_put_contents($messagesPath, json_encode(PsoMessageText::asMap(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
        if ($systemDay !== null && is_file($workDir.'/'.LoadAuditCommand::OUT_SYSTEM_DAY_INPUTS)) {
            ToolRunFiles::put($context->run, LoadAuditCommand::OUT_SYSTEM_DAY_INPUTS, $workDir.'/'.LoadAuditCommand::OUT_SYSTEM_DAY_INPUTS);
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

        return new ToolResult(
            result: ['html' => $html, 'findings' => $findings, 'counts' => $counts, 'system_day' => $systemDay],
            warnings: $warnings,
            summary: ['counts' => $counts, 'system_day' => $systemDay !== null],
        );
    }
}
