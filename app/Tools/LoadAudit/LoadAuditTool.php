<?php

namespace App\Tools\LoadAudit;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
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
        return [ExportKind::Plan, ExportKind::Sys];
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

        $argv = LoadAuditCommand::build(
            $checkout, $workDir, $inputPaths, $options, $context->timeZone,
            $context->settings?->displayName ?: UnfiledLabel::for($context, ExportKind::Load),
        );
        $timeout = (int) config('tools.load_audit.timeout_seconds', 840);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The audit');

        $json = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_JSON);
        $html = @file_get_contents($workDir.'/'.LoadAuditCommand::OUT_HTML);
        $findings = $json === false ? null : json_decode($json, true);
        if (! is_array($findings) || $html === false || $html === '') {
            throw new RuntimeException('The audit finished but wrote no results.');
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
            result: ['html' => $html, 'findings' => $findings, 'counts' => $counts],
            warnings: $warnings,
            summary: ['counts' => $counts],
        );
    }
}
