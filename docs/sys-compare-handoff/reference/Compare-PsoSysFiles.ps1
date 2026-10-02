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

    Unset parameters: a parameter that is not in the export is using its default. The default,
    data type and official description come from pso_parameters_reference.csv (keep it next to
    the script). Values are compared as effective values, so an explicit value equal to the
    default (e.g. 1 vs unset with default 1, PT5M vs PT0H5M0S) is NOT a difference; those rows
    are marked Same (default). Profiles do not inherit from each other: a parameter unset in any
    profile uses its default.

    Parameters are described in plain English using the official descriptions in
    pso_parameters_reference.csv, plus the extra notes and the definitions for parameters the
    reference does not cover in ParamDefinitions.csv (Parameter,Definition,Note,Basis), which
    sits next to the script. ParamDefinitions_Template.csv lists what is still undocumented.

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
    [switch]$NoColor,
    # Parameter reference (defaults, data types, official descriptions). Unset parameters use their default.
    [string]$ParametersReference = '.\pso_parameters_reference.csv'
    # ================================================================
)

$ErrorActionPreference = 'Stop'
$ScriptDir = $PSScriptRoot
if ([string]::IsNullOrEmpty($ScriptDir)) { $ScriptDir = (Get-Location).Path }

# ---------------------------------------------------------------------------
# Parameter definitions (plain English)
# The official description of every parameter comes from pso_parameters_reference.csv.
# ParamDefinitions.csv (next to this script) adds to it:
#   - Definition: used as the full text for parameters the reference does not cover
#                 (or to override the official text);
#   - Note:       extra knowledge appended after the official text (KB / field experience);
#   - Basis:      where it came from ("KB: <file>", "Release notes", "Inference ..." = unverified,
#                 shown with a leading [Inferred]).
# Add rows to ParamDefinitions.csv to document more parameters. Save it as CSV UTF-8.
# Each run also writes ParamDefinitions_Template.csv listing parameters that still need a definition.
# ---------------------------------------------------------------------------
$Defs = @{}

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

