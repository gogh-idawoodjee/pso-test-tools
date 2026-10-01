<?php

namespace App\Support\SysCompare;

/**
 * Plain-English scope note for each table in the row-count listing.
 */
final class TableScopes
{
    public const string DEFAULT_SCOPE = 'Not compared - nothing meaningful to compare';

    /**
     * @var array<string, string>
     */
    private const array SCOPES = [
        'Users' => 'Not compared - user accounts',
        'System_Version' => 'Reported separately - see the PSO version section (not row-compared)',
        'Application_Data' => 'Not compared - per-user saved filters and screen settings',
        'User_Application_Data' => 'Not compared - links users to their saved filters and settings',
        'User_Custom_List' => 'Not compared - per-user custom list layouts',
        'User_External_Task' => 'Not compared - external tasks linked to individual users',
        'User_Group' => 'Not compared - which users belong to which groups',
        'User_List' => 'Not compared - per-user list/column layouts',
        'User_Object' => 'Not compared - objects linked to individual users',
        'User_Parameter' => 'Not compared - per-user parameter values',
        'User_Permission' => 'Not compared - per-user permission overrides',
        'Profile_Parameter' => 'Compared: Parameters',
        'Org_Schedule_Exception_Type' => 'Compared: Exception Types',
        'Group_Permission' => 'Compared: Group Permissions',
        'Groups' => 'Compared: Groups',
        'Organisation_Permission' => 'Compared: Org Permissions',
        'Organisation_List' => 'Compared: Lists',
        'List' => 'Compared: Lists (org defaults only)',
        'List_Entry' => 'Compared: Lists (org defaults only)',
        'Entry' => 'Compared: Lists (org defaults only)',
        'Travel_Time_Profile' => 'Compared: Travel',
        'Travel_Time_Weighting' => 'Compared: Travel',
        'Travel_Time_Polygon' => 'Compared: Travel',
        'Polygon' => 'Compared: Travel',
        'Profile' => 'Compared: Profiles & Other',
        'Terminology_Organisation' => 'Compared: Profiles & Other',
        'Org_Schedule_Exc_Type_Data' => 'Compared: Profiles & Other',
        'Organisation' => 'Compared: Profiles & Other',
    ];

    public static function for(string $table): string
    {
        return self::SCOPES[$table] ?? self::DEFAULT_SCOPE;
    }
}
