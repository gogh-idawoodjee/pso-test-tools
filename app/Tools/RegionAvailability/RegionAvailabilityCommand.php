<?php

namespace App\Tools\RegionAvailability;

use App\Tools\LoadAudit\PsoToolsCheckout;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * The argument list for region_availability.py. Pure: no process, no filesystem, so every shape is unit
 * tested. --account names the file AccountSettingsFile wrote, so the script never has to work the
 * account out from the LOAD; --time-zone is the run's zone and beats the account file, as on the command line.
 */
class RegionAvailabilityCommand
{
    public const string OUT_DIR = 'out';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path; only 'load' is used
     * @param  array<string, mixed>  $options  week_of (Y-m-d string from the form, or blank)
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, string $timeZone, string $account): array
    {
        $workDir = rtrim($workDir, '/');
        $argv = [
            $checkout->python(), '-I', $checkout->regionAvailabilityScript(),
            '--load', $inputPaths['load'],
            '--account', $account,
            '--time-zone', $timeZone,
            '--accounts-dir', $workDir.'/'.AccountSettingsFile::DIR,
            '--out-root', $workDir.'/'.self::OUT_DIR,
        ];

        $week = self::weekOf($options['week_of'] ?? null);
        if ($week !== null) {
            array_push($argv, '--week-of', $week);
        }

        return $argv;
    }

    /** The "Week of" form value as YYYY-MM-DD, or null when blank. */
    public static function weekOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