# Definitions file (see above).
$UserDefsFile = Join-Path $ScriptDir 'ParamDefinitions.csv'
$DefsFileLoaded = $false
if (Test-Path -LiteralPath $UserDefsFile) {
    foreach ($ud in (Import-Csv -LiteralPath $UserDefsFile -Encoding UTF8)) {
        if ([string]::IsNullOrWhiteSpace($ud.Parameter)) { continue }
        if ([string]::IsNullOrWhiteSpace($ud.Definition) -and [string]::IsNullOrWhiteSpace($ud.Note)) { continue }
        $ub = 'User-supplied'
        if (-not [string]::IsNullOrWhiteSpace($ud.Basis)) { $ub = $ud.Basis }
        $Defs[$ud.Parameter.Trim()] = @{ Text = [string]$ud.Definition; Note = [string]$ud.Note; Basis = $ub }
    }
    $DefsFileLoaded = $true
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

function Get-RefEntry([string]$pn, [string]$app) {
    if ([string]::IsNullOrEmpty($pn)) { return $null }
    $lk = $pn.ToLowerInvariant()
    if (-not $ParamRef.ContainsKey($lk)) { return $null }
    $lst = $ParamRef[$lk]
    foreach ($e in $lst) { if ($e.App -ceq $app) { return $e } }
    return $lst[0]
}

# Definition = official description from the reference, plus any Note from ParamDefinitions.csv.
# A Definition in ParamDefinitions.csv replaces the official text (used for parameters the
# reference does not cover). Falls back to the name patterns, then to nothing.
function Get-ParamDef([string]$name, [string]$app) {
    $d = $null
    if ($Defs.ContainsKey($name)) { $d = $Defs[$name] }
    if ($null -ne $d -and -not [string]::IsNullOrWhiteSpace($d.Text)) {
        $t = $d.Text
        if (-not [string]::IsNullOrWhiteSpace($d.Note)) { $t = $t + ' Note: ' + $d.Note }
        return @{ Text = $t; Basis = $d.Basis }
    }
    $ent = Get-RefEntry $name $app
    if ($null -ne $ent -and -not [string]::IsNullOrWhiteSpace($ent.Desc)) {
        $t = $ent.Desc.Trim()
        $bs = 'Schema reference'
        if ($null -ne $d -and -not [string]::IsNullOrWhiteSpace($d.Note)) {
            if (-not $t.EndsWith('.')) { $t = $t + '.' }
            $t = $t + ' Note: ' + $d.Note
            $bs = 'Schema reference + ' + $d.Basis
        }
        return @{ Text = $t; Basis = $bs }
    }
    foreach ($dp in $DefPatterns) {
        if ($name -match $dp.Pattern) { return @{ Text = $dp.Text; Basis = $dp.Basis } }
    }
    return $null
}

# ISO-8601 duration (PT5M, PT0H5M0S, P2D ...) -> total seconds, or $null if it is not one
function ConvertFrom-IsoDuration([string]$s) {
    $m = [regex]::Match($s.Trim(), '^P(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$')
    if (-not $m.Success) { return $null }
    $any = $false
    $tot = 0.0
    $mult = @(86400.0, 3600.0, 60.0, 1.0)
    for ($i = 1; $i -le 4; $i++) {
        if ($m.Groups[$i].Success) {
            $any = $true
            $tot += ([double]::Parse($m.Groups[$i].Value, $Inv) * $mult[$i - 1])
        }
    }
    if (-not $any) { return $null }
    return $tot
}

# Canonical form used ONLY to decide whether two values are the same (PT5M = PT0H5M0S, True = true, 1 = 1.0)
function ConvertTo-CanonValue($v, [string]$type) {
    if ($null -eq $v) { return '' }
    $s = [string]$v
    switch ($type.ToUpperInvariant()) {
        'BOOLEAN' { return $s.Trim().ToLowerInvariant() }
        'INTEGER' {
            $n = [long]0
            if ([long]::TryParse($s.Trim(), [System.Globalization.NumberStyles]::Integer, $Inv, [ref]$n)) { return $n.ToString($Inv) }
            return $s
        }
        'DOUBLE' {
            $dv = [double]0
            if ([double]::TryParse($s.Trim(), [System.Globalization.NumberStyles]::Float, $Inv, [ref]$dv)) { return $dv.ToString('R', $Inv) }
            return $s
        }
        'TIMESPAN' {
            $secs = ConvertFrom-IsoDuration $s
            if ($null -ne $secs) { return ('dur:' + $secs.ToString('R', $Inv)) }
            return $s
        }
        default { return $s }
    }
}

function Show-ParamValue($v, [bool]$mask) {
    if ($null -eq $v -or ([string]$v).Length -eq 0) { return $NullText }
    if ($mask) { return '[API key set]' }
    return (Format-Text $v)
}

# What value does this environment really use for a profile parameter?
#   explicit value -> reference default -> (absent). Profiles do NOT inherit from each other:
#   a parameter unset in any profile uses its default.
function Get-EffectiveParam([string]$en, [string]$prof, [string]$pn, [string]$app) {
    $mask = ($pn -match '(?i)key')
    if (-not $ProfilesByEnv[$en].ContainsKey($prof)) { return @{ Display = '(no profile)'; Canon = '(no profile)' } }
    $ent = Get-RefEntry $pn $app
    $type = ''
    if ($null -ne $ent) { $type = $ent.Type }
    $k = $prof + [char]31 + $pn + [char]31 + $app
    if ($RawParams[$en].ContainsKey($k)) {
        $v = $RawParams[$en][$k]
        return @{ Display = (Show-ParamValue $v $mask); Canon = (ConvertTo-CanonValue $v $type) }
    }
    if ($null -ne $ent) {
        $dflt = $ent.Default
        $dd = 'blank'
        if (-not [string]::IsNullOrEmpty($dflt)) { if ($mask) { $dd = '[API key set]' } else { $dd = $dflt } }
        return @{ Display = ('(default: ' + $dd + ')'); Canon = (ConvertTo-CanonValue $dflt $type) }
    }
    return @{ Display = $Absent; Canon = $Absent }
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

# DIFF = effective values differ; Same (default) = shown values differ only because one side uses a default
function Get-Status($vals, $cmp) {
    if ($null -eq $cmp) { $cmp = $vals }
    for ($i = 1; $i -lt $cmp.Count; $i++) {
        if ($cmp[$i] -cne $cmp[0]) { return 'DIFF' }
    }
    for ($i = 1; $i -lt $vals.Count; $i++) {
        if ($vals[$i] -cne $vals[0]) { return 'Same (default)' }
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

function Add-TabRow($tab, [string[]]$keyVals, [string[]]$cellVals, $extra, [string[]]$cmpVals = $null) {
    if ($null -eq $cmpVals) { $cmpVals = $cellVals }
    $row = @{ KeyVals = $keyVals; CellVals = $cellVals; CmpVals = $cmpVals; Extra = $extra; Status = (Get-Status $cellVals $cmpVals) }
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
# Parameter reference: defaults, data types, official descriptions.
# A parameter that is not in the export is using its default, so defaults are
# needed to tell "same value" from "really different".
# ---------------------------------------------------------------------------
$ParamRef = New-CsMap
$RefLoaded = $false
$RefPath = Resolve-InputPath $ParametersReference
if (Test-Path -LiteralPath $RefPath) {
    foreach ($rr in (Import-Csv -LiteralPath $RefPath -Encoding UTF8)) {
        $lk = ([string]$rr.parameter_id).ToLowerInvariant()
        if ([string]::IsNullOrEmpty($lk)) { continue }
        if (-not $ParamRef.ContainsKey($lk)) { $ParamRef[$lk] = New-Object System.Collections.ArrayList }
        [void]$ParamRef[$lk].Add(@{ App = [string]$rr.application; Type = [string]$rr.data_type; Default = [string]$rr.default_value; Desc = [string]$rr.description })
    }
    $RefLoaded = ($ParamRef.Count -gt 0)
}
if (-not $DefsFileLoaded) { Write-Colored 'ParamDefinitions.csv not found next to the script: using the official reference descriptions only.' 'DarkGray' }
if ($RefLoaded) {
    Write-Colored 'Parameter defaults: ' 'DarkGray' -NoNewline
    Write-Colored ('{0} parameters loaded from {1}' -f $ParamRef.Count, (Split-Path -Leaf $RefPath)) 'Gray'
} else {
    Write-Warning ('Parameter reference not found or empty ({0}): unset parameters will be shown as (absent) and NOT matched to their defaults.' -f $RefPath)
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
$RawParams = @{}          # env -> map "profile|parameter|apptype" -> raw value
$ProfilesByEnv = @{}      # env -> map profile id -> $true
$AppByParam = New-CsMap   # parameter id -> application type (first one seen)
foreach ($en in $EnvNames) {
    $m = New-CsMap
    foreach ($r in (Get-Rows $en 'Profile_Parameter')) {
        $pid_ = Get-Field $r 'parameter_id'
        $app_ = Get-Field $r 'parameter_application_type_id'
        $k = (Get-Field $r 'profile_id') + [char]31 + $pid_ + [char]31 + $app_
        $v = Get-Field $r 'parameter_value'
        if ($pid_ -match '(?i)key' -and -not [string]::IsNullOrEmpty($v)) { $ApiKeyValues[$v] = $true }
        $m[$k] = $v
        if (-not $AppByParam.ContainsKey($pid_)) { $AppByParam[$pid_] = [string]$app_ }
    }
    $RawParams[$en] = $m
    $pf = New-CsMap
    foreach ($r in (Get-Rows $en 'Profile')) { $pf[(Get-Field $r 'id')] = $true }
    $ProfilesByEnv[$en] = $pf
}
$keySeen = New-CsMap
$keyList = New-Object System.Collections.ArrayList
foreach ($en in $EnvNames) {
    foreach ($k in $RawParams[$en].Keys) {
        if (-not $keySeen.ContainsKey($k)) { $keySeen[$k] = $true; [void]$keyList.Add($k) }
    }
}
$sortedKeys = @($keyList | Sort-Object `
    @{ Expression = { Get-ProfileOrder ($_.Split([char]31)[0]) } }, `
    @{ Expression = { $_.Split([char]31)[0] } }, `
    @{ Expression = { $_.Split([char]31)[1] } })

$TabParams = New-Tab 'Parameters' '01_Parameters.csv' @('Profile', 'Parameter', 'AppType') @('Default', 'Definition')
foreach ($k in $sortedKeys) {
    $parts = $k.Split([char]31)
    $disp = @()
    $cmp = @()
    foreach ($en in $EnvNames) {
        $ef = Get-EffectiveParam $en ($parts[0]) ($parts[1]) ($parts[2])
        $disp += $ef.Display
        $cmp += $ef.Canon
    }
    $ent = Get-RefEntry ($parts[1]) ($parts[2])
    $dflt = ''
    if ($null -ne $ent) {
        if ($ent.Default -eq '') { $dflt = '(blank)' }
        elseif ($parts[1] -match '(?i)key') { $dflt = '[API key set]' }
        else { $dflt = $ent.Default }
    }
    $extra = @{ Default = $dflt; Definition = '' }
    $pdef = Get-ParamDef ($parts[1]) ($parts[2])
    if ($null -ne $pdef) {
        $dtext = $pdef.Text
        if ($pdef.Basis -like 'Inference*') { $dtext = '[Inferred] ' + $dtext }
        $extra['Definition'] = $dtext
    }
    Add-TabRow $TabParams @($parts[0], $parts[1], $parts[2]) $disp $extra $cmp
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
$TravelCmp = @{}
foreach ($en in $EnvNames) {
    $d = [ordered]@{}
    $cc = [ordered]@{}
    foreach ($tp in $travelParams) {
        $label = 'Param: ' + $tp[0] + ' / ' + $tp[1]
        $tapp = ''
        if ($AppByParam.ContainsKey($tp[1])) { $tapp = $AppByParam[$tp[1]] }
        $ef = Get-EffectiveParam $en ($tp[0]) ($tp[1]) $tapp
        $d[$label] = $ef.Display
        $cc[$label] = $ef.Canon
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
    foreach ($kk in @($d.Keys)) { if (-not $cc.Contains($kk)) { $cc[$kk] = $d[$kk] } }
    $TravelInfo[$en] = $d
    $TravelCmp[$en] = $cc
}
foreach ($item in @($TravelInfo[$EnvNames[0]].Keys)) {
    $vals = @()
    $tcmp = @()
    foreach ($en in $EnvNames) { $vals += [string]$TravelInfo[$en][$item]; $tcmp += [string]$TravelCmp[$en][$item] }
    Add-TabRow $TabTravel @($item) $vals $null $tcmp
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
    'Users' = 'Not compared - user accounts'
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
            if ($i -ne $BaseIdx -and $r.CmpVals[$i] -cne $r.CmpVals[$BaseIdx]) { $diffs[$EnvNames[$i]]++ }
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
    if ($needDef.ContainsKey($pn)) { continue }
    $pd = Get-ParamDef $pn ($r.KeyVals[2])
    if ($null -eq $pd) { $needDef[$pn] = '' }
    elseif ($pd.Basis -like 'Inference (name pattern)') { $needDef[$pn] = $pd.Text }
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
    if ($val -ceq $Absent -or $val -ceq $NullText -or $val -ceq '(none)' -or $val.StartsWith('(default:') -or $val -ceq '(no profile)') { $cls += 'muted' }
    if ($idx -ne $BaseIdx -and $row.CmpVals[$idx] -cne $row.CmpVals[$BaseIdx]) { $cls += 'diff' }
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

[void]$sb.AppendLine('<h2>Scope</h2><div class="note"><ul>')
[void]$sb.AppendLine('<li><b>Compared:</b> profile parameters, exception types, groups and group permissions, organisation permissions, org-default list layouts, travel-time setup, profiles, terminology, exception type data, organisation record.</li>')
[void]$sb.AppendLine('<li><b>Not compared:</b> Users and all per-user tables - saved filters, screen settings, list layouts, and which groups, permissions and parameters are assigned to each user. The set of users differs between environments, so comparing them would mostly be noise. This also means group membership (who is in which group) is not compared, only what each group is allowed to do.</li>')
[void]$sb.AppendLine('<li><b>System_Version:</b> not compared row by row; it is summarised in the PSO version section instead.</li>')
[void]$sb.AppendLine('<li><b>Parameters:</b> a parameter missing from the export is using its default. The default comes from pso_parameters_reference.csv and is shown as (default: x), and it counts as equal to an explicit value that matches it. Rows that differ only because of defaults are marked Same (default) and are hidden. Profiles do not inherit from each other: a parameter unset in any profile uses its default.</li>')
[void]$sb.AppendLine('<li><b>Lists and polygons:</b> IDs are GUIDs that differ per environment, so lists are matched on content and polygons are summarised by count.</li>')
[void]$sb.AppendLine('<li><b>Groups:</b> names that differ only by case are treated as one group.</li>')
[void]$sb.AppendLine('<li><b>Matching:</b> case- and whitespace-sensitive. A leading or trailing space is shown with a visible marker.</li>')
[void]$sb.AppendLine('<li><b>Secrets:</b> API key values are masked.</li>')
[void]$sb.AppendLine('</ul></div>')

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
[void]$sb.AppendLine('<li>' + $paramDiffs.Count + ' parameter rows have different effective values across the environments (a parameter that is unset counts as its default value).</li>')
$paramDefaulted = @($TabParams.Rows | Where-Object { $_.Status -eq 'Same (default)' })
if ($paramDefaulted.Count -gt 0) {
    $dn = @($paramDefaulted | ForEach-Object { $_.KeyVals[1] } | Sort-Object -Unique)
    $dshow = ($dn | Select-Object -First 6) -join ', '
    if ($dn.Count -gt 6) { $dshow = $dshow + ', ...' }
    [void]$sb.AppendLine('<li>' + $paramDefaulted.Count + ' more parameter rows only look different in the export because one environment leaves the parameter unset and uses the default (hidden above): ' + (Get-HtmlText $dshow) + '.</li>')
}
if (-not $RefLoaded) { [void]$sb.AppendLine('<li><b>Parameter defaults were NOT applied</b> because pso_parameters_reference.csv was not found; unset parameters show as (absent) and may simply be using their default.</li>') }
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
$defaultedCount = @($TabParams.Rows | Where-Object { $_.Status -eq 'Same (default)' }).Count
if ($RefLoaded) { Write-Colored ('Parameter defaults applied: {0} parameter row(s) differ only because of defaults and are counted as the same.' -f $defaultedCount) 'DarkGray' }
else { Write-Colored 'Parameter defaults NOT applied (pso_parameters_reference.csv not found next to the script).' 'Yellow' }
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
