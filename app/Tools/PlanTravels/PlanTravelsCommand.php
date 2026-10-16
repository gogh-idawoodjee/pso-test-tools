<?php

namespace App\Tools\PlanTravels;

use App\Tools\LoadAudit\PsoToolsCheckout;

/** The argument lists for plan_travels.py build and merge-google. Pure: no process, no filesystem, so every shape is unit tested. */
class PlanTravelsCommand
{
    public const OUT_JSON = 'legs.json';

    public const OUT_CSV = 'plan_travels.csv';

    public const OUT_HTML = 'plan_travels.html';

    public const GOOGLE_JSON = 'google.json';

    /** @return list<string> */
    public static function build(PsoToolsCheckout $checkout, string $workDir, string $exportPath, string $firstDate, int $days, ?string $datasetName): array
    {
        $argv = [$checkout->python(), $checkout->planTravelsScript(), 'build', '--export', $exportPath, '--date', $firstDate, '--days', (string) $days];

        // Shown in the page header; the script prints none when it is not given.
        if (filled(trim((string) $datasetName))) {
            $argv[] = '--dataset-name='.trim((string) $datasetName);
        }

        array_push($argv, '--out-dir', rtrim($workDir, '/'), '--template', $checkout->planTravelsTemplate());

        return $argv;
    }

    /** @return list<string> */
    public static function mergeGoogle(PsoToolsCheckout $checkout, string $workDir, float $reviewMinutes, float $reviewPct): array
    {
        $dir = rtrim($workDir, '/');

        return [
            $checkout->python(), $checkout->planTravelsScript(), 'merge-google',
            '--legs', $dir.'/'.self::OUT_JSON, '--google', $dir.'/'.self::GOOGLE_JSON, '--out-dir', $dir,
            '--template', $checkout->planTravelsTemplate(),
            '--review-minutes', self::number($reviewMinutes), '--review-pct', self::number($reviewPct),
        ];
    }

    /** 20.0 is "20", 12.5 is "12.5": the script parses either and the argv stays readable in the logs. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
