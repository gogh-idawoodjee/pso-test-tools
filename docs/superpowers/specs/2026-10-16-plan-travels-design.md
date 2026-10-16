# Plan travels: design

Port of pso-tools `scripts/Build-PlanTravelsReport.ps1` as a dataset tool in pso-test-tools, with the Google side
in pso-services. Issues: pso-test-tools #29 (this port), #27 (Travel Analyzer on Google Routes), pso-services #43
(its foundation). Behaviour reference: `docs/superpowers/notes/2026-10-16-plan-travels-inventory.md` (the
inventory); where this spec is silent, the inventory is the rule.

## 1. Goal

Answer "how long are the planned travels in this export, and do they match real road times?" from a plan export
on the web, with the same legs the PowerShell script produces, optionally checked against Google Routes, as a
page on the run and a CSV download.

## 2. Decisions (settled with Ish, 2026-10-16)

- **Split:** leg building in Python in pso-tools (the reference implementation, run by the web as a child process
  like the load audit and the activity trace); Google Routes in pso-services behind one endpoint with a database
  cache; pso-test-tools orchestrates and shows the result. One Google implementation, one cache.
- **Cache:** a pso-services table, seeded once from `pso-tools/scripts/google_routes_cache.csv` (coordinates and
  times only, no PII). The Windows script keeps its own CSV cache until it is retired.
- **PowerShell:** unchanged, like the activity trace: Python becomes the reference and the script stays as the
  Windows version until the web replaces it. No parity rule between them beyond the golden test below.
- **Out of scope:** `-BuildImportXml` (moves to the trim export tool, #28), zone aggregation (#43 later), any
  write-back to PSO.

## 3. Architecture

```
pso-test-tools                 pso-tools (checkout on the box)        pso-services
PlanTravelsTool.run()          plan_travels.py build  ----legs.json,  POST /api/v2/routes/compute
  1. run build  ------------->   legs.csv, plan_travels.html            GoogleRoutesClient
  2. unique pairs ---------------------------------------------------->  google_routes table (cache)
  3. run merge-google --------> plan_travels.py merge-google            routes.googleapis.com
       <-- enriched json, csv, html
  4. store html, csv, summary; run page shows the html in a sandboxed iframe + CSV download
```

Without Google (the default) steps 2 and 3 are skipped and the build output is the result.

## 4. pso-tools: `plan-travels/plan_travels.py` and `plan_travels_template.html`

Standard library only, Python 3.9+. Streams the export with `iterparse` (no `[xml]` style DOM); memory stays flat on
a 135 MB file. Accepts `.xml` or a `.zip` holding one XML (same rule as the other tools).

### 4.1 Commands

```
plan_travels.py build --export <xml|zip> --date YYYY-MM-DD [--days 1..31] --out-dir <dir>
                      [--template <html>] [--review-minutes 20] [--review-pct 0]
plan_travels.py merge-google --legs <legs.json> --google <google.json> --out-dir <dir>
                      [--template <html>] [--review-minutes 20] [--review-pct 0]
```

`build` (with an optional `--dataset-name` for the page header) writes `legs.json`, `plan_travels.csv` and `plan_travels.html` to the out dir. `merge-google` reads the
legs JSON and a Google results JSON (section 6.3) and rewrites all three with the Google columns and flags.
Exit 0 with zero legs (the page says so; the CSV has only the header). Exit 1 on a missing file or a root that is
not `dsScheduleData`. Warnings (unplaced fragments and the other inventory section 4 lines) go to stderr prefixed
`warning: ` and into `legs.json` under `warnings`, so the web shows them on the run.

### 4.2 Leg rules

Exactly the inventory's sections 3 to 6, including the three-pass order (GPS fix chains, then start-of-day pairs,
then break groups), Resolve-Fragments (whole-metre rule), the nine Merge Type values, the exclusion of legs touching
PRIVATE `activity_class_id` or NON-AVAILABILITY `activity_type_id` on either end, the end-of-day leg, the Direction
labels, coordinate sources (Location, or the FIX for a GPS fix leg), Siebel Call ID and Order Number from
Additional_Attribute, the Google Maps link, and the per-column rounding. Known script limitations (inventory 11)
are kept, not fixed, except these deliberate deviations:

