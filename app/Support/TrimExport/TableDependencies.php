<?php

namespace App\Support\TrimExport;

/**
 * The table lists of pso-tools scripts/Remove-PsoTables.ps1, copied verbatim: the tables the Cleanup for
 * input preset removes ($CleanupTables) and the tables a table's rows point at ($DependsOn), which drive
 * the "kept a table without its parent" warnings. Keep both in step with the script.
 */
class TableDependencies
{
    public const string INPUT_REFERENCE = 'Input_Reference';

    /** @var list<string> */
    public const array CLEANUP_TABLES = [
        'Plan', 'Plan_Resource', 'Plan_Route', 'Plan_Travel', 'Plan_Break', 'Allocation', 'Allocation_Data',
        'Schedule_Event', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Exception_Response',
        'Appointment_Request', 'Appointment_Offer', 'Appointment_Offer_Response', 'Appointment_Summary',
        'Explanation_Request', 'Explanation_Request_Change', 'Broadcast', 'Broadcast_Parameter',
        'Additional_Attribute', 'Activity_Custom_URL', 'Resource_Custom_URL', 'URL_String', 'URL_Parameter',
        'Object_Group', 'Source_Data', 'Source_Data_Parameter',
    ];

    /** @var array<string, list<string>> child table => the tables its rows point at */
    public const array DEPENDS_ON = [
        'Activity_Status' => ['Activity'], 'Activity_SLA' => ['Activity'], 'Activity_Skill' => ['Activity'], 'Activity_Custom_URL' => ['Activity'],
        'Additional_Attribute' => ['Activity', 'Resources'], 'Allocation' => ['Activity', 'Resources', 'Plan'], 'Allocation_Data' => ['Allocation'],
        'Plan_Route' => ['Plan', 'Resources'], 'Plan_Travel' => ['Plan', 'Resources'], 'Plan_Break' => ['Plan', 'Resources'], 'Plan_Resource' => ['Plan', 'Resources'],
        'Shift' => ['Resources'], 'Shift_Break' => ['Shift'], 'Resource_Skill' => ['Resources'], 'Resource_Region' => ['Resources', 'Region'],
        'Resource_Region_Availability' => ['Resource_Region'], 'Resource_Skill_Availability' => ['Resource_Skill'], 'Availability' => ['Resources'],
        'Location_Region' => ['Location', 'Region'], 'Schedule_Exception_Data' => ['Schedule_Exception'], 'Broadcast_Parameter' => ['Broadcast'],
        'URL_Parameter' => ['URL_String'], 'Source_Data_Parameter' => ['Source_Data'],
        'Appointment_Offer' => ['Appointment_Request'], 'Appointment_Offer_Response' => ['Appointment_Request'], 'Appointment_Summary' => ['Appointment_Request'],
    ];

    /**
     * @param  list<string>  $presentTables
     * @return list<string> the cleanup tables that are in the file, in the script's order
     */
    public static function cleanupPresent(array $presentTables): array
    {
        return array_values(array_intersect(self::CLEANUP_TABLES, $presentTables));
    }

    /**
     * One warning per kept table whose parent (a table the file has) is removed, as the script warns.
     *
     * @param  list<string>  $presentTables  tables in the file
     * @param  list<string>  $droppedTables  tables the run removes
     * @return list<string>
     */
    public static function warnings(array $presentTables, array $droppedTables): array
    {
        $present = array_flip($presentTables);
        $dropped = array_flip($droppedTables);
        $warnings = [];

        foreach (self::DEPENDS_ON as $child => $parents) {
            if (! isset($present[$child]) || isset($dropped[$child])) {
                continue;
            }

            $lost = array_values(array_filter($parents, static fn (string $parent): bool => isset($present[$parent], $dropped[$parent])));
            if ($lost === []) {
                continue;
            }

            $warnings[] = $child.' is kept but '.implode(' and ', $lost).(count($lost) === 1 ? ' is' : ' are').' removed, so its rows will point at nothing.';
        }

        sort($warnings, SORT_STRING);

        return $warnings;
    }
}
