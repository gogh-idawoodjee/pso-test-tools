<?php

namespace App\Tools\LoadAudit;

/**
 * The argument list for audit_load.py. Pure: no process, no filesystem, so every shape is unit tested.
 * An empty --accounts-dir keeps the script from reading the account files in the checkout; everything
 * per customer comes from the dataset settings instead.
 */
class LoadAuditCommand
{
    public const OUT_JSON = 'load_audit.json';

    public const OUT_HTML = 'load_audit.html';

    public const OUT_MD = 'load_audit.md';

    public const ACCOUNTS_DIR = 'accounts';

    public const DEFAULT_GRACE_HOURS = 24;

    public const DEFAULT_FLAG_ACTIVITIES = 5;

    public const DEFAULT_FLAG_DELETIONS = 30;

    public const MESSAGES_FILE = 'messages.json';

    public const OUT_SYSTEM_DAY = 'system_day.json';

    public const OUT_SYSTEM_DAY_INPUTS = 'system_day_inputs.csv';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path
     * @param  array<string, mixed>  $options  ifs_release, complete_grace_hours
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $displayName, ?string $messagesPath = null): array
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
            $noiseIds = self::normalisedIds((string) (filled($options['noise_event_ids'] ?? null) ? $options['noise_event_ids'] : config('tools.load_audit.noise_event_ids', '')));
            if ($noiseIds !== '') {
                $argv[] = '--noise-ids';
                $argv[] = $noiseIds;
            }

            foreach (['flag_activities' => self::DEFAULT_FLAG_ACTIVITIES, 'flag_deletions' => self::DEFAULT_FLAG_DELETIONS] as $option => $default) {
                $argv[] = '--'.str_replace('_', '-', $option);
                $argv[] = (string) (is_numeric($options[$option] ?? null) ? (int) $options[$option] : $default);
            }
        }

        $argv[] = '--tz';
        $argv[] = $timeZone;

        if (filled(trim((string) $displayName))) {
            $argv[] = '--org='.trim((string) $displayName);
        }

        array_push($argv, '--accounts-dir', $workDir.'/'.self::ACCOUNTS_DIR,
            '--params', $checkout->paramsFile(),
            '--watchlist', $checkout->watchlistFile());

        $release = trim((string) ($options['ifs_release'] ?? ''));
        if ($release !== '') {
            $argv[] = '--ifs-release';
            $argv[] = $release;
        }

        $grace = $options['complete_grace_hours'] ?? null;
        array_push($argv, '--complete-grace-hours', (string) (is_numeric($grace) ? (int) $grace : self::DEFAULT_GRACE_HOURS));

        // No schedule review on the web (it is a separate product), so the dashboard hides its button.
        array_push($argv, '--review', 'none',
            '--out', $workDir.'/'.self::OUT_MD,
            '--json', $workDir.'/'.self::OUT_JSON,
            '--html', $workDir.'/'.self::OUT_HTML);

        return $argv;
    }

    /** "3041, 1767 ,24578" becomes "3041,1767,24578". */
    public static function normalisedIds(string $list): string
    {
        return implode(',', array_filter(array_map('trim', explode(',', $list)), static fn (string $id): bool => $id !== ''));
    }
}