1. Case: lookups keyed by ID are case-sensitive (repo rule); the PRIVATE / NON-AVAILABILITY and `FIX` comparisons
   and the Additional_Attribute labels stay case-insensitive as in the script.
2. Numbers: parsed invariant (no locale); printed shortest round-trip like pwsh and Python.
3. Rounding: the .NET way the script uses (`[math]::Round`: scale by the decimal places, round half to even on
   the scaled value, unscale), implemented as `ps_round`. Python's own `round` on the binary value differs on 163
   golden cells (for example 2,685 m is 2.68 km in the script and 2.69 with `round`), so it is not used.
4. Row order: the CSV and page sort by resource id, then leg start time, then destination activity id (the script's
   order is not reproducible).
5. Flag with PSO time 0 s and a Google time: `Missing PSO Time` (the script's fall-through said Missing Google
   Time). Recorded in the page's notes.

### 4.3 Output columns

The 30 columns of inventory section 9 in that order, then the 11 Google columns of section 7 when Google results
are merged. Column names are the script's, verbatim, so the travel tuning port (#31) and existing spreadsheets keep
working. `legs.json` holds the same rows as objects with snake_case keys plus `meta` (export name, date range,
counts, thresholds, Google on/off, warnings).

### 4.4 Page

`plan_travels_template.html` filled with `__DATA__` (the legs JSON) and `__TITLE__`, in the house style of the other
tool pages (light and dark, plain English, no em dashes). Contents:

- Header: dataset, date range, export name, leg count, and when Google ran: cached / fetched / skipped counts and
  the review thresholds.
- Summary tiles: legs, resources, Review legs, Missing legs (PSO or Google time), average ratio on compared legs.
- Leg table: every column, with client-side filters on resource, direction, merge type, flag and a text search;
  sort on any column; the Google Maps link opens in a new tab; a "Check in PSO" link per leg (section 7.3).
- Warnings panel listing the build warnings.
- Download CSV button (the CSV is served by the web app; on the CLI the file sits beside the page).

### 4.5 Tests (`tests/plan_travels/`)

- Synthetic unit tests, one per merge rule, on small hand-written exports: break split (sum), break trip plus slice,
  break duplicate, start-of-day trip plus slice (Pre-Shift columns), start-of-day duplicate, start-of-day unpaired,
  GPS fix chain with pre-fix catch-up, synthetic `!%!%FIX` chain, dead end at the fix, exclusions on each end,
  end-of-day leg, missing coordinates, zero legs, a zip input.
- Golden test (skipped when the file is absent): `~/pso-data/SATE/1005/north_travel_1005.xml` with
  `--date 2026-10-05 --days 5` against `~/pso-data/SATE/1005/golden/plan_travels_2026-10-05_5d.csv` (1,781 rows,
  pwsh output, no Google). Rows sorted on Resource ID, Activity ID (Destination), Previous Activity ID, Plan ID;
  every one of the 30 columns equal, numeric columns compared as decimals. Also asserts the inventory's counts
  (765 / 515 / 501 by direction; 13 Break, 8 GPS fix, 6 Start of day (duplicate), 1 Start of day; 21 rows with 2
  fragments).
- A merge-google test with a hand-written Google JSON covering OK, Review on minutes only, Review needing the
  percent too, Skipped, Missing Google Time, Missing PSO Time, and the weighting value.

## 5. pso-services: Google Routes

### 5.1 `GoogleRoutesClient`

