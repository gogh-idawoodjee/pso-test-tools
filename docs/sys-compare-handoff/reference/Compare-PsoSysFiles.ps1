<#
.SYNOPSIS
    Compares PSO system data (sys file) XML exports across environments.

.DESCRIPTION
    Reads one DsSystemData export per environment and writes:
      - one CSV per comparison area (Parameters, Exception Types, Group Permissions, ...)
      - a Summary_Tally.csv, ParamDefinitions_Template.csv, 10_Versions.csv and 11_VersionHistory.csv
      - a single self-contained HTML summary (PSO_Compare_Summary.html)

    Compared: profile parameters, schedule exception types, groups + group permissions,
    organisation permissions, org-default list layouts, travel-time setup, profiles,
    terminology, exception type data, organisation record.
    NOT compared row-by-row (by design): Users and all per-user tables. Per-user tables
    hold data tied to individual user accounts: each person's saved filters, screen settings
    and list layouts, plus which groups, permissions and parameters are assigned to each user.
    They are left out because the set of users differs between environments, so comparing
    them would mostly show noise. This also means group MEMBERSHIP (who is in which group)
    is not compared, only what each group is allowed to do.

    System_Version is reported separately: the current PSO version, last upgrade and last
    patch per environment, with a loud red warning if they are not all on the same version.

    Comparison is case-sensitive and whitespace-sensitive. A leading/trailing space in a
    value is shown with a visible marker (U+2423) so it can be seen in the output.
    Group names that differ only by case (e.g. ST_Ops_Mgr / ST_Ops_MGR) are treated as
    the same group; the Groups output flags the spelling difference.

    Routing/API key values are masked in all output.

    Parameters are described in plain English from a built-in table (about 190 entries taken
    from the PSO knowledge base). Add your own via ParamDefinitions.csv (Parameter,Definition,
    Basis) next to the script. ParamDefinitions_Template.csv lists what is still undocumented.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Compare-PsoSysFiles.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Compare-PsoSysFiles.ps1 -Baseline STG -OpenReport

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Compare-PsoSysFiles.ps1 -NoColor

.NOTES
    Works in Windows PowerShell 5.1 and PowerShell 7+. No modules required.
    Keep this file ASCII-only (Windows PowerShell 5.1 misreads non-ASCII in scripts
    saved without a BOM).
#>
[CmdletBinding()]
param(
    # ===================== EDIT THESE DEFAULTS =====================
    # Order matters: the FIRST environment is the baseline unless -Baseline is given.
    # Relative paths are resolved against the folder this script is in.
    [hashtable[]]$Environments = @(
        @{ Name = 'PROD'; Path = '.\prod.xml' },
        @{ Name = 'ACC';  Path = '.\acc.xml'  },
        @{ Name = 'STG';  Path = '.\stg.xml'  },
        @{ Name = 'TST';  Path = '.\tst.xml'  }
    ),
    [string]$OutputDir = '.\PsoCompareOutput',
    [string]$Baseline = '',
    # HTML shows only differing rows by default (CSVs always contain every row).
    [switch]$ShowSameRowsInHtml,
    [switch]$OpenReport,
    # Turn off coloured console output.
    [switch]$NoColor
    # ================================================================
)

$ErrorActionPreference = 'Stop'
$ScriptDir = $PSScriptRoot
if ([string]::IsNullOrEmpty($ScriptDir)) { $ScriptDir = (Get-Location).Path }

