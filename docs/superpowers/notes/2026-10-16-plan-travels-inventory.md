# Plan travels inventory

Behaviour inventory of pso-tools `scripts/Build-PlanTravelsReport.ps1` (2,032 lines, changelog to 2026-10-08), read in full on 2026-10-16, plus the header of an older output CSV (`travel/plan_travels_output_initial.csv`) and the golden CSV at `~/pso-data/ACME/1005/golden/plan_travels_2026-10-05_5d.csv`. Reference for the spec and plan; not a design.

## Biggest surprises

1. The whole file is loaded with `[xml]$xml = Get-Content -Raw` (DOM, not streaming), against the repo convention. Only `-BuildImportXml` streams (a compiled C# XmlReader/XmlWriter pass).
2. The report itself only date-filters Plan_Travel (on the first 10 characters of the leg's `start_time`). The help text says Shift, Activity_SLA and Activity_Status are filtered by the range too: that is true only inside `-BuildImportXml`. Shift, Activity_Status, Activity_SLA and Allocation are never read for the CSV.
3. The start location of the first leg is never looked up from Resources or Shift: it is always `Plan_Travel.start_location_id` (or the first fragment's, after a start-of-day merge). End-of-day legs end at `Plan_Travel.end_location_id`, not `Resources.location_id_end`.
4. Merging runs in three separate passes in a fixed order (GPS fix chains, then start-of-day pairs, then break groups), each on what the previous left, and each applies the whole-metre rule (Resolve-Fragments) with slightly different consequences. Merge Type has nine possible values, four of them ("Break (slice dropped)", "Break (duplicate)", "GPS fix (slice dropped)", "GPS fix (duplicate)") missing from the help text.
5. On a start-of-day "trip + slice" pair the slice goes to Pre-Shift columns; on a break or GPS-fix chain the slice is dropped silently with no column. Pre-Shift Travel rounds to 1 dp, Pre-Fix Travel to 2 dp.
6. Step 4 parses `expected_travel_time` with the regex `^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$`: days or fractional seconds give a null PSO time ("Missing PSO Time"). Merged legs are re-encoded through Format-IsoDuration (whole seconds) first. The test file has neither days nor fractions.
7. Flag bug: a leg with PSO time 0 s and a real Google time is flagged "Missing Google Time" (the compare branch needs `psoSeconds > 0`, and the fall-through assumes Google returned nothing). A Google duration of 0 s also lands there.
8. `% Divergence` is computed from the already rounded ratio (`round((round(g/p,3) - 1) * 100, 1)`), so it moves in 0.1 steps of the 3 dp ratio.
9. Final sort is `Sort-Object 'Resource ID', 'Activity ID (Destination)'`: string sort (culture-aware, case-insensitive), not by time, and ties (all end-of-day legs of a resource sort first with an empty destination id) fall in hashtable order. Row order is not reproducible; compare sorted.
10. Number formatting differs between PowerShell 5.1 and 7: doubles print with 15 significant digits on 5.1 and shortest round-trip on 7 (the golden CSV has a 17-digit GPS fix latitude, so it was made under pwsh, inference). The Google Maps link uses `-f`, which is culture-dependent; Location latitude/longitude use culture-dependent `[double]::TryParse` (comma-decimal locales break both).
11. Interactive: with `-ValidateWithGoogle` and no `-ApiKey`, the script calls Read-Host (env var confirm, or a secure prompt). With zero rows it warns and exits 0 without writing a CSV or saving seeded cache entries.

## 1. Inputs and parameters

- `-InputFile` (mandatory, alias `-InputXmlPath`): dsScheduleData XML. Missing file: Write-Error, exit 1. No `dsScheduleData` root: Write-Error, exit 1. No zip support.
- `-Date` (mandatory): `yyyy-MM-dd`, parsed with `ParseExact(..., 'yyyy-MM-dd', $null)` (throws on any other format).
- `-Days` (int, default 1, `[ValidateRange(1, 31)]`).
- `-OutputPath` (default `.\plan_travels_output`): base path without extension.
- `-NoTimestamp` (switch): write `<OutputPath>.csv` instead of `<OutputPath>_yyyy-MM-dd_HHmmss.csv`.
- `-BuildImportXml` (switch): also write the filtered import XML (section 10).
- `-ValidateWithGoogle` (switch).
- `-ApiKey` (string). Resolution when blank, before any XML work: env `GOOGLE_MAPS_API_KEY` present means Read-Host "Use this key? (Y/n)" showing the last 4 characters (empty or Y/y = use it; else secure prompt); env absent means secure prompt. Still blank: Write-Error, exit 1.
- `-TravelMode` (default `DRIVE`), `-RoutingPreference` (default `TRAFFIC_UNAWARE`). Passed through unvalidated.
- `-RequestDelayMs` (int, default 150).
- `-ReviewThresholdMinutes` (double, default 20), `-ReviewThresholdPct` (double, default 0 = off).
- `-GoogleCacheFile` (alias `-GoogleCachePath`; default `google_routes_cache.csv` in `$PSScriptRoot`, else current folder).
- `-SeedCacheFrom` (string[]): earlier output CSVs.
- `-NoCache` (switch).
- `$ErrorActionPreference = "Stop"`. No error trap printing the stage (against the repo convention).
- Constant `$KeepElements` (29 entity names, section 10).

## 2. Entities read from the XML

All via DOM dot access on `$root` (`$xml.dsScheduleData`); `[string]` of a missing child is `''`.

- Plan_Travel: `resource_id`, `shift_id`, `plan_id`, `activity_id`, `visit_id`, `previous_activity_id`, `previous_visit_id`, `start_location_id`, `end_location_id`, `distance` (metres, string), `expected_travel_time` (ISO 8601 duration), `start_time`, `end_time`, `shift_start_datetime`, `schedule_event_id`.
- Activity: `id`, `activity_class_id`, `activity_type_id`, `description`, `location_id` (lookup keyed by id, `@{}` so case-insensitive). Import XML also uses `location_id`.
- Location: `id`, `latitude`, `longitude`. Parsed with `[double]::TryParse(string, [ref])` (current culture). Empty value stays null; an unparseable non-empty value most likely becomes 0 through the out parameter (inference, not tested).
- Location_Region: `location_id`, `region_id`; first row per location in file order wins.
- Resources (element name `Resources`): `id`, `first_name`, `surname`. Import XML also: `location_id_start`, `location_id_end`.
- Schedule_Event: `id`, `event_type_id` (kept when `-ne 'FIX'` is false, so case-insensitive), `latitude`, `longitude` (invariant parse; row skipped if either fails), `event_date_time` (else `date_time_stamp`), `resource_id`.
- Additional_Attribute: `label` (only `Call ID` and `Siebel Order Number`, compared with PowerShell `-ne`, so case-insensitive), `label_value` (blank skipped), `activity_id`. Later rows overwrite earlier for the same activity and label. Rows with no `activity_id` are keyed by `''` (harmless).
- Import XML only: Shift (`id`, `start_datetime`, `location_id_start`, `location_id_end`), Activity_SLA (`activity_id`, `datetime_start`, `datetime_end`), Activity_Status (`activity_id`, `date_time_fixed`, `duration`), plus Shift_Break, Activity_Group, Activity_Custom_URL, Activity_Skill filtered in the C# pass.
- Never read: Allocation, Activity_Status (for the report), Shift (for the report), Plan, Plan_Route, Input_Reference.
- Console counts printed: Activities, Locations, Resources, Plan_Travel, Location_Region.

## 3. Date filtering

- Range: `$dateStringSet` = every `yyyy-MM-dd` from `-Date` to `-Date + Days - 1`.
- Report filter (Step 4): `Get-DatePortion(start_time)` (first 10 characters; null if shorter than 10) must be in the set. Pure string prefix, no offset handling: correct only because the export's times are `+00:00` (all are in the test file).
- Applied to the leg after merging, so the leg's `start_time` is the earliest fragment's (start of day and break) or the earliest post-fix fragment's (GPS fix chains; the pre-fix fragment's time is ignored). All merging runs on every Plan_Travel row in the file, before the date filter.
- Range-overlap bounds (`$targetDayStartUtc` = range start at UTC midnight as DateTimeOffset, `$targetDayEndUtc` = start + Days minus one tick) are used only by `-BuildImportXml`.

## 4. Leg building

Order of the pipeline:
1. GPS fix chains (section 5) on rows in a (resource, shift) touched by a fix; output `$fixLegs`.
2. Start-of-day pairing on the remaining "plain" rows; output `$sodLegs`; the rest go to `$breakPool`.
3. Break merge on `$breakPool`; output plus `$fixLegs` plus `$sodLegs` becomes the leg list.
4. Step 4 filter and enrichment per leg.

Step 4 per leg, in order:
- Skip if `start_time` date not in range (counted in "Rows in range" only if it passes).
- End-of-day test `Test-EndOfDayLeg`: `activity_id` empty AND `previous_activity_id` set AND `end_location_id` set.
- `activity_id` empty and not end-of-day: dropped silently (no counter).
- Not end-of-day and `Test-PrivateOrNonAvailability(activity_id)`: excluded, counter `excludedDestination`.
- `previous_activity_id` set and `Test-PrivateOrNonAvailability(previous_activity_id)`: excluded, counter `excludedOrigin`. Applies to end-of-day legs too.
- `Test-PrivateOrNonAvailability(id)`: false if id blank or activity not in the file; true if Activity `activity_class_id -eq 'PRIVATE'` or `activity_type_id -eq 'NON-AVAILABILITY'` (PowerShell `-eq`, case-insensitive).
- Direction: end-of-day = `Activity -> End Location`; `previous_activity_id` empty = `Start Location -> Activity`; else `Activity -> Activity`. A GPS fix leg whose previous activity was found reads `Activity -> Activity` though it starts at the fix.
- Origin coordinates: Location of `start_location_id`; for a GPS fix leg, overwritten with the fix's lat/lon. Destination: Location of `end_location_id`.
- No leg is dropped for missing coordinates; such legs get empty coordinates and no link (Google: Skipped).
- Unplaced fragments and other warnings (all Write-Log, Yellow unless noted, not in the CSV):
  - `WARNING: unplaced travel fragment (<why>): resource R, shift S, <start_time> to <end_time>, previous activity P`; why = `no start fragment`, `middle fragment with no start fragment`, `chain never reached a destination`.
  - `Travel ends at the GPS position (no destination after the fix, no leg reported): resource R, shift S, N fragment(s) from T1 to T2` (DarkGray, not counted as unplaced).
  - `WARNING: travel fragment with no previous activity and no activity: resource R, shift S, T1 to T2` (dropped, counted in `sodUnpaired`).
  - `WARNING: start-of-day travel fragment with no partner (reported as it is): resource R, shift S, activity A, T1 to T2` (passed through unchanged as a leg).
  - `WARNING: split travel that is neither a trip with slices nor pieces adding up to whole metres (summed): <what>; raw distances d1, d2`.
- Summary lines: start-of-day counts, break-not-summed count, GPS fix counts (`FIX event(s) found`, `leg(s) start from a fix`, FIX-tagged fragments / resources / accounted for, synthetic chained of total, dead ends), `Unplaced fragments: N`.

## 5. Fragment merging

### Resolve-Fragments (whole-metre rule, "spec v3")

```
Distance = sum(double(distance) where distance non-empty); Seconds = sum(Get-TravelSeconds)
Rule = 'sum'
if rows < 2: return
decs = Get-RawDecimal(distance) per row   # decimal, NumberStyles.Float, invariant, trimmed
if any dec is null: return 'sum'
whole = count(dec == truncate(dec))
sameDist = all decs equal decs[0]; sameTime = all raw expected_travel_time strings equal row 0
if whole == n and sameDist and sameTime: Rule='duplicate'; Distance=decs[0]; Seconds=row0 time
elif whole == 1: Rule='trip'; Trip = the whole row; Slices = the others; Distance/Seconds = Trip's
elif whole == 0 and |sum(decs) - round(sum(decs))| <= 0.01: Rule='sum'
else: Rule='unclear' (warn), keep the sum
```

- "14200" and "14200.0" are both whole. 1 whole + any fractional rows is always 'trip' (no check that slices add up).

### GPS FIX chains (run first)

- FIX index: `$fixById[id]`; `$fixByResource[resource_id]` = latest by string compare of Time.
- `Test-SyntheticActivityId(id)`: contains `!%!%` (e.g. `<resource>!%!%FIX`).
- `Test-FixTagged(row)`: `schedule_event_id` is a key of `$fixById` (so only FIX events with valid coordinates).
- `Test-PreFix(row)`: `start_location_id` set, `end_location_id` empty, FIX-tagged.
- Selection: `fixShiftKeys` = every `resource|shift` with a tagged or synthetic row. `fixGroupKeys` = `resource|shift|previous_activity_id|previous_visit_id` of each tagged non-synthetic row. Rows in a fix shift go to `shiftRows[resource|shift]` (all of them); a row is a chain row if tagged, synthetic, or its group key is in `fixGroupKeys`; else it goes to the plain pool. Rows outside fix shifts go to the plain pool.
- Per fix shift: sort chain rows by `start_time` then `end_time` (strings). For each pre-fix row P, find the first row (other than P) that is tagged with P's `schedule_event_id` or has any synthetic `previous_activity_id`; if it sits before P, move P to just before it.
- Chain scan:

```
chain = null
for r in rows:
  hasStart, hasEnd, fixy = tagged or synthetic
  if hasStart and not hasEnd:
     if chain: Close-OpenChain(chain)
     chain = [r]
  elif hasEnd:
     if not chain:
        if hasStart or fixy: chain = []
        else: Add-Unplaced(r, 'no start fragment'); continue
     chain += r; Complete-FixChain(chain); chain = null
  else:  # neither location
     if not chain:
        if fixy: chain = []
        else: Add-Unplaced(r, 'middle fragment with no start fragment'); continue
     chain += r
if chain: Close-OpenChain(chain)
```

- Close-OpenChain: if no row is tagged or synthetic, Add-Unplaced all with `chain never reached a destination`; else counts a dead end (`fixDeadEnds`), counts tagged/synthetic rows as accounted, logs the DarkGray line. No leg.
- Complete-FixChain(chain):
  - `prefix` = first Test-PreFix row; `post` = rows after it (all rows if no prefix).
  - fix = prefix's event; else first tagged row's event; else, if any row is synthetic, `$fixByResource[resource]` (the resource's latest fix, regardless of time).
  - No fix and chain of 1: the raw row is emitted unchanged.
  - Distance/time = Resolve-Fragments(post). The pre-fix fragment is excluded.
  - start_time = minimum `start_time` over post.
  - previous_activity_id: first non-blank, non-synthetic `previous_activity_id` in the chain (with its `previous_visit_id`); else, with a fix, `Get-LastActivityBefore(shift, fix.Time)`: the row in that (resource, shift) with an `activity_id` and the greatest `start_time` < fix time (string compare); takes its `activity_id` and `visit_id`.
  - start_location_id: '' with a fix; else first non-blank in the chain.
  - Other fields from the last row (`plan_id`, `resource_id`, `shift_id`, `end_time`, `end_location_id`, `activity_id`, `shift_start_datetime`).
  - `merged_fragments` = post count. `merge_type` = `GPS fix` (fix) / `Break` (no fix, post > 1) / '' then suffix ` (slice dropped)` (trip) or ` (duplicate)`.
  - `prefix_distance_km` = round(prefix distance / 1000, 2); `prefix_travel_min` = round(prefix seconds / 60, 2); `fix_lat`, `fix_lon`, `fix_time` (raw string).

### Start-of-day pairs (second)

- From the plain pool: rows with a `previous_activity_id`, or with both locations, go to the break pool. Rows with no previous activity and no `activity_id` are warned and dropped. The rest are grouped on `resource|shift|activity_id|visit_id` (ordinal Dictionary).
- Group must have exactly one "first" (start only) and one "last" (end only); otherwise every row is warned and passed through unchanged.
- Chain = first, then rows with neither location sorted by `start_time`, then last. Resolve-Fragments(chain).
  - duplicate: `Start of day (duplicate)`, counted once.
  - trip: `Start of day`; Pre-Shift Distance = round(sum slice metres / 1000, 2), Pre-Shift Travel = round(sum slice seconds / 60, 1). The code takes whichever fragment is whole as the trip (the comment says the second; not enforced).
  - sum / unclear: `Start of day`, summed, no Pre-Shift values.
- Leg: start_time = earliest in chain; `start_location_id` from first; everything else from last; `merged_fragments` = chain count. The comment's "pre-shift 15 minutes" is an observation (slice = trip prorated to the first 15 minutes), not a rule in the code.

### Break merge (third)

- Group the break pool on `resource|previous_activity_id|previous_visit_id` (`@{}`, case-insensitive; no shift in the key).
- Group of 1: unchanged. Else orphans = no `end_location_id`; terminals = has one. Merge only if orphans >= 1, terminals == 1, and no orphan has a non-blank `start_location_id` differing from a non-blank terminal one. Otherwise every row passes through unchanged.
- Merge: sum distance and seconds of terminal + orphans; earliest `start_time`; start location = terminal's, else first orphan's non-blank one. Then Resolve-Fragments(orphans + terminal): trip or duplicate replace the totals with `Break (slice dropped)` / `Break (duplicate)` (counted in `breakNotSummed`); else `Break`.
- `merged_fragments` is not set on the node; Step 4 reads `mergedActivityKeys[resource|previous_activity_id|previous_visit_id]` = orphans + 1.

### Merge Type and Merged Fragments in Step 4

- Merged Fragments: node's `merged_fragments` if present, else `mergedActivityKeys` lookup, else 1.
- Merge Type: node's `merge_type` if present, else `Break` if Merged Fragments > 1, else empty.
- Possible values: empty, `Break`, `Break (slice dropped)`, `Break (duplicate)`, `Start of day`, `Start of day (duplicate)`, `GPS fix`, `GPS fix (slice dropped)`, `GPS fix (duplicate)`.

## 6. Per-leg enrichment

- Resource Name: `"{first_name} {surname}".Trim()`; empty if not found.
- GB: `Get-ActivityGB(id)` = Location_Region.region_id of the activity's `location_id`. Origin GB from `previous_activity_id` (empty if none); destination GB from `activity_id`.
- Activity type and description: `activity_type_id`, `description` of the previous and destination activities (destination blank for end-of-day legs).
- Call ID / Siebel Order Number: Additional_Attribute labels `Call ID` and `Siebel Order Number` of the destination activity.
- Coordinates: Location lat/lon, or FIX lat/lon for the origin of a GPS fix leg.
- Google Maps link: `https://www.google.com/maps/dir/?api=1&origin={lat},{lon}&destination={lat},{lon}&travelmode=driving`; null if any coordinate null.
- Plan ID: `plan_id` of the leg (last fragment for merged legs).
- Distance (km): round(double(distance) / 1000, 2); null if distance empty. `[math]::Round` is banker's rounding.
- Expected Travel Time (sec): regex parse above (H, M, S integers only). (min): round(sec / 60, 1).
- Get-TravelSeconds: `XmlConvert.ToTimeSpan(...).TotalSeconds`, 0 on blank or error (used in merging). Format-IsoDuration: round to whole seconds, `PT` + `{h}H` if > 0 + `{m}M` if > 0 + `{s}S` if > 0 or both h and m are 0 (so 0 is `PT0S`).

## 7. Google validation

- Only with `-ValidateWithGoogle`. Endpoint `https://routes.googleapis.com/directions/v2:computeRoutes`, POST.
- Headers: `Content-Type: application/json`, `X-Goog-Api-Key`, `X-Goog-FieldMask: routes.duration,routes.distanceMeters`.
- Body: `{origin:{location:{latLng:{latitude,longitude}}}, destination:{...}, travelMode, routingPreference, units:"METRIC"}`.
- Pre-count of cache misses (legs with null start or end latitude are not counted). If misses > 0: one test call from 50.65,-104.87 to 50.78,-104.43; failure is Write-Error and exit 1. Else "Every leg is cached".
- Per leg (in sorted order, with Write-Progress): no coordinates means `Validation Error` = `Missing/invalid coordinates -- skipped`; cache hit means Google Source `Cache`; else API call: `duration` "Ns" to int, `distanceMeters / 1000` rounded 2, Source `API`; no routes = `No route returned`; exception = message plus ` | Response: <body>` when present. No retry. Sleep `RequestDelayMs` after every API call (success or failure), not after cache hits.
- Comparison (psoSeconds = Expected Travel Time (sec)):

```
if googleSec and psoSec and psoSec > 0:
   ratio = round(g / p, 3)
   pct = round((ratio - 1) * 100, 1)
   absMin = round(|g - p| / 60, 1)
   signed = round((p - g) / 60, 1)
   Difference = "PSO is {signed} min longer than Google" | "PSO is {|signed|} min shorter than Google" | "Same"
   if absMin >= ReviewThresholdMinutes and (ReviewThresholdPct <= 0 or |pct| >= ReviewThresholdPct):
        Flag = Review; Estimated Weighting Adjustment = round(ratio, 2)
   else Flag = OK
elif error: Skipped
elif psoSec is null: Missing PSO Time
else: Missing Google Time   # also psoSec == 0 with a Google time (bug), or Google 0 s
```

- Estimated weighting: the Google/PSO ratio rounded to 2 dp, only on Review rows; the factor PSO's time would be multiplied by to match Google (below 1 when PSO is longer).
- Columns added: Google Duration (min) (round(sec/60, 1)), Google Duration (sec), Google Distance (km), Google Source, Ratio (Google / PSO), % Divergence, Abs Divergence (min), Difference, Flag, Estimated Weighting Adjustment, Validation Error.
- Summary log: total, from cache, API calls, cache size, skipped, flagged (with thresholds).

## 8. Cache

- Key: `string.Format(Invariant, "{0:F6},{1:F6}|{2:F6},{3:F6}|{4}|{5}", oLat, oLon, dLat, dLon, TravelMode, RoutingPreference)`.
- File: CSV (Export-Csv, UTF8, all quoted) with columns `Key, DurationSec, DistanceKm, FetchedUtc`. Loaded with Import-Csv when it exists (blank keys skipped; DurationSec `[int]`, DistanceKm invariant double). Loaded whenever the cache is enabled, even without Google validation.
- Written: new API results with duration > 0 only (errors never cached), `FetchedUtc` = UTC ISO `o`. Saved every 250 new entries, at the end if any were added, and after seeding. Whole file rewritten in hashtable order. Save failure is a Yellow warning only.
- Seeding (`-SeedCacheFrom`, with or without Google): per file (missing file warned); skip rows with a `Validation Error`; duration from `Google Duration (sec)` if the column exists and is non-empty, else round(`Google Duration (min)` * 60); skip if none or <= 0; coordinates from the four Start/End Location columns (invariant; skip on failure); existing keys kept; distance from `Google Distance (km)` else 0; `FetchedUtc` = `seeded:<file name>`. Assumes the current mode/preference.
- `-NoCache`: no read, no write, no seeding (silently).

## 9. Output

- File: `<OutputPath>_yyyy-MM-dd_HHmmss.csv` (stamp taken at start), or `<OutputPath>.csv` with `-NoTimestamp`. Export-Csv `-NoTypeInformation -Encoding UTF8` (5.1: BOM and every value quoted; nulls are empty unquoted). Up to 5 attempts, 2 s apart, on a locked file; then exit 1.
- No rows: Write-Warning, exit 0, no CSV.
- Columns in order:
  1. Resource ID (`resource_id`)
  2. Resource Name
  3. Direction
  4. Previous Activity ID
  5. GB (Previous Activity)
  6. Previous Activity Type
  7. Previous Activity Description
  8. Activity ID (Destination)
  9. GB (Destination Activity)
  10. Destination Activity Type
  11. Destination Activity Description
  12. Start Location Latitude
  13. Start Location Longitude
  14. End Location Latitude
  15. End Location Longitude
  16. Distance (km)
  17. Expected Travel Time (min)
  18. Expected Travel Time (sec)
  19. Merged Fragments
  20. Merge Type
  21. GPS Fix (`Yes` or empty)
  22. GPS Fix Time
  23. Pre-Fix Distance (km)
  24. Pre-Fix Travel (min)
  25. Pre-Shift Distance (km)
  26. Pre-Shift Travel (min)
  27. Google Maps Directions Link
  28. Plan ID
  29. Call ID
  30. Siebel Order Number
  31 to 41 (Google only): the eleven columns in section 7.
- The older sample CSV has columns 1 to 19, 27, 28 and an older Google block (no Google Duration (sec), no Google Source).
- Write-Log: `[HH:mm:ss] ` in DarkGray, then the message coloured by regex unless `-Color` is passed: `^===` Cyan `[>>] `; `Flagged for review|Dropped \d` Yellow `[!!] `; `(outside requested date)|not in keep list` DarkGray; `FINAL rows|CSV written|Done ===|API key OK|From cache|Every leg is cached` Green `[OK] `; `Skipping|Nothing dropped` DarkGray; else Gray. Step 4 logs rows in range, excluded destination, excluded origin, end-of-day count, FINAL rows.

## 10. -BuildImportXml (out of scope for the port)

- Writes `<input base name>_import.xml` in the CSV's folder. Streaming C# pass keeps only depth-1 elements in `$KeepElements`: Activity, Activity_Custom_URL, Activity_Group, Activity_SLA, Activity_Status, Activity_Skill, Activity_Type, Additional_Attribute, Availability, Custom_URL, Input_Reference, Location, Location_Region, Region, Resource_Custom_URL, Resource_Preference, Resource_Region, Resource_Region_Availability, Resource_Skill, Resource_Skill_Availability, Resource_Type, Resources, Shift, Shift_Break, Shift_Type, Skill, SLA_Type, URL_Parameter, URL_String.
- Date scoping: Shift by `start_datetime` date; Shift_Break by kept shift; Activity_SLA by window overlap; Activity if an SLA window overlaps or (PRIVATE, no SLA) Activity_Status `date_time_fixed + duration` overlaps; Activity_Custom_URL / Activity_Status / Activity_Skill by kept activity; Activity_Group only if both activities kept; Additional_Attribute dropped only when it has an `activity_id` not kept; Location if referenced by any Resource start/end, a kept Activity or a kept Shift. Availability, Resource_Region_Availability, Resource_Skill_Availability never date-filtered (the 112 to 4 incident). Logs dropped counts per type.

## 11. Known limitations, inference and TODO

- No TODO markers in the file. Labelled observations, not rules: the break split "confirmed" on one resource; "every break split in north_travel_1005.xml" sums; trip + slice = first 15 minutes before the shift (12 pairs), 18 duplicated, 4 genuine splits (whole-file counts); Call ID matched the description in 124 of 150; PRIVATE activities have no SLA (0 of 3650).
- The break merge key has no shift, and relies on `previous_visit_id` to keep days apart.
- A synthetic-only chain with no FIX-tagged row takes the resource's latest fix, which may be a different fix from the one the chain belongs to.
- Pre-fix reordering treats any synthetic row as a post-fix partner of any pre-fix row in the shift.
- Coordinates of 0,0 or out of range are not checked. Missing Activity, Location or Resource gives blanks, never an error.
- Flag "N/A" is the initial value but every branch overwrites it.
- Hashtables (`@{}`) are case-insensitive; IDs are numeric in the test data, so no effect seen.

## Golden test candidates

- File: `~/pso-data/ACME/1005/north_travel_1005.xml` (135 MB, 5,081 Plan_Travel rows, 97 FIX Schedule_Events, 4 plan ids).
- Existing golden: `~/pso-data/ACME/1005/golden/plan_travels_2026-10-05_5d.csv` (1,781 rows, 30 columns, no BOM, no Google). Name implies `-Date 2026-10-05 -Days 5 -NoTimestamp` (inference). Contents: Direction 765 Activity -> Activity, 515 Start Location -> Activity, 501 Activity -> End Location; Merge Type 13 Break, 8 GPS fix, 6 Start of day (duplicate), 1 Start of day; Merged Fragments 2 on 21 rows; no row missing coordinates.
- Also worth running: `-Date 2026-10-04 -Days 6` (the full Plan_Travel range per the brief) to catch the whole-file counts in the changelog.
- Parameters: `-InputFile <file> -Date 2026-10-05 -Days 5 -OutputPath <x> -NoTimestamp`, no Google, no import XML.
- Compare exactly without Google (after sorting rows on Resource ID, Activity ID, Previous Activity ID, Plan ID): every column 1 to 30, with care on 12 to 16 and 22 to 26 (number formatting: compare as decimals, since 5.1 prints 15 significant digits, pwsh and Python shortest round-trip, and the link column embeds the same numbers) and on rounding (`[math]::Round` is banker's on the double; Python `round` is correct on the binary value; rare differences at exact halves are possible).
- Google columns: only reproducible with a seeded cache (`-SeedCacheFrom` an earlier Google run, or the shared `scripts/google_routes_cache.csv`), and then the comparison logic (ratio, %, abs, Difference, Flag, weighting) can be checked exactly.
