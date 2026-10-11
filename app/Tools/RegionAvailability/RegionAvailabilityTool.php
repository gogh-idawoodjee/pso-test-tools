<?php

namespace App\Tools\RegionAvailability;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use Filament\Forms\Components\DatePicker;
use RuntimeException;

/**
 * Runs pso-tools/region-availability/region_availability.py (the reference implementation, never ported)
 * against the run's LOAD for one week, with the dataset's settings handed over as the account file, and
 * keeps the page it rendered plus the gathered JSON. The page's three layers (week grid, one-day view,
 * region pivot) are the drill-down; nothing is rebuilt in PHP.
 */
class RegionAvailabilityTool implements Tool
{
    public static function id(): ToolId
    {
        return ToolId::RegionAvailability;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Load];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function optionFields(): array
    {
        return [
            DatePicker::make('options.week_of')
                ->label('Week of')
                ->helperText('Any date in the week to show. Blank: the week of the LOAD (a weekend LOAD shows the next week).')
                ->native(false)
                ->rule('date')
                ->nullable(),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['load']);
        // The job runs with nobody logged in, so the user-owned scope would hide the environment from the relation.
        $accountId = $context->scope instanceof Dataset
            ? Environment::query()->withoutGlobalScopes()->find($context->scope->environment_id)?->account_id
            : null;
        $account = AccountSettingsFile::accountName($accountId);
        AccountSettingsFile::write($context->settings, $workDir.'/'.AccountSettingsFile::DIR, $account);

        $argv = RegionAvailabilityCommand::build($checkout, $workDir, $inputPaths, $options, $context->timeZone, $account);
        $timeout = (int) config('tools.region_availability.timeout_seconds', 600);

        $script = ScriptRun::execute($context, $workDir, $argv, $timeout, 'The viewer');

        // The script names the folder after the LOAD's account and dataset id; find the one page it wrote.
        $pages = glob($workDir.'/'.RegionAvailabilityCommand::OUT_DIR.'/*/*/region_availability_*.html') ?: [];
        if (count($pages) !== 1) {
            throw new RuntimeException('The viewer finished but wrote no page.');
        }
        $html = file_get_contents($pages[0]);
        $json = @file_get_contents(substr($pages[0], 0, -strlen('.html')).'.json');
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || $html === false || $html === '') {
            throw new RuntimeException('The viewer finished but wrote no page.');
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $raw = is_array($meta['counts'] ?? null) ? $meta['counts'] : [];
        $counts = [
            'techs' => (int) ($raw['techs'] ?? 0),
            'shifts' => (int) ($raw['shifts'] ?? 0),
            'techs_with_changes' => (int) ($raw['techs_with_changes'] ?? 0),
        ];
        $week = (string) ($meta['week'] ?? '');

        $warnings = [];
        foreach (preg_split('/\r?\n/', $script->stdout) as $line) {
            if (str_starts_with($line, 'WARNING: ')) {
                $warnings[] = trim(substr($line, strlen('WARNING: ')));
            }
        }

        return new ToolResult(
            result: ['html' => $html, 'data' => $data],
            warnings: $warnings,
            summary: ['week' => $week, 'counts' => $counts, 'line' => self::summaryLine($counts, $week)],
        );
    }

    /** @param array{techs: int, shifts: int, techs_with_changes: int} $counts */
    public static function summaryLine(array $counts, string $week): string
    {
        return sprintf('%d technicians, %d with changes, week of %s', $counts['techs'], $counts['techs_with_changes'], $week === '' ? 'the LOAD' : $week);
    }
}
