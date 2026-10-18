# Travel tuning report: design

Issue #31. This ports pso-tools `travel/Build-TravelTuningReport.ps1` (1,046 lines): Python first in pso-tools, then a
dataset tool in pso-test-tools that runs it. The behaviour reference is
`docs/superpowers/notes/2026-10-18-travel-tuning-inventory.md` (the inventory). Where this spec says nothing, the
inventory is the rule. Approved by Ish, 2026-10-18.

## 1. Goal

The question it answers: "Did this round of travel weightings make PSO's travel times closer to real road times,
and what did it break?" It compares a baseline plan travels run with one or more tuned runs of the same dataset,
together with the polygon weightings each run used. The output is the same one-page report the script builds:
- headline savings
- a map of polygons and flagged legs
- a PSO vs Google scatter
- accuracy and distance bands
- a signed error histogram and the over/under trade-off
- corridors, worst offenders, and legs to check
- the GB summary, notes, and the iteration timeline

## 2. Decisions

- **Split:** like plan travels and the activity trace. Python in pso-tools is the reference implementation, and
  the web runs it as a child process. The PowerShell script stays as the Windows version, unchanged.
- **Reference data (golden):** `~/pso-data/SATE/1013/` (local only, git-ignored). It holds:
  - Ish's Windows run, `travel_tuning_report_20261013_2235.html`: "Prod (no polygons)" =
    `plan_travels_output_north_initial.csv`, and "Grid v6" = `plan_travels_output_pass2.csv` with
    `PSO_Sys_pass_1.xml` (251 polygons: 160 Grid, 82 Corridor, 9 other).
  - A pwsh 7 run of the script on the same inputs.

  The two match on every value except the order of four corridor groups that have no trips in either run (a
  tie the script leaves unordered).
- **Polygons stay rectangles.** The script reduces every polygon to its bounding rectangle. All 251 real polygons
  are axis-aligned rectangles, so the port keeps that model and warns when a polygon is not one (count only).
- **Out of scope:** writing weightings back to PSO, suggesting weightings, the South baseline (no tuned pass), and
  any change to the PowerShell script.

## 3. pso-tools: `travel/travel_tuning.py` and `travel/travel_tuning_template.html`

It sits next to the script and `travel/lib/` (Chart.js 4.4.1, Leaflet 1.9.4), which `--offline` inlines. It uses
the standard library only, on Python 3.9+. Polygon files are streamed with `iterparse`, because a full SYS export
can be large (the 251-polygon one is 4.4 MB).

### 3.1 Command

```
travel_tuning.py --manifest <csv> --out <html> [--json <path>] [--title ..] [--client ..] [--dataset ..]
                 [--region-label GB] [--review-minutes 20] [--small-minutes 5] [--model-tol-min 1]
                 [--model-tol-pct 5] [--long-leg-km 300] [--tiles esri-gray|esri|osm|none] [--offline]
                 [--review-csv <csv>] [--notes <md>] [--generated "YYYY-MM-DD HH:MM"]
travel_tuning.py --runs a.csv b.csv .. [--labels ..] [--polygons '' p.xml ..]   (instead of --manifest)
```

- **Manifest:** a CSV with the columns `Label,RunCsv,PolygonXml`, the same as the script's. It lists the
  baseline first and the final run last, and needs at least two runs.
- **Defaults:** the same as the script's parameters (inventory 1). The web always passes a manifest.
- **Outputs:**
  - The page.
  - With `--json`, the report data on its own (for tests and the web summary).
- **Generated time:** taken from `--generated`, so tests can pin it. Default: now, local time.
- **Messages:** the exit codes and the `Stopped during: <stage>: <sentence>` lines follow plan_travels.py, with one
  error class (`TravelTuningError`).
- **Warnings:** each goes to stderr as `warning: <text>` and into `meta.warnings`. There are five:
  - common legs under 60%
  - a model check under 80%
  - a misplaced baseline
  - leg pieces inside more than one polygon
  - polygons that are not rectangles

  Warnings carry no ids, only labels and counts.
- **Summary:** the last stdout line is `summary: <text>`, for example "Prod (no polygons) to Grid v6: flagged 75 to
  10, total error 8,294 to 5,889 min, 44 fixed, 0 newly broken". It has no ids.

### 3.2 Rules

Exactly inventory sections 2 to 7. That covers:
- reading the run CSVs (old and new layouts, both the Windows and web writers)
- exclusions
- matching legs across runs on the 5 dp coordinate key, n-th occurrence to n-th occurrence
- the four leg populations
- every calculation
- the polygon reader, Clip / Crossings / WeightAt / Predict, and the model check
- the hotspot, strongest weightings and corridors
- check these, map data, the JSON shape, and the Markdown subset

The page is the script's template, extracted verbatim: the page-side arithmetic stays in JavaScript.

Pinned behaviour, where the script is ambiguous or version-dependent:

1. **Rounding:** R1 and R2 copy .NET's scaled away-from-zero rounding, and the 5 dp key and the map coordinates copy
   its scaled half-to-even rounding (inventory 9). Python's `round()` would change about 10% of times.
2. **Corridor groups:** grouped case-insensitively and sorted by group name case-insensitively, which is pwsh 7's
   order. On the golden data this matches pwsh exactly, and the Windows output only on the order of the four
   no-trip groups.
