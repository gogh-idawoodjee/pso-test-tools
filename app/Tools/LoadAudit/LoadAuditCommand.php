<?php

namespace App\Tools\LoadAudit;

use App\Support\Settings\AppSettings;

/**
 * The argument list for audit_load.py. No process and no filesystem, so every shape is unit tested; the
 * noise ids and flag thresholds fall back to AppSettings, read through app() so tests can bind a stub.
 * An empty --accounts-dir keeps the script from reading the account files in the checkout; everything
 * per customer comes from the dataset settings instead.
 */
class LoadAuditCommand
{
    public const OUT_JSON = 'load_audit.json';

    public const OUT_HTML = 'load_audit.html';

    public const OUT_MD = 'load_audit.md';

    public const ACCOUNTS_DIR = 'accounts';

    public const MESSAGES_FILE = 'messages.json';

    public const OUT_SYSTEM_DAY = 'system_day.json';

    public const OUT_SYSTEM_DAY_INPUTS = 'system_day_inputs.csv';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path
     * @param  array<string, mixed>  $options  ifs_release, complete_grace_hours
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $displayName, ?string $messagesPath = null, ?string $paramsPath = null, ?string $watchlistPath = null): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [$checkout->python(), '-I', $checkout->script(), '--load', $inputPaths['load']];

        foreach (['plan', 'sys'] as $kind) {
            if (filled($inputPaths[$kind] ?? null)) {
                $argv[] = "--{$kind}";
                $argv[] = $inputPaths[$kind];
            }
        }

        foreach (['audits' => '--audits', 'pso_events' => '--events', 'appointment_history' => '--appointments'] as $kind => $flag) {
            if (filled($inputPaths[$kind] ?? null)) {
                $argv[] = $flag;
                $argv[] = $inputPaths[$kind];
            }
        }

        if (filled($messagesPath)) {
            $argv[] = '--messages';
            $argv[] = $messagesPath;
        }

        // The engine only knows these flags once its System day leg ships, so they ride along with System day inputs only.
        $hasSystemDayInputs = array_intersect_key($inputPaths, array_flip(['audits', 'pso_events', 'appointment_history'])) !== [];
        if ($hasSystemDayInputs) {
            $settings = app(AppSettings::class);
            $noiseIds = self::normalisedIds((string) (self::hasOption($options, 'noise_event_ids') ? $options['noise_event_ids'] : $settings->noiseEventIds()));
            if ($noiseIds !== '') {
                $argv[] = '--noise-ids';
                $argv[] = $noiseIds;
            }

            foreach (['flag_activities' => $settings->flagActivities(...), 'flag_deletions' => $settings->flagDeletions(...)] as $option => $settingsDefault) {
                $argv[] = '--'.str_replace('_', '-', $option);
                $argv[] = (string) (is_numeric($options[$option] ?? null) ? (int) $options[$option] : $settingsDefault());
            }
        }

        $argv[] = '--tz';
        $argv[] = $timeZone;

        if (filled(trim((string) $displayName))) {
            $argv[] = '--org='.trim((string) $displayName);
        }

        array_push($argv, '--accounts-dir', $workDir.'/'.self::ACCOUNTS_DIR,
            '--params', $paramsPath ?? $checkout->paramsFile(),
            '--watchlist', $watchlistPath ?? $checkout->watchlistFile());

        $release = trim((string) ($options['ifs_release'] ?? ''));
        if ($release !== '') {
            $argv[] = '--ifs-release';
            $argv[] = $release;
        }

        $grace = $options['complete_grace_hours'] ?? null;
        array_push($argv, '--complete-grace-hours', (string) (is_numeric($grace) ? (int) $grace : app(AppSettings::class)->completeGraceHours()));

        // No schedule review on the web (it is a separate product), so the dashboard hides its button.
        array_push($argv, '--review', 'none',
            '--out', $workDir.'/'.self::OUT_MD,
            '--json', $workDir.'/'.self::OUT_JSON,
            '--html', $workDir.'/'.self::OUT_HTML);

        return $argv;
    }

    /** @param array<string, mixed> $options */
    private static function hasOption(array $options, string $option): bool
    {
        return array_key_exists($option, $options) && $options[$option] !== null;
    }

    /** "3041, 1767 ,24578" becomes "3041,1767,24578". */
    /** Whole-number event ids, comma separated; anything else is dropped, so only ids ever reach the engine. */
    public static function normalisedIds(string $list): string
    {
        return implode(',', array_filter(array_map('trim', explode(',', $list)), static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1));
    }
}
