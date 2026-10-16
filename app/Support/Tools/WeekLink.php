<?php

namespace App\Support\Tools;

use App\Enums\Tool as ToolId;
use App\Models\ToolRun;
use DateTimeImmutable;

/**
 * The region availability page's Previous and Next week links. Inside the app the page is one run's
 * stored HTML, so the sibling files the links name do not exist; the page posts the week to the app
 * instead and the app runs the viewer again on the same LOAD for that week.
 */
class WeekLink
{
    /** The Monday to run for, or null when the message is not one this run can act on. */
    public static function weekFor(ToolRun $run, mixed $week): ?string
    {
        if ($run->tool !== ToolId::RegionAvailability || ! is_string($week) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $week);

        return $date !== false && $date->format('Y-m-d') === $week ? $week : null;
    }
}
