<?php

namespace App\Tools\ActivityTrace;

use App\Tools\LoadAudit\PsoToolsCheckout;

/** The argument list for trace_activity.py. Pure: no process, no filesystem, so every shape is unit tested. */
class ActivityTraceCommand
{
    public const OUT_HTML = 'trace.html';

    public const OUT_JSON = 'trace.json';

    public const OUT_BOOKING_CSV = 'booking.csv';

    public const OUT_HISTORY_CSV = 'history.csv';

    /**
     * @param  array<string, string>  $inputPaths  kind value => local path: audits, and load / event_log when given
     * @param  array<string, mixed>  $options  activity_id and work_task_id, either or both
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $inputPaths, array $options, ?string $timeZone, ?string $ifsTimeZone, ?string $datasetName): array
    {
        $argv = [$checkout->python(), $checkout->activityTraceScript(), '--audits', $inputPaths['audits']];

        foreach (['activity_id' => '--activity', 'work_task_id' => '--work-task'] as $option => $flag) {
            $value = trim((string) ($options[$option] ?? ''));
            if ($value !== '') {
                array_push($argv, $flag, $value);
            }
        }

        foreach (['load' => '--load', 'event_log' => '--event-log'] as $kind => $flag) {
            if (filled($inputPaths[$kind] ?? null)) {
                array_push($argv, $flag, $inputPaths[$kind]);
            }
        }

        // No zone: the script reads and shows times in UTC and says so on the page.
        if (filled(trim((string) $timeZone))) {
            array_push($argv, '--tz', trim((string) $timeZone));
        }

        if (filled(trim((string) $ifsTimeZone))) {
            array_push($argv, '--ifs-tz', trim((string) $ifsTimeZone));
        }

        if (filled(trim((string) $datasetName))) {
            $argv[] = '--dataset-name='.trim((string) $datasetName);
        }

        array_push($argv, '--out-dir', rtrim($workDir, '/'), '--template', $checkout->activityTraceTemplate());

        return $argv;
    }
}
