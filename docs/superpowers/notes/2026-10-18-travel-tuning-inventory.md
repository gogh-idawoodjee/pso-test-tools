# Travel tuning report inventory

Behaviour inventory of pso-tools `travel/Build-TravelTuningReport.ps1` (1,046 lines plus a trailing blank, no changelog), read in full on 2026-10-17, for porting it to a Python reference implementation that the PSO Test Tools web app runs (as it runs `plan-travels/plan_travels.py` and `activity-trace/trace_activity.py`). Also read: the plan travels inventory (format model), pso-tools `CLAUDE.md`, and the CSV writer of `plan-travels/plan_travels.py` (`COLUMNS`, `GOOGLE_COLUMNS`, `fmt_num`, `compare`, lines 681 to 1040). Observed behaviour comes from one pwsh 7.6.6 run on the four local run CSVs without polygons (section 12). Reference for the spec and plan; not a design.

Line numbers are the script's. "Banker's" means .NET `MidpointRounding.ToEven`; "away" means `MidpointRounding.AwayFromZero`. Both are applied by .NET's scale, round, unscale method (section 9), not by exact decimal rounding.

## Biggest surprises

1. "Polygons" are not polygons. Read-Polygons keeps up to 8 vertices only to take their min/max: every polygon becomes its axis-aligned bounding rectangle (`s`, `n`, `west`, `east`, 412-413). Clip, Crossings, the model check, the hotspot, the corridor table and the map all work on rectangles (the map draws `L.rectangle`). Exact for the grid imports this was built for; wrong for any non-rectangular polygon.
2. R1 and R2 use `MidpointRounding.AwayFromZero` (204-205), unlike Build-PlanTravelsReport (banker's). Halves are common, not rare: PSO and Google times are whole seconds, so `sec / 60` sits on an exact x.x5 whenever `sec mod 6 = 3`. On the local data, Python's `round(x, 1)` disagrees with the script on 318 of 3,771 baseline PSO times, 84 signed differences, and 28 Google times in the final run. The port needs an exact emulation (section 9), not `round()`.
3. A leg is flagged on `R1(|PSO min - Google min|) >= ReviewThresholdMinutes` (315, 479), recomputed here. The CSV's own `Flag`, `Abs Divergence (min)` and `-ReviewThresholdPct` rule are never read. For older exports without `Google Duration (sec)`, the Google time is `Google Duration (min) * 60`, a value already rounded to 0.1 min (up to 3 s off).
4. Legs are matched across runs on coordinates only, as a 5 dp key (311), pairing the n-th occurrence of a key in run A with the n-th in run B (350-361). On the local data 157 keys repeat (248 extra legs per run), so file order decides which of the repeated legs pair up.
5. Four leg populations are used side by side and the page mixes them: all usable legs of a run (flagged count, over/under hours, GB summary, map, check these), legs shared by baseline and final (accuracy, bands, histogram, scatter, worst, corridors, hotspot), consecutive-run pairs (fixed, newly broken, model check), and legs common to every run (timeline average error and bias). The headline cards mix the first two.
6. The model check and the overlap counter: `WeightAt` increments `$script:OVERLAPS` once per evaluated piece midpoint inside two or more rectangles (454), during Predict for both runs' crossings, for every leg of every model check. The warning ("leg pieces fall inside more than one polygon") is console only and only printed when the final run has polygons (596). The count is not legs and not in the JSON.
7. `R1 $l.km` for the map's flagged legs converts a missing distance to `0.0`, not null (1015; R1 takes `[double]`), so a flagged leg with no Google or PSO distance shows "0 km" in its popup and is never dashed.
8. Group-Object order differs by PowerShell version: pwsh 7 sorts the groups by name (tested: input b, A, a, c, B gives A, b, c), 5.1 returns them in order of first appearance. This decides the corridor table's row order (581). Group-Object is also case-insensitive, so corridor groups `C1` and `c1` merge.
9. The page does part of the arithmetic itself in JavaScript: the headline "% fewer", phantom hours recovered, the scatter's default range and axis limits, the diff-view values and the 2-minute histogram are computed from the JSON in the browser (716-717, 811-813, 830-831, 851-853). A port that keeps the template keeps these for free; a port that moves them server side must copy JS rounding.
10. The JSON is written by `ConvertTo-Json -Compress`, whose number and escaping output differs between 5.1 and 7 (section 10). Nothing on the page depends on it, but byte-level parity with either PowerShell is not a sensible target.

## 1. Parameters, defaults and path rules

### Parameters (124-144)

| Parameter | Type, default | Notes |
|---|---|---|
| `-Runs` | string[] | Run CSVs, baseline first, final last; at least 2 (235, 240). |
| `-Labels` | string[] | One per run; default `Baseline`, `Run 1`, `Run 2`, ... (241). Count mismatch throws (242). |
| `-PolygonXml` | string[] | Aligned with runs; `''` for a run without polygons. Count mismatch throws (254). Omitted: all `''` (256). |
| `-ReviewCsv` | string, `''` | Final run's polygon review CSV. |
| `-NotesFile` (alias `-NotesPath`) | string, `''` | Markdown notes. |
| `-Manifest` | string, `''` | CSV `Label,RunCsv,PolygonXml`; replaces the three list parameters. |
| `-OutputPath` | string, `''` | Default `travel_tuning_report_<yyyyMMdd_HHmm>.html` in the current folder (local time, invariant culture, 259). |
| `-Title` | `Travel Tuning Report` | HTML-escaped into `<title>`; raw into `meta.title`. |
| `-ClientName`, `-DatasetName` | `''` | Header only. |
| `-RegionLabel` | `GB` | What the page calls the region columns (e.g. Territory). |
| `-ReviewThresholdMinutes` (alias `-ThresholdMinutes`) | double, 20 | Flag rule. |
| `-SmallErrorMinutes` | double, 5 | Over/under measures and "newly underestimated". |
| `-ModelToleranceMinutes` | double, 1 | Model check tolerance (absolute). |
| `-ModelTolerancePct` | double, 5 | Model check tolerance (percentage of the actual PSO time). |
| `-LongLegKm` | double, 300 | Map: flagged legs longer than this are thin and dashed. |
| `-MapTiles` | `esri-gray` | ValidateSet `esri-gray`, `esri`, `osm`, `none`. |
| `-Offline` | switch | Inline Chart.js and Leaflet from `lib\` next to the script. |

### Editable constants (149-169)

- `$CommonWarnPct = 60`: warn when legs common to all runs are under this % of the smallest run's usable legs.
- `$ModelWarnPct = 80`: warn when a model check is under this %.
- `$CorridorMinShare = 0.5`: share of a leg's straight line inside a corridor group's rectangles.
- `$HotspotCalloutPct = 15`: hotspot callout level.
- `$CheckRows = 15`: rows per run in "check these".
- `$DistanceBands`: `[0,5) Under 5 km`, `[5,15) 5-15 km`, `[15,30) 15-30 km`, `[30,60) 30-60 km`, `[60,100) 60-100 km`, `[100,1e9) 100+ km`.
- CDN URLs: Chart.js 4.4.1 `chart.umd.min.js`, Leaflet 1.9.4 `leaflet.min.js` and `leaflet.min.css`, all on cdnjs. `$LibFiles` = those three names.
- `$TextBenchmark`, `$TextOverUnder`, `$TextStayWrong`: fixed explanatory paragraphs, trimmed into `text.*`. None contain em dashes.

### Error handling and logging (171-193)

- `$ErrorActionPreference = 'Stop'`; a trap prints `Stopped during: <stage>`, the message, position and script stack, then `exit 1`. Stages: `starting`, `reading the arguments`, `reading <label>`, `matching legs across runs`, `reading the polygon files`, `computing the metrics`, `model check <A> to <B>`, `polygon callouts`, `corridor table`, `building the report data`, `writing the report`.
- `Log` prefixes `[ {elapsed:N1}s] ` (current culture `N1`, console only). `Head` cyan, `Ok` green, `Detail` dark gray with 3-space indent, `Warn` yellow `WARNING: `.

### Lists and the manifest (217-238)

- Split-List (220-225): every list element is split on commas, each part trimmed then stripped of `'` and `"`. So labels cannot contain commas; an empty `''` element survives as an empty string (needed for `-PolygonXml '', a.xml`).
- Manifest (227-233): resolved against the current folder, read with `Import-Csv -Encoding UTF8`. No rows throws. Each row: `RunCsv` resolved against the manifest's folder; `Label` taken as is (blank allowed, and blank labels are not replaced by defaults since the array is non-empty); `PolygonXml` resolved if non-blank, else `''`. With a manifest, `-Runs`, `-Labels`, `-PolygonXml` are ignored.
- Resolve-In (207-212): blank gives `''`; relative paths joined to the base; missing file throws `File not found: <path>`; returns the resolved full path.
- `-ReviewCsv`, `-NotesFile` and relative `-OutputPath` resolve against the current folder (257-260). The output folder is not created.
- `-Offline` checks the three lib files exist first and throws with a download hint (262-264). Lib folder is `$PSScriptRoot\lib` (fallbacks: the invocation path, then the current folder, 185-187).

### Label rules (243-251)

- Baseline misplaced: the first run at index i >= 1 whose label matches `baseline` (regex, case-insensitive) sets `base_misplaced = {label, at = i + 1, first = label of run 0}` and warns. Only the first such label. Nothing is reordered.
- Labels appear verbatim in console, JSON and page (escaped on the page).
- Region plural (console, 611): `y` after a non-vowel becomes `ies`, else `s`. The page's `plural` (697) also adds `es` after s, x, z, ch, sh.

## 2. Input formats

### Run CSVs (Read-Run, 286-330)

Read with `Import-Csv -Encoding UTF8` (BOM tolerated; whole file in memory; header names exact). Values parsed by `Num` (195-203): trimmed, invariant culture, `NumberStyles.Float`; blank or unparseable gives null.

Required (throws with a hint when missing; checked on the first row's property names, 289-291):
- `Start Location Latitude`, `Start Location Longitude`, `End Location Latitude`, `End Location Longitude`, `Expected Travel Time (sec)`.
- `Google Duration (min)` ("Re-run ... with -ValidateWithGoogle").
- No rows throws `<label> (<path>) has no rows.`

Optional, detected per file:
- `Google Duration (sec)` (`hasGSec`): when absent or blank on a row, Google seconds = `Google Duration (min) * 60` (303).
- `Validation Error` (`hasVal`).
- `Merged Fragments` (`hasMerged`): absent, blank or unparseable gives 1; else `[int]` of the number (PowerShell `[int]` rounds half to even; values are whole in practice).
- `GPS Fix` (`hasFix`): when present also reads `GPS Fix Time` (trimmed string) and `Pre-Fix Distance (km)` (Num). `gpsFix` is true only when `GPS Fix` trimmed equals `Yes` (PowerShell `-eq`, case-insensitive). When absent: `gpsFix` false, `fixTime` `''`, `prefixKm` null, and the run's `hasFixCols` is false (the page then says "GPS fix not recorded in this export").

Other columns read, all trimmed strings, missing column gives `''`: `Resource ID`, `Resource Name`, `GB (Previous Activity)`, `GB (Destination Activity)`, `Direction` (stored as `dir`, never used), `Google Distance (km)` and `Distance (km)` (numbers, for `km`).

Never read: Previous/Destination activity ids, types and descriptions, `Expected Travel Time (min)`, `Merge Type`, Pre-Shift columns, `Google Maps Directions Link`, `Plan ID`, `Call ID`, `Siebel Order Number`, `Google Source`, `Ratio (Google / PSO)`, `% Divergence`, `Abs Divergence (min)`, `Difference`, `Flag`, `Estimated Weighting Adjustment`, `Pre-Fix Travel (min)`.

Columns of the local test files (no values):
- `plan_travels_output_initial.csv`: 30 columns, the older layout: plan travels columns 1 to 19, 27, 28, then `Google Duration (min)`, `Google Distance (km)`, `Ratio`, `% Divergence`, `Abs Divergence (min)`, `Difference`, `Flag`, `Estimated Weighting Adjustment`, `Validation Error`. No `Google Duration (sec)`, no `Google Source`.
- `grid_v1/v2/v3_pass1.csv`: 32 columns: the same plus `Google Duration (sec)` and `Google Source`.
- None of the four has `Merge Type`, `GPS Fix`, the Pre-Fix or Pre-Shift columns, `Call ID` or `Siebel Order Number`. So the GPS fix path, `fix_time` and `prefix_km` are untested by local data.
- All have a UTF-8 BOM and every value quoted (Export-Csv style).

Current writers:
- `scripts/Build-PlanTravelsReport.ps1 -ValidateWithGoogle`: 41 columns (30 base plus 11 Google, plan travels inventory section 9).
- Web Plan travels tool, `plan_travels.py`: the same 41 headers in the same order (`COLUMNS` + `GOOGLE_COLUMNS`), UTF-8 with BOM, `QUOTE_ALL`. Numbers via `fmt_num` (shortest round-trip, whole values without `.0`), booleans `Yes`/`''` (so `GPS Fix` is `Yes` or empty, as the tuning script expects).
- Differences that reach the tuning report:
  - Same-location legs (identical start and end route key): `plan_travels.py` writes Google min/sec/km 0, Flag OK, no validation error (deviation from the script, lines 1005-1008). The tuning report keeps such a leg with Google 0 when its PSO time is positive; with PSO 0 it is excluded as "no PSO time". What Build-PlanTravelsReport writes for these legs depends on what Google returns (inference: `0s`, giving Google sec 0 and Flag Missing Google Time); either way the tuning script keeps it on the Google value, not on the flag.
  - `plan_travels.py` writes `Google Duration (sec)` but leaves `Google Duration (min)` blank when Google seconds is 0 (`ps_round(g / 60, 1) if g else None`); the tuning script reads seconds first, so this has no effect. A file with neither is excluded as "no Google time".
  - Legs without coordinates: both writers put `Missing/invalid coordinates -- skipped` in `Validation Error`, so they count as validation-error exclusions, not "no PSO time".
  - PSO time 0 with a Google time: plan_travels.py flags `Missing PSO Time`, the script `Missing Google Time`; the tuning script ignores Flag and excludes both as "no PSO time".
  - No header difference: the tuning script would read a web CSV exactly like a script CSV.

### Polygon import XML (Read-Polygons, 385-416)

- DOM load (`XmlDocument.Load`, fine for a few hundred polygons). Namespace: the root element's namespace URI, bound to prefix `d:` when non-empty. Expected root `DsSystemData` but not checked: `//Travel_Time_Polygon` and `//Polygon` are searched anywhere in the document.
- `Travel_Time_Polygon`: `polygon_id` (string, trimmed) and `weighting` (Num). Kept when the id is non-blank and the weighting parses; later rows overwrite earlier ones for the same id (ordinal dictionary). A missing `polygon_id` or `weighting` child throws (InnerText of null under Stop).
- `Polygon`, in document order:
  - `id`: InnerText trimmed; a missing `id` child throws.
  - `description`: optional, trimmed, else `''`.
  - `latitude_1`..`latitude_8` with `longitude_1`..`longitude_8`: a vertex is kept when both elements exist and both parse. Fewer than 3 vertices: polygon skipped silently.
  - `group`: id up to the first `-` when the dash is at index > 0, else the whole id.
  - `gtype`: first token of the description split on runs of whitespace or `|`; when it is letters only (`^[A-Za-z]+$`), first letter upper, rest lower (invariant), else `Other`. Empty description gives `Other`. Values seen in the help: `Corridor` drives the corridor table; others are display only.
  - `w`: the weighting for that id, else 1.0, with `hasW` false.
  - `s` = min latitude, `n` = max latitude, `west` = min longitude, `east` = max longitude.
- Console per run: `<label>: N polygons (<count> <type>, ...)` using Group-Object order, plus `, K without a weighting (treated as 1.0)`.
- No polygon XML is in the local test data; the polygon paths are untested here.

### Review CSV (566-567)

- `Import-Csv -Encoding UTF8`. Keyed by `Polygon` trimmed (blank skipped, ordinal, last row wins).
- Read: `GBs / Route` (corridor route label), `Note` (corridor fallback label and map popup note). The documented `Weighting`, `Effect`, `Lat South`, `Lat North`, `Lon West`, `Lon East` are never read.

### Notes Markdown (1024)

- `[IO.File]::ReadAllText` (UTF-8 by default, BOM detected), converted by ConvertFrom-Md (section 8). Missing file throws at argument time.

## 3. Leg parsing and exclusions (298-324)

Per CSV row, `ix` counts every data row from 1 (before exclusions; stored as `row`). In this order:

1. `psec` = Num(`Expected Travel Time (sec)`); `gmin` = Num(`Google Duration (min)`); `gsec` = Num(`Google Duration (sec)`) when the column exists, else null; if `gsec` is null and `gmin` is not, `gsec = gmin * 60.0`.
2. `Validation Error` present and non-blank after trim: excluded, `exValidation`.
3. `psec` null or `<= 0`: excluded, `exNoPso`.
4. `gsec` null: excluded, `exNoGoogle`. A Google time of 0 is kept.
5. Any of the four coordinates null: excluded, counted in `exNoPso` (not a separate counter; the page footer calls it "no PSO time").

Usable leg fields:
- `p = psec / 60`, `g = gsec / 60`, `d = p - g` (minutes, unrounded doubles). Positive `d` = PSO longer than Google.
- `ad = R1(|d|)`.
- `km` = Num(`Google Distance (km)`), else Num(`Distance (km)`), else null.
- `key` (311): each coordinate `[Math]::Round(x, 5)` (banker's, scaled) formatted `0.00000` invariant, joined with `|` in the order start lat, start lon, end lat, end lon.
- `res`, `resName`, `gbFrom`, `gbTo`, `dir`, `merged`, `gpsFix`, `fixTime`, `prefixKm` as in section 2.

Per run totals (325-329) over usable legs, file order: `flagged` = count of `ad >= threshold`; `over` = sum of positive `d`; `under` = sum of `-d` for negative `d`; `total = over + under` (all minutes). `rows` = CSV data rows; `excluded = exNoG + exNoP + exVal`.

Console per run: `<label>: {rows:N0} legs, {usable:N0} usable, {excluded:N0} excluded, {flagged:N0} flagged (<file>)`.

Not checked: a run whose rows are all excluded (0 usable legs) is accepted; Accuracy then divides by 1 and `minRun` is 0 (section 5).

## 4. Matching across runs (343-379)

- Group-ByKey (344-348): per run, ordinal `Dictionary<string, List>` of legs by `key`, lists in file order, keys in first-appearance order.
- Match-Runs(a, b) (350-361): for each leg of run a in file order, `n` = its index in its own key list (reference equality via `List.IndexOf`, so identical rows stay distinct); if run b has the key and at least `n + 1` legs under it, pair `(leg_a, b_list[n])`. Output in run a's file order. One-to-one by construction.
- `PAIRS["i-1|i"]` for every consecutive pair; `PAIRS["0|NR-1"]` = baseline vs final (`SH`, the "shared legs"), computed once more unless NR is 2.
- COMMON (368-373): for each key of run 0 in first-appearance order, `n` = min list length over all runs (0 if any run lacks the key); for j < n, the tuple of the j-th leg of every run. So COMMON uses the same n-th occurrence rule and is consistent with every pairwise match.
- `minRun` = smallest usable leg count; `commonPct = Pct(COMMON.Count, minRun)`; `common_warn = commonPct < 60`, with a console warning.
- Console: `Compared on N legs present in both <first> and <last>`; `Timeline compared on N legs common to all K runs (P% of the smallest run)`.

## 5. Calculations in order

### Helpers

- `R1(x)` = `Math.Round(x, 1, AwayFromZero)`; `R2(x)` = 2 dp away (section 9 for the exact method).
- `Pct(a, b)` = 0.0 when `b == 0`, else `R1(100 * a / b)`.
- `Flagged(l)` = `l.ad >= ReviewThresholdMinutes` (the rounded absolute difference).
- `LegGBs(l)` (279-284): `[gbFrom]` if non-blank, plus `gbTo` if non-blank and different. PowerShell `-ne` compares strings case-insensitively, so `ab` and `AB` count once. The comment names an alternative rule (destination GB, else previous) as the place to change it.
- `RouteOf(l)` (517): `"<gbFrom or 'home'> to <gbTo or 'home'>"`. "home" also appears when an activity simply has no region.

### Polygon geometry (426-473)

All in raw degrees (lat as y, lon as x); fractions along the segment are invariant under axis scaling, so no projection.

Clip(l, r), Liang-Barsky (428-438):
```
t0 = 0; t1 = 1
dx = lo2 - lo1; dy = la2 - la1
for (p, q) in [(-dx, lo1 - west), (dx, east - lo1), (-dy, la1 - s), (dy, n - la1)]:
    if p == 0:
        if q < 0: return None
    else:
        t = q / p
        if p < 0:
            if t > t1: return None
            if t > t0: t0 = t
        else:
            if t < t0: return None
            if t < t1: t1 = t
if t1 <= t0: return None
return (t0, t1)
```
- A segment touching only an edge or corner returns None (`t1 <= t0`). A zero-length leg (start = end) inside or on the rectangle returns (0, 1); outside returns None.

Crossings(l, polys) (440-450): empty list for no polygons. Bounding-box prefilter (skip when `r.n < minLat`, `r.s > maxLat`, `r.east < minLon` or `r.west > maxLon`; inclusive), then Clip; returns `[(poly, t0, t1)]` in polygon file order.

WeightAt(cross, t) (452-457): the `w` of the first crossing with `t0 <= t <= t1` (file order); if a second one also contains t, `OVERLAPS += 1` and stop. 1.0 when none.

Predict(legA, polysA, polysB) (460-473), the PSO time expected in run B from run A's time and the change of weighting along the straight line:
```
ca = Crossings(legA, polysA); cb = Crossings(legA, polysB)
if not ca and not cb: return None
ts = sorted([0, 1] + all t0, t1 of ca + all t0, t1 of cb)     # duplicates kept
f = 0
for k in range(len(ts) - 1):
    seg = ts[k+1] - ts[k]
    if seg <= 0: continue
    mid = (ts[k] + ts[k+1]) / 2
    f += seg * WeightAt(cb, mid) / WeightAt(ca, mid)
return legA.p * f
```
- Uses run A's leg geometry for both sides (B's leg has the same 5 dp key).
- A weighting of 0 in run A gives division by zero: pwsh returns Infinity for doubles (tested), so the leg fails the check. 5.1 behaves the same for doubles (inference).
- Assumes PSO applies the weighting of the polygon a path piece is in, linear in path length. That is the script's model, not confirmed PSO behaviour.

### Accuracy(legs) (485-491)

Over a list of legs (sums in list order):
```
n = len(legs)
sa = sum |d|; sd = sum d; P = sum p; G = sum g
t10 = count |d| >= 10           # raw d, fixed 10 minutes
o5  = count d >= Small          # raw d
u5  = count d <= -Small
if n == 0: n = 1
avg_abs = R2(sa / n); bias = R2(sd / n)
pso_h = R1(P / 60); google_h = R1(G / 60)
pso_vs_google_pct = Pct(P - G, G)
pct_10 = Pct(t10, n); pct_over = Pct(o5, n); pct_under = Pct(u5, n)
n_over = o5; n_under = u5
```
- `ACC_B = Accuracy(SH baseline legs)`, `ACC_F = Accuracy(SH final legs)` (492-493).

### Newly underestimated (494-496)

- `NEWU` = shared pairs with `final.d <= -Small` and `|base.d| < Small` (raw values).
- `newly_under_5_10` = those with `final.d > -10` (fixed 10).

### PairStats(pairs) (480-484)

`shared` = pair count; `fixed` = flagged in A and not B; `newly_broken` = flagged in B and not A; `still` = flagged in both. `PS_BF = PairStats(SH)` goes to `acc.pair` (`still` is console only on the page).

### Distance bands (499-503)

- Over SH, using the baseline leg's `km`; pairs with null km are skipped (so band legs can sum to fewer than the shared count; locally they sum to exactly the shared count).
- In band when `lo <= km < hi`. `legs` = count; `base = R2(mean |base.d|)`, `final = R2(mean |final.d|)`; both null when the band is empty.

### GB summary (506-514)

- GBFlags(legs): over all usable legs of a run, for flagged legs, `+1` per entry of LegGBs (ordinal dictionary). Run 0 gives `gbB`, final gives `gbF`.
- `cleared` = keys of gbB not in gbF; `still` = keys in both as `{gb, base, final}`; `new` = keys of gbF not in gbB as `{gb, final}`. Each sorted with `Sort-Object` on the string (current culture, case-insensitive; section 10). `base_count = gbB.Count`.
- Membership tests are ordinal (case-sensitive) while LegGBs dedup is case-insensitive.

### Worst offenders (518-519)

- SH sorted by `|base.d|` descending, then `base.row` ascending; first 10.
- Each: `res = base.resName`, `res_id = base.res`, `route = RouteOf(base)`, `base = R1(base.d)`, `final = R1(final.d)`, `google = R1(base.g)`, `fixed = not Flagged(final)`.
- "fixed" is true for a pair that was never flagged too (only the final run is tested).

### Timeline and model check (522-562)

Per run i (in order):
- `label`, `polygons` (count when a polygon XML was given, else 0), `legs` (usable), `excluded`, `flagged`.
- `over_h = R1(over / 60)`, `under_h = R1(under / 60)`, `total_h = R1(total / 60)`.
- `over_min`, `under_min`, `total_min` = `[Math]::Round(x)` (banker's, to a whole minute; double). Console only.
- `common_avg_abs`, `common_bias` = Accuracy(COMMON column i).
- For i > 0: `fixed`, `newly_broken`, `shared_prev` from PairStats(PAIRS i-1, i); null for run 0.
- Model check, for i > 0 when any run has polygons and run i-1 or run i has a polygon XML: over PAIRS i-1, i, `pred = Predict(a, POLY[i-1], POLY[i])`; skip when None; `tot += 1`; `tol = max(ModelToleranceMinutes, b.p * ModelTolerancePct / 100)`; `ok += 1` when `|pred - b.p| <= tol`. Sets `model_ok`, `model_n` (else both null).
- Model warning (552-561): for each i with `model_n > 0`, `mp = Math.Round(100 * ok / n, 1)` (banker's, not Pct); `mp < 80` adds `{from, to, ok, n, pct = mp}` to `meta.model_warn` and warns.

### Polygon callouts and corridor table (565-597), final run's polygons only

Skipped entirely (HOTSPOT and STRONG null, corridors empty) when the final run has no polygons.

- Hotspot: for each NEWU pair, Crossings(final leg, final polygons); `hits[id] += 1` per rectangle crossed (a leg crossing three rectangles counts three times). When any hits: top = most hits, ties by id ascending (Sort-Object on the string, culture). `{id, legs, of = NEWU.Count, pct = Pct(legs, of), w, desc, callout = pct >= 15}`; `w` and `desc` from the first polygon with that id.
- Strongest weightings: `low` = Sort-Object `w, id` first; `high` = `w` descending, then `id` ascending. `{id, w, desc}` each. Computed even when every weighting is 1.0.
- Corridors: polygons with `gtype == 'Corridor'`, grouped by `group` (Group-Object: case-insensitive; order differs by version, surprise 8). Per group:
  - For each SH pair: `inside` = sum of `t1 - t0` over Crossings(final leg, group rectangles). Overlapping rectangles in a group are double counted, so `inside` can exceed 1. When `inside >= 0.5`: `legs += 1`, add `base.p`, `final.p`, `final.g`; add both runs' non-blank `resName` to an ordinal SortedSet (`techs`, sorted ordinal).
  - `route`: first polygon in the group (group order) with a review row whose `GBs / Route` (trimmed) is non-blank, else its `Note`; failing that, the second `|`-separated part of the first polygon's description, trimmed.
  - `weightings`: distinct `w` values sorted ascending, each `ToString('0.00', invariant)`, joined `, `.
  - `{group, polygons, weightings, route, legs, techs, base, final, google}` with `base/final/google = R1(mean)` or null when no legs.
- OVERLAPS warning (596), console only (surprise 6).

### "Check these" (1006-1011)

For run 0 and the final run:
- Candidates: usable legs with `merged > 1` or `gpsFix`.
- `merged` = count with `merged > 1`; `gps` = count with `gpsFix`; `has_fix_cols`.
- `rows`: sorted by Flagged (1 before 0), then `|d|` descending; no further tie-break (Sort-Object is stable in pwsh 7, so file order; 5.1 does not promise stability). First 15.
- Row: `{res = resName, route, merged, gps, fix_time, prefix_km (raw double or null), p = R1(p), g = R1(g), d = R1(d), flagged}`.

### Map data (993, 1014-1029)

- `flagged.base`, `flagged.final`: flagged legs of run 0 and the final run, file order, each `[la1, lo1, la2, lo2, R1(d), R1(p), R1(g), resName, route, R1(km)]`. Coordinates by `Math.Round(x, 5)` (banker's). `R1(km)` gives 0.0 for null km (surprise 7).
- `bounds` = `[minLat, minLon, maxLat, maxLon]` over the raw coordinates of every usable leg of run 0 and the final run; starts at 90, 180, -90, -180, so a run pair with no legs would give `[90, 180, -90, -180]`.
- `polygons`: final run's polygons in file order, `{id, w, desc, group, gtype, s, n, west, east, note}` with `note` = review `Note` trimmed or `''`.
- `poly_counts[i]`: `{label, total, by_type}`; `by_type` = Group-Object gtype sorted by Name, `[{type, n}]`.

### Shared scatter data (1013)

`shared` = per SH pair, in baseline file order, `[R1(base.g), R1(base.p), R1(final.p)]`. The final run's Google time is not carried; the page uses the baseline Google for both runs.

### Headline savings

Computed on the page (716-723) from the JSON:
- Flagged legs: `TB.flagged to TF.flagged` (timeline rows, all usable legs); `drop = TB.flagged ? Math.round(100 * (TB.flagged - TF.flagged) / TB.flagged) : 0`, shown as "N% fewer" or "N% more".
- Phantom travel recovered: `TB.over_h - TF.over_h` (difference of two R1 values), with both values.
- Average error per leg and bias: `acc.base` and `acc.final` (shared legs).

### Console check (602-618)

Per-run line (polygons, flagged, over/under/total min, fixed, newly broken, model), baseline vs final accuracy (`{0:0.00}`, `{2:+0.00;-0.00}`, `{4:+0.0;-0.0}` formats), newly underestimated, fixed/broken/still, GB counts, hotspot, strongest weightings, corridor groups with and without trips. Console only; worth keeping as the port's stdout summary, not a parity target.

## 6. JSON embedded in the page

`ConvertTo-Json -Depth 10 -Compress`, then every `</` replaced by `<\/` (1032); placed in `<script type="application/json" id="data">`. Keys the page reads are marked (r); the rest are carried but unused.

- `meta`: `title` (r), `client` (r), `dataset` (r), `generated` (r; local time `yyyy-MM-dd HH:mm`), `labels` (r), `files`, `threshold` (r), `small` (r), `tol_min` (r), `tol_pct` (r), `shared` (r), `common` (r), `common_pct` (r), `common_warn` (r), `common_warn_pct` (r), `base_misplaced` (r; null or `{label, at, first}`), `model_warn` (r; `[{from, to, ok, n, pct}]`), `model_warn_pct` (r), `have_polys` (r), `offline` (r), `tiles` (r), `long_leg_km` (r), `corridor_share` (r), `region` (r), `check_rows` (r), `hotspot_pct` (r).
- `runs[]` (r): `label`, `file`, `rows`, `legs`, `excluded`, `ex_google`, `ex_pso`, `ex_validation`.
- `timeline[]` (r): `label`, `polygons`, `legs`, `excluded`, `flagged`, `over_h`, `under_h`, `total_h`, `over_min`, `under_min`, `total_min`, `fixed`, `newly_broken`, `shared_prev`, `model_ok`, `model_n`, `common_avg_abs`, `common_bias`. The page reads `label`, `polygons`, `flagged`, `over_h`, `under_h`, `total_h`, `common_avg_abs`, `fixed`, `newly_broken`, `model_ok`, `model_n`.
- `acc` (r): `base` and `final` = `{avg_abs, bias, pso_h, google_h, pso_vs_google_pct, pct_10, pct_over, pct_under, n_over, n_under}`; `newly_under`; `newly_under_5_10`; `pair = {shared, fixed, newly_broken, still}` (unused by the page).
- `bands[]` (r): `{band, legs, base, final}`.
- `gb` (r): `base_count`, `cleared` (strings), `still` (`{gb, base, final}`), `new` (`{gb, final}`).
- `worst[]` (r): `{res, res_id, route, base, final, google, fixed}`.
- `check[]` (r): 2 entries (run 0, then the final run): `{label, merged, gps, has_fix_cols, rows[]}`.
- `corridors[]` (r), `hotspot` (r, or null), `strong` (r, or null).
- `shared` (r): arrays of 3 numbers.
- `flagged` (r): `{base, final}`, arrays of 10.
- `bounds` (r): 4 numbers.
- `polygons[]` (r), `poly_counts[]` (r; `total`, `by_type[].type`, `by_type[].n`).
- `text` (r): `benchmark`, `overunder`, `staywrong`.
- `notes` (r): HTML string or `''` (falsy hides the section).

## 7. The page (623-944)

Single file. Placeholders `__TITLE__` (escaped title), `__LIBS__`, `__DATA__`, replaced in that order with plain string Replace (1041). Written UTF-8 without BOM (1042-1043).

- `__LIBS__` online: Leaflet CSS link, Chart.js and Leaflet script tags from cdnjs. Offline: `<style>` with `lib/leaflet.min.css`, then `<script>` blocks with the two JS files, each `</script` replaced by `<\/script` (1033-1040). Local `travel/lib` holds all three.
- CSS tokens on `:root` with dark mode under `prefers-color-scheme` (guarded by `:not([data-theme=light])`) and `[data-theme=dark]`; a Theme button toggles and remembers `tt-theme` in localStorage (try/catch) and re-renders.
- `RENDER()` builds everything from the JSON, in order:
  1. Header: title, client / dataset, generated; run chips (legs, excluded, polygon count); "Compared on ... / Timeline compared on ..."; banners for `base_misplaced`, each `model_warn`, `common_warn`.
  2. Sticky section menu (`nav.toc`), one pill per rendered section (SHORT names; `s-bands` in the table is a dead entry).
  3. Headline cards (four, section 5).
  4. `s-map`: Leaflet map with base/final flagged toggle (final shown first), legend, tile error note, long-leg note.
  5. `s-scatter`: Chart.js scatter, buttons for run (baseline/final), view (PSO vs Google, Difference) and range (60, 120, All). Default range: the smallest of 60/120/all holding 99% of shared legs (max of the three values). Reference lines at equality and +/- threshold.
  6. `s-acc`: accuracy table (green/red against the better direction) and the distance-band bar chart.
  7. `s-hist`: signed error histogram of `final - base Google`: 2-minute bins from -30 to +30 plus two end buckets (32 bars), baseline and final overlaid (`grouped:false`).
  8. `s-trade`: bar chart of `n_over` / `n_under` both runs, newly underestimated sentence, hotspot callout (or note below the callout level), "Watch these first" strongest weightings, the three fixed texts.
  9. `s-corr` (only when corridors exist): table of corridors with legs; corridors with none folded into one sentence.
  10. `s-worst`: worst offenders table.
  11. `s-check` (only when any check row exists): per run heading and table; GPS fix cell shows `HH:mm` (`fix_time.slice(11,16)`) and pre-fix km.
  12. `s-gb`: region summary (chips for cleared, tables for still and new).
  13. `s-notes` (only when notes non-empty).
  14. `s-tl`: timeline table (polygon count tooltip from `by_type`) and a two-axis line chart (flagged, total error hours).
  15. Footer: method, excluded legs per run, Google settings, libraries online or embedded.
- Map: `L.map('map', {preferCanvas: true, zoomSnap: 0.25})`; fits the flagged legs' endpoints of both runs plus polygon corners, else `bounds`. Tiles from `meta.tiles`: `esri-gray` = Esri World Light/Dark Gray Base plus Reference label layer in `shadowPane` (follows the theme at render), maxZoom 16; `esri` = World Street Map, maxZoom 19; `osm` = openstreetmap.org, maxZoom 18; `none` (no entry in TILES) = no tiles. Tile errors show a note. Rectangles coloured blue (w < 1), orange (w > 1), gray (1), fill alpha `0.15 + 0.55 * min(1, |ln w| / ln 2)`; popup id, weighting, desc, note. Flagged legs: straight lines coloured over/under by `d`, dashed and thin when `km > long_leg_km`.
- Number formatting on the page: `n0` = rounded, `en-US` grouping; `n1` = `en-US` 1 dp; `n2` = `toFixed(2)`; `sg` adds `+` for positive.
- Missing Chart.js or Leaflet degrade to notes in place of the charts or map.
- Wording check: no em dashes in fixed texts or page strings; uses "flagged", "legs", "technicians". The footer always credits OpenStreetMap even with Esri tiles (minor).

## 8. Markdown renderer (949-987)

- The whole file is HTML-escaped first (`&`, `<`, `>`, `"`), so raw HTML in notes shows as text. Lines split on `\r?\n`; each line trimmed before matching.
- Tables: a line `^\|.*\|$` followed by a separator line `^\|[\s:|-]+\|$`. Header cells to `<th>`, then following lines matching `^\|.*\|$` to `<tr><td>`. Cells: strip outer `|`, split on `|`, trim, inline formatting. Alignment colons ignored. Output `<table><thead>...</thead><tbody>...</tbody></table>`.
- Headings `#` to `######` plus a space: level + 2, capped at 6 (`#` becomes `<h3>`, `####` and deeper `<h6>`).
- Lists: `-`, `*` or `+` plus space (ul); `digits.` or `digits)` plus space (ol, numbering ignored). Switching kind closes the open list. No nesting.
- Blank line: closes the paragraph and any list. Any other line closes an open list and joins the current paragraph with a single space.
- Inline, applied in this order on the escaped text: `` `code` ``; links `[text](url)` only for `http://`, `https://`, `mailto:`, `#`, `./`, `/` (no spaces in the URL); `**bold**`; `__bold__`; `*em*` (not after a word character or `*`, not before a word character); `_em_` (same with `_`). Later rules also apply inside code spans (no protection), and regex `\w` is Unicode.
- Not supported: code blocks, blockquotes, horizontal rules, images, nested lists, line breaks.

## 9. Rounding, exactly

.NET (both Framework 4.x and .NET Core) implements `Math.Round(double, digits, mode)` by scaling: `v = x * 10^digits`; then for ToEven `v = Math.Round(v)` (round half to even on the scaled double); for AwayFromZero `frac = modf(v, &v); if |frac| >= 0.5: v += sign(frac)`; then `v / 10^digits`. Halves are judged on the scaled double, not on the decimal value of x. Example: `19.95` (stored just below 19.95) times 10 is exactly `199.5`, so R1 gives 20.0 while Python's `round(19.95, 1)` gives 19.9 (tested in pwsh: `[Math]::Round(19.95,1,'AwayFromZero')` = 20).

Python equivalents:
- `R1/R2`: `v = x * 10.0**d; f, i = math.modf(v); i += copysign(1, f) if abs(f) >= 0.5 else 0; return i / 10.0**d`.
- Banker's `Math.Round(x, 5)` (key, map coordinates), `Math.Round(x, 1)` (model warning pct): `round(x * 10.0**d) / 10.0**d`, which is `plan_travels.py`'s existing `ps_round`.
- `[Math]::Round(x)` (whole minutes): `round(x)` (Python rounds half to even on the double; same).
- Key formatting `ToString('0.00000', invariant)` on an already rounded double: `'%.5f' % v` should give the same digits for a value already rounded to 5 dp (inference; the golden test should confirm it on every key).
- `ToString('0.00')` for corridor weightings: .NET Core 3.0+ formats the exact binary value and rounds half away from zero on exact decimal halves (`0.125` gives `0.13`, tested); Python `'%.2f'` gives `0.12`. Use Decimal with ROUND_HALF_UP on the exact value, or only match for weightings that are not exact halves.

Measured impact on local data (naive `round()` against the emulation, usable legs): baseline 318 PSO minutes and 84 signed and absolute differences; final run 336 PSO minutes, 111 differences, 28 Google minutes.

## 10. Nondeterminism, culture and version differences to pin

- `meta.generated` and the default output file name use local time. The port should take the time as an input (or UTC) so tests can pin it.
- Sort-Object on strings (GB lists, hotspot tie, strongest weighting tie) uses current culture, case-insensitive (tested: `_x, 10, 9, a-1, a1, b1, B2, JN3S`). Python: a pinned key, recommended `(s.casefold(), s)`; differs from culture for punctuation such as `_` and `-` (none in the GB codes seen, inference).
- Group-Object: case-insensitive, and output order version-dependent (surprise 8). Pin to sorted by group name, case-insensitive, first-seen spelling.
- Dictionary enumeration (key groups, COMMON) is insertion order; Python dict matches.
- Sort stability: worst offenders tie-break on row (deterministic); "check these" has no tie-break (pwsh stable, 5.1 not guaranteed). Pin to stable sort in file order.
- Floating sums: Accuracy, over/under and corridor sums are plain left-to-right double additions in list order. Use the same order and plain `+` (not `math.fsum`) to match at halves.
- Number parsing: invariant everywhere (`Num`), so comma-decimal locales are safe. Console `N0/N1` formats are culture-dependent but console only.
- JSON writer: pwsh 7 (Newtonsoft) prints whole doubles as `20.0`, `7265.0`; 5.1 (JavaScriptSerializer) prints `20` and escapes `<`, `>`, `&`, `'` as `<` and so on (inference for 5.1, not tested). Python `json.dumps` prints `20.0` for floats and `20` for ints. Values are the same to the page either way.
- The page itself: JS `toLocaleString('en-US')` and `Math.round` are fixed; the histogram bins use differences of 1 dp numbers (float noise at bin edges), identical whatever writes the JSON.
- Encoding: CSVs read as UTF-8 (BOM tolerated); output UTF-8 without BOM.
- Under PowerShell 5.1, doubles print with 15 significant digits in strings, but nothing here stringifies a raw double except `ConvertTo-Json` and console output.

## 11. Decisions the port must make

1. Rounding: emulate .NET's scaled AwayFromZero for R1/R2 and scaled ToEven for the 5 dp key, map coordinates and model pct. Recommendation: do it exactly (helpers as in section 9); it changes about 10% of displayed values and can change flags at other thresholds.
2. Polygons as rectangles: keep the bounding-rectangle model for parity, or clip against the real polygon. Recommendation: keep rectangles in the reference (parity, and the grid imports are rectangles) and record the limitation; warn when a polygon's vertices do not form an axis-aligned rectangle.
3. Missing km on flagged map legs (0.0 instead of null). Recommendation: emit null (a documented deviation, display only; the page already handles null).
4. Group order for corridors and case handling. Recommendation: sort groups by name case-insensitively (matches pwsh 7) and group case-insensitively; record it as the pinned behaviour.
5. String sort for GB lists and tie-breaks. Recommendation: `(casefold, original)` key; document that culture collation may differ for punctuation.
6. "Check these" tie order. Recommendation: stable sort, ties in file order (matches pwsh 7).
7. Inputs: CSV files only, or also the web Plan travels tool's `legs.json`. Recommendation: the reference reads the CSVs (same contract as the script, and older exports keep working); the web app can pass the CSVs the Plan travels tool wrote, or a thin loader can map legs.json keys onto the same leg record later.
8. Same-location legs from plan_travels.py (Google 0, PSO > 0) are kept and can be flagged. Recommendation: keep the script's rule (only excluded on missing values) for parity and note it; revisit if a same-location leg ever shows up flagged.
9. Template: reuse the script's page as `travel_tuning_template.html` with the JSON in `__DATA__` (and keep the page-side arithmetic in JS). Recommendation: yes, extracted verbatim, with `__LIBS__` filled with the cdnjs tags by default; follow plan_travels.py's safer embedding (`<` written as `<`) instead of the `</` replacement, which is equivalent for JSON.parse.
10. `-Offline` and tiles in the web app. Recommendation: keep both as options; the web app can default to CDN libs and `esri-gray`. Offline needs the three lib files shipped next to the Python script.
11. Overlap warning and console summary. Recommendation: emit warnings (common legs, model check, misplaced baseline, overlaps) as a `warnings` list in the JSON or result as well as stdout, so the web app can show them; keep the count definition (pieces, not legs) and say so in the wording.
12. Generated time and output name. Recommendation: accept `generated` from the caller (default now, local), and let the web app name the output; keep the `yyyy-MM-dd HH:mm` format.
13. Parity proof. The script is PowerShell only, so there is no parity rule to keep after the port (as with plan travels). Recommendation: a golden test that runs the pwsh script and the Python port on the four local CSVs (and a synthetic polygon set, since no real polygon XML is local) and compares the JSON value by value (numbers as values, ignoring `generated`), plus synthetic unit tests for Clip, Predict, matching with repeated keys, the old/new CSV layouts and the Markdown subset.
14. Error messages: the script throws on missing columns, missing files and count mismatches with plain hints. Recommendation: keep the same checks and wording as a single error class, as plan_travels.py does.

## 12. Observed run (pwsh 7.6.6, no polygons)

Superseded as golden data on 2026-10-18: Ish replaced these four CSVs with a fresh batch with polygons, in `~/pso-data/ACME/1013/` (see the spec, section 2). The figures below describe the old batch only.

Command: the four local CSVs as `-Runs` with labels Baseline, Grid v1, Grid v2, Grid v3, output to the scratchpad. Ran in 2.6 s.

| Run | CSV rows | Usable | Excluded (no Google / no PSO / validation) | Flagged |
|---|---|---|---|---|
| Baseline | 3,846 | 3,771 | 75 (2 / 4 / 69) | 101 |
| Grid v1 | 3,803 | 3,740 | 63 (0 / 4 / 59) | 27 |
| Grid v2 | 3,828 | 3,769 | 59 (0 / 4 / 55) | 27 |
| Grid v3 | 3,830 | 3,769 | 61 (0 / 4 / 57) | 11 |

- Shared (baseline vs final): 3,187 legs. Common to all four: 2,852 (76.3% of the smallest run, no warning).
- Consecutive pairs: 2,943, 3,221 and 3,653 shared legs; fixed 59, 1, 15; newly broken 0, 0, 0.
- Baseline vs final pair stats: fixed 72, newly broken 0, still flagged 9. Newly underestimated 121 (111 within 5 to 10 min).
- Bands: six, all populated, legs summing to the 3,187 shared (no null km).
- GB summary: 47 regions flagged at baseline, 36 cleared, 11 still, 0 new.
- Check these: baseline 83 merged legs, final 54; GPS fix 0 in both (`has_fix_cols` false); 15 rows each.
- Worst offenders: 10 rows, 8 fixed.
- Map: 101 flagged baseline legs, 11 final; no null km.
- JSON top-level keys: `meta, runs, timeline, acc, bands, gb, worst, check, corridors, hotspot, strong, shared, flagged, bounds, polygons, poly_counts, text, notes`. Without polygons: `corridors`, `polygons`, `model_warn`, every `by_type` are `[]`; `hotspot`, `strong`, `base_misplaced`, every `model_ok`/`model_n` are null; run 0's `fixed`/`newly_broken`/`shared_prev` null.
- Number style: whole doubles written with `.0` (`threshold: 20.0`, `over_min: 7265.0`); ints for counts.
- Local data shape: 3,523 distinct keys in the baseline and 3,521 in the final run; in each, 157 keys repeat (248 extra legs).

Untested by local data: polygons (Read-Polygons, Clip, Predict, model check, hotspot, strong, corridors, map rectangles), review CSV, notes, GPS fix columns, `-Offline`, manifest, misplaced baseline.