One class calling `https://routes.googleapis.com/directions/v2:computeRoutes` with the script's request: headers
`X-Goog-Api-Key` and `X-Goog-FieldMask: routes.duration,routes.distanceMeters`, body `origin`/`destination`
latLng, `travelMode`, `routingPreference`, `units: METRIC`. Returns duration seconds and distance km, or an error
string (`No route returned`, or the HTTP status and message). Timeout 10 s, no retry. A configurable delay between
calls (`GOOGLE_ROUTES_DELAY_MS`, default 150). Key: the server's `pso-services.settings.google_key`, with the
existing `googleApiKey` passthrough convention kept for the Travel Analyzer.

### 5.2 Cache table `google_routes`

`origin_lat`, `origin_lng`, `dest_lat`, `dest_lng` as `decimal(9,6)` (the script's F6 key), `travel_mode`,
`routing_preference`, `duration_sec` (unsigned int), `distance_km` (decimal 8,2), `fetched_at`, `source` (`google`
or `seeded:<file>`), unique index on the six key columns. Only successful results with duration > 0 are stored.
Nothing else is stored: no activity, resource or customer identifiers.

Seeding: `php artisan google-routes:seed <csv>` reads the script's cache CSV (`Key, DurationSec, DistanceKm,
FetchedUtc`), inserts missing keys, reports inserted / skipped. Run once on the box by Ish with the CSV copied there.

### 5.3 Endpoint `POST /api/v2/routes/compute`

Request (JSON, `Accept: application/json`):

```json
{ "travelMode": "DRIVE", "routingPreference": "TRAFFIC_UNAWARE",
  "legs": [ { "id": "any string", "origin": { "lat": 52.1, "lng": -106.6 }, "destination": { "lat": 52.2, "lng": -106.7 } } ] }
```

Validation (422 `{message, errors}`): 1 to 200 legs, ids unique, coordinates in range, mode one of DRIVE, WALK,
BICYCLE, TWO_WHEELER, TRANSIT; preference one of TRAFFIC_UNAWARE, TRAFFIC_AWARE, TRAFFIC_AWARE_OPTIMAL.

Response 200:

```json
{ "results": [ { "id": "…", "durationSec": 4374, "distanceKm": 117.04, "source": "cache" } ,
               { "id": "…", "error": "No route returned" } ],
  "counts": { "cache": 1, "google": 0, "error": 1 } }
```

Cache first per leg (rounded to 6 dp); misses call Google in sequence with the delay; successes are stored. If the
key is not configured, 503 `{message: "Google Routes is not configured on pso-services."}` before any work. The
general `throttle:api` applies per request, not per leg. No PSO logon is involved, so `DisallowProdUrl` does not
apply. README gets a section like the session one.

### 5.4 Travel Analyzer switch (#27)

`TravelService::getDistanceMatrix` is replaced by a call to `GoogleRoutesClient` through the same cache, with
`DRIVE` and `TRAFFIC_UNAWARE`. The values handed back keep the shape the page renders (`duration.value` seconds,
`distance.value` metres and their text forms), so `travel-analyzer.blade.php` is unchanged. The existing warnings
(key missing, denied, no route) keep their wording.

### 5.5 Tests

Pest: validation cases; cache hit returns without calling Google (`Http::fake` asserts nothing sent); miss calls
Google with the exact headers, body and field mask, stores the row, and a second call is a hit; Google error is
returned per leg and not cached; 503 without a key; seed command on a three-row CSV with one duplicate; Travel
Analyzer result shape unchanged with the new client.

## 6. pso-test-tools: the tool

### 6.1 Registration

`Tool::PlanTravels` already exists with its entry page stub. `PlanTravelsTool` implements the `Tool` contract:
required kind `plan` (the plan export), no optional kinds, scope dataset. The wizard's existing Where > Inputs >
Options > Run flow applies.

### 6.2 Options

- First date (required; default the export's Input_Reference date from the export summary when known).
- Days (1 to 31, default 1).
- Check against Google Routes (toggle, default off; helper text names the cost: one Google call per trip not
  already in the shared cache).
- Travel mode (select, default DRIVE) and routing preference (select, default TRAFFIC_UNAWARE), shown when the
  toggle is on.
- Review threshold minutes (default 20) and percent (default 0 = minutes only), shown when the toggle is on.

### 6.3 Run pipeline (`PlanTravelsTool::run`)

1. `ScriptRun::execute` of `plan_travels.py build` in the run's work dir (timeout from `config/tools.php`, default
   600 s). Progress: "Building legs".
2. With Google on: read `legs.json`, collect the distinct (origin, destination) pairs with coordinates, post them to
   pso-services `/routes/compute` in chunks of 200 through a `PsoServicesRoutesClient` (same base URL and JSON
   conventions as `PsoServicesSessionClient`), reporting progress per chunk ("Checking trips with Google, 3 of 9").
   A chunk failure (network, 503, 5xx) stops the run with the error as the run's failure message; the build output
   is kept so the run still shows the legs without Google columns and says why.
3. Write `google.json` keyed by each leg's `route_key` from `legs.json` (the script's cache key shape,
   `"<oLat>,<oLng>|<dLat>,<dLng>"` with six decimals; copied verbatim, never recomputed in PHP), each value
   `{ durationSec, distanceKm, source }` or `{ error }`, and run
   `plan_travels.py merge-google`. Progress: "Merging Google results".
4. Store `html`, `csv` and `data` (the legs JSON) in the encrypted result; summary (plain): leg count, resources,
   review count, missing count, google counts, date range. Warnings from `legs.json` become the run's warnings.

### 6.4 Run page

`resources/views/filament/tool-runs/plan_travels.blade.php`: the summary line and badges (legs, Review, Missing),
Download CSV and Download JSON buttons, and the page in the same sandboxed iframe the trace uses. New route
`tool-runs.download-csv` serving `result['csv']` as `plan_travels_<dataset>_<date>.csv`.

### 6.5 "Check in PSO"

Each leg links to the Travel Analyzer prefilled: `/travel-analyzer?lat_from=&long_from=&lat_to=&long_to=`.
`TravelAnalyzer::mount` reads those query values into the travel form when all four are present and valid. The
link opens in a new tab from the iframe (the sandbox allows popups).

### 6.6 Tests

Pest: command builder for both commands; the tool with a fake pso-tools checkout (a stub `plan_travels.py` that
writes known files) and `Http::fake` for pso-services, covering Google off, Google on with cache and google
sources, a 503 from pso-services (run fails with the message, legs kept), chunking at 200; the run page renders
badges and buttons; CSV download route is behind panel auth; Travel Analyzer prefill from the query string.

## 7. Security and data

- The Google key never leaves pso-services (the tool does not send one). The cache holds coordinates and times
  only. Leg data (names, activity ids, descriptions) lives only in the encrypted run result, as for other tools.
- The page runs in the sandboxed iframe; its only outbound links are Google Maps and the app's own Travel Analyzer.
- No production PSO URL is touched: this tool reads an export and calls Google.

## 8. Performance

- Build on the 135 MB test file: target under 30 s and under 200 MB RSS in Python (the pwsh DOM run takes 4 s but
  holds the whole file; streaming is the repo rule).
- Google: 1,781 legs is about 1,800 calls worst case at 150 ms, about 5 minutes, inside the tool timeout; with the
  seeded cache most SaskTel trips are hits. Progress is reported per chunk so the run page shows movement.

## 9. Plans

One implementation plan per repo, executed in the order below (pso-tools first, since the other two consume its
output files; pso-services second, since the tool's Google step calls it).

## 10. Rollout

1. pso-tools: `plan-travels/` with tests; bundle unchanged (the Windows script stays).
2. pso-services: client, migration, seed command, endpoint, analyzer switch, README. Ish runs the seed once.
3. pso-test-tools: tool, options, run page, CSV route, analyzer prefill, config and `.env.example` entries.
4. Browser check by Ish on a SaskTel plan export with Google off, then on with the cache seeded; close #29 and #27.
