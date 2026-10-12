# Notes: tools that overlap between pso-tools and this app

Date: 2026-10-12. Read-only review, nothing changed. Input for the specs of the remaining ports.
"Confirmed" means read in the code; "inference" means reasoned from the code, not tested on data.

Remaining ports after this review (agreed 2026-10-12): gateway upload parity (#23), sys compare (#14),
appointment timeline, plan travels (merged with Travel Analyzer, section 1), Remove-PsoTables (merged with
Filter Load File, section 2), Find-XmlElementMatches, and the travel tuning report last.
Find-ActivityStatusSlaMismatch is not wanted on the web for now. The schedule review is a separate product.

## 1. Travel Analyzer (web) vs Build-PlanTravelsReport.ps1

They answer different questions with the same idea (PSO travel time against Google).

| | Travel Analyzer (`app/Filament/Pages/TravelAnalyzer.php`) | Build-PlanTravelsReport.ps1 (pso-tools `scripts/`, 2,032 lines) |
|---|---|---|
| Question | "What does PSO think one trip takes, right now?" | "Across a whole plan, which legs does PSO get wrong, and by how much?" |
| Input | Two points typed in (geocode button, "Random NYC Spots") | A dsScheduleData export with plan output (`Plan_Travel`) |
| PSO side | Live: pso-services `POST travelanalyzer` sends a `Travel_Detail_Request` with a once-only `SCHEDULING_TRAVEL_ANALYSER` broadcast; PSO posts `Travel_Detail` back to pso-services, which calls `/api/travel/callback` here; the page polls the cache for 2 minutes | Offline: reads `Plan_Travel` rows already in the export |
| Google side | **Distance Matrix API** (legacy), from pso-services, departure traffic not pinned | **Routes API** `computeRoutes`, `TRAFFIC_UNAWARE` by default (PSO has no live traffic) |
| Scale | 1 leg | Every leg for 1 to 31 days, Google calls cached in `google_routes_cache.csv` (coordinates and times only), `-SeedCacheFrom` old CSVs |
| Output | A PSO vs Google table on the page | One CSV per run: leg per row, Google duration and distance, ratio, % divergence, Flag (OK / Review / Skipped / Missing Google Time / Missing PSO Time), estimated weighting, Google Maps link, Siebel Call ID and Order Number |
| Scope | Environment (needs a live PSO login) | Dataset (an export) |

What the report knows that the analyzer does not (confirmed, all hard-won, keep in any port):
- Leg building: travels to an activity from a shift start or another activity, plus the end-of-day leg home;
  legs touching a PRIVATE activity class or a NON-AVAILABILITY type are dropped.
- `Plan_Travel` fragments merged into one leg: split by a break, split around the shift start (with the
  "duplicate" case where both halves carry the whole trip, counted once, and the 15-minute pre-shift part shown
  apart), and GPS FIX legs (start from the technician's live position; the pre-fix catch-up fragment excluded).
- Review flag by minutes and optionally by percent, so long trips a few percent off are not flagged.
- Estimated weighting = Google / PSO ratio, the direction a travel weighting fix would need.
- `-BuildImportXml`: a second output, a trimmed import XML. See section 2: it is a third trimming tool.

Implementation notes for the port (confirmed):
- The report loads the whole export with `[xml]` (line 351), against this repo's own PowerShell rule; only the
  import XML pass streams (compiled C#). A port must stream.
- PowerShell only, no Python twin, so the "run the pso-tools Python" pattern does not apply as is: either write
  `plan_travels.py` in pso-tools first (then run it like the load audit) or port to PHP.
- Google key: today Ish's own key from `GOOGLE_MAPS_API_KEY`. The app already has `psott.google_api_key`.
- The cache holds no PII, so it can be a shared table here (keyed by rounded coordinates, mode, preference).

Suggested shape (inference, to settle in the spec): keep them as two things.
- Plan travels becomes a dataset tool on the export-sets contract (input: a plan export; options: date, days,
  thresholds, Google on/off). Result: the leg table on the page plus the CSV download.
- Travel Analyzer stays the live single-leg check. Two small joins worth having: a "check this leg in PSO" link
  from a report row that opens the analyzer prefilled, and moving the analyzer's Google call from Distance
  Matrix to Routes `TRAFFIC_UNAWARE` so both tools compare against the same Google number. (Distance Matrix is
  the legacy API; that change is in pso-services.)
- The "Random NYC Spots" button is a demo aid; leave it.

## 2. Filter Load File (web) vs Remove-PsoTables.ps1 (and the plan travels import XML)

Three tools trim a dsScheduleData export, each a different way:

| | Filter Load File (`FilterLoadFile.php`, `ProcessResourceFile`, `ResourceActivityFilterService`) | Remove-PsoTables.ps1 (281 lines) | Plan travels `-BuildImportXml` |
|---|---|---|---|
| Cuts | **Rows**: by region, resource, activity type, activity id, date range; optional Input_Reference datetime override | **Whole tables**: default cleanup list (plan output, appointments, schedule events and exceptions, broadcasts, URLs, source data, Additional_Attribute...), or `-Remove`, or `-Keep`; `-List` counts only | Whitelist of 26 input tables, then rows by date (Shift, Shift_Break, Activity_SLA, Activity by SLA overlap or fixed time for PRIVATE, child rows, Locations referenced) |
| Format | **JSON only** | XML, or the largest XML in a zip | XML |
| Memory | Whole file `json_decode`d (100 MB upload cap) | Streams (compiled C#) | Streams (compiled C#) |
| Integrity | See below | Whole tables only, so no row-level breakage; warns when a kept table depends on a removed one (dependency map in the script) | Cascades by design; Availability, Resource_Region_Availability, Resource_Skill_Availability deliberately kept whole (the 112 -> 4 incident) |
| Output | Filtered JSON on the `public` disk, zipped over 8 MB, served by `/download/{filename}` | `<name>.clean.xml` / `.kept.xml` next to the input | `<input>_import.xml` |

Filter Load File integrity findings (confirmed in `ResourceActivityFilterService::assembleFilteredData`, which
starts from `array_merge($this->data, ...)` so every table it does not list is passed through untouched):
- Rebuilt: Resources, Location, Shift, Shift_Break, Resource_Region, Resource_Skill,
  Resource_Region_Availability, Availability, Activity, Activity_SLA, Activity_Status, Activity_Group.
- Not touched, so rows for removed activities and resources stay in the file: **Activity_Skill,
  Additional_Attribute, Activity_Custom_URL, Resource_Skill_Availability, Resource_Custom_URL**, and any plan
  output (Plan_*, Allocation). That breaks this repo's cascade rule (removing anything must cascade through every
  dependent child, with referential checks).
- Availability is cut to the ids referenced by the kept Resource_Region_Availability rows. Inference: any
  Availability row referenced only by Resource_Skill_Availability (or another table) is dropped while the row
  pointing at it stays. Needs checking on a real export before relying on either reading.
- Good: Availability, Resource_Region_Availability and Resource_Skill_Availability are not filtered by date.
  Shifts and activities (by SLA window overlap) are.
- The download goes through the unauthenticated `/download/{filename}` route: this page is the reason that route
  exists, so #19 and this tool should be fixed together.

Suggested shape (inference, to settle in the spec): one "Trim export" dataset tool on the export-sets contract,
reading an uploaded export (XML or JSON, zip or not), streaming, with two steps that can be combined:
1. Tables: remove the cleanup list, a named list, or keep only a named list (Remove-PsoTables semantics, its
   dependency warnings and `-List` preview).
2. Rows: by region, resource, activity type, activity and date (Filter Load File's options), with a full cascade
   through every child table, availability kept whole, and a referential check at the end that lists anything
   still pointing at nothing.
Output becomes an export file in the dataset (so it can go straight to Gateway Upload) instead of a public-disk
download. The plan travels import XML then becomes a preset of this tool (its 26-table whitelist plus a date),
not a feature of the travel report. Filter Load File is retired once this ships, as Technician Availability was.

Answered by Ish 2026-10-12:
- JSON input is still needed: the tool must read both XML and JSON (Remove-PsoTables and the plan travels pass
  are XML only today).
- The Input_Reference datetime override is used: keep it as an option.
