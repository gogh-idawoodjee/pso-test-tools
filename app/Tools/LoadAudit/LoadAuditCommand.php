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

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path
     * @param  array<string, mixed>  $options  ifs_release, complete_grace_hours
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, ?string $displayName): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [$checkout->python(), '-I', $checkout->script(), '--load', $inputPaths['load']];

        foreach (['plan', 'sys'] as $kind) {
            if (filled($inputPaths[$kind] ?? null)) {
                $argv[] = "--{$kind}";
                $argv[] = $inputPaths[$kind];
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
}