# ---------------------------------------------------------------------------
# Parameter definitions (plain English)
# Basis (not shown as a column): "KB: <file>" = documented in the project knowledge base,
# "Release notes", or "Inference (...)" = only the parameter name to go on (unverified).
# Inferred definitions are marked with a leading [Inferred] in the output.
# Add or edit entries below, or (easier) drop a ParamDefinitions.csv next to this script
# with the columns Parameter,Definition,Basis. Entries in the CSV override the ones here.
# The run also writes ParamDefinitions_Template.csv listing every parameter that still
# lacks a specific definition, ready to fill in.
# ---------------------------------------------------------------------------
$Defs = @{
    'ActivityText'                                        = @{ Text = 'Text shown on each activity bar on the Gantt. Built from an expression such as {Table.Field}; fields starting with # are derived values.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ActivitySubtext'                                     = @{ Text = 'Secondary line of text shown under the main activity text. Same {Table.Field} expression syntax.'; Basis = 'Inference (not in KB)' }
    'ActivityBaseLabel'                                   = @{ Text = 'Label format for activities, apparently the base/default label the other activity label settings build on. Confirm on the Parameters screen.'; Basis = 'Inference (not in KB)' }
    'PrivateActivityText'                                 = @{ Text = 'Text shown on private activities (unavailability blocks such as holiday, sickness, training) on the Gantt.'; Basis = 'Inference (not in KB)' }
    'PrivateActivityLabel'                                = @{ Text = 'Label shown for private activities outside the Gantt, by analogy with ActivityLabel.'; Basis = 'Inference (not in KB)' }
    'ResourceLabel'                                       = @{ Text = 'How a resource is named outside the Gantt, e.g. {First name} {Surname} [{Id}] shows "Charles Ollivon [T1001]". Anything outside { } (spaces, brackets) is shown as typed, so a trailing space is cosmetic only.'; Basis = 'KB: 18-pso-workbench-administration, 27-workbench-wise-simulation' }
    'ResourceText'                                        = @{ Text = 'Main text shown for each resource row on the Gantt, by analogy with ActivityText.'; Basis = 'Inference (not in KB)' }
    'ResourceSubtext'                                     = @{ Text = 'Secondary line shown under the resource name (here the employer).'; Basis = 'Inference (not in KB)' }
    'ResourceDescriptionFormat'                           = @{ Text = 'Format of the resource description string, e.g. in lists and tooltips.'; Basis = 'Inference (not in KB)' }
    'ARPResourceLabelFormat'                              = @{ Text = 'How a resource is labelled in the Advanced Resource Planner (ARP).'; Basis = 'Inference (not in KB)' }
    'AllowSplitTravel'                                    = @{ Text = 'By name, lets the scheduler split a travel leg (for example around a break or a shift boundary). NOT documented in the KB, and the KB currently says there is no separate travel-splitting toggle (it follows split_allowed on the destination activity type), so this needs confirming with IFS.'; Basis = 'Inference (not in KB)' }
    'CommitToAllocatedShift'                              = @{ Text = 'When someone manually commits an activity, keep it in the shift it is already allocated to. If it is on together with CommitToAvailabilityWindow, whichever has the later start wins.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'CommittedActivitiesConstraintsOption'                = @{ Text = '1 = committed activities (status 30-40) have their time constraints obeyed, so the start may be pushed to a valid time; 0 = constraints are not enforced. Field reality: only Activity_Availabilities are enforced, not SLA windows. No effect once the resource is Travelling (50).'; Basis = 'KB: 06-tasks, 14-extended-patterns, 16-constraints-and-preferences' }
    'CommittedActivitiesProfileId'                        = @{ Text = 'Points the DEFAULT profile at the profile to use for committed (imminent) activities. This is step 2 of the real-time travel setup; without it the committed-activities profile is not picked up.'; Basis = 'KB: 17-travel' }
    'TravelCalculationOption'                             = @{ Text = 'How travel time is worked out: HierarchicalTravelMatrix (the HTM travel database), StraightLine (as the crow flies, with a speed factor), or RealTimeTravel (live routing service, meant for committed/imminent activities and layered on top of HTM or straight line).'; Basis = 'KB: 17-travel, 10-appointment-booking' }
    'TravelTimeProfileId'                                 = @{ Text = 'Which travel-time profile (time-of-day and area weightings, barriers) adjusts journey times. It is the last fallback after Shift, Resource and Resource Type. The system ships with an empty DEFAULT profile.'; Basis = 'KB: 17-travel, 00-property-inheritance' }
    'HierarchicalDatabaseMatrixId'                        = @{ Text = 'Which HTM (travel matrix) to use. Set per profile, this is how multiple HTM connections are supported.'; Basis = 'KB: 17-travel, 18-pso-workbench-administration' }
    'RealTimeTravelProvider'                              = @{ Text = 'Which online routing service supplies real-time travel: NONE or TOMTOM.'; Basis = 'KB: 17-travel' }
    'RoutingApiKey'                                       = @{ Text = 'API key for the online routing service; required for real-time travel. (Masked in this output.)'; Basis = 'KB: 17-travel' }
    'IsochroneCompareStraight'                            = @{ Text = 'Used with the Travel Analyser isochrone preview to compare against a straight-line disc when the preview looks very different from the real isochrone.'; Basis = 'KB: 17-travel' }
    'ImplicitBreaksOnOffEventsRequired'                   = @{ Text = 'Controls when PSO assumes an implicit break has been taken: whether it waits for the resource break on/off events or assumes the break happens as planned. Added in 6.5.0.25; a fix in 6.16.0.79 made it apply during appointment booking. The True/False meaning is inferred from the name.'; Basis = 'Release notes' }
    'MaxDisplaceableActivityPriority'                     = @{ Text = 'Appointment booking: low-priority activities up to this priority are ignored when building an offer (no attempt to reallocate them). -1 = off; e.g. 2 ignores priority 1-2. Never applies to committed-or-later or fully fixed activities.'; Basis = 'KB: 10-appointment-booking' }
    'SortValuePrecedenceMaximumStatus'                    = @{ Text = 'Order of committed activities. Default 30 sorts by status first (Accepted before Downloaded before Sent before Committed), then commit_sort_value. Setting 40 makes commit_sort_value the main key with status as tie-breaker. Each activity needs a unique status + sort value + date_time_status combination.'; Basis = 'KB: 06-tasks' }
    'StandardSendScheduleExceptionAccepts'                = @{ Text = 'When true, schedule-exception acknowledgements are handled in the standard way and the separate Schedule_Exception_Response broadcast is bypassed.'; Basis = 'KB: 22-pso-restful-gateway' }
    'OpenIdAuthority'                                     = @{ Text = 'Issuer URL of the identity provider used for single sign-on (OpenID Connect). Expected to differ per environment. If it is wrong, logins fail; the packaged "Reset OpenIdAuthority.xml" restores password login.'; Basis = 'KB: 18-pso-workbench-administration' }
    'MaxUserSessions'                                     = @{ Text = 'By name, the cap on concurrent sessions per user; -1 presumably means unlimited.'; Basis = 'Inference (not in KB)' }
    'GpsFrequency'                                        = @{ Text = 'By name, how often GPS positions are expected or used (PT5M = every 5 minutes).'; Basis = 'Inference (not in KB)' }
    'SLAActivityAgeingFactor'                             = @{ Text = 'By name, a multiplier on how SLA-related activity age is calculated or displayed. Confirm before relying on it.'; Basis = 'Inference (not in KB)' }
    'SchedulingWindowLength'                              = @{ Text = 'By name, how far ahead the scheduler plans (P2D = 2 days). Related to the dataset Scheduling Work Days, but the parameter itself is not documented in the KB.'; Basis = 'Inference (not in KB)' }
    'ActivityLabel'                                       = @{ Text = 'Activity label shown outside the Gantt (elsewhere in the workspace). The #label field in expressions uses this parameter.'; Basis = 'KB: 18-pso-workbench-administration' }
    'LocationLabel'                                       = @{ Text = 'Location label shown outside the Gantt. The #label field on Location uses this parameter.'; Basis = 'KB: 18-pso-workbench-administration' }
    'AllowAllocateBeforeCommitted'                        = @{ Text = 'Default false. Whether other activities can be scheduled before a committed activity in a route (true also allows emergency allocation when the Gantt is already full of committed jobs). Confirmed by IFS R&D: an activity with date_time_fixed populated counts as fixed-time even if fixed=false and is always scheduled at that time, whatever this parameter says.'; Basis = 'KB: 16-constraints-and-preferences, 24-open-questions-for-ifs' }
    'AllowAuthenticationGateway'                          = @{ Text = 'If true on an OIDC-enabled system, standard username/password login is still accepted by adding ?authGateway=true to the Workbench login URL (case-sensitive). A fallback if single sign-on breaks.'; Basis = 'KB: 18-pso-workbench-administration' }
    'AllowLocationlessActivities'                         = @{ Text = 'Must be true to allow activities that have no location (locationless activities).'; Basis = 'KB: 06-tasks' }
    'CascadeSchedulingObjectDeletions'                    = @{ Text = 'By name, whether deleting a scheduling object also deletes the objects that depend on it. Not documented in the KB.'; Basis = 'Inference (not in KB)' }
    'JsonFormatVersion'                                   = @{ Text = 'JSON format used by the RESTful Gateway and Schedule Broadcast Manager. The format changed in PSO 6.15; set to "Version 1" to revert to the pre-6.15 format.'; Basis = 'KB: 22-pso-restful-gateway' }
    'NoActivityChangesFromStatus'                         = @{ Text = 'Status at or above which NO manual changes are allowed to an activity (fields are greyed out).'; Basis = 'KB: 18-pso-workbench-administration' }
    'NoStatusChangesFromStatus'                           = @{ Text = 'Status at or above which only non-status, non-resource changes are allowed. Note: the KB spells this NoStatusChangeFromStatus (singular Change); the parameter ID in these sys files is NoStatusChangesFromStatus.'; Basis = 'KB: 18-pso-workbench-administration' }
    'NoStatusChangeFromStatus'                            = @{ Text = 'KB spelling of NoStatusChangesFromStatus (see that entry): status at or above which only non-status, non-resource changes are allowed.'; Basis = 'KB: 18-pso-workbench-administration' }
    'OpenIdAllowLegacyAuthentication'                     = @{ Text = 'If true, Gateway calls can still use a PSO user and password instead of an OIDC token.'; Basis = 'KB: 18-pso-workbench-administration' }
    'OpenIdClientId'                                      = @{ Text = 'Client ID registered for the PSO Workbench app with the identity provider (OpenID Connect).'; Basis = 'KB: 18-pso-workbench-administration' }
    'OpenIdResourceId'                                    = @{ Text = 'Optional. Client ID for a registered Web API in the identity provider.'; Basis = 'KB: 18-pso-workbench-administration' }
    'UserNameClaim'                                       = @{ Text = 'Which OpenID Connect claim maps to the PSO user ID.'; Basis = 'KB: 18-pso-workbench-administration' }
    'iSWBDistanceUnit'                                    = @{ Text = 'By name, the distance unit shown in the Workbench (km or miles), by analogy with the other iSWB display settings. Not documented in the KB.'; Basis = 'Inference (not in KB)' }
    'ScheduleCommittedActivitiesInActiveShift'            = @{ Text = 'When false, committed activities that do not fit the active shift can be moved to later shifts. When true (the default) they stay in the active shift regardless.'; Basis = 'KB: 06-tasks' }
    'UseLatestUpdateForStatusTime'                        = @{ Text = 'By default the FIRST on-site status date is used as the activity start when a resource sends several on-site updates. Set to true to always use the most recent one.'; Basis = 'KB: 06-tasks' }
    'FixedActivityAfterShiftBuffer'                       = @{ Text = 'A fixed-time activity starting shortly after shift end (within this buffer, default 1 hour) is assumed to belong to the previous shift, so the resource travels from the last shift activity.'; Basis = 'KB: 06-tasks' }
    'AllowAllocateWithParallelCommits'                    = @{ Text = 'Boolean. By default uncommitted activities can be scheduled in parallel with the last committed activity in a shift (never earlier). When false they must be scheduled strictly after all committed activities finish.'; Basis = 'KB: 20-resource-capacity-management' }
    'AllowPartiallyCommittedBucketRoutes'                 = @{ Text = 'Default false. False: once a bucket shift has a committed activity, no more uncommitted activities can be added to it. True: DSE can keep adding uncommitted activities alongside committed ones (this raises a Partially Committed Bucket Route exception).'; Basis = 'KB: 20-resource-capacity-management, 08-pso-advanced' }
    'EnforceConstraintsOnCallEnd'                         = @{ Text = 'By default only the start of an activity is checked against availability constraints (start-based). Setting this parameter switches to checking the full duration.'; Basis = 'KB: 16-constraints-and-preferences' }
    'TimeHorizon'                                         = @{ Text = 'Suggested Dispatch commit rule (default 30 min): an activity is only committed if the resource is due to start travelling within this time.'; Basis = 'KB: 08-pso-advanced, 06-tasks' }
    'MaximumCommittedActivities'                          = @{ Text = 'Suggested Dispatch commit rule (default 1): maximum activities at committed status or above (and below completed) for a resource at any one time.'; Basis = 'KB: 08-pso-advanced' }
    'CommitBreaks'                                        = @{ Text = 'Suggested Dispatch: whether breaks are committed (default true).'; Basis = 'KB: 08-pso-advanced' }
    'CountBreaks'                                         = @{ Text = 'Whether committed breaks count toward MaximumCommittedActivities (default true).'; Basis = 'KB: 08-pso-advanced' }
    'IgnoreBreakTime'                                     = @{ Text = 'If true, break time is excluded from the TimeHorizon calculation. Discrepancy: the KB default is false, but the PSO Scheduling Schema Technical Guide says True. Not yet confirmed with IFS R&D, so check your dataset.'; Basis = 'KB: 08-pso-advanced, 24-open-questions-for-ifs' }
    'SendExternalCommitsMode'                             = @{ Text = 'When Suggested_Dispatch records are written for activities committed outside SDS. NONE (default) = normal behaviour only; ALL = whenever any activity is committed or uncommitted that SDS did not suggest; MANUAL = only for activities committed manually in the Scheduling Workbench.'; Basis = 'KB: 08-pso-advanced' }
    'LogonRequired'                                       = @{ Text = 'Suggested Dispatch (default true): activities are only committed when the resource is logged on.'; Basis = 'KB: 08-pso-advanced' }
    'NextDayCommit'                                       = @{ Text = 'Suggested Dispatch (default false): allow commit suggestions for the next shift.'; Basis = 'KB: 08-pso-advanced' }
    'TimeBeforeShiftStart'                                = @{ Text = 'Suggested Dispatch (default 30 min): how far before a shift starts SDS begins making suggestions for it.'; Basis = 'KB: 08-pso-advanced' }
    'LogoffUncommit'                                      = @{ Text = 'Default 30. A status threshold, not a yes/no: when a resource logs off, activities at this status or lower are uncommitted (30 = Committed, so Accepted jobs at 40 survive). 0 disables.'; Basis = 'KB: 08-pso-advanced' }
    'EndShiftHorizon'                                     = @{ Text = 'Default 1 hour. If the resource has not logged off this long after shift end, the shift is treated as finished and LogoffUncommit is applied.'; Basis = 'KB: 08-pso-advanced' }
    'NextDayCommitBuffer'                                 = @{ Text = 'Default 0 hours. Start committing the next shift activities this long before the current shift ends.'; Basis = 'KB: 08-pso-advanced' }
    'ShiftsInFuture'                                      = @{ Text = 'Default 0. Number of future shifts, beyond the current one, to make commit suggestions for.'; Basis = 'KB: 08-pso-advanced' }
    'CommitToAvailabilityWindow'                          = @{ Text = 'When true and SDS suggests committing an activity inside an availability window, date_time_earliest is set to the window start (or shift start, whichever is later), so the activity cannot be scheduled before the window opens.'; Basis = 'KB: 08-pso-advanced, 25-workbench-scheduling-ui' }
    'MaximumVisitCostReliefProportion'                    = @{ Text = 'Appointment booking, basic displacement (default 0.5): slightly inflates the offer value to account for the low-priority activities that were near the appointed activity in the route. 0 disables the adjustment.'; Basis = 'KB: 10-appointment-booking' }
    'MinimumAppointmentSlotsPerThread'                    = @{ Text = 'Parallelism for non-blocking appointment requests. Default -1 = auto-determine thread count; 0 forces strictly sequential processing.'; Basis = 'KB: 10-appointment-booking' }
    'MaximumLinkedCallAttempts'                           = @{ Text = 'Default and recommended 3. How hard appointment booking tries to allocate all linked calls together; higher finds more offers but is slower per offer.'; Basis = 'KB: 10-appointment-booking' }
    'AppointmentFallbackProfileId'                        = @{ Text = 'Set on the dataset active profile (e.g. DEFAULT) to point at a fallback profile, typically StraightLine travel, used in the appointment offer phase to speed up very high frequency booking.'; Basis = 'KB: 10-appointment-booking' }
    'UseFallbackProfileForAppointmentSummary'             = @{ Text = 'Default false. Whether the appointment summary/confirmation step also uses the fallback profile. False means the summary validates against real HTM travel.'; Basis = 'KB: 10-appointment-booking' }
    'ScheduleWindowStartBuffer'                           = @{ Text = 'New in 6.17.0.102. Mitigates offered appointments becoming unavailable because time elapsed between the offer and its acceptance.'; Basis = 'Release notes: 28-release-notes-watchlist' }
    'TravelCalculationMethod'                             = @{ Text = 'KB name for the HTM vs Straight Line choice. The parameter ID actually used in these sys files is TravelCalculationOption (see that entry).'; Basis = 'KB: 17-travel' }
    'LocationAddTime'                                     = @{ Text = 'Extra time added on arrival at a location before work can start (e.g. parking, walking to the site). Can also be set on a Location or Location Type.'; Basis = 'KB: 17-travel' }
    'SpeedFactor'                                         = @{ Text = 'Multiplier on journey time, e.g. 1.5 makes expected journeys 50% longer. Set on Resource or Resource Type; the parameter is the final fallback (there is no shift-level override).'; Basis = 'KB: 17-travel, 00-property-inheritance' }
    'RoutingMaximumDistanceMeters'                        = @{ Text = 'Default 4000 m. If the straight-line distance between two points is within this, a routing calculation is attempted. 0 disables routing. Do not alter unless advised by IFS product development.'; Basis = 'KB: 17-travel' }
    'RoutingMaximumSearchMetersMultiplier'                = @{ Text = 'Default 2 (= 8000 m). The routing calculation aborts and falls back to HTM if the destination is not reached within this distance. Do not alter unless advised by IFS.'; Basis = 'KB: 17-travel' }
    'RoutingCalculator'                                   = @{ Text = 'Default TravelAnalyser. InProcess = each service loads its own routing data (reloaded on every LOAD); TravelAnalyser = the Travel Analyser service centralises routing data and shares it across LOADs and datasets. Do not alter unless advised by IFS.'; Basis = 'KB: 17-travel' }
    'CommittedActivitiesProfileThreshold'                 = @{ Text = 'Maximum time window (from current schedule time) in which the committed-activities profile applies. Beyond it, activities revert to the default travel method. Keep it tight: real-time travel for distant activities wastes performance.'; Basis = 'KB: 17-travel' }
    'RealTimeTravelRefreshCacheFrequency'                 = @{ Text = 'How often real-time travel information refreshes. Lower = fresher data but more calls to the routing service.'; Basis = 'KB: 17-travel' }
    'RealTimeTravelAvoidTolls'                            = @{ Text = 'Avoid tolls in real-time routes (can combine with RealTimeTravelAvoidHighways).'; Basis = 'KB: 17-travel' }
    'RealTimeTravelAvoidHighways'                         = @{ Text = 'Avoid highways/motorways in real-time routes.'; Basis = 'KB: 17-travel' }
    'RealTimeTravelMaxCallsPerSecond'                     = @{ Text = 'Throttle on calls to the real-time routing service; use it to stop TomTom 403 errors.'; Basis = 'KB: 18-pso-workbench-administration' }
    'IsochroneTravelTimes'                                = @{ Text = 'Which travel-time bands are available for isochrones (added or removed via the UI).'; Basis = 'KB: 17-travel' }
    'MaxSpeedMPS'                                         = @{ Text = 'One of three speed settings (with MinSpeedMPS and RateOfSpeedIncrease) to check when the Travel Analyser isochrone preview disc looks very different from the real isochrone.'; Basis = 'KB: 17-travel' }
    'MinSpeedMPS'                                         = @{ Text = 'See MaxSpeedMPS: speed setting behind the isochrone preview disc.'; Basis = 'KB: 17-travel' }
    'RateOfSpeedIncrease'                                 = @{ Text = 'See MaxSpeedMPS: speed setting behind the isochrone preview disc.'; Basis = 'KB: 17-travel' }
    'UseOutlinedIsochrones'                               = @{ Text = 'Switches isochrones from filled/translucent to outlined (the in-panel checkbox does the same).'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'MinPasswordLength'                                   = @{ Text = 'Default 8. Minimum password length.'; Basis = 'KB: 18-pso-workbench-administration' }
    'MinPasswordCharacterCategories'                      = @{ Text = 'Required number of character categories (upper, lower, numeric, symbolic, titlecase) in a password.'; Basis = 'KB: 18-pso-workbench-administration' }
    'PasswordCannotContainId'                             = @{ Text = 'Default false. Password may not contain the user ID.'; Basis = 'KB: 18-pso-workbench-administration' }
    'PasswordCannotContainNamePart'                       = @{ Text = 'Default false. Password may not contain part of the user name.'; Basis = 'KB: 18-pso-workbench-administration' }
    'PasswordValidityPeriod'                              = @{ Text = 'Default 0 (never expires). Maximum password age.'; Basis = 'KB: 18-pso-workbench-administration' }
    'PasswordExpiryWarningPeriod'                         = @{ Text = 'Default 0 (no warning). How long before expiry the user is warned.'; Basis = 'KB: 18-pso-workbench-administration' }
    'MaxRetainedPasswords'                                = @{ Text = 'Default 0. Number of prior passwords checked for reuse. Both this and OldPasswordRetention must be above 0 for reuse checks to work.'; Basis = 'KB: 18-pso-workbench-administration' }
    'OldPasswordRetention'                                = @{ Text = 'Default 0. How long old passwords are kept for reuse checks. Both this and MaxRetainedPasswords must be above 0 for reuse checks to work.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ActiveDirectoryDomain'                               = @{ Text = 'Set to the Active Directory domain to enable AD login (part of the AD setup steps).'; Basis = 'KB: 18-pso-workbench-administration' }
    'JwtExpiryDays'                                       = @{ Text = 'Default 1 day. Workbench login token expiry; consider raising for long WISE simulation runs. In a child organisation it must be changed at head-organisation level.'; Basis = 'KB: 27-workbench-wise-simulation' }
    'JwtLogoutHours'                                      = @{ Text = 'Default 2 hours. Workbench session logout time; consider raising for long WISE simulation runs. In a child organisation it must be changed at head-organisation level.'; Basis = 'KB: 27-workbench-wise-simulation' }
    'EnableSwaggerUI'                                     = @{ Text = 'Shows the interactive Swagger UI on the RESTful Gateway (hidden by default).'; Basis = 'KB: 22-pso-restful-gateway' }
    'WorkbenchHostURL'                                    = @{ Text = 'Allow-list of calling host URLs for embedded Workbench use (head organisation only, once per hosting server). Multiple URLs space-separated; * wildcards allowed. For IFS Cloud it holds the callback URL.'; Basis = 'KB: 22-pso-restful-gateway, 11-dispatch-console, 13-admin-troubleshooting' }
    'DefaultDatasetId'                                    = @{ Text = 'Dataset used by the RESTful Gateway when Input_Reference has no dataset_id (default "Default").'; Basis = 'KB: 22-pso-restful-gateway' }
    'MaximumODataRecords'                                 = @{ Text = 'Default 100,000. Maximum rows returned by an OData query ($top); hitting it returns an @odata.nextLink for paging.'; Basis = 'KB: 22-pso-restful-gateway' }
    'LogStatistics'                                       = @{ Text = 'Shows CPU and memory per server in the System view. Off by default because it has a performance cost.'; Basis = 'KB: 18-pso-workbench-administration' }
    'AutoScale'                                           = @{ Text = 'Reflects the install configuration for information only. Changing it does not affect scaling (edit the Helm values instead).'; Basis = 'KB: 18-pso-workbench-administration' }
    'DatasetAvailability'                                 = @{ Text = 'Dataset high availability: 0 = no draining (test or single-instance only); 1 (default) = new instance is ready before the old one stops; 2 or more = HA mode with N-1 replicas alongside the primary (needs proportionally more hardware).'; Basis = 'KB: 18-pso-workbench-administration' }
    'AutoReload'                                          = @{ Text = 'Enables the automatic daily reload: a fresh initial LOAD (FULL source extraction) at AutoReloadTimeOfDay for datasets whose Source_Data is tagged source_extraction_method=AUTOMATIC.'; Basis = 'KB: 18-pso-workbench-administration' }
    'AutoReloadTimeOfDay'                                 = @{ Text = 'Time of day for the automatic reload (default midnight).'; Basis = 'KB: 18-pso-workbench-administration' }
    'InactiveSessionLogoutSeconds'                        = @{ Text = 'Default 1 hour. Inactivity period before a session is ended.'; Basis = 'KB: 18-pso-workbench-administration' }
    'SessionRetentionSeconds'                             = @{ Text = 'Default 1 week. How long ended-session data is kept.'; Basis = 'KB: 18-pso-workbench-administration' }
    'CheckForEventsSeconds'                               = @{ Text = 'Default 10 min. Poll frequency for session and other checks; also governs how often organisation-deletion readiness is checked.'; Basis = 'KB: 18-pso-workbench-administration' }
    'RunSystemTest'                                       = @{ Text = 'Default false. Enables the periodic system smoke test (needs an Admin Service restart).'; Basis = 'KB: 18-pso-workbench-administration' }
    'RunSystemTestSeconds'                                = @{ Text = 'Default 3600 (1 hour). How often the system test runs.'; Basis = 'KB: 18-pso-workbench-administration' }
    'SystemTestDatasetId'                                 = @{ Text = 'Default SystemSmokeTest. Dataset used by the system test.'; Basis = 'KB: 18-pso-workbench-administration' }
    'SystemTestTimeLimit'                                 = @{ Text = 'Default 2 min. Maximum time allowed for the system test.'; Basis = 'KB: 18-pso-workbench-administration' }
    'SystemTestAllocationTypes'                           = @{ Text = 'Default 27 (all: DSE + appointment + dispatch + travel). Adjust down if a component is not in use, e.g. 11 if there is no Travel Analyser.'; Basis = 'KB: 18-pso-workbench-administration' }
    'CheckLicenceFrequency'                               = @{ Text = 'Default hourly. How often the licence is checked (Default organisation only; all other licence parameters are per organisation).'; Basis = 'KB: 18-pso-workbench-administration' }
    'LicenceNotificationEmails'                           = @{ Text = 'Default false. Enables licence notification emails.'; Basis = 'KB: 18-pso-workbench-administration' }
    'LicenceNotificationEmailAddresses'                   = @{ Text = 'Comma-separated recipients for licence notifications (empty by default).'; Basis = 'KB: 18-pso-workbench-administration' }
    'LicenceNotificationLanguageId'                       = @{ Text = 'Language for licence notifications (empty by default).'; Basis = 'KB: 18-pso-workbench-administration' }
    'SetDatasetsToReactiveOnInput'                        = @{ Text = 'Converts DYNAMIC and APPOINTMENT datasets to REACTIVE on LOAD (used with auto-scaling so DSE can scale to zero).'; Basis = 'KB: 18-pso-workbench-administration' }
    'BackgroundProcessReactiveTasks'                      = @{ Text = 'Must be false to allow DSE scale-to-zero when REACTIVE datasets exist (the installer sets it when auto-scaling is enabled).'; Basis = 'KB: 18-pso-workbench-administration' }
    'LoadBalancingSensitivityFactor'                      = @{ Text = 'Load balancing between instances: higher = a dataset is less likely to move.'; Basis = 'KB: 18-pso-workbench-administration' }
    'LoadBalancingExclusionStart'                         = @{ Text = 'Start of the period when load balancing is disabled (pairs with LoadBalancingExclusionEnd).'; Basis = 'KB: 18-pso-workbench-administration' }
    'LoadBalancingExclusionEnd'                           = @{ Text = 'End of the period when load balancing is disabled (pairs with LoadBalancingExclusionStart).'; Basis = 'KB: 18-pso-workbench-administration' }
    'UnregisterApplicationOnFailCount'                    = @{ Text = 'Default 3. Failed pings before a component is unregistered from the system DB.'; Basis = 'KB: 18-pso-workbench-administration' }
    'CheckRequiredServices'                               = @{ Text = 'Compares active services against expected roles and logs an event on mismatch. On by default for Multi-Role Azure; on-prem needs true.'; Basis = 'KB: 18-pso-workbench-administration' }
    'CheckRequiredServicesFrequency'                      = @{ Text = 'Default 10 min. How often the required-service check runs (restart the Admin Service after changing).'; Basis = 'KB: 18-pso-workbench-administration' }
    'AutoRestartServices'                                 = @{ Text = 'With an SSM installed on every server, lets the Admin Service ask SSM to restart unresponsive services.'; Basis = 'KB: 18-pso-workbench-administration' }
    'CheckForNewApplicationsSeconds'                      = @{ Text = 'Default 20 s. How often system status data is refreshed.'; Basis = 'KB: 18-pso-workbench-administration' }
    'SystemStatusEventWindow'                             = @{ Text = 'Default 10 min. Recording window for system status events.'; Basis = 'KB: 18-pso-workbench-administration' }
    'DeleteOrganisationGracePeriod'                       = @{ Text = 'Default 1 hour. Child organisations marked for deletion are removed after this period (cascades through all databases).'; Basis = 'KB: 18-pso-workbench-administration' }
    'EmptyRoutesExceptionStart'                           = @{ Text = 'Default 12 hours. Empty Route exception only considers shifts intersecting the timeline or starting within this window.'; Basis = 'KB: 18-pso-workbench-administration' }
    'DoOnLocationAllowRevisits'                           = @{ Text = 'The KB only mentions it as the condition for the Location Partially Allocated exception (relevant when false). By name, whether a do-on-location location can be revisited.'; Basis = 'Inference (not in KB)' }
    'ActivityAvailabilityLeeway'                          = @{ Text = 'Leeway applied when the #available field is worked out for an activity.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ResourceAvailabilityLeeWayMinutes'                   = @{ Text = 'Leeway (minutes) applied when the #available field is worked out for a resource.'; Basis = 'KB: 18-pso-workbench-administration' }
    'AuditRetentionDays'                                  = @{ Text = 'Default 30 days. How long audit rows are kept before the Schedule Archiving Service purges them.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ReportingRetentionDays'                              = @{ Text = 'Default 90 days. How long reporting data is kept in the archive.'; Basis = 'KB: 18-pso-workbench-administration' }
    'InputRetentionSeconds'                               = @{ Text = 'Default 1 day. Resource data ages out of the Scheduling DB this long after it stops appearing in input data.'; Basis = 'KB: 18-pso-workbench-administration' }
    'OutputRetentionSeconds'                              = @{ Text = 'Default 1 day. Output data retention in the Scheduling DB.'; Basis = 'KB: 18-pso-workbench-administration' }
    'KeepDeletedARPDataHours'                             = @{ Text = 'Default 7 days. ARP soft-deletes first, then permanently purges after this period.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ManageARPDataTimeOfDay'                              = @{ Text = 'Time of day at which ARP data is managed and purged.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ManageARPDataOptions'                                = @{ Text = 'Controls how ARP data is managed and purged.'; Basis = 'KB: 18-pso-workbench-administration' }
    'ARPAccessHistoryRetention'                           = @{ Text = 'New in 6.14.0.27. By name, how long ARP access history is retained.'; Basis = 'Inference (not in KB)' }
    'AutomatedSnapshotExpiryPeriod'                       = @{ Text = 'Default 7 days. Automated snapshots expire after this period.'; Basis = 'KB: 19-schedule-archive' }
    'CheckForChangesSeconds'                              = @{ Text = 'Default every 5 minutes. How often reporting dimension and fact data updates as scheduling datasets change.'; Basis = 'KB: 19-schedule-archive' }
    'CheckForUpdateSeconds'                               = @{ Text = 'Archive (ARC) parameter, default 10 minutes. How often ARC picks up timetable and timetable-usage changes.'; Basis = 'KB: 19-schedule-archive' }
    'AutoDurationEnabledByDefault'                        = @{ Text = 'SIM parameter. Default value of auto_duration_enabled when it is not specified on the activity or activity type.'; Basis = 'KB: 19-schedule-archive' }
    'MinimumNumberOfSamplesForActivityDurationEstimate'   = @{ Text = 'Archiving Service. Minimum sample count before a grouping gets a duration estimate. Recommended 10 or more.'; Basis = 'KB: 19-schedule-archive' }
    'AutoDurationConfidenceLevelThreshold'                = @{ Text = 'SIM parameter. Minimum confidence required before an estimated duration is applied.'; Basis = 'KB: 19-schedule-archive' }
    'AutoDurationReloadEstimatesPeriod'                   = @{ Text = 'SIM parameter. How often SIM reloads the estimate table.'; Basis = 'KB: 19-schedule-archive' }
    'DatasetTypesToProcess'                               = @{ Text = 'DSE parameter. Must be SEGMENT to use distributed scheduling (DST).'; Basis = 'KB: 23-architecture-and-sizing' }
    'SegmentDatasetOption'                                = @{ Text = 'Set true to enable segmented datasets. Typically needs multiple DSE instances even for one logical dataset.'; Basis = 'KB: 23-architecture-and-sizing' }
    'ResegmentationFactor'                                = @{ Text = 'Default 50%. Re-segmentation triggers when schedulable activity volume changes by more than this (or the current segmentation becomes invalid).'; Basis = 'KB: 23-architecture-and-sizing' }
    'BackgroundOptimiseAppointmentWindow'                 = @{ Text = 'DSE keeps optimising the appointment-window portion as a background task once broadcast targets are met and there is no pending input; set false to switch that off.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeedDynamicDatasetOption'                            = @{ Text = 'Set true to feed a dynamic dataset from a larger pool (with FeederMechanism).'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederMechanism'                                     = @{ Text = 'Default EXCLUSION. How activities are selected to feed the DSE dataset.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederMinimumActivities'                             = @{ Text = 'Default 1,000. The feeder only activates above this number of activities.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeedActivityInJeopardyThreshold'                     = @{ Text = 'Activities whose jeopardy deadline is within this threshold must be sent to the DSE dataset.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeedActivitiesMultiplier'                            = @{ Text = 'Default 0.5. Target ratio of unallocated to allocated activities in the DSE dataset (8,000 allocated targets 4,000 unallocated).'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederMaximumInitialActivities'                      = @{ Text = 'Default 10,000. Caps the first batch sent. Recommended: set close to the expected final DSE dataset size.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederActivityValueImportanceWeighting'              = @{ Text = 'Advanced tuning weight for the feeder (activity value). Consult IFS before changing.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederValuePerHourImportanceWeighting'               = @{ Text = 'Advanced tuning weight for the feeder (value per hour). Consult IFS before changing.'; Basis = 'KB: 23-architecture-and-sizing' }
    'FeederProximityImportanceWeighting'                  = @{ Text = 'Advanced tuning weight for the feeder (proximity). Consult IFS before changing.'; Basis = 'KB: 23-architecture-and-sizing' }
    'AggregationTargetActivityCount'                      = @{ Text = 'Default 10,000. Activity aggregation applies when the dataset is at least this large.'; Basis = 'KB: 23-architecture-and-sizing' }
    'AggregationMaximumMergeDistanceMetres'               = @{ Text = 'Default 2,000 m. Activities are only merged if within this distance of each other.'; Basis = 'KB: 23-architecture-and-sizing' }
    'AggregationMaximumActivityDuration'                  = @{ Text = 'Default 2 hours. Aggregated duration may not exceed this.'; Basis = 'KB: 23-architecture-and-sizing' }
    'GanttShowRegions'                                    = @{ Text = 'Sets the organisation default for showing the regions tree on the resource Gantt.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'DragAndDropCommitOption'                             = @{ Text = 'What a Gantt drag and drop does: 1 Commit To Resource (validates first unless ManChaNoValidation), 2 Open Manual Changes (pre-fills the panel), 3 Validate and Commit To Resource (always validates). Drag and drop is not enabled by default.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'TreatMembershipWithNoCategoryAsUtilised'             = @{ Text = 'Whether a resource membership with no category counts as utilised or shows as free time in the Resource Planner (also affects the utilisation search slider).'; Basis = 'KB: 26-workbench-planning-ui' }
    'PSWBucketDefaultColour'                              = @{ Text = 'Gantt colour of a bucket shift where all activities are allocated (uncommitted).'; Basis = 'KB: 20-resource-capacity-management' }
    'PSWBucketCommittedColour'                            = @{ Text = 'Gantt colour of a bucket shift where all activities are committed or higher.'; Basis = 'KB: 20-resource-capacity-management' }
    'PSWBucketCompletedColour'                            = @{ Text = 'Gantt colour of a bucket shift for completed work.'; Basis = 'KB: 20-resource-capacity-management' }
    'PSWBreakDefaultColourExplicit'                       = @{ Text = 'Gantt colour of explicit breaks.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'PSWCommittedBreakColour'                             = @{ Text = 'Break colour by event status when Colour by Status is on: committed break.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'PSWBreakOnColour'                                    = @{ Text = 'Break colour by event status when Colour by Status is on: break on.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'PSWBreakOffColour'                                   = @{ Text = 'Break colour by event status when Colour by Status is on: break off.'; Basis = 'KB: 25-workbench-scheduling-ui' }
    'ShiftImportDatesRow'                                 = @{ Text = 'Excel shift import: the row that holds the dates.'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportResourceIdColumn'                         = @{ Text = 'Excel shift import: the column that holds resource IDs.'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportDivisionIdColumn'                         = @{ Text = 'Excel shift import: optional column that holds division IDs.'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportRowsStart'                                = @{ Text = 'Excel shift import: first data row; import stops at the first row with no resource id.'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportColumnsStart'                             = @{ Text = 'Excel shift import: first data column; import stops at the first column with no date.'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportSheetName'                                = @{ Text = 'Excel shift import: worksheet name (optional, defaults to the first worksheet).'; Basis = 'KB: 26-workbench-planning-ui' }
    'ShiftImportCheckExistingCharacter'                   = @{ Text = 'Excel shift import: character used in a cell to check against an existing shift (default *).'; Basis = 'KB: 26-workbench-planning-ui' }
    'WISEAutoGenerateResourceTypes'                       = @{ Text = 'Default off. If on, WISE auto-creates resource types around the most common skill combinations (up to 6 distinct skills; with more skills, or none, one resource type holding every skill).'; Basis = 'KB: 27-workbench-wise-simulation' }
    'TranslateLabelFormats'                               = @{ Text = 'Translates the {} expressions inside label formats for display and editing (free text is not translated), so a filter written in one language works in another.'; Basis = 'KB: 27-workbench-wise-simulation' }
    'iSWBMapType'                                         = @{ Text = 'Map provider for the Workbench, in caps (e.g. BING, HERE). Also called MapProvider.'; Basis = 'KB: 18-pso-workbench-administration' }
    'iSWBMapKey'                                          = @{ Text = 'Map provider key; billable transactions are charged to it, so group users appropriately. Also called MapKey.'; Basis = 'KB: 18-pso-workbench-administration' }
    'TurnByTurnDirectionsProvider'                        = @{ Text = 'Turn-by-turn provider (currently BING or HERE only). Each request is a separate billable transaction.'; Basis = 'KB: 18-pso-workbench-administration' }
    'TurnByTurnDirectionsKey'                             = @{ Text = 'Key for the turn-by-turn provider (billable per request).'; Basis = 'KB: 18-pso-workbench-administration' }
}

# Name-pattern hints, used ONLY when a parameter has no specific definition above.
$DefPatterns = @(
    @{ Pattern = '^PSW\w*Colou?r$';            Text = 'Workbench colour setting for a Gantt or UI element (the name says which). Not individually described in the KB.' },
    @{ Pattern = '^ShiftImport';               Text = 'Setting for the Resource Planner Excel shift import mapping (rows, columns, sheet, characters). See the Import Shift Data section of the planning UI guide.' },
    @{ Pattern = '^RealTimeTravel';            Text = 'Real-time travel (online routing service) setting. See the Real Time Travel section of the travel guide.' },
    @{ Pattern = '^Routing';                   Text = 'Routing calculation setting. The KB says not to alter routing parameters unless advised by IFS product development.' },
    @{ Pattern = '^Password';                  Text = 'Password policy setting for PSO user logins.' },
    @{ Pattern = '^OpenId|Claim$';             Text = 'OpenID Connect single sign-on setting.' },
    @{ Pattern = '^Feed';                      Text = 'Feeder setting for dynamic datasets (large datasets). Consult IFS before changing.' },
    @{ Pattern = '^Aggregation';               Text = 'Activity aggregation setting for very large datasets.' },
    @{ Pattern = '^Licen[cs]e';                Text = 'Licence checking or notification setting.' },
    @{ Pattern = '^(RunSystemTest|SystemTest)'; Text = 'System smoke test setting.' },
    @{ Pattern = '^AutoDuration';              Text = 'Automatic activity duration estimation setting (SIM / archive).' },
    @{ Pattern = '^LoadBalancing';             Text = 'Dataset load balancing setting.' },
    @{ Pattern = '^Gateway|^OData';            Text = 'RESTful Gateway setting.' },
    @{ Pattern = '^TurnByTurn|^iSWBMap';       Text = 'Workbench map / turn-by-turn provider setting (billable provider keys).' },
    @{ Pattern = 'Seconds$|Minutes$|Hours$|Days$|Period$'; Text = 'A time setting (interval, retention or timeout); the name gives the unit. Not individually described in the KB.' }
)
foreach ($dp in $DefPatterns) { $dp['Basis'] = 'Inference (name pattern)' }

# Optional user-maintained definitions (overrides the table above).
$UserDefsFile = Join-Path $ScriptDir 'ParamDefinitions.csv'
if (Test-Path -LiteralPath $UserDefsFile) {
    foreach ($ud in (Import-Csv -LiteralPath $UserDefsFile)) {
        if (-not [string]::IsNullOrWhiteSpace($ud.Parameter) -and -not [string]::IsNullOrWhiteSpace($ud.Definition)) {
            $ub = 'User-supplied'
            if (-not [string]::IsNullOrWhiteSpace($ud.Basis)) { $ub = $ud.Basis }
            $Defs[$ud.Parameter.Trim()] = @{ Text = $ud.Definition; Basis = $ub }
        }
    }
}

# Tables we read row-by-row (everything else is only counted).
$KeepTables = @(
    'Profile', 'Profile_Parameter', 'Org_Schedule_Exception_Type', 'Org_Schedule_Exc_Type_Data',
    'Groups', 'Group_Permission', 'Organisation', 'Organisation_Permission', 'Organisation_List',
    'List', 'List_Entry', 'Entry', 'Terminology_Organisation',
    'Travel_Time_Profile', 'Travel_Time_Weighting', 'Travel_Time_Polygon', 'Polygon',
    'System_Version'
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
$Absent   = '(absent)'
$NullText = '(null)'
$SpMark   = [string][char]0x2423
$Inv      = [System.Globalization.CultureInfo]::InvariantCulture
$Utf8Bom  = New-Object System.Text.UTF8Encoding($true)

function Write-Colored([string]$text, [string]$color = 'Gray', [switch]$NoNewline, [string]$bg = '') {
    if ($NoColor) { Write-Host $text -NoNewline:$NoNewline }
    elseif ($bg -ne '') { Write-Host $text -ForegroundColor $color -BackgroundColor $bg -NoNewline:$NoNewline }
    else { Write-Host $text -ForegroundColor $color -NoNewline:$NoNewline }
}

# Green = identical to baseline, Yellow = some rows differ, Red = more than a quarter differ.
function Get-SeverityColor([int]$diffs, [int]$total) {
    if ($diffs -eq 0) { return 'Green' }
    if ($total -gt 0 -and ($diffs / $total) -gt 0.25) { return 'Red' }
    return 'Yellow'
}

function New-CsMap { New-Object System.Collections.Hashtable ([System.StringComparer]::Ordinal) }

function Resolve-InputPath([string]$p) {
    if ([System.IO.Path]::IsPathRooted($p)) { return $p }
    return [System.IO.Path]::GetFullPath((Join-Path $ScriptDir $p))
}

function Get-Field($row, [string]$name) {
    if ($row.ContainsKey($name)) { return $row[$name] }
    return $null
}

function Get-ParamDef([string]$name) {
    if ($Defs.ContainsKey($name)) { return $Defs[$name] }
    foreach ($dp in $DefPatterns) {
        if ($name -match $dp.Pattern) { return @{ Text = $dp.Text; Basis = $dp.Basis } }
    }
    return $null
}

function Format-Text($s) {
    if ($null -eq $s) { return $NullText }
    $s = [string]$s
    if ($s.Length -eq 0) { return $NullText }
    $lead  = $s.Length - $s.TrimStart(' ').Length
    $trail = $s.Length - $s.TrimEnd(' ').Length
    if ($lead -eq 0 -and $trail -eq 0) { return $s }
    return (($SpMark * $lead) + $s.Trim(' ') + ($SpMark * $trail))
}

function Get-Status($vals) {
    for ($i = 1; $i -lt $vals.Count; $i++) {
        if ($vals[$i] -cne $vals[0]) { return 'DIFF' }
    }
    return 'Same'
}

function Get-AllowEdit($row) {
    $a = 'F'; $e = 'F'
    if ((Get-Field $row 'allow') -eq 'true') { $a = 'T' }
    if ((Get-Field $row 'allow_edit') -eq 'true') { $e = 'T' }
    return ('{0} / {1}' -f $a, $e)
}

function Read-SysFile([string]$path) {
    $doc = New-Object System.Xml.XmlDocument
    $doc.PreserveWhitespace = $true      # keep values that are only spaces
    $doc.Load($path)
    $tables = @{}
    $counts = @{}
    foreach ($node in $doc.DocumentElement.ChildNodes) {
        if ($node.NodeType -ne [System.Xml.XmlNodeType]::Element) { continue }
        $t = $node.LocalName
        if ($counts.ContainsKey($t)) { $counts[$t] = $counts[$t] + 1 } else { $counts[$t] = 1 }
        if ($KeepTables -contains $t) {
            $row = @{}
            foreach ($f in $node.ChildNodes) {
                if ($f.NodeType -eq [System.Xml.XmlNodeType]::Element) { $row[$f.LocalName] = $f.InnerText }
            }
            if (-not $tables.ContainsKey($t)) { $tables[$t] = New-Object System.Collections.ArrayList }
            [void]$tables[$t].Add($row)
        }
    }
    return @{ Tables = $tables; Counts = $counts }
}

function Get-Rows([string]$en, [string]$table) {
    # Streams the row hashtables one by one (or nothing). Wrap calls in @() when a count is needed.
    if ($Data[$en].Tables.ContainsKey($table)) { $Data[$en].Tables[$table] }
}

function New-Tab([string]$title, [string]$file, [string[]]$keyHeaders, [string[]]$extraHeaders) {
    return @{
        Title = $title; File = $file; KeyHeaders = $keyHeaders; ExtraHeaders = $extraHeaders
        Rows = (New-Object System.Collections.ArrayList)
    }
}

function Add-TabRow($tab, [string[]]$keyVals, [string[]]$cellVals, $extra) {
    $row = @{ KeyVals = $keyVals; CellVals = $cellVals; Extra = $extra; Status = (Get-Status $cellVals) }
    [void]$tab.Rows.Add($row)
}

function Get-ProfileOrder([string]$p) {
    if ($p -ceq 'DEFAULT') { return 0 }
    if ($p -ceq 'CONTRACTORS') { return 1 }
    return 2
}

function ConvertTo-VersionSafe([string]$s) {
    $v = $null
    if (-not [string]::IsNullOrEmpty($s) -and [version]::TryParse($s.Trim(), [ref]$v)) { return $v }
    return (New-Object System.Version 0, 0)
}

function Format-Utc($d) {
    if ($null -eq $d) { return '' }
    return ([datetime]$d).ToString('yyyy-MM-dd HH:mm', $Inv)
}

function ConvertTo-IntSafe([string]$s) {
    $n = 0
    if ([int]::TryParse($s, [ref]$n)) { return $n }
    return 0
}

# ---------------------------------------------------------------------------
# Load environments
# ---------------------------------------------------------------------------
$EnvNames = @()
$Skipped = @()
$Data = @{}
foreach ($e in $Environments) {
    $path = Resolve-InputPath $e.Path
    if (-not (Test-Path -LiteralPath $path)) {
        Write-Warning ("Skipping {0}: file not found ({1})" -f $e.Name, $path)
        $Skipped += $e.Name
        continue
    }
    Write-Colored 'Reading ' 'DarkGray' -NoNewline
    Write-Colored ('{0,-6} ' -f $e.Name) 'Cyan' -NoNewline
    Write-Colored $path 'Gray'
    try { $loaded = Read-SysFile $path }
    catch {
        Write-Warning ("Skipping {0}: could not read file ({1})" -f $e.Name, $_.Exception.Message)
        $Skipped += $e.Name
        continue
    }
    $loaded['Path'] = $path
    $Data[$e.Name] = $loaded
    $EnvNames += $e.Name
}
if ($EnvNames.Count -lt 2) { throw 'Need at least two environment files to compare.' }

$BaseIdx = 0
if (-not [string]::IsNullOrEmpty($Baseline)) {
    $baseFound = $false
    for ($i = 0; $i -lt $EnvNames.Count; $i++) { if ($EnvNames[$i] -ieq $Baseline) { $BaseIdx = $i; $baseFound = $true } }
    if (-not $baseFound) { Write-Warning ("Baseline '{0}' was not loaded; using {1} instead." -f $Baseline, $EnvNames[0]) }
} elseif ($EnvNames[0] -ine $Environments[0].Name) {
    Write-Warning ("Default baseline '{0}' was not loaded; using {1} instead." -f $Environments[0].Name, $EnvNames[0])
}
$BaseName = $EnvNames[$BaseIdx]
Write-Colored 'Baseline: ' 'DarkGray' -NoNewline
Write-Colored $BaseName 'Green'
Write-Colored 'Comparing: ' 'DarkGray' -NoNewline
Write-Colored ($EnvNames -join ', ') 'Gray'
if ($Skipped.Count -gt 0) {
    Write-Colored 'Skipped:   ' 'DarkGray' -NoNewline
    Write-Colored ($Skipped -join ', ') 'Yellow'
}

# ---------------------------------------------------------------------------
# PSO version (from System_Version): current version, last upgrade, last patch
#   Current version = highest version_id recorded (compared numerically, not as text).
#   Last upgrade    = most recent row of type "Upgrade from ...".
#   Last patch      = most recent row of type "Update ..." (Update / Update data).
#   Timestamps are UTC as stored in the file.
# ---------------------------------------------------------------------------
$Now = (Get-Date).ToUniversalTime()
$VerInfo = @{}
foreach ($en in $EnvNames) {
    $recs = @()
    foreach ($r in (Get-Rows $en 'System_Version')) {
        $stamp = $null
        $sv = Get-Field $r 'version_stamp'
        if (-not [string]::IsNullOrEmpty($sv)) {
            $dto = [DateTimeOffset]::MinValue
            if ([DateTimeOffset]::TryParse($sv, $Inv, [System.Globalization.DateTimeStyles]::AssumeUniversal, [ref]$dto)) { $stamp = $dto.UtcDateTime }
        }
        $vid = Get-Field $r 'version_id'
        $recs += [pscustomobject]@{
            Version = $vid; VObj = (ConvertTo-VersionSafe $vid)
            Type = (Get-Field $r 'version_type'); User = (Get-Field $r 'version_user'); Stamp = $stamp
        }
    }
    $info = @{
        HasData = ($recs.Count -gt 0); Current = ''; CurrentV = $null; Release = ''
        UpFrom = ''; UpTo = ''; UpDate = $null; DaysSinceUp = $null
        PatchVer = ''; PatchDate = $null; Created = $null; Status = 'UNKNOWN'; History = @()
    }
    if ($recs.Count -gt 0) {
        $curRec = @($recs | Sort-Object VObj)[-1]
        $info['Current'] = [string]$curRec.Version
        $info['CurrentV'] = $curRec.VObj
        $info['Release'] = ('{0}.{1}' -f $curRec.VObj.Major, $curRec.VObj.Minor)
        $ups = @($recs | Where-Object { $_.Type -like 'Upgrade from*' -and $null -ne $_.Stamp } | Sort-Object Stamp)
        if ($ups.Count -gt 0) {
            $lu = $ups[-1]
            $info['UpTo'] = [string]$lu.Version
            $info['UpFrom'] = ([string]$lu.Type -replace '^Upgrade from\s*', '')
            $info['UpDate'] = $lu.Stamp
            $info['DaysSinceUp'] = [int][math]::Floor(($Now - $lu.Stamp).TotalDays)
        }
        $pts = @($recs | Where-Object { $_.Type -like 'Update*' -and $null -ne $_.Stamp } | Sort-Object Stamp)
        if ($pts.Count -gt 0) { $lp = $pts[-1]; $info['PatchVer'] = [string]$lp.Version; $info['PatchDate'] = $lp.Stamp }
        $cr = @($recs | Where-Object { $_.Type -eq 'Creation' -and $null -ne $_.Stamp })
        if ($cr.Count -gt 0) { $info['Created'] = $cr[0].Stamp }
        $info['History'] = @($recs | Sort-Object Stamp)
    }
    $VerInfo[$en] = $info
}
$VerKnown = @($EnvNames | Where-Object { $VerInfo[$_]['HasData'] })
$VerMissing = @($EnvNames | Where-Object { -not $VerInfo[$_]['HasData'] })
$VerMaxV = $null; $VerMax = ''
foreach ($en in $VerKnown) {
    if ($null -eq $VerMaxV -or $VerInfo[$en]['CurrentV'] -gt $VerMaxV) { $VerMaxV = $VerInfo[$en]['CurrentV']; $VerMax = $VerInfo[$en]['Current'] }
}
$VerDistinct = New-CsMap
$VerReleases = New-CsMap
foreach ($en in $VerKnown) {
    $VerDistinct[$VerInfo[$en]['Current']] = $true
    $VerReleases[$VerInfo[$en]['Release']] = $true
}
$VersionComparable = ($VerKnown.Count -ge 2)
$VersionMismatch = ($VersionComparable -and $VerDistinct.Count -gt 1)
foreach ($en in $VerKnown) {
    if ($VerInfo[$en]['CurrentV'] -eq $VerMaxV) { $VerInfo[$en]['Status'] = 'LATEST' } else { $VerInfo[$en]['Status'] = 'BEHIND' }
}
$VerKindText = ''
if ($VersionMismatch) {
    if ($VerReleases.Count -gt 1) {
        $rp = @()
        foreach ($en in $VerKnown) { $rp += ('{0} {1}' -f $en, $VerInfo[$en]['Release']) }
        $VerKindText = 'Different RELEASES: ' + ($rp -join ', ')
    } else {
        $VerKindText = 'Same release (' + $VerInfo[$VerKnown[0]]['Release'] + '), different patch builds.'
    }
}

function Write-VersionConsole {
    Write-Host ''
    Write-Colored 'PSO version (from System_Version, dates in UTC)' 'Cyan'
    Write-Colored ('{0,-8}{1,-13}{2,-9}{3,-38}{4,-28}{5,5}' -f 'Env', 'Current', 'Status', 'Last upgrade', 'Last patch', 'Days') 'White'
    foreach ($en in $EnvNames) {
        $vi = $VerInfo[$en]
        $up = '-'
        if ($null -ne $vi['UpDate']) { $up = ('{0} ({1} -> {2})' -f $vi['UpDate'].ToString('yyyy-MM-dd', $Inv), $vi['UpFrom'], $vi['UpTo']) }
        $pt = '-'
        if ($null -ne $vi['PatchDate']) { $pt = ('{0} on {1}' -f $vi['PatchVer'], $vi['PatchDate'].ToString('yyyy-MM-dd', $Inv)) }
        $days = ''
        if ($null -ne $vi['DaysSinceUp']) { $days = [string]$vi['DaysSinceUp'] }
        $cur = $vi['Current']
        if ([string]::IsNullOrEmpty($cur)) { $cur = '?' }
        $c = 'DarkGray'
        if ($vi['Status'] -eq 'LATEST') { $c = 'Green' } elseif ($vi['Status'] -eq 'BEHIND') { $c = 'Red' }
        Write-Colored ('{0,-8}{1,-13}{2,-9}{3,-38}{4,-28}{5,5}' -f $en, $cur, $vi['Status'], $up, $pt, $days) $c
    }
    if ($VersionMismatch) {
        Write-Host ''
        Write-Colored '  !!  PSO VERSION MISMATCH - THE ENVIRONMENTS ARE NOT ALL ON THE SAME VERSION  !!  ' 'White' -bg 'Red'
        Write-Colored ('  Highest version: {0}' -f $VerMax) 'Red'
        foreach ($en in $EnvNames) {
            if ($VerInfo[$en]['Status'] -eq 'BEHIND') { Write-Colored ('  {0} is on {1}  (behind {2})' -f $en, $VerInfo[$en]['Current'], $VerMax) 'Red' }
        }
        Write-Colored ('  ' + $VerKindText) 'Red'
    } elseif ($VersionComparable) {
        Write-Host ''
        Write-Colored ('  All {0} environments are on the same PSO version: {1}  ' -f $VerKnown.Count, $VerMax) 'Black' -bg 'Green'
    }
    if ($VerMissing.Count -gt 0) { Write-Warning ('No System_Version data in: ' + ($VerMissing -join ', ') + ' (version unknown)') }
}
Write-VersionConsole

# ---------------------------------------------------------------------------
# 1. Parameters
# ---------------------------------------------------------------------------
$ApiKeyValues = New-CsMap
$P = @{}
foreach ($en in $EnvNames) {
    $m = New-CsMap
    foreach ($r in (Get-Rows $en 'Profile_Parameter')) {
        $pid_ = Get-Field $r 'parameter_id'
        $k = (Get-Field $r 'profile_id') + [char]31 + $pid_ + [char]31 + (Get-Field $r 'parameter_application_type_id')
        $v = Get-Field $r 'parameter_value'
        if ($pid_ -match '(?i)key' -and -not [string]::IsNullOrEmpty($v)) {
            $ApiKeyValues[$v] = $true
            $m[$k] = '[API key set]'
        } else {
            $m[$k] = Format-Text $v
        }
    }
    $P[$en] = $m
}
$keySeen = New-CsMap
$keyList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($k in $P[$en].Keys) {
        if (-not $keySeen.ContainsKey($k)) { $keySeen[$k] = $true; [void]$keyList.Add($k) }
    }
}
$sortedKeys = @($keyList | Sort-Object `
    @{ Expression = { Get-ProfileOrder ($_.Split([char]31)[0]) } }, `
    @{ Expression = { $_.Split([char]31)[0] } }, `
    @{ Expression = { $_.Split([char]31)[1] } })

$TabParams = New-Tab 'Parameters' '01_Parameters.csv' @('Profile', 'Parameter', 'AppType') @('Definition')
foreach ($k in $sortedKeys) {
    $parts = $k.Split([char]31)
    $vals = @()
    foreach ($en in $EnvNames) {
        if ($P[$en].ContainsKey($k)) { $vals += $P[$en][$k] } else { $vals += $Absent }
    }
    $extra = @{ Definition = '' }
    $pdef = Get-ParamDef ($parts[1])
    if ($null -ne $pdef) {
        $dtext = $pdef.Text
        if ($pdef.Basis -like 'Inference*') { $dtext = '[Inferred] ' + $dtext }
        $extra['Definition'] = $dtext
    }
    Add-TabRow $TabParams @($parts[0], $parts[1], $parts[2]) $vals $extra
}

# ---------------------------------------------------------------------------
# 2. Exception types
# ---------------------------------------------------------------------------
$X = @{}
$XDesc = New-CsMap
foreach ($en in $EnvNames) {
    $m = New-CsMap
    foreach ($r in (Get-Rows $en 'Org_Schedule_Exception_Type')) {
        $k = (Get-Field $r 'profile_id') + [char]31 + (Get-Field $r 'schedule_exception_type_id')
        $on = 'off'
        if ((Get-Field $r 'active') -eq 'true') { $on = 'on' }
        $act = Get-Field $r 'activation_setting'
        if ([string]::IsNullOrEmpty($act)) { $act = '-' }
        $m[$k] = ('{0} / attn {1} / act {2}' -f $on, (Get-Field $r 'attention_value'), $act)
        $d = Get-Field $r 'description'
        if (-not [string]::IsNullOrEmpty($d) -and -not $XDesc.ContainsKey($k)) { $XDesc[$k] = $d }
    }
    $X[$en] = $m
}
$keySeen = New-CsMap
$keyList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($k in $X[$en].Keys) {
        if (-not $keySeen.ContainsKey($k)) { $keySeen[$k] = $true; [void]$keyList.Add($k) }
    }
}
$sortedKeys = @($keyList | Sort-Object `
    @{ Expression = { Get-ProfileOrder ($_.Split([char]31)[0]) } }, `
    @{ Expression = { $_.Split([char]31)[0] } }, `
    @{ Expression = { ConvertTo-IntSafe ($_.Split([char]31)[1]) } })

$TabExc = New-Tab 'Exception Types' '02_ExceptionTypes.csv' @('Profile', 'TypeId', 'Description') @()
foreach ($k in $sortedKeys) {
    $parts = $k.Split([char]31)
    $vals = @()
    foreach ($en in $EnvNames) {
        if ($X[$en].ContainsKey($k)) { $vals += $X[$en][$k] } else { $vals += $Absent }
    }
    $desc = ''
    if ($XDesc.ContainsKey($k)) { $desc = $XDesc[$k] }
    Add-TabRow $TabExc @($parts[0], $parts[1], $desc) $vals $null
}

# ---------------------------------------------------------------------------
# 3. Groups + group permissions (group names matched case-insensitively)
# ---------------------------------------------------------------------------
$Spell = New-CsMap                # lower(group) -> map env -> actual id
$GroupRow = @{}                   # env -> map lower(group) -> row
foreach ($en in $EnvNames) {
    $gm = New-CsMap
    foreach ($r in (Get-Rows $en 'Groups')) {
        $id = Get-Field $r 'id'
        $lc = $id.ToLowerInvariant()
        $gm[$lc] = $r
        if (-not $Spell.ContainsKey($lc)) { $Spell[$lc] = @{} }
        $Spell[$lc][$en] = $id
    }
    $GroupRow[$en] = $gm
}
function Get-GroupDisplay([string]$lc) {
    if ($Spell.ContainsKey($lc)) {
        if ($Spell[$lc].ContainsKey($BaseName)) { return $Spell[$lc][$BaseName] }
        foreach ($en in $EnvNames) { if ($Spell[$lc].ContainsKey($en)) { return $Spell[$lc][$en] } }
    }
    return $lc
}
function Test-SpellingDiffers([string]$lc) {
    if (-not $Spell.ContainsKey($lc)) { return $false }
    $distinct = New-CsMap
    foreach ($en in $Spell[$lc].Keys) { $distinct[$Spell[$lc][$en]] = $true }
    return ($distinct.Count -gt 1)
}

$GP = @{}
$GpCount = @{}
foreach ($en in $EnvNames) {
    $m = New-CsMap
    $c = New-CsMap
    foreach ($r in (Get-Rows $en 'Group_Permission')) {
        $lc = (Get-Field $r 'group_id').ToLowerInvariant()
        $k = $lc + [char]31 + (Get-Field $r 'permission_id')
        $m[$k] = Get-AllowEdit $r
        if ($c.ContainsKey($lc)) { $c[$lc] = $c[$lc] + 1 } else { $c[$lc] = 1 }
    }
    $GP[$en] = $m
    $GpCount[$en] = $c
}
$keySeen = New-CsMap
$keyList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($k in $GP[$en].Keys) {
        if (-not $keySeen.ContainsKey($k)) { $keySeen[$k] = $true; [void]$keyList.Add($k) }
    }
}
$sortedKeys = @($keyList | Sort-Object `
    @{ Expression = { (Get-GroupDisplay ($_.Split([char]31)[0])).ToLowerInvariant() } }, `
    @{ Expression = { $_.Split([char]31)[1] } })

$TabGP = New-Tab 'Group Permissions' '03_GroupPermissions.csv' @('Group', 'Permission', 'Note') @()
foreach ($k in $sortedKeys) {
    $parts = $k.Split([char]31)
    $vals = @()
    foreach ($en in $EnvNames) {
        if ($GP[$en].ContainsKey($k)) { $vals += $GP[$en][$k] } else { $vals += $Absent }
    }
    $note = ''
    if (Test-SpellingDiffers ($parts[0])) { $note = 'group spelling differs across environments' }
    Add-TabRow $TabGP @((Get-GroupDisplay ($parts[0])), $parts[1], $note) $vals $null
}

$TabGroups = New-Tab 'Groups' '04_Groups.csv' @('Group', 'Attribute') @()
$groupKeys = @($Spell.Keys | Sort-Object { (Get-GroupDisplay $_).ToLowerInvariant() })
foreach ($lc in $groupKeys) {
    $disp = Get-GroupDisplay $lc
    $v1 = @(); $v2 = @(); $v3 = @(); $v4 = @()
    foreach ($en in $EnvNames) {
        if ($GroupRow[$en].ContainsKey($lc)) {
            $r = $GroupRow[$en][$lc]
            $v1 += (Get-Field $r 'id')
            $v2 += (Format-Text (Get-Field $r 'group_id'))
            $v3 += (Format-Text (Get-Field $r 'description'))
        } else {
            $v1 += $Absent; $v2 += $Absent; $v3 += $Absent
        }
        $cnt = 0
        if ($GpCount[$en].ContainsKey($lc)) { $cnt = $GpCount[$en][$lc] }
        $v4 += [string]$cnt
    }
    Add-TabRow $TabGroups @($disp, 'Present as') $v1 $null
    Add-TabRow $TabGroups @($disp, 'Parent group') $v2 $null
    Add-TabRow $TabGroups @($disp, 'Description') $v3 $null
    Add-TabRow $TabGroups @($disp, 'Permission rows, allow + deny (count)') $v4 $null
}

# ---------------------------------------------------------------------------
# 4. Organisation permissions
# ---------------------------------------------------------------------------
$OP = @{}
foreach ($en in $EnvNames) {
    $m = New-CsMap
    foreach ($r in (Get-Rows $en 'Organisation_Permission')) { $m[(Get-Field $r 'permission_id')] = Get-AllowEdit $r }
    $OP[$en] = $m
}
$keySeen = New-CsMap
$keyList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($k in $OP[$en].Keys) { if (-not $keySeen.ContainsKey($k)) { $keySeen[$k] = $true; [void]$keyList.Add($k) } }
}
$TabOrgPerm = New-Tab 'Org Permissions' '05_OrgPermissions.csv' @('Permission') @()
foreach ($k in @($keyList | Sort-Object)) {
    $vals = @()
    foreach ($en in $EnvNames) { if ($OP[$en].ContainsKey($k)) { $vals += $OP[$en][$k] } else { $vals += $Absent } }
    Add-TabRow $TabOrgPerm @($k) $vals $null
}

# ---------------------------------------------------------------------------
# 5. Org-default list layouts (matched on content: list IDs are per-environment GUIDs)
# ---------------------------------------------------------------------------
$OL = @{}
foreach ($en in $EnvNames) {
    $ent = New-CsMap
    foreach ($r in (Get-Rows $en 'Entry')) { $ent[(Get-Field $r 'id')] = (Get-Field $r 'label') }
    $le = New-CsMap
    foreach ($r in (Get-Rows $en 'List_Entry')) {
        $lid = Get-Field $r 'list_id'
        if (-not $le.ContainsKey($lid)) { $le[$lid] = New-Object System.Collections.ArrayList }
        [void]$le[$lid].Add($r)
    }
    $out = New-CsMap
    foreach ($o in (Get-Rows $en 'Organisation_List')) {
        $labels = New-Object System.Collections.ArrayList
        $lid = Get-Field $o 'list_id'
        if ($le.ContainsKey($lid)) {
            $ordered = @($le[$lid] | Sort-Object { ConvertTo-IntSafe (Get-Field $_ 'sequence') })
            foreach ($r in $ordered) {
                $eid = Get-Field $r 'entry_id'
                $lbl = $null
                if ($null -ne $eid -and $ent.ContainsKey($eid)) { $lbl = $ent[$eid] }
                [void]$labels.Add((Format-Text $lbl))
            }
        }
        $out[(Get-Field $o 'list_type_id')] = $labels
    }
    $OL[$en] = $out
}
$listTypes = New-CsMap
foreach ($en in $EnvNames) { foreach ($k in $OL[$en].Keys) { $listTypes[$k] = $true } }
$TabLists = New-Tab 'Lists' '06_Lists.csv' @('OrgDefaultList', 'Position') @()
foreach ($lt in @($listTypes.Keys | Sort-Object { ConvertTo-IntSafe $_ })) {
    $max = 0
    foreach ($en in $EnvNames) {
        if ($OL[$en].ContainsKey($lt) -and $OL[$en][$lt].Count -gt $max) { $max = $OL[$en][$lt].Count }
    }
    for ($i = 0; $i -lt $max; $i++) {
        $vals = @()
        foreach ($en in $EnvNames) {
            if ($OL[$en].ContainsKey($lt) -and $i -lt $OL[$en][$lt].Count) { $vals += $OL[$en][$lt][$i] } else { $vals += '(none)' }
        }
        Add-TabRow $TabLists @(('List type ' + $lt), [string]($i + 1)) $vals $null
    }
}

# ---------------------------------------------------------------------------
# 6. Travel
# ---------------------------------------------------------------------------
$Inv = [System.Globalization.CultureInfo]::InvariantCulture
$TabTravel = New-Tab 'Travel' '07_Travel.csv' @('Item') @()
$travelParams = @(
    @('DEFAULT', 'TravelCalculationOption'), @('CommittedActivitiesProfile', 'TravelCalculationOption'),
    @('STRAIGHTLINE', 'TravelCalculationOption'), @('DEFAULT', 'RealTimeTravelProvider'),
    @('CommittedActivitiesProfile', 'RealTimeTravelProvider'), @('DEFAULT', 'TravelTimeProfileId'),
    @('CommittedActivitiesProfile', 'TravelTimeProfileId'), @('STRAIGHTLINE', 'TravelTimeProfileId'),
    @('DEFAULT', 'AllowSplitTravel'), @('DEFAULT', 'HierarchicalDatabaseMatrixId')
)
$TravelInfo = @{}
foreach ($en in $EnvNames) {
    $pm = New-CsMap
    foreach ($r in (Get-Rows $en 'Profile_Parameter')) {
        $pm[(Get-Field $r 'profile_id') + [char]31 + (Get-Field $r 'parameter_id')] = (Get-Field $r 'parameter_value')
    }
    $d = [ordered]@{}
    foreach ($tp in $travelParams) {
        $key = $tp[0] + [char]31 + $tp[1]
        $label = 'Param: ' + $tp[0] + ' / ' + $tp[1]
        if ($pm.ContainsKey($key)) { $d[$label] = (Format-Text ($pm[$key])) } else { $d[$label] = $Absent }
    }
    $ttp  = @(Get-Rows $en 'Travel_Time_Profile')
    $tw  = @(Get-Rows $en 'Travel_Time_Weighting')
    $tpo = @(Get-Rows $en 'Travel_Time_Polygon')
    $poly = @(Get-Rows $en 'Polygon')
    $ids = @($ttp | ForEach-Object { Get-Field $_ 'id' } | Sort-Object)
    if ($ids.Count -gt 0) { $d['Travel time profile IDs'] = ($ids -join ', ') } else { $d['Travel time profile IDs'] = '(none)' }
    $d['Weighting rows'] = [string]$tw.Count
    $zones = New-CsMap
    foreach ($w in $tw) {
        $z = Get-Field $w 'time_zone'
        if ([string]::IsNullOrEmpty($z)) { $z = '(none)' }
        $zones[$z] = $true
    }
    if ($zones.Count -gt 0) { $d['Weighting time zone(s)'] = (@($zones.Keys | Sort-Object) -join ', ') } else { $d['Weighting time zone(s)'] = '(none)' }
    $d['Polygons defined'] = [string]$poly.Count
    $d['Polygon-weighting links'] = [string]$tpo.Count
    $ws = @()
    foreach ($t in $tpo) {
        $wv = Get-Field $t 'weighting'
        $n = 0.0
        if (-not [string]::IsNullOrEmpty($wv) -and [double]::TryParse($wv, [System.Globalization.NumberStyles]::Float, $Inv, [ref]$n)) { $ws += $n }
    }
    if ($ws.Count -gt 0) {
        $mm = $ws | Measure-Object -Minimum -Maximum
        $d['Polygon weighting range'] = ($mm.Minimum.ToString('G', $Inv) + ' - ' + $mm.Maximum.ToString('G', $Inv))
    } else { $d['Polygon weighting range'] = '(none)' }
    if ($poly.Count -gt 0) {
        $pids = @($poly | ForEach-Object { Get-Field $_ 'id' } | Sort-Object)
        $sample = (@($pids | Select-Object -First 2) -join ', ')
        if ($poly.Count -gt 2) { $sample = $sample + ' ...' }
        $d['Polygon IDs (sample)'] = $sample
    } else { $d['Polygon IDs (sample)'] = '(none)' }
    $TravelInfo[$en] = $d
}
foreach ($item in @($TravelInfo[$EnvNames[0]].Keys)) {
    $vals = @()
    foreach ($en in $EnvNames) { $vals += [string]$TravelInfo[$en][$item] }
    Add-TabRow $TabTravel @($item) $vals $null
}

# ---------------------------------------------------------------------------
# 7. Profiles and other
# ---------------------------------------------------------------------------
$TabOther = New-Tab 'Profiles & Other' '08_ProfilesAndOther.csv' @('Item', 'Key', 'Notes') @()
# Profiles
$profSeen = New-CsMap; $profList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) { foreach ($r in (Get-Rows $en 'Profile')) { $id = Get-Field $r 'id'; if (-not $profSeen.ContainsKey($id)) { $profSeen[$id] = $true; [void]$profList.Add($id) } } }
foreach ($id in @($profList | Sort-Object)) {
    $vals = @(); $desc = ''
    foreach ($en in $EnvNames) {
        $found = $null
        foreach ($r in (Get-Rows $en 'Profile')) { if ((Get-Field $r 'id') -ceq $id) { $found = $r } }
        if ($null -eq $found) { $vals += $Absent } else {
            $vals += (Format-Text (Get-Field $found 'profile_type'))
            $dd = Get-Field $found 'description'
            if ($desc -eq '' -and -not [string]::IsNullOrEmpty($dd)) { $desc = $dd }
        }
    }
    Add-TabRow $TabOther @('Profile', $id, $desc) $vals $null
}
# Terminology
$termSeen = New-CsMap; $termList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) { foreach ($r in (Get-Rows $en 'Terminology_Organisation')) { $t = Get-Field $r 'term'; if (-not $termSeen.ContainsKey($t)) { $termSeen[$t] = $true; [void]$termList.Add($t) } } }
foreach ($t in @($termList | Sort-Object)) {
    $vals = @()
    foreach ($en in $EnvNames) {
        $found = $null
        foreach ($r in (Get-Rows $en 'Terminology_Organisation')) { if ((Get-Field $r 'term') -ceq $t) { $found = $r } }
        if ($null -eq $found) { $vals += $Absent } else { $vals += (Format-Text (Get-Field $found 'alias_cap_singular')) }
    }
    Add-TabRow $TabOther @('Terminology', $t, 'alias (capitalised singular)') $vals $null
}
# Exception type data
$etSeen = New-CsMap; $etList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($r in (Get-Rows $en 'Org_Schedule_Exc_Type_Data')) {
        $k = (Get-Field $r 'profile_id') + [char]31 + (Get-Field $r 'schedule_exception_type_id') + [char]31 + (Get-Field $r 'sequence')
        if (-not $etSeen.ContainsKey($k)) { $etSeen[$k] = $true; [void]$etList.Add($k) }
    }
}
foreach ($k in @($etList | Sort-Object)) {
    $parts = $k.Split([char]31)
    $vals = @()
    foreach ($en in $EnvNames) {
        $found = $null
        foreach ($r in (Get-Rows $en 'Org_Schedule_Exc_Type_Data')) {
            $kk = (Get-Field $r 'profile_id') + [char]31 + (Get-Field $r 'schedule_exception_type_id') + [char]31 + (Get-Field $r 'sequence')
            if ($kk -ceq $k) { $found = $r }
        }
        if ($null -eq $found) { $vals += $Absent } else { $vals += ('active=' + (Get-Field $found 'active')) }
    }
    Add-TabRow $TabOther @('Exc type data', ('{0} / type {1} / seq {2}' -f $parts[0], $parts[1], $parts[2]), '') $vals $null
}
# Organisation record
foreach ($fld in @('account_id', 'name', 'status', 'organisation_id')) {
    $vals = @()
    foreach ($en in $EnvNames) {
        $rows = @(Get-Rows $en 'Organisation')
        if ($rows.Count -gt 0 -and $rows[0].ContainsKey($fld)) { $vals += (Format-Text ($rows[0][$fld])) } else { $vals += $Absent }
    }
    Add-TabRow $TabOther @('Organisation', $fld, '') $vals $null
}

$Tabs = @($TabParams, $TabExc, $TabGP, $TabGroups, $TabOrgPerm, $TabLists, $TabTravel, $TabOther)

# ---------------------------------------------------------------------------
# Table counts
# ---------------------------------------------------------------------------
$ScopeMap = @{
    'Users' = 'Not compared - user accounts (excluded per request)'
    'System_Version' = 'Reported separately - see the PSO version section (not row-compared)'
    'Application_Data' = 'Not compared - per-user saved filters and screen settings'
    'User_Application_Data' = 'Not compared - links users to their saved filters and settings'
    'User_Custom_List' = 'Not compared - per-user custom list layouts'
    'User_External_Task' = 'Not compared - external tasks linked to individual users'
    'User_Group' = 'Not compared - which users belong to which groups'
    'User_List' = 'Not compared - per-user list/column layouts'
    'User_Object' = 'Not compared - objects linked to individual users'
    'User_Parameter' = 'Not compared - per-user parameter values'
    'User_Permission' = 'Not compared - per-user permission overrides'
    'Profile_Parameter' = 'Compared: Parameters'; 'Org_Schedule_Exception_Type' = 'Compared: Exception Types'
    'Group_Permission' = 'Compared: Group Permissions'; 'Groups' = 'Compared: Groups'
    'Organisation_Permission' = 'Compared: Org Permissions'; 'Organisation_List' = 'Compared: Lists'
    'List' = 'Compared: Lists (org defaults only)'; 'List_Entry' = 'Compared: Lists (org defaults only)'
    'Entry' = 'Compared: Lists (org defaults only)'
    'Travel_Time_Profile' = 'Compared: Travel'; 'Travel_Time_Weighting' = 'Compared: Travel'
    'Travel_Time_Polygon' = 'Compared: Travel'; 'Polygon' = 'Compared: Travel'
    'Profile' = 'Compared: Profiles & Other'; 'Terminology_Organisation' = 'Compared: Profiles & Other'
    'Org_Schedule_Exc_Type_Data' = 'Compared: Profiles & Other'; 'Organisation' = 'Compared: Profiles & Other'
}
$allTables = New-CsMap
foreach ($en in $EnvNames) { foreach ($t in $Data[$en].Counts.Keys) { $allTables[$t] = $true } }
$CountRows = @()
foreach ($t in @($allTables.Keys | Sort-Object)) {
    $o = [ordered]@{ Table = $t }
    foreach ($en in $EnvNames) {
        $c = 0
        if ($Data[$en].Counts.ContainsKey($t)) { $c = $Data[$en].Counts[$t] }
        $o[$en] = $c
    }
    $scope = 'Not compared - nothing meaningful to compare'
    if ($ScopeMap.ContainsKey($t)) { $scope = $ScopeMap[$t] }
    $o['Scope'] = $scope
    $CountRows += [pscustomobject]$o
}

# ---------------------------------------------------------------------------
# Tally
# ---------------------------------------------------------------------------
$Tally = @()
foreach ($tab in $Tabs) {
    $total = $tab.Rows.Count
    $diffs = @{}
    foreach ($en in $EnvNames) { $diffs[$en] = 0 }
    $notSame = 0
    foreach ($r in $tab.Rows) {
        if ($r.Status -eq 'DIFF') { $notSame++ }
        for ($i = 0; $i -lt $EnvNames.Count; $i++) {
            if ($i -ne $BaseIdx -and $r.CellVals[$i] -cne $r.CellVals[$BaseIdx]) { $diffs[$EnvNames[$i]]++ }
        }
    }
    $Tally += @{ Name = $tab.Title; Total = $total; Diffs = $diffs; NotSame = $notSame }
}

# ---------------------------------------------------------------------------
# Write CSVs
# ---------------------------------------------------------------------------
$outPath = Resolve-InputPath $OutputDir
if (-not (Test-Path -LiteralPath $outPath)) { [void](New-Item -ItemType Directory -Path $outPath) }

function Write-CsvFile([string]$path, $objs) {
    $lines = @($objs | ConvertTo-Csv -NoTypeInformation)
    [System.IO.File]::WriteAllLines($path, $lines, $Utf8Bom)
}

foreach ($tab in $Tabs) {
    $objs = @()
    foreach ($r in $tab.Rows) {
        $o = [ordered]@{}
        for ($i = 0; $i -lt $tab.KeyHeaders.Count; $i++) { $o[$tab.KeyHeaders[$i]] = $r.KeyVals[$i] }
        for ($i = 0; $i -lt $EnvNames.Count; $i++) { $o[$EnvNames[$i]] = $r.CellVals[$i] }
        $o['Status'] = $r.Status
        foreach ($h in $tab.ExtraHeaders) { $o[$h] = $r.Extra[$h] }
        $objs += [pscustomobject]$o
    }
    if ($objs.Count -gt 0) { Write-CsvFile (Join-Path $outPath $tab.File) $objs }
}
Write-CsvFile (Join-Path $outPath '09_TableCounts.csv') $CountRows

$tallyObjs = @()
foreach ($t in $Tally) {
    $o = [ordered]@{ Area = $t.Name; RowsCompared = $t.Total }
    foreach ($en in $EnvNames) {
        if ($en -ne $BaseName) { $o[('DiffFrom_' + $BaseName + '_' + $en)] = $t.Diffs[$en] }
    }
    $o['NotIdenticalAcrossAll'] = $t.NotSame
    $tallyObjs += [pscustomobject]$o
}
Write-CsvFile (Join-Path $outPath 'Summary_Tally.csv') $tallyObjs

# PSO versions
$verObjs = @()
foreach ($en in $EnvNames) {
    $vi = $VerInfo[$en]
    $verObjs += [pscustomobject][ordered]@{
        Environment = $en; CurrentVersion = $vi['Current']; Release = $vi['Release']; Status = $vi['Status']
        LastUpgradeUtc = (Format-Utc ($vi['UpDate'])); UpgradedFrom = $vi['UpFrom']; UpgradedTo = $vi['UpTo']
        LastPatchVersion = $vi['PatchVer']; LastPatchUtc = (Format-Utc ($vi['PatchDate']))
        DaysSinceUpgrade = $vi['DaysSinceUp']; CreatedUtc = (Format-Utc ($vi['Created']))
    }
}
Write-CsvFile (Join-Path $outPath '10_Versions.csv') $verObjs
$histObjs = @()
foreach ($en in $EnvNames) {
    foreach ($h in $VerInfo[$en]['History']) {
        $histObjs += [pscustomobject][ordered]@{ Environment = $en; StampUtc = (Format-Utc ($h.Stamp)); Version = $h.Version; Type = $h.Type; User = $h.User }
    }
}
if ($histObjs.Count -gt 0) { Write-CsvFile (Join-Path $outPath '11_VersionHistory.csv') $histObjs }

# Template of parameters that still have no specific definition (fill in, save as ParamDefinitions.csv)
$needDef = New-CsMap
foreach ($r in $TabParams.Rows) {
    $pn = $r.KeyVals[1]
    if (-not $Defs.ContainsKey($pn) -and -not $needDef.ContainsKey($pn)) {
        $hint = ''
        $pd = Get-ParamDef $pn
        if ($null -ne $pd) { $hint = $pd.Text }
        $needDef[$pn] = $hint
    }
}
if ($needDef.Count -gt 0) {
    $tpl = @()
    foreach ($pn in @($needDef.Keys | Sort-Object)) {
        $tpl += [pscustomobject][ordered]@{ Parameter = $pn; Definition = ''; Basis = ''; CurrentHint = $needDef[$pn] }
    }
    Write-CsvFile (Join-Path $outPath 'ParamDefinitions_Template.csv') $tpl
}

# ---------------------------------------------------------------------------
# HTML summary
# ---------------------------------------------------------------------------
function Get-HtmlText($s) { return [System.Net.WebUtility]::HtmlEncode([string]$s) }

function Get-CellHtml([string]$val, [int]$idx, $row) {
    $cls = @()
    if ($val -ceq $Absent -or $val -ceq $NullText -or $val -ceq '(none)') { $cls += 'muted' }
    if ($idx -ne $BaseIdx -and $val -cne $row.CellVals[$BaseIdx]) { $cls += 'diff' }
    $c = ''
    if ($cls.Count -gt 0) { $c = ' class="' + ($cls -join ' ') + '"' }
    return ('<td' + $c + '>' + (Get-HtmlText $val) + '</td>')
}

function Get-TabHtml($tab) {
    $sb = New-Object System.Text.StringBuilder
    $rows = @($tab.Rows | Where-Object { $ShowSameRowsInHtml -or $_.Status -eq 'DIFF' })
    [void]$sb.AppendLine('<table class="sortable"><thead><tr>')
    foreach ($h in $tab.KeyHeaders) { [void]$sb.AppendLine('<th>' + (Get-HtmlText $h) + '</th>') }
    foreach ($en in $EnvNames) {
        $suffix = ''
        if ($en -ceq $BaseName) { $suffix = ' (baseline)' }
        [void]$sb.AppendLine('<th>' + (Get-HtmlText ($en + $suffix)) + '</th>')
    }
    foreach ($h in $tab.ExtraHeaders) { [void]$sb.AppendLine('<th>' + (Get-HtmlText $h) + '</th>') }
    [void]$sb.AppendLine('</tr></thead><tbody>')
    foreach ($r in $rows) {
        [void]$sb.Append('<tr>')
        foreach ($kv in $r.KeyVals) { [void]$sb.Append('<td class="k">' + (Get-HtmlText $kv) + '</td>') }
        for ($i = 0; $i -lt $EnvNames.Count; $i++) { [void]$sb.Append((Get-CellHtml ($r.CellVals[$i]) $i $r)) }
        foreach ($h in $tab.ExtraHeaders) {
            $txt = $r.Extra[$h]
            if ($h -eq 'Definition' -and $txt.StartsWith('[Inferred]')) { [void]$sb.Append('<td class="muted">' + (Get-HtmlText $txt) + '</td>') }
            elseif ($txt -eq '' -and $h -eq 'Definition') { [void]$sb.Append('<td class="muted">(no definition yet)</td>') }
            else { [void]$sb.Append('<td>' + (Get-HtmlText $txt) + '</td>') }
        }
        [void]$sb.AppendLine('</tr>')
    }
    if ($rows.Count -eq 0) { [void]$sb.AppendLine('<tr><td colspan="99" class="muted">No differing rows.</td></tr>') }
    [void]$sb.AppendLine('</tbody></table>')
    return $sb.ToString()
}

# quick-read facts
$paramDiffs = @($TabParams.Rows | Where-Object { $_.Status -eq 'DIFF' })
$presenceDiffs = 0; $valueDiffs = 0
foreach ($r in $paramDiffs) {
    $hasAbs = $false
    foreach ($v in $r.CellVals) { if ($v -ceq $Absent) { $hasAbs = $true } }
    if ($hasAbs) { $presenceDiffs++ } else { $valueDiffs++ }
}
$missingDefs = @($paramDiffs | Where-Object { $_.Extra['Definition'] -eq '' } | ForEach-Object { $_.KeyVals[1] } | Sort-Object -Unique)
$groupPresence = @()
foreach ($r in $TabGroups.Rows) {
    if ($r.KeyVals[1] -eq 'Present as') {
        $missing = @()
        for ($i = 0; $i -lt $EnvNames.Count; $i++) { if ($r.CellVals[$i] -ceq $Absent) { $missing += $EnvNames[$i] } }
        if ($missing.Count -gt 0) { $groupPresence += ($r.KeyVals[0] + ' (missing in ' + ($missing -join ', ') + ')') }
    }
}
$excOnly = @{}
foreach ($en in $EnvNames) { $excOnly[$en] = 0 }
foreach ($r in $TabExc.Rows) {
    $present = @()
    for ($i = 0; $i -lt $EnvNames.Count; $i++) { if ($r.CellVals[$i] -cne $Absent) { $present += $i } }
    if ($present.Count -eq 1) { $excOnly[$EnvNames[$present[0]]]++ }
}

$sb = New-Object System.Text.StringBuilder
$css = @'
body{font-family:Arial,Helvetica,sans-serif;font-size:13px;margin:24px;color:#1a1a1a}
h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 8px;border-bottom:2px solid #1f3864;padding-bottom:3px}
.meta{color:#595959;font-size:12px;margin-bottom:12px}
table{border-collapse:collapse;margin:6px 0 14px;width:100%}
th{background:#1f3864;color:#fff;text-align:left;padding:5px 7px;font-size:12px;position:sticky;top:0}
td{border:1px solid #bfbfbf;padding:4px 7px;vertical-align:top;word-break:break-word}
td.diff{background:#ffe699} td.muted{color:#7f7f7f;font-style:italic}
td.k{word-break:normal;overflow-wrap:normal}
.vbanner{padding:14px 18px;font-size:18px;font-weight:bold;border-radius:4px;margin:8px 0 12px}
.vbanner small{display:block;font-size:13px;font-weight:normal;margin-top:4px}
.vbanner.bad{background:#c00000;color:#fff;border:3px solid #7f0000}
.vbanner.ok{background:#2e7d32;color:#fff}
.vbanner.warn{background:#ffe699;color:#1a1a1a}
td.vok{background:#c6efce;color:#006100;font-weight:bold} td.vbad{background:#f4b6b6;color:#9c0006;font-weight:bold}
table.sortable thead th{cursor:pointer;user-select:none}
table.sortable thead th:hover{background:#2f4a80}
table.sortable thead th[data-dir="asc"]::after{content:" \25B2";font-size:10px}
table.sortable thead th[data-dir="desc"]::after{content:" \25BC";font-size:10px}
table.tally td,table.tally th{text-align:center} table.tally td:first-child,table.tally th:first-child{text-align:left}
details{margin:8px 0} summary{cursor:pointer;font-weight:bold;padding:4px 0}
ul{margin:4px 0 4px 18px;padding:0} li{margin:2px 0}
.note{background:#f2f2f2;border-left:4px solid #1f3864;padding:6px 10px;margin:6px 0}
'@
$js = @'
(function () {
  function isNum(s) { return /^-?[0-9]+(\.[0-9]+)?%?$/.test(s); }
  function compare(a, b) {
    if (isNum(a) && isNum(b)) { return parseFloat(a) - parseFloat(b); }
    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
  }
  var tables = document.querySelectorAll('table.sortable');
  Array.prototype.forEach.call(tables, function (t) {
    var body = t.tBodies[0];
    if (!body) { return; }
    var heads = t.tHead.rows[0].cells;
    Array.prototype.forEach.call(body.rows, function (r, i) { r.setAttribute('data-i', i); });
    Array.prototype.forEach.call(heads, function (th, col) {
      th.title = 'Click to sort (click again to reverse, a third time to reset)';
      th.addEventListener('click', function () {
        var rows = Array.prototype.slice.call(body.rows);
        if (rows.length < 2 || rows[0].cells.length !== heads.length) { return; }
        var cur = th.getAttribute('data-dir');
        var next = cur === 'asc' ? 'desc' : (cur === 'desc' ? '' : 'asc');
        Array.prototype.forEach.call(heads, function (h) { h.removeAttribute('data-dir'); });
        if (next) { th.setAttribute('data-dir', next); }
        rows.sort(function (ra, rb) {
          var ia = Number(ra.getAttribute('data-i')), ib = Number(rb.getAttribute('data-i'));
          if (!next) { return ia - ib; }
          var c = compare(ra.cells[col].textContent.trim(), rb.cells[col].textContent.trim());
          if (c === 0) { return ia - ib; }
          return next === 'asc' ? c : -c;
        });
        rows.forEach(function (r) { body.appendChild(r); });
      });
    });
  });
})();
'@
[void]$sb.AppendLine('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>PSO environment comparison</title><style>')
[void]$sb.AppendLine($css)
[void]$sb.AppendLine('</style></head><body>')
[void]$sb.AppendLine('<h1>PSO system data comparison</h1>')
[void]$sb.AppendLine('<div class="meta">Generated ' + (Get-HtmlText (Get-Date -Format 'yyyy-MM-dd HH:mm')) + ' | Baseline: ' + (Get-HtmlText $BaseName) + ' | Amber cell = differs from baseline | Click a column header to sort</div>')

[void]$sb.AppendLine('<h2>PSO version</h2>')
if ($VersionMismatch) {
    $bl = @()
    foreach ($en in $EnvNames) { if ($VerInfo[$en]['Status'] -eq 'BEHIND') { $bl += ($en + ' is on ' + $VerInfo[$en]['Current']) } }
    [void]$sb.AppendLine('<div class="vbanner bad">NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION<small>Highest version: ' + (Get-HtmlText $VerMax) + '. Behind: ' + (Get-HtmlText ($bl -join '; ')) + '. ' + (Get-HtmlText $VerKindText) + '</small></div>')
} elseif ($VersionComparable) {
    [void]$sb.AppendLine('<div class="vbanner ok">All ' + $VerKnown.Count + ' environments are on the same PSO version: ' + (Get-HtmlText $VerMax) + '</div>')
} else {
    [void]$sb.AppendLine('<div class="vbanner warn">PSO version could not be compared: System_Version data is missing in one or more environments.</div>')
}
[void]$sb.AppendLine('<table><thead><tr><th>Environment</th><th>Current version</th><th>Status</th><th>Last upgrade (UTC)</th><th>Upgraded from -&gt; to</th><th>Last patch / update (UTC)</th><th>Days since upgrade</th><th>Created (UTC)</th></tr></thead><tbody>')
foreach ($en in $EnvNames) {
    $vi = $VerInfo[$en]
    $cls = ''
    if ($vi['Status'] -eq 'BEHIND') { $cls = ' class="vbad"' } elseif ($vi['Status'] -eq 'LATEST') { $cls = ' class="vok"' }
    $upTxt = '-'; $fromTo = '-'
    if ($null -ne $vi['UpDate']) { $upTxt = (Format-Utc ($vi['UpDate'])); $fromTo = $vi['UpFrom'] + ' -> ' + $vi['UpTo'] }
    $ptxt = '-'
    if ($null -ne $vi['PatchDate']) { $ptxt = $vi['PatchVer'] + ' on ' + (Format-Utc ($vi['PatchDate'])) }
    $dtxt = '-'
    if ($null -ne $vi['DaysSinceUp']) { $dtxt = [string]$vi['DaysSinceUp'] }
    $ctxt = '-'
    if ($null -ne $vi['Created']) { $ctxt = (Format-Utc ($vi['Created'])) }
    [void]$sb.AppendLine('<tr><td>' + (Get-HtmlText $en) + '</td><td' + $cls + '>' + (Get-HtmlText $vi['Current']) + '</td><td' + $cls + '>' + (Get-HtmlText $vi['Status']) + '</td><td>' + (Get-HtmlText $upTxt) + '</td><td>' + (Get-HtmlText $fromTo) + '</td><td>' + (Get-HtmlText $ptxt) + '</td><td>' + (Get-HtmlText $dtxt) + '</td><td>' + (Get-HtmlText $ctxt) + '</td></tr>')
}
[void]$sb.AppendLine('</tbody></table>')
[void]$sb.AppendLine('<div class="note">Taken from the System_Version table; all times are UTC. Current version = highest version recorded. Last upgrade = most recent "Upgrade from" record. Last patch = most recent "Update" record. The vendor-stamped SYSTEM_USER patch records carry the same timestamp in every environment, so they say nothing about when an environment was upgraded.</div>')
[void]$sb.AppendLine('<details><summary>Full version history (oldest first)</summary><table class="sortable"><thead><tr><th>Environment</th><th>When (UTC)</th><th>Version</th><th>Type</th><th>User</th></tr></thead><tbody>')
foreach ($en in $EnvNames) {
    foreach ($h in $VerInfo[$en]['History']) {
        [void]$sb.AppendLine('<tr><td>' + (Get-HtmlText $en) + '</td><td>' + (Get-HtmlText (Format-Utc ($h.Stamp))) + '</td><td>' + (Get-HtmlText $h.Version) + '</td><td>' + (Get-HtmlText $h.Type) + '</td><td>' + (Get-HtmlText $h.User) + '</td></tr>')
    }
}
[void]$sb.AppendLine('</tbody></table></details>')

[void]$sb.AppendLine('<h2>Environments</h2><table><thead><tr><th>Name</th><th>File</th><th>Size</th><th>OpenIdAuthority realm</th></tr></thead><tbody>')
foreach ($en in $EnvNames) {
    $realm = ''
    foreach ($r in $TabParams.Rows) {
        if ($r.KeyVals[0] -ceq 'DEFAULT' -and $r.KeyVals[1] -ceq 'OpenIdAuthority') {
            $realm = $r.CellVals[[array]::IndexOf($EnvNames, $en)]
            $realm = $realm.TrimEnd('/').Split('/')[-1]
        }
    }
    $fpath = $Data[$en].Path
    $size = '{0:N1} MB' -f ((Get-Item -LiteralPath $fpath).Length / 1MB)
    [void]$sb.AppendLine('<tr><td>' + (Get-HtmlText $en) + '</td><td>' + (Get-HtmlText $fpath) + '</td><td>' + (Get-HtmlText $size) + '</td><td>' + (Get-HtmlText $realm) + '</td></tr>')
}
[void]$sb.AppendLine('</tbody></table>')

[void]$sb.AppendLine('<h2>Scope</h2><div class="note">Compared: profile parameters, exception types, groups and group permissions, organisation permissions, org-default list layouts, travel-time setup, profiles, terminology, exception type data, organisation record.<br>Not compared: Users (as requested) and all per-user tables. System_Version is not row-compared either; it is summarised in the PSO version section instead. Per-user tables hold data tied to individual user accounts - each person''s saved filters, screen settings and list layouts, plus which groups, permissions and parameters are assigned to each user. They are left out because the set of users differs between environments, so comparing them would mostly show noise. This also means group membership (who is in which group) is not compared, only what each group is allowed to do.<br>List and polygon IDs are GUIDs that differ per environment, so lists are matched on content and polygons are summarised by count. Group names differing only by case are treated as one group. API key values are masked. Comparison is case- and whitespace-sensitive; a leading or trailing space is shown with a visible marker.</div>')

[void]$sb.AppendLine('<h2>Rows that differ from ' + (Get-HtmlText $BaseName) + '</h2><table class="tally"><thead><tr><th>Area</th><th>Rows compared</th>')
foreach ($en in $EnvNames) { if ($en -ne $BaseName) { [void]$sb.AppendLine('<th>' + (Get-HtmlText $en) + '</th>') } }
[void]$sb.AppendLine('<th>Not identical across all</th></tr></thead><tbody>')
$grandTotal = 0; $grandNot = 0; $grandDiff = @{}
foreach ($en in $EnvNames) { $grandDiff[$en] = 0 }
foreach ($t in $Tally) {
    [void]$sb.Append('<tr><td>' + (Get-HtmlText $t.Name) + '</td><td>' + $t.Total + '</td>')
    foreach ($en in $EnvNames) { if ($en -ne $BaseName) { [void]$sb.Append('<td>' + $t.Diffs[$en] + '</td>'); $grandDiff[$en] += $t.Diffs[$en] } }
    [void]$sb.AppendLine('<td>' + $t.NotSame + '</td></tr>')
    $grandTotal += $t.Total; $grandNot += $t.NotSame
}
[void]$sb.Append('<tr><td><b>Total</b></td><td><b>' + $grandTotal + '</b></td>')
foreach ($en in $EnvNames) { if ($en -ne $BaseName) { [void]$sb.Append('<td><b>' + $grandDiff[$en] + '</b></td>') } }
[void]$sb.AppendLine('<td><b>' + $grandNot + '</b></td></tr>')
[void]$sb.Append('<tr><td>Share of rows differing</td><td></td>')
foreach ($en in $EnvNames) {
    if ($en -ne $BaseName) {
        $pct = 0
        if ($grandTotal -gt 0) { $pct = [math]::Round(100.0 * $grandDiff[$en] / $grandTotal) }
        [void]$sb.Append('<td>' + $pct + '%</td>')
    }
}
[void]$sb.AppendLine('<td></td></tr></tbody></table>')

[void]$sb.AppendLine('<h2>Quick read</h2><ul>')
if ($VersionMismatch) { [void]$sb.AppendLine('<li><b>PSO versions differ between environments</b> (highest ' + (Get-HtmlText $VerMax) + '). See the PSO version section at the top.</li>') }
[void]$sb.AppendLine('<li>' + $paramDiffs.Count + ' parameter rows are not identical across all environments (' + $valueDiffs + ' with different values, ' + $presenceDiffs + ' set in some environments and absent in others).</li>')
if ($groupPresence.Count -gt 0) { [void]$sb.AppendLine('<li>Groups not present everywhere: ' + (Get-HtmlText ($groupPresence -join '; ')) + '.</li>') }
$onlyParts = @()
foreach ($en in $EnvNames) { if ($excOnly[$en] -gt 0) { $onlyParts += ($en + ' ' + $excOnly[$en]) } }
if ($onlyParts.Count -gt 0) { [void]$sb.AppendLine('<li>Exception type rows that exist in only one environment: ' + (Get-HtmlText ($onlyParts -join ', ')) + '.</li>') }
if ($missingDefs.Count -gt 0) { [void]$sb.AppendLine('<li>Differing parameters with no definition yet (add to $Defs in the script): ' + (Get-HtmlText ($missingDefs -join ', ')) + '.</li>') }
[void]$sb.AppendLine('</ul>')

$open = ' open'
foreach ($tab in $Tabs) {
    $shown = @($tab.Rows | Where-Object { $ShowSameRowsInHtml -or $_.Status -eq 'DIFF' }).Count
    [void]$sb.AppendLine('<h2>' + (Get-HtmlText $tab.Title) + '</h2>')
    [void]$sb.AppendLine('<details' + $open + '><summary>' + $shown + ' of ' + $tab.Rows.Count + ' rows shown (full data in ' + (Get-HtmlText $tab.File) + ')</summary>')
    [void]$sb.AppendLine((Get-TabHtml $tab))
    [void]$sb.AppendLine('</details>')
    $open = ''
}

[void]$sb.AppendLine('<h2>Table row counts</h2><details><summary>All tables</summary><table class="sortable"><thead><tr><th>Table</th>')
foreach ($en in $EnvNames) { [void]$sb.AppendLine('<th>' + (Get-HtmlText $en) + '</th>') }
[void]$sb.AppendLine('<th>Scope</th></tr></thead><tbody>')
foreach ($c in $CountRows) {
    [void]$sb.Append('<tr><td>' + (Get-HtmlText $c.Table) + '</td>')
    foreach ($en in $EnvNames) { [void]$sb.Append('<td>' + $c.($en) + '</td>') }
    [void]$sb.AppendLine('<td>' + (Get-HtmlText $c.Scope) + '</td></tr>')
}
[void]$sb.AppendLine('</tbody></table></details>')
[void]$sb.AppendLine('<script>')
[void]$sb.AppendLine($js)
[void]$sb.AppendLine('</script>')
[void]$sb.AppendLine('</body></html>')

$htmlPath = Join-Path $outPath 'PSO_Compare_Summary.html'
[System.IO.File]::WriteAllText($htmlPath, $sb.ToString(), $Utf8Bom)

# ---------------------------------------------------------------------------
# Console summary
# ---------------------------------------------------------------------------
Write-Host ''
Write-Colored ('Rows differing from {0}' -f $BaseName) 'Cyan'
Write-Colored '  (green = none, yellow = some, red = more than 25% of the rows in that area)' 'DarkGray'
Write-Host ''
Write-Colored ('{0,-20}{1,9}' -f 'Area', 'Compared') 'White' -NoNewline
foreach ($en in $EnvNames) { if ($en -ne $BaseName) { Write-Colored ('{0,9}' -f $en) 'White' -NoNewline } }
Write-Colored ('{0,17}' -f 'Not identical') 'White'
Write-Colored ('-' * (29 + 9 * ($EnvNames.Count - 1) + 17)) 'DarkGray'
$sumTotal = 0; $sumNot = 0; $sumDiff = @{}
foreach ($en in $EnvNames) { $sumDiff[$en] = 0 }
foreach ($t in $Tally) {
    Write-Colored ('{0,-20}{1,9}' -f $t.Name, $t.Total) 'Gray' -NoNewline
    foreach ($en in $EnvNames) {
        if ($en -ne $BaseName) {
            $d = [int]$t.Diffs[$en]
            Write-Colored ('{0,9}' -f $d) (Get-SeverityColor $d $t.Total) -NoNewline
            $sumDiff[$en] += $d
        }
    }
    Write-Colored ('{0,17}' -f $t.NotSame) (Get-SeverityColor $t.NotSame $t.Total)
    $sumTotal += $t.Total; $sumNot += $t.NotSame
}
Write-Colored ('-' * (29 + 9 * ($EnvNames.Count - 1) + 17)) 'DarkGray'
Write-Colored ('{0,-20}{1,9}' -f 'Total', $sumTotal) 'White' -NoNewline
foreach ($en in $EnvNames) {
    if ($en -ne $BaseName) { Write-Colored ('{0,9}' -f $sumDiff[$en]) (Get-SeverityColor ($sumDiff[$en]) $sumTotal) -NoNewline }
}
Write-Colored ('{0,17}' -f $sumNot) (Get-SeverityColor $sumNot $sumTotal)
Write-Colored ('{0,-29}' -f 'Share of rows differing') 'DarkGray' -NoNewline
foreach ($en in $EnvNames) {
    if ($en -ne $BaseName) {
        $pct = 0
        if ($sumTotal -gt 0) { $pct = [math]::Round(100.0 * $sumDiff[$en] / $sumTotal) }
        Write-Colored ('{0,8}%' -f $pct) (Get-SeverityColor ($sumDiff[$en]) $sumTotal) -NoNewline
    }
}
Write-Host ''
Write-Host ''
Write-Colored 'Output folder: ' 'DarkGray' -NoNewline
Write-Colored $outPath 'Green'
Write-Colored 'HTML summary : ' 'DarkGray' -NoNewline
Write-Colored $htmlPath 'Green'
if ($ApiKeyValues.Count -gt 1) { Write-Warning 'Routing API key values DIFFER between environments (values are masked in the output).' }
if ($needDef.Count -gt 0) {
    Write-Colored ('{0} parameter(s) have no specific definition - see ParamDefinitions_Template.csv' -f $needDef.Count) 'Yellow'
}
if ($VersionMismatch) { Write-Colored '  PSO VERSION MISMATCH - see the version table at the top of the output  ' 'White' -bg 'Red' }
elseif ($VersionComparable) { Write-Colored ('PSO version: all environments on {0}' -f $VerMax) 'Green' }
else { Write-Colored 'PSO version could not be compared (System_Version data missing)' 'Yellow' }
if ($OpenReport) { Invoke-Item -LiteralPath $htmlPath }