3. **String sorts** (GB lists, ties): key `(casefold, original)`.
4. **"Check these" ties:** a stable sort in file order.
5. **Sums:** plain left-to-right addition in list order (not `fsum`), so that values that land on halves round the
   same way as the script.
6. **Corridor weightings** (`0.00` text): formatted as pwsh 7 does: round to 15 significant digits, then halves away
   from zero (`(1.005).ToString('0.00')` gives `1.01`). Corrected while planning.
Deviations from the script, each listed on the page's footer:

1. A flagged map leg with no distance gets `km: null` instead of `0.0`. The popup no longer says "0 km".
2. Warnings show on the page (script: console only).
3. JSON is embedded with `<` written as `<` (plan_travels.py's rule) instead of replacing `</`.

### 3.3 Tests (`tests/travel_tuning/`)

- **Golden** (skipped when `~/pso-data/SATE/1013/` is absent): the Python JSON against the pwsh run, compared value
  by value. Only `meta.generated` and the null km deviation are ignored. Against Ish's Windows report, the corridor
  rows are also compared as a set. Pinned numbers:
  - Prod: 3,463 usable legs, 75 flagged.
  - Grid v6: 3,369 usable legs, 10 flagged, 44 fixed, 0 newly broken, model check 1,055 of 1,056.
  - 2,112 shared legs; average error 2.58 to 1.85 min.
  - 39 GBs flagged at baseline, 30 cleared.
  - Hotspot G052 (9 of 42 newly underestimated legs).
  - 18 corridor groups.
- **Synthetic:** rounding at halves, matching with repeated keys, the old CSV layout (no `Google Duration (sec)`, no
  GPS fix columns), Clip and Predict on overlapping rectangles, the non-rectangle warning, the manifest and its
  errors, and the Markdown subset.
- A pwsh run of the script on the synthetic polygon set, kept as a fixture, so overlap and review-CSV paths are
  checked against the script and not only against our reading of it.

## 4. pso-test-tools: Travel tuning tool

A tool, `Tool::TravelTuning`, under Analysis tools and in the wizard. It accepts Unfiled
(`Tool::acceptsUnfiled()` true), like Plan travels: plan travels runs can belong to a dataset or to a user's Unfiled
scope. All runs in one report come from the same scope as the run being started (amended with Ish, 2026-10-18).

### 4.1 Inputs: runs, not files

This is the first tool whose inputs are other runs. The wizard's Inputs step shows the finished Plan travels runs
of the chosen scope (the dataset's, or the user's Unfiled runs) that have Google columns, with these columns:
- the date range
- when the run was made
- legs
- flagged
- whether it has Google

The user picks two or more of them and puts them in order (baseline first) with up and down buttons. Each picked
run gets:

- **Label:** editable. It defaults to "Baseline" for the first, then "Run 1", "Run 2" (the script's defaults).
- **Polygons:** optional. A SYS export of the dataset's environment (from Unfiled: one of the user's Unfiled SYS exports) (a polygon import XML is a `DsSystemData`
  file, so it is stored as SYS too), or "None". Every SYS export is checked for a `Polygon` table when it is picked.
  One without polygons is refused with "This SYS export has no Polygon table".

A run without Google columns cannot be picked. Its row says "Run plan travels again with Google on".

### 4.2 Options

- Title, client, dataset name: prefilled from the account and dataset.
- Review threshold (minutes), small error (minutes), model tolerance (minutes and %), long leg (km).
- Region label: prefilled from the account settings, else GB.
- Map tiles: esri-gray by default.
- Notes: a Markdown text box. It replaces the notes file and is rendered by the same Markdown subset.
- Review CSV: not on the web in this version. The corridor rows then fall back to the polygon description, as in the
  script without `-ReviewCsv`.

The page loads Chart.js and Leaflet from cdnjs. There is no offline mode on the web.

### 4.3 Running

`TravelTuningTool::run` writes the following to the scratch directory, runs `travel_tuning.py` through
`ScriptRun`, and stores the page:
- each picked run's stored CSV (from its encrypted result)
- each picked SYS export, decrypted to a temporary file and deleted after the run
- a manifest that lists them

The stored result is the HTML (encrypted). `ToolRun.summary` holds counts only:
- runs
- flagged in the baseline and the final run
- total error hours
- fixed and newly broken
- shared legs

Warnings are the script's, which carry no ids. A SYS export holds live credentials, so it is never copied anywhere
but the scratch directory. Only the polygon numbers reach the page.

The run page shows the report in the sandboxed iframe, like plan travels, plus a Download HTML button.

### 4.4 When the source runs go

A tuning run keeps its own copy of the page, so it outlives the plan travels runs and SYS exports it was built
from, which expire on their usual schedule.

## 5. Order of work

1. Python port and tests in pso-tools (plan A). The PowerShell script and the Windows bundle stay unchanged.
2. The web tool in pso-test-tools (plan B), after Ish has checked the Python page against his Windows report.

## 6. Answered by Ish (2026-10-18)

1. Web inputs are plan travels runs only. CSVs from the Windows script stay a Windows-only route.
2. No review CSV on the web. Route names and map notes come from the polygon descriptions (`Corridor w0.67 | <route>`), as in Ish's 2026-10-13 report.
