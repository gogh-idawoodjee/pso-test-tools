# Plan Travels (pso-tools Python) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Python reference implementation of `scripts/Build-PlanTravelsReport.ps1` (the report, not `-BuildImportXml`): `plan_travels.py build` turns a plan export and a date range into the same travel legs the script produces (`legs.json`, `plan_travels.csv`, `plan_travels.html`), and `plan_travels.py merge-google` adds Google Routes results and review flags to those three files.

**Architecture:** One module, `plan-travels/plan_travels.py`, standard library only. A streaming reader (`iterparse`, namespace stripped, elements cleared) keeps only the seven tables the report needs. Legs are built in the script's fixed order: GPS fix chains, then start-of-day pairs, then break groups, each applying the whole-metre rule (`resolve_fragments`), then the Step 4 date filter, exclusions and enrichment. Outputs are written from one dict (`{"meta": ..., "legs": [...]}`), which is also what the page template is filled with. pso-test-tools runs `build`, sends `route_key`s to pso-services, writes `google.json` and runs `merge-google`. The PowerShell script is not changed.

**Tech Stack:** Python 3.9+ stdlib (`argparse`, `csv`, `decimal`, `json`, `re`, `zipfile`, `xml.etree.ElementTree.iterparse`, `unittest`), one self-contained HTML template (vanilla JS, no CDN).

**Spec:** pso-test-tools `docs/superpowers/specs/2026-10-16-plan-travels-design.md` (sections 1 to 4, 7, 8). Behaviour reference: `docs/superpowers/notes/2026-10-16-plan-travels-inventory.md` (sections 3 to 9 are the rules; where they differ from the spec, spec section 4.2 deviations win). Source script: pso-tools `scripts/Build-PlanTravelsReport.ps1`. Patterns copied from pso-tools `activity-trace/trace_activity.py` and `tests/activity_trace/`.

All work in pso-tools (`/Users/idawoodjee/Documents/Projects/PSO Tools`), branch `plan-travels` from main. The pso-services plan (`2026-10-16-plan-travels-services.md`) and the pso-test-tools plan consume the files defined under Interfaces; do not change those shapes without changing both plans.

---

## Interfaces (consumed by the other two plans)

### CLI

```
python3 plan-travels/plan_travels.py build --export <xml|zip> --date YYYY-MM-DD [--days 1..31] --out-dir <dir>
        [--dataset-name <name>] [--template <html>] [--review-minutes 20] [--review-pct 0]
python3 plan-travels/plan_travels.py merge-google --legs <legs.json> --google <google.json> --out-dir <dir>
        [--template <html>] [--review-minutes 20] [--review-pct 0]
```

- `build` writes `legs.json`, `plan_travels.csv`, `plan_travels.html` into `--out-dir` (created if missing). `--dataset-name` is optional (shown in the page header; `meta.dataset_name`, null when not given).
- `merge-google` reads `legs.json` and `google.json` and rewrites all three files in `--out-dir` with the Google columns filled. Its `--review-minutes` / `--review-pct` replace `meta.thresholds`. Running it twice is safe (the Google keys are recomputed).
- `--template` defaults to `plan_travels_template.html` next to the script.
- Exit 0 with outputs, also with zero legs (CSV is the header only, `legs` is `[]`, the page says so). Exit 1 on any failure: bad arguments, missing file, a root that is not `dsScheduleData`, unreadable XML or JSON, missing template. stderr then has `Stopped during: <stage>: <plain sentence>` for a known failure, else `Stopped during: <stage>` and `<ExceptionClass>: <message>` on the next line.
- stderr: `warning: <text>` per build warning (also in `meta.warnings`); with `PSO_PROGRESS=lines`, `PROGRESS {"stage":"reading","percent":N}` lines (0 first, 100 last). stdout: last line `summary: <text>`.

### CSV columns (verbatim, inventory section 9, in this order)

```
Resource ID, Resource Name, Direction, Previous Activity ID, GB (Previous Activity), Previous Activity Type,
Previous Activity Description, Activity ID (Destination), GB (Destination Activity), Destination Activity Type,
Destination Activity Description, Start Location Latitude, Start Location Longitude, End Location Latitude,
End Location Longitude, Distance (km), Expected Travel Time (min), Expected Travel Time (sec), Merged Fragments,
Merge Type, GPS Fix, GPS Fix Time, Pre-Fix Distance (km), Pre-Fix Travel (min), Pre-Shift Distance (km),
Pre-Shift Travel (min), Google Maps Directions Link, Plan ID, Call ID, Siebel Order Number
```

Then, only when `meta.google` is true, the 11 Google columns (inventory section 7, verbatim, in this order):

```
Google Duration (min), Google Duration (sec), Google Distance (km), Google Source, Ratio (Google / PSO),
% Divergence, Abs Divergence (min), Difference, Flag, Estimated Weighting Adjustment, Validation Error
```

CSV file: UTF-8 with BOM, every value quoted (`csv.QUOTE_ALL`), null written as an empty string. Numbers are printed shortest round-trip with no trailing `.0` on whole values (`52`, `14.2`, `52.809507318624306`). `GPS Fix` is `Yes` or empty.

### `legs.json`

```json
{
  "meta": { ... },
  "legs": [ { ... one object per leg, in CSV row order ... } ]
}
```

Each leg object has exactly these 45 keys (always present; null when the CSV cell would be empty, except where noted):

| # | CSV column | key | JSON type |
|---|---|---|---|
| 1 | Resource ID | `resource_id` | string |
| 2 | Resource Name | `resource_name` | string or null |
| 3 | Direction | `direction` | `"Start Location -> Activity"`, `"Activity -> Activity"` or `"Activity -> End Location"` |
| 4 | Previous Activity ID | `previous_activity_id` | string, `""` when none |
| 5 | GB (Previous Activity) | `previous_gb` | string or null |
| 6 | Previous Activity Type | `previous_activity_type` | string or null |
| 7 | Previous Activity Description | `previous_activity_description` | string or null |
| 8 | Activity ID (Destination) | `activity_id` | string, `""` on an end-of-day leg |
| 9 | GB (Destination Activity) | `destination_gb` | string or null |
| 10 | Destination Activity Type | `destination_activity_type` | string or null |
| 11 | Destination Activity Description | `destination_activity_description` | string or null |
| 12 | Start Location Latitude | `start_lat` | number or null |
| 13 | Start Location Longitude | `start_lng` | number or null |
| 14 | End Location Latitude | `end_lat` | number or null |
| 15 | End Location Longitude | `end_lng` | number or null |
| 16 | Distance (km) | `distance_km` | number (2 dp) or null |
| 17 | Expected Travel Time (min) | `travel_min` | number (1 dp) or null |
| 18 | Expected Travel Time (sec) | `travel_sec` | integer or null |
| 19 | Merged Fragments | `merged_fragments` | integer (1 or more) |
| 20 | Merge Type | `merge_type` | one of `Break`, `Break (slice dropped)`, `Break (duplicate)`, `Start of day`, `Start of day (duplicate)`, `GPS fix`, `GPS fix (slice dropped)`, `GPS fix (duplicate)`, or null |
| 21 | GPS Fix | `gps_fix` | boolean (CSV `Yes` / empty) |
| 22 | GPS Fix Time | `gps_fix_time` | string (raw export time) or null |
| 23 | Pre-Fix Distance (km) | `prefix_distance_km` | number (2 dp) or null |
| 24 | Pre-Fix Travel (min) | `prefix_travel_min` | number (2 dp) or null |
| 25 | Pre-Shift Distance (km) | `preshift_distance_km` | number (2 dp) or null |
| 26 | Pre-Shift Travel (min) | `preshift_travel_min` | number (1 dp) or null |
| 27 | Google Maps Directions Link | `maps_link` | string or null |
| 28 | Plan ID | `plan_id` | string |
| 29 | Call ID | `call_id` | string or null |
| 30 | Siebel Order Number | `siebel_order_number` | string or null |
| - | (not in CSV) | `start_time` | string, the leg's start time (earliest fragment) |
| - | (not in CSV) | `end_time` | string (`""` when absent) |
| - | (not in CSV) | `shift_id` | string |
| - | (not in CSV) | `route_key` | `"<oLat>,<oLng>\|<dLat>,<dLng>"` with `%.6f` on each number, or null when any of the four coordinates is null |
| 31 | Google Duration (min) | `google_min` | number (1 dp) or null |
| 32 | Google Duration (sec) | `google_sec` | integer or null |
| 33 | Google Distance (km) | `google_km` | number or null |
| 34 | Google Source | `google_source` | `"Cache"`, `"API"` (from google.json `source` `cache` / `google`; any other value copied) or null |
| 35 | Ratio (Google / PSO) | `ratio` | number (3 dp) or null |
| 36 | % Divergence | `pct_divergence` | number (1 dp) or null |
| 37 | Abs Divergence (min) | `abs_divergence_min` | number (1 dp) or null |
| 38 | Difference | `difference` | string or null |
| 39 | Flag | `flag` | `OK`, `Review`, `Skipped`, `Missing PSO Time`, `Missing Google Time`, or null before merge-google |
| 40 | Estimated Weighting Adjustment | `weighting` | number (2 dp, Review legs only) or null |
| 41 | Validation Error | `validation_error` | string or null |

`meta`:

```json
{
  "tool": "plan_travels",
  "schema_version": 1,
  "export_name": "north_travel_1005.xml",
  "dataset_name": "NORTH",
  "generated": "2026-10-16T09:12:00Z",
  "date_from": "2026-10-05",
  "date_to": "2026-10-09",
  "days": 5,
  "thresholds": { "review_minutes": 20.0, "review_pct": 0.0 },
  "google": false,
  "google_counts": null,
  "counts": {
    "plan_travel_rows": 5081, "legs": 1781, "resources": 146,
    "by_direction": { "Start Location -> Activity": 515, "Activity -> Activity": 765, "Activity -> End Location": 501 },
    "by_merge_type": { "Break": 13, "Break (slice dropped)": 0, "Break (duplicate)": 0, "Start of day": 1,
                       "Start of day (duplicate)": 6, "GPS fix": 8, "GPS fix (slice dropped)": 0, "GPS fix (duplicate)": 0 },
    "merged_legs": 21, "missing_coordinates": 0, "missing_pso_time": 0,
    "rows_in_range": 0, "excluded_destination": 0, "excluded_origin": 0, "end_of_day": 501,
    "fragments_left_out": 0, "fix_events": 97, "fix_legs": 0, "fix_dead_ends": 0, "fix_tagged_rows": 0,
    "fix_tagged_resources": 0, "fix_tagged_accounted": 0, "synthetic_rows": 0, "synthetic_chained": 0,
    "sod_pairs": 0, "sod_slice": 0, "sod_summed": 0, "sod_duplicate": 0, "sod_unpaired": 0,
    "break_merged": 0, "break_absorbed": 0, "break_not_summed": 0,
    "review": null, "ok": null, "skipped": null, "missing_google_time": null, "missing": 0, "average_ratio": null
  },
  "warnings": ["start-of-day travel fragment with no partner (reported as it is): resource 1001, ..."],
  "notes": ["Times are UTC. ...", "..."]
}
```

- Counters from `rows_in_range` to `break_not_summed` follow the script's log lines. The merge counters (`fix_*`, `synthetic_*`, `sod_*`, `break_*`, `fragments_left_out`) count over every Plan_Travel row in the file, before the date filter, as the script does. Values shown as 0 above are placeholders for this example only.
- `merged_legs`: legs with `merged_fragments > 1`. `missing_coordinates`: legs with `route_key` null. `missing_pso_time`: legs with `travel_sec` null.
- Before merge-google: `google` false, `google_counts` null, `review`, `ok`, `skipped`, `missing_google_time`, `average_ratio` null, `missing` = `missing_pso_time`.
- After merge-google: `google` true; `google_counts` = `{"trips": <keys in google.json>, "cache": <legs with source cache>, "google": <legs with source google>, "error": <legs whose google.json value is an error or absent>, "skipped": <legs flagged Skipped>}`; `review`, `ok`, `skipped`, `missing_pso_time`, `missing_google_time` = legs per flag; `missing` = `missing_pso_time + missing_google_time`; `average_ratio` = mean of `ratio` over legs with a ratio, `ps_round(..., 3)`, or null when none.

### `google.json` (written by pso-test-tools, read by merge-google)

```json
{
  "52.111260,-106.584383|52.090480,-106.585050": { "durationSec": 442, "distanceKm": 3.31, "source": "cache" },
  "52.099346,-106.552207|52.090480,-106.585050": { "error": "No route returned" }
}
```

- Key: exactly a leg's `route_key` (pso-test-tools copies it from `legs.json`, never recomputes it; it splits it on `|` and `,` to build the pso-services request).
- Value: `{durationSec: int, distanceKm: number, source: "cache" | "google"}` or `{error: string}`.
- A leg whose `route_key` is null gets `Validation Error` `Missing/invalid coordinates -- skipped` (the script's text, kept for existing spreadsheets). A leg whose key is not in the file gets `No Google result for this trip`. Both are flagged `Skipped`.

---

## Global Constraints

- Python 3.9+, standard library only. Stream with `iterparse(events=('start','end'))`, strip namespaces with `local(tag)`, read fields before `el.clear()` and `root.clear()` (copy `trace_activity.py` lines 444 to 460). Build field dicts only for the seven tables used (Plan_Travel, Activity, Location, Location_Region, Resources, Schedule_Event, Additional_Attribute). Target on the 135 MB file: under 30 s and 200 MB RSS.
- Leg rules are inventory sections 3 to 6 exactly, with the spec 4.2 deviations: ID-keyed lookups and group keys are case-sensitive (plain dicts); `PRIVATE`, `NON-AVAILABILITY`, `FIX` and the Additional_Attribute labels `Call ID` / `Siebel Order Number` compare case-insensitively; numbers parse invariant (`float`, `decimal.Decimal`); rows sorted by resource id, then leg start time, then destination activity id (numeric ids compare as numbers); a leg with PSO time 0 s and a Google time is `Missing PSO Time`.
- Rounding: every `[math]::Round(x, d)` of the script is `ps_round(x, d)` (Task 2), which does what .NET does: scale by 10^d, round half to even, unscale. This departs from spec 4.2 item 3 (Python `round` on the binary value): checked while writing this plan, Python `round` gives 163 cells (104 `Expected Travel Time (min)`, 59 `Distance (km)`, e.g. 2,685 m is 2.68 in pwsh and 2.69 with `round(2.685, 2)`) that differ from the golden CSV, and `ps_round` gives none. The spec's item 3 should be amended to match. `Format-IsoDuration`'s whole-second rounding is Python `round(x)`, which is already half to even on the double, as .NET.
- Kept script limitations (inventory 11): break key has no shift; a synthetic-only chain takes the resource's latest fix; pre-fix reordering treats any synthetic row as a partner; 0,0 coordinates are not checked; missing Activity, Location or Resource gives blanks, never an error; `Merged Fragments` comes from the break-key lookup for any leg without its own count.
- Inference choices (not in the script, recorded here): a non-numeric distance or coordinate is treated as missing (the script would throw on a distance and read 0 for a Location coordinate); `.xml` or a `.zip` whose biggest `.xml` member is the export (same rule as `trace_activity.py` `_load_file`, lines 400 to 420).
- Warnings are the script's lines without the `WARNING: ` prefix, with "unplaced travel fragment" reworded to "travel fragment left out" (house wording: never "placed"). Page and CSV text: plain English, no em dashes, "Allocated" never "placed", warnings shown on the page.
- Exit codes and stderr format copy `trace_activity.py` `main()` (lines 3221 to 3258) and `_Parser` (lines 3194 to 3200), except warnings go to stderr (spec 4.1), not stdout.
- No PowerShell file changes; `make-windows-bundle.sh` unchanged. Tests: `python3 -m unittest discover -s tests/plan_travels -t .` from the repo root. Never commit export data or outputs (`output/` is git-ignored; tests write to temp folders).

## Review Focus

1. A pre-fix fragment that sorts after its post-fix partner (overlapping times) must be moved before it, giving one GPS fix leg with Pre-Fix columns, not a raw leg plus a dead end. (Task 3 test `test_prefix_moved_before_overlapping_post_fix`.)
2. The whole-metre rule reads raw distance strings as decimals: `"14200.0"` is whole; a blank or non-numeric distance leaves the chain summed with no warning. (Task 2 tests `test_whole_metre_reads_raw_strings`, `test_blank_or_bad_distance_keeps_sum`.)
3. A leg that did not come from the break merge but shares a merged break key (`resource|previous activity|previous visit`) reads `Merged Fragments` 2 and `Merge Type` Break, as the script does. (Task 6 test `test_merged_fragments_from_break_key`.)
4. Flag with PSO time 0 s and a Google time is `Missing PSO Time` (spec deviation 5); PSO time > 0 with Google 0 s stays `Missing Google Time`. (Task 9 test `test_zero_pso_time_with_google_time_is_missing_pso_time`.)
5. `route_key` is the cross-repo contract: `%.6f` on each of the four numbers, null when any coordinate is missing, and merge-google matches on it verbatim. (Task 6 test `test_route_key_six_decimals`, Task 9 test `test_cli_merge_google_rewrites_three_files`.)

---

## File structure

Create:
- `plan-travels/plan_travels.py`: `Export`, `read_export()`, `date_list()`, `resolve_fragments()`, `merge_fix_chains()`, `pair_start_of_day()`, `merge_breaks()`, `build_rows()`, `build()`, `make_meta()`, `write_outputs()`, `render_html()`, `merge_google()`, `main()`.
- `plan-travels/plan_travels_template.html`: the page, filled with `__TITLE__` and `__DATA__`.
- `tests/plan_travels/__init__.py` (empty), `pt_fixtures.py` (synthetic exports; named so it never collides with `tests/activity_trace/fixtures.py`), `test_reader.py`, `test_fragments.py`, `test_fix_chains.py`, `test_start_of_day.py`, `test_breaks.py`, `test_rows.py`, `test_outputs.py`, `test_page.py`, `test_google.py`, `test_golden.py`.

Modify:
- `CLAUDE.md` (repository tree, parity rule exception paragraph).
- `scripts/INDEX.md` (paragraph under `## travel\Build-PlanTravelsReport.ps1`).

---

### Task 1: Reader, date range, CLI skeleton, progress, exit codes

**Files:**
- Create: `plan-travels/plan_travels.py`, `tests/plan_travels/__init__.py`, `tests/plan_travels/pt_fixtures.py`, `tests/plan_travels/test_reader.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
```python
class PlanTravelsError(ValueError)        # known failure: 'Stopped during: <stage>: <message>', exit 1
class _ArgError(Exception)
PT_FIELDS: tuple                          # the 15 Plan_Travel fields of inventory section 2
class Export:
    travel: list            # dict per Plan_Travel row: every PT_FIELDS key ('' when absent) plus '_n' (file order)
    activities: dict        # id -> {'class', 'type', 'description', 'location_id'}
    locations: dict         # id -> (lat or None, lon or None)
    location_region: dict   # location_id -> region_id (first row wins)
    resources: dict         # id -> 'first surname'.strip()
    fixes: dict             # FIX Schedule_Event id -> {'id', 'resource_id', 'time', 'lat', 'lon'}
    fix_by_resource: dict   # resource_id -> latest fix (string compare of time, first wins a tie)
    attributes: dict        # activity_id -> {'call_id': str|None, 'order_no': str|None}
def read_export(path: str, progress_cls) -> Export
def date_list(first: str, days: int) -> list            # ['YYYY-MM-DD', ...]; raises _ArgError
def in_range(start_time, dates) -> bool                 # first 10 characters in dates
def local(tag) -> str
def to_float(s) -> float | None
class Progress, class _Silent                           # copied from trace_activity.py
def main(argv=None) -> int
```

- [ ] **Step 1: Branch**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools"
git switch main && git pull --ff-only && git switch -c plan-travels
mkdir -p plan-travels tests/plan_travels && touch tests/plan_travels/__init__.py
```

- [ ] **Step 2: Fixtures**

`tests/plan_travels/pt_fixtures.py` (copy `tmpdir()` from `tests/activity_trace/fixtures.py` lines 12 to 18 and `run_main` from `tests/activity_trace/test_outputs.py` lines 26 to 45):

```python
"""Synthetic plan exports for the plan travels tests (no customer data)."""
import atexit
import contextlib
import io
import os
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'plan-travels'))
import plan_travels as pt  # noqa: E402

_ROOT = []
NS = 'http://360Scheduling.com/Schema/dsScheduleData.xsd'


def tmpdir() -> str:
    if not _ROOT:
        _ROOT.append(tempfile.mkdtemp(prefix='plan-travels-tests-'))
        atexit.register(shutil.rmtree, _ROOT[0], True)
    return tempfile.mkdtemp(dir=_ROOT[0])


def ds(rows_xml: str, root: str = 'dsScheduleData') -> bytes:
    return ('<?xml version="1.0"?><%s xmlns="%s">' % (root, NS) + rows_xml + '</%s>' % root).encode()


def row(tag, **fields):
    return f'<{tag}>' + ''.join(f'<{k}>{v}</{k}>' for k, v in fields.items() if v is not None) + f'</{tag}>'


def at(hhmm, day='2026-10-05'):
    return '%sT%s:00+00:00' % (day, hhmm)


def travel(resource='R1', shift='S1', plan='P1', start='14:00', end=None, day='2026-10-05', sloc=None, eloc=None,
           act=None, visit=None, prev=None, pvisit=None, dist=None, ett=None, event=None):
    return row('Plan_Travel', plan_id=plan, resource_id=resource, shift_id=shift, distance=dist,
               end_time=at(end, day) if end else None, start_time=at(start, day) if start else None,
               start_location_id=sloc, end_location_id=eloc, activity_id=act,
               visit_id=visit if visit is not None else ('1' if act else None),
               previous_activity_id=prev, previous_visit_id=pvisit if pvisit is not None else ('1' if prev else None),
               shift_start_datetime=at('14:00', day), schedule_event_id=event, expected_travel_time=ett)


LOOKUPS = ''.join([
    row('Resources', id='R1', first_name='Ann', surname='Lee'),
    row('Resources', id='R2', first_name='Bo'),
    row('Location', id='L-S', latitude='52.0', longitude='-106.0'),
    row('Location', id='L-E', latitude='52.05', longitude='-106.05'),
    row('Location', id='L-1001', latitude='52.1', longitude='-106.1'),
    row('Location', id='L-1002', latitude='52.2', longitude='-106.2'),
    row('Location', id='L-1003', latitude='52.3', longitude='-106.3'),
    row('Location', id='L-BAD', latitude='abc', longitude=''),
    row('Location', id='L-FINE', latitude='52.12345678', longitude='-106.5'),
    row('Location_Region', location_id='L-1001', region_id='E3'),
    row('Location_Region', location_id='L-1001', region_id='ZZ'),
    row('Location_Region', location_id='L-1002', region_id='XB1L'),
    row('Activity', id='1001', activity_class_id='CALL', activity_type_id='SERVICE_ORDER', description='Job one',
        location_id='L-1001'),
    row('Activity', id='1002', activity_class_id='CALL', activity_type_id='TROUBLE', description='Job two',
        location_id='L-1002'),
    row('Activity', id='1003', activity_class_id='CALL', activity_type_id='SERVICE_ORDER', description='Job three',
        location_id='L-1003'),
    row('Activity', id='9001', activity_class_id='PRIVATE', activity_type_id='LUNCH', description='Lunch',
        location_id='L-1003'),
    row('Activity', id='9002', activity_class_id='CALL', activity_type_id='non-availability', description='Training',
        location_id='L-1003'),
    row('Additional_Attribute', activity_id='1001', label='Call ID', label_value='C-1'),
    row('Additional_Attribute', activity_id='1001', label='siebel order number', label_value='1-555'),
    row('Additional_Attribute', activity_id='1001', label='Call ID', label_value='C-2'),
    row('Additional_Attribute', activity_id='1002', label='Work Task ID', label_value='WT-9'),
    row('Schedule_Event', id='R1-FIX', event_type_id='FIX', resource_id='R1', event_date_time=at('17:00'),
        latitude='52.25', longitude='-106.25'),
])


def write_export(rows_xml, name='export.xml', lookups=True, root='dsScheduleData') -> str:
    path = os.path.join(tmpdir(), name)
    with open(path, 'wb') as fh:
        fh.write(ds((LOOKUPS if lookups else '') + rows_xml, root))
    return path


def write_zip(rows_xml, member='export.xml', extra=None) -> str:
    path = os.path.join(tmpdir(), 'export.zip')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for name, data in (extra or {}).items():
            zf.writestr(name, data)
        zf.writestr(member, ds(LOOKUPS + rows_xml))
    return path


def read(rows_xml):
    return pt.read_export(write_export(rows_xml), pt._Silent)


def run_main(argv, progress=None):
    old = os.environ.get('PSO_PROGRESS')
    if progress is None:
        os.environ.pop('PSO_PROGRESS', None)
    else:
        os.environ['PSO_PROGRESS'] = progress
    out, err = io.StringIO(), io.StringIO()
    try:
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = pt.main(argv)
    finally:
        if old is None:
            os.environ.pop('PSO_PROGRESS', None)
        else:
            os.environ['PSO_PROGRESS'] = old
    return code, out.getvalue(), err.getvalue()
```

- [ ] **Step 3: Failing reader tests**

`tests/plan_travels/test_reader.py`:

```python
"""Reader, date range, exit codes and progress."""
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, row, at, read, write_export, write_zip, run_main, tmpdir, ds  # noqa: E402


class ReaderTests(unittest.TestCase):
    def test_reads_lookups_and_travel(self):
        ex = read(travel(sloc='L-S', eloc='L-1001', act='1001', dist='2966', ett='PT6M54S', end='14:20'))
        r = ex.travel[0]
        self.assertEqual((r['distance'], r['expected_travel_time'], r['_n']), ('2966', 'PT6M54S', 0))
        self.assertEqual((r['previous_activity_id'], r['schedule_event_id']), ('', ''))
        self.assertEqual(set(pt.PT_FIELDS) | {'_n'}, set(r))
        self.assertEqual(ex.activities['9001']['class'], 'PRIVATE')
        self.assertEqual(ex.activities['1001']['location_id'], 'L-1001')
        self.assertEqual(ex.locations['L-1001'], (52.1, -106.1))
        self.assertEqual(ex.location_region['L-1001'], 'E3')          # first row wins
        self.assertEqual((ex.resources['R1'], ex.resources['R2']), ('Ann Lee', 'Bo'))
        self.assertEqual(ex.attributes['1001'], {'call_id': 'C-2', 'order_no': '1-555'})
        self.assertNotIn('1002', ex.attributes)                      # only the two labels are kept
        self.assertEqual(ex.fixes['R1-FIX']['lat'], 52.25)
        self.assertEqual(ex.fixes['R1-FIX']['time'], at('17:00'))
        self.assertIs(ex.fix_by_resource['R1'], ex.fixes['R1-FIX'])

    def test_fix_events_case_insensitive_latest_and_bad_coordinates_skipped(self):
        ex = read(''.join([
            row('Schedule_Event', id='R2-a', event_type_id='fix', resource_id='R2', date_time_stamp=at('09:00'),
                latitude='51.0', longitude='-105.0'),
            row('Schedule_Event', id='R2-b', event_type_id='FIX', resource_id='R2', event_date_time=at('10:00'),
                latitude='51.5', longitude='-105.5'),
            row('Schedule_Event', id='R2-c', event_type_id='FIX', resource_id='R2', event_date_time=at('11:00'),
                latitude='abc', longitude='-105.5'),
            row('Schedule_Event', id='R2-d', event_type_id='LOGON', resource_id='R2', event_date_time=at('12:00'),
                latitude='51.9', longitude='-105.9'),
        ]))
        self.assertEqual(ex.fixes['R2-a']['time'], at('09:00'))      # date_time_stamp when no event_date_time
        self.assertNotIn('R2-c', ex.fixes)
        self.assertNotIn('R2-d', ex.fixes)
        self.assertEqual(ex.fix_by_resource['R2']['id'], 'R2-b')

    def test_bad_or_blank_coordinate_is_none(self):
        ex = read('')
        self.assertEqual(ex.locations['L-BAD'], (None, None))

    def test_zip_uses_biggest_xml_member(self):
        path = write_zip(travel(sloc='L-S', eloc='L-1001', act='1001'), member='plan.xml',
                         extra={'small.xml': ds(''), 'readme.txt': b'x'})
        ex = pt.read_export(path, pt._Silent)
        self.assertEqual(len(ex.travel), 1)

    def test_date_list_and_in_range(self):
        dates = pt.date_list('2026-10-30', 3)
        self.assertEqual(dates, ['2026-10-30', '2026-10-31', '2026-11-01'])
        self.assertTrue(pt.in_range('2026-10-31T23:59:00+00:00', set(dates)))
        self.assertFalse(pt.in_range('2026-11-02T00:00:00+00:00', set(dates)))
        self.assertFalse(pt.in_range('2026-10', set(dates)))
        self.assertFalse(pt.in_range('', set(dates)))
        self.assertFalse(pt.in_range(None, set(dates)))


class CliTests(unittest.TestCase):
    def test_missing_file_exit_1(self):
        code, out, err = run_main(['build', '--export', '/nope/plan.xml', '--date', '2026-10-05', '--out-dir', tmpdir()])
        self.assertEqual(code, 1)
        self.assertIn('Stopped during: reading the export: The export plan.xml was not found.', err)

    def test_root_must_be_dsScheduleData(self):
        path = write_export('', root='dsSystemData')
        code, out, err = run_main(['build', '--export', path, '--date', '2026-10-05', '--out-dir', tmpdir()])
        self.assertEqual(code, 1)
        self.assertIn('Stopped during: reading the export:', err)
        self.assertIn('not dsScheduleData', err)

    def test_bad_date_or_days_exit_1(self):
        path = write_export('')
        for extra in (['--date', '05/10/2026'], ['--date', '2026-02-30'], ['--date', '2026-10-05', '--days', '32'],
                      ['--date', '2026-10-05', '--days', '0'], ['--date', '2026-10-05', '--days', 'x']):
            code, out, err = run_main(['build', '--export', path, '--out-dir', tmpdir()] + extra)
            self.assertEqual(code, 1, extra)
            self.assertIn('Stopped during: reading the arguments:', err)

    def test_progress_lines_when_env_set(self):
        path = write_export(travel(sloc='L-S', eloc='L-1001', act='1001'))
        code, out, err = run_main(['build', '--export', path, '--date', '2026-10-05', '--out-dir', tmpdir()],
                                  progress='lines')
        self.assertIn('PROGRESS {"stage":"reading","percent":0}', err)
        self.assertIn('PROGRESS {"stage":"reading","percent":100}', err)

    def test_build_reads_rows(self):     # replaced in Task 7 when outputs exist
        path = write_export(travel(sloc='L-S', eloc='L-1001', act='1001'))
        code, out, err = run_main(['build', '--export', path, '--date', '2026-10-05', '--out-dir', tmpdir()])
        self.assertEqual(code, 0, err)
        self.assertEqual(out.strip().splitlines()[-1], 'summary: read 1 Plan_Travel rows')


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 4: Run to see them fail**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: errors, `ModuleNotFoundError: No module named 'plan_travels'`.

- [ ] **Step 5: Implement the reader and CLI skeleton**

`plan-travels/plan_travels.py`. Copy the `Progress` and `_Silent` classes verbatim from `activity-trace/trace_activity.py` lines 273 to 334, and `_Parser` from lines 3194 to 3200.

```python
#!/usr/bin/env python3
"""
Plan travels: every planned travel leg in a PSO plan export for a date range, as a page, a CSV and a JSON file,
optionally checked against Google Routes results.

Python reference implementation of scripts/Build-PlanTravelsReport.ps1 (the report; not -BuildImportXml).
Python 3.9+, standard library only.

Usage:
    python3 plan_travels.py build --export <xml|zip> --date YYYY-MM-DD [--days 1..31] --out-dir <dir>
                                  [--dataset-name <name>] [--template <html>] [--review-minutes 20] [--review-pct 0]
    python3 plan_travels.py merge-google --legs <legs.json> --google <google.json> --out-dir <dir>
                                  [--template <html>] [--review-minutes 20] [--review-pct 0]

Exit codes: 0 with outputs (also with zero legs); 1 on any failure, with "Stopped during: <stage>: <sentence>"
on stderr for a known failure, else "Stopped during: <stage>" and the error.

With PSO_PROGRESS=lines, progress goes to stderr as 'PROGRESS {"stage":"reading","percent":..}' lines.
Warnings go to stderr as 'warning: <text>' lines and into legs.json meta.warnings. The last stdout line is
'summary: <text>'.

All times in PSO exports are UTC; a leg belongs to the day of the first 10 characters of its start_time.
"""
import argparse
import collections
import csv
import datetime as dt
import decimal
import html
import json
import math
import os
import re
import sys
import time
import zipfile
import xml.etree.ElementTree as ET

PT_FIELDS = ('resource_id', 'shift_id', 'plan_id', 'activity_id', 'visit_id', 'previous_activity_id',
             'previous_visit_id', 'start_location_id', 'end_location_id', 'distance', 'expected_travel_time',
             'start_time', 'end_time', 'shift_start_datetime', 'schedule_event_id')
WANTED = frozenset(('Plan_Travel', 'Activity', 'Location', 'Location_Region', 'Resources', 'Schedule_Event',
                    'Additional_Attribute'))
ATTRIBUTE_LABELS = {'call id': 'call_id', 'siebel order number': 'order_no'}


class PlanTravelsError(ValueError):
    """A known failure, printed after 'Stopped during: <stage>:' with no exception class name (exit 1)."""


class _ArgError(Exception):
    pass


def local(tag):
    return tag.rsplit('}', 1)[-1]


def to_float(s):
    """Invariant float of a trimmed string; None when blank, not a number, or not finite."""
    s = (s or '').strip()
    if not s or '_' in s:
        return None
    try:
        v = float(s)
    except ValueError:
        return None
    return v if math.isfinite(v) else None


# --- Progress and _Silent: copy trace_activity.py lines 273 to 334 here, unchanged ---


class Export:
    """What the report reads from one plan export (inventory section 2)."""

    def __init__(self, name):
        self.name = name
        self.travel = []
        self.activities = {}
        self.locations = {}
        self.location_region = {}
        self.resources = {}
        self.fixes = {}
        self.fix_by_resource = {}
        self.attributes = {}

    def add(self, table, f):
        if table == 'Plan_Travel':
            r = {k: f.get(k, '') for k in PT_FIELDS}
            r['_n'] = len(self.travel)
            self.travel.append(r)
        elif table == 'Activity':
            if 'id' in f:
                self.activities[f['id']] = {'class': f.get('activity_class_id', ''),
                                            'type': f.get('activity_type_id', ''),
                                            'description': f.get('description', ''),
                                            'location_id': f.get('location_id', '')}
        elif table == 'Location':
            if 'id' in f:
                self.locations[f['id']] = (to_float(f.get('latitude')), to_float(f.get('longitude')))
        elif table == 'Location_Region':
            loc = f.get('location_id', '')
            if loc and loc not in self.location_region:
                self.location_region[loc] = f.get('region_id', '')
        elif table == 'Resources':
            if 'id' in f:
                self.resources[f['id']] = ('%s %s' % (f.get('first_name', ''), f.get('surname', ''))).strip()
        elif table == 'Schedule_Event':
            if f.get('event_type_id', '').lower() != 'fix':
                return
            lat, lon = to_float(f.get('latitude')), to_float(f.get('longitude'))
            if lat is None or lon is None:
                return
            fix = {'id': f.get('id', ''), 'resource_id': f.get('resource_id', ''),
                   'time': f.get('event_date_time') or f.get('date_time_stamp', ''), 'lat': lat, 'lon': lon}
            self.fixes[fix['id']] = fix
            prev = self.fix_by_resource.get(fix['resource_id'])
            if prev is None or fix['time'] > prev['time']:
                self.fix_by_resource[fix['resource_id']] = fix
        elif table == 'Additional_Attribute':
            key = ATTRIBUTE_LABELS.get(f.get('label', '').lower())
            val = f.get('label_value', '')
            if key and val:
                a = self.attributes.setdefault(f.get('activity_id', ''), {'call_id': None, 'order_no': None})
                a[key] = val


class _Counting:
    """A read-only file wrapper that counts the bytes handed to the parser (progress on a zip member too)."""

    def __init__(self, fh):
        self.fh, self.pos = fh, 0

    def read(self, n=-1):
        b = self.fh.read(n)
        self.pos += len(b)
        return b


def open_export(path):
    """(handle, uncompressed size, zip or None): .xml, or the biggest .xml member of a .zip."""
    base = os.path.basename(path)
    if not os.path.isfile(path):
        raise PlanTravelsError('The export %s was not found.' % base)
    if path.lower().endswith('.zip'):
        try:
            zf = zipfile.ZipFile(path)
        except zipfile.BadZipFile:
            raise PlanTravelsError('The export %s is not a zip file.' % base)
        infos = [i for i in zf.infolist() if not i.is_dir() and i.filename.lower().endswith('.xml')]
        if not infos:
            zf.close()
            raise PlanTravelsError('The zip %s holds no XML file.' % base)
        info = max(infos, key=lambda i: i.file_size)
        return zf.open(info), info.file_size, zf
    return open(path, 'rb'), os.path.getsize(path), None


def read_export(path, progress_cls):
    fh, size, zf = open_export(path)
    ex = Export(os.path.basename(path))
    src = _Counting(fh)
    prog = progress_cls('reading', size)
    depth, root, n = 0, None, 0
    try:
        for ev, el in ET.iterparse(src, events=('start', 'end')):
            if ev == 'start':
                depth += 1
                if root is None:
                    root = el
                    if local(el.tag) != 'dsScheduleData':
                        raise PlanTravelsError('%s is not a PSO schedule export: its root element is %s, not '
                                               'dsScheduleData.' % (ex.name, local(el.tag)))
                continue
            depth -= 1
            if depth != 1:
                continue
            table = local(el.tag)
            if table in WANTED:
                ex.add(table, {local(c.tag): (c.text or '').strip() for c in el})
            el.clear()
            root.clear()
            n += 1
            if n % 2000 == 0:
                prog.update(src.pos)
    except ET.ParseError as e:
        raise PlanTravelsError('The export %s could not be read as XML (%s).' % (ex.name, e))
    finally:
        fh.close()
        if zf is not None:
            zf.close()
    prog.done()
    return ex


_DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')


def date_list(first, days):
    if not _DATE.match(first or ''):
        raise _ArgError('--date must be YYYY-MM-DD, not %s' % first)
    try:
        d0 = dt.datetime.strptime(first, '%Y-%m-%d').date()
    except ValueError:
        raise _ArgError('--date %s is not a real date' % first)
    if not 1 <= days <= 31:
        raise _ArgError('--days must be 1 to 31, not %d' % days)
    return [(d0 + dt.timedelta(days=i)).isoformat() for i in range(days)]


def in_range(start_time, dates):
    return bool(start_time) and len(start_time) >= 10 and start_time[:10] in dates


# --- _Parser: copy trace_activity.py lines 3194 to 3200 here ---


def build_parser():
    ap = _Parser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='command', required=True)     # subparsers inherit _Parser
    tpl = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'plan_travels_template.html')
    b = sub.add_parser('build', help='plan export -> legs.json, plan_travels.csv, plan_travels.html')
    b.add_argument('--export', required=True, help='plan export (.xml, or a .zip holding one)')
    b.add_argument('--date', required=True, help='first date, YYYY-MM-DD')
    b.add_argument('--days', type=int, default=1, help='number of days, 1 to 31')
    b.add_argument('--out-dir', required=True)
    b.add_argument('--dataset-name', help='dataset name for the page header')
    m = sub.add_parser('merge-google', help='add Google Routes results to a build output')
    m.add_argument('--legs', required=True)
    m.add_argument('--google', required=True)
    m.add_argument('--out-dir', required=True)
    for p in (b, m):
        p.add_argument('--template', default=tpl)
        p.add_argument('--review-minutes', type=float, default=20.0)
        p.add_argument('--review-pct', type=float, default=0.0)
    return ap


def main(argv=None):
    stage = 'reading the arguments'
    try:
        args = build_parser().parse_args(argv)
        if args.command != 'build':
            raise _ArgError('merge-google is not available yet')
        date_list(args.date, args.days)
        stage = 'reading the export'
        ex = read_export(args.export, Progress)
        print('summary: read %d Plan_Travel rows' % len(ex.travel))
        return 0
    except (_ArgError, PlanTravelsError) as e:
        sys.stderr.write('Stopped during: %s: %s\n' % (stage, e))
        return 1
    except Exception as e:  # noqa: BLE001 - every other failure is exit 1 with the stage
        sys.stderr.write('Stopped during: %s\n%s: %s\n' % (stage, type(e).__name__, e))
        return 1


if __name__ == '__main__':
    sys.exit(main())
```

- [ ] **Step 6: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK` (10 tests).

- [ ] **Step 7: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/__init__.py tests/plan_travels/pt_fixtures.py tests/plan_travels/test_reader.py
git commit -m "Plan travels: streaming reader, date range and CLI skeleton"
```

---

### Task 2: Whole-metre rule (`resolve_fragments`) and duration helpers

**Files:**
- Modify: `plan-travels/plan_travels.py`
- Create: `tests/plan_travels/test_fragments.py`

**Interfaces:**
- Consumes: `to_float` (Task 1).
- Produces:
```python
def travel_seconds(s) -> float           # Get-TravelSeconds: xs:duration total seconds, 0 on blank or invalid
def format_iso_duration(total) -> str    # Format-IsoDuration: whole seconds, 'PT0S' for 0
def ps_round(x, digits) -> float | None  # [math]::Round(double, digits): scale, round half to even, unscale
def raw_decimal(text) -> decimal.Decimal | None
def dist_value(v) -> float | None        # raw distance string or a merged float -> metres
def resolve_fragments(rows, what, warnings) -> dict
    # {'rule': 'sum'|'duplicate'|'trip'|'unclear', 'distance': float, 'seconds': float,
    #  'trip': row|None, 'slices': [rows]}
```

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_fragments.py`:

```python
"""The whole-metre rule (Resolve-Fragments) and the duration helpers."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt  # noqa: E402


def frag(dist, ett='PT10M'):
    return {'distance': dist, 'expected_travel_time': ett}


class FragmentTests(unittest.TestCase):
    def resolve(self, rows):
        w = []
        return pt.resolve_fragments(rows, 'resource R1, shift S1, activity 1001', w), w

    def test_single_row_is_summed(self):
        r, w = self.resolve([frag('2966', 'PT6M54S')])
        self.assertEqual((r['rule'], r['distance'], r['seconds'], w), ('sum', 2966.0, 414.0, []))

    def test_duplicate_counted_once(self):
        r, w = self.resolve([frag('14200', 'PT15M35S'), frag('14200.0', 'PT15M35S')])
        self.assertEqual((r['rule'], r['distance'], r['seconds']), ('duplicate', 14200.0, 935.0))

    def test_same_distance_different_time_is_not_duplicate(self):
        r, w = self.resolve([frag('14200', 'PT15M35S'), frag('14200', 'PT15M36S')])
        self.assertEqual(r['rule'], 'unclear')
        self.assertEqual(r['distance'], 28400.0)

    def test_trip_plus_slice_keeps_the_trip(self):
        rows = [frag('22163.7', 'PT15M'), frag('90860', 'PT1H1M42S')]
        r, w = self.resolve(rows)
        self.assertEqual(r['rule'], 'trip')
        self.assertIs(r['trip'], rows[1])
        self.assertEqual(r['slices'], [rows[0]])
        self.assertEqual((r['distance'], r['seconds']), (90860.0, 3702.0))

    def test_genuine_split_is_summed(self):
        r, w = self.resolve([frag('13668.449197860962', 'PT15M'), frag('531.550802139038', 'PT35S')])
        self.assertEqual(r['rule'], 'sum')
        self.assertAlmostEqual(r['distance'], 14200.0, places=6)
        self.assertEqual((r['seconds'], w), (935.0, []))

    def test_unclear_warns_and_sums(self):
        r, w = self.resolve([frag('1000.5'), frag('2000.2')])
        self.assertEqual(r['rule'], 'unclear')
        self.assertAlmostEqual(r['distance'], 3000.7, places=6)
        self.assertEqual(w, ['split travel that is neither a trip with slices nor pieces adding up to whole metres '
                             '(summed): resource R1, shift S1, activity 1001; raw distances 1000.5, 2000.2'])
        r, w = self.resolve([frag('1000'), frag('2000')])
        self.assertEqual(r['rule'], 'unclear')

    def test_whole_metre_reads_raw_strings(self):
        rows = [frag('14200.0', 'PT15M'), frag('500.5', 'PT1M')]
        r, w = self.resolve(rows)
        self.assertEqual(r['rule'], 'trip')
        self.assertIs(r['trip'], rows[0])

    def test_blank_or_bad_distance_keeps_sum(self):
        for first in ('', '1_000', 'abc'):
            r, w = self.resolve([frag(first, 'PT1M'), frag('500', 'PT2M')])
            self.assertEqual((r['rule'], r['distance'], r['seconds'], w), ('sum', 500.0, 180.0, []), first)

    def test_travel_seconds(self):
        cases = {'PT1H2M3S': 3723, 'PT2.5S': 2.5, 'P1DT1H': 90000, '': 0, 'PT': 0, 'P': 0, 'garbage': 0,
                 ' PT5M ': 300, 'PT0S': 0}
        for s, want in cases.items():
            self.assertEqual(pt.travel_seconds(s), want, s)

    def test_ps_round_matches_dotnet(self):
        # pwsh: [math]::Round(2.685, 2) = 2.68 and [math]::Round(7.45, 1) = 7.4, where Python round gives 2.69 and 7.5
        self.assertEqual((pt.ps_round(2.685, 2), pt.ps_round(447 / 60, 1), pt.ps_round(98.979, 2)), (2.68, 7.4, 98.98))
        self.assertEqual((pt.ps_round(0.125, 2), pt.ps_round(None, 2), pt.ps_round(3469 / 60, 2)), (0.12, None, 57.82))

    def test_format_iso_duration(self):
        cases = {0: 'PT0S', 59.5: 'PT1M', 60.5: 'PT1M', 61.5: 'PT1M2S', 3600: 'PT1H', 3661: 'PT1H1M1S',
                 3720: 'PT1H2M', 16015: 'PT4H26M55S'}
        for s, want in cases.items():
            self.assertEqual(pt.format_iso_duration(s), want, s)


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_fragments`
Expected: `AttributeError: module 'plan_travels' has no attribute 'resolve_fragments'` (and the other helpers).

- [ ] **Step 3: Implement**

Add after `in_range`:

```python
# xs:duration as XmlConvert.ToTimeSpan reads it (Y = 365 days, M = 30 days); anything else is 0.
_XS_DUR = re.compile(r'^(-)?P(?!$)(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?'
                     r'(?:T(?=\d)(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$')


def travel_seconds(s):
    m = _XS_DUR.match((s or '').strip())
    if not m:
        return 0.0
    neg, y, mo, d, h, mi, sec = m.groups()
    total = ((int(y or 0) * 365 + int(mo or 0) * 30 + int(d or 0)) * 86400
             + int(h or 0) * 3600 + int(mi or 0) * 60 + float(sec or 0))
    return -total if neg else total


def format_iso_duration(total):
    whole = int(round(total))                  # banker's rounding, as [math]::Round
    h, rest = divmod(whole, 3600)
    m, s = divmod(rest, 60)
    out = 'PT'
    if h > 0:
        out += '%dH' % h
    if m > 0:
        out += '%dM' % m
    if s > 0 or (h == 0 and m == 0):
        out += '%dS' % s
    return out


def ps_round(x, digits):
    """[math]::Round(double, digits) as .NET does it: scale by 10^digits, round half to even, unscale."""
    if x is None:
        return None
    p = 10.0 ** digits
    return round(x * p) / p


def raw_decimal(text):
    """The raw distance as a decimal (NumberStyles.Float, invariant), None when blank or not a number."""
    t = (text or '').strip() if isinstance(text, str) else ''
    if not t or '_' in t:
        return None
    try:
        d = decimal.Decimal(t)
    except decimal.InvalidOperation:
        return None
    return d if d.is_finite() else None


def dist_value(v):
    """Metres from a leg's distance: a float on a merged leg, the raw string on a Plan_Travel row ('' = none)."""
    if v is None or v == '':
        return None
    if isinstance(v, float):
        return v
    return to_float(v)


def resolve_fragments(rows, what, warnings):
    """Whole-metre rule (spec v3), on the raw distance strings of a chain of fragments (inventory section 5)."""
    res = {'rule': 'sum', 'distance': 0.0, 'seconds': 0.0, 'trip': None, 'slices': []}
    for r in rows:
        v = dist_value(r['distance'])
        if v is not None:
            res['distance'] += v
        res['seconds'] += travel_seconds(r['expected_travel_time'])
    if len(rows) < 2:
        return res
    decs = []
    for r in rows:
        d = raw_decimal(r['distance'])
        if d is None:
            return res
        decs.append(d)
    whole = [d == d.to_integral_value(rounding=decimal.ROUND_DOWN) for d in decs]
    same_dist = all(d == decs[0] for d in decs[1:])
    same_time = all(r['expected_travel_time'] == rows[0]['expected_travel_time'] for r in rows[1:])
    if all(whole) and same_dist and same_time:
        res.update(rule='duplicate', distance=float(decs[0]),
                   seconds=travel_seconds(rows[0]['expected_travel_time']))
        return res
    if sum(whole) == 1:
        at = whole.index(True)
        res.update(rule='trip', trip=rows[at], slices=[r for i, r in enumerate(rows) if i != at],
                   distance=float(decs[at]), seconds=travel_seconds(rows[at]['expected_travel_time']))
        return res
    total = sum(decs, decimal.Decimal(0))
    if not any(whole) and abs(total - total.to_integral_value(rounding=decimal.ROUND_HALF_EVEN)) <= decimal.Decimal('0.01'):
        return res
    res['rule'] = 'unclear'
    warnings.append('split travel that is neither a trip with slices nor pieces adding up to whole metres '
                    '(summed): %s; raw distances %s' % (what, ', '.join(str(r['distance']) for r in rows)))
    return res
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/test_fragments.py
git commit -m "Plan travels: whole-metre rule and duration helpers"
```

---

### Task 3: GPS fix chains (first pass)

**Files:**
- Modify: `plan-travels/plan_travels.py`
- Create: `tests/plan_travels/test_fix_chains.py`

**Interfaces:**
- Consumes: `Export` (Task 1), `resolve_fragments`, `travel_seconds`, `format_iso_duration`, `dist_value`, `ps_round` (Task 2).
- Produces:
```python
SYNTHETIC = '!%!%'
def merge_fix_chains(ex, c, warnings) -> (fix_legs: list, plain: list)
    # c: collections.Counter, adds fix_tagged_rows, fix_tagged_resources, synthetic_rows, synthetic_chained,
    #    fix_tagged_accounted, fix_legs, fix_dead_ends, fragments_left_out
    # a merged fix leg is dict(tail_row) overridden with: distance (float), start_time, start_location_id,
    #    previous_activity_id, previous_visit_id, expected_travel_time, merged_fragments, merge_type,
    #    gps_fix, fix_lat, fix_lon, fix_time, prefix_distance_km, prefix_travel_min
    # raw rows pass through unchanged (no 'merge_type' key)
```

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_fix_chains.py`:

```python
"""GPS fix chains: pre-fix catch-up, reordering, synthetic ids, dead ends, fragments left out."""
import collections
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, at, read  # noqa: E402

START_OF_DAY = travel(start='14:00', end='14:20', sloc='L-S', eloc='L-1001', act='1001', dist='2966', ett='PT6M54S')


def run(rows_xml):
    ex = read(rows_xml)
    c, w = collections.Counter(), []
    legs, plain = pt.merge_fix_chains(ex, c, w)
    return legs, plain, c, w


class FixChainTests(unittest.TestCase):
    def test_prefix_catch_up_excluded_and_leg_starts_at_fix(self):
        legs, plain, c, w = run(START_OF_DAY + ''.join([
            travel(start='16:17', end='17:00', sloc='L-1001', prev='1001', dist='98979', ett='PT57M49S',
                   event='R1-FIX'),
            travel(start='17:26', end='21:53', eloc='L-1002', act='1002', dist='426917', ett='PT4H26M55S',
                   event='R1-FIX'),
        ]))
        self.assertEqual(plain, [])
        self.assertEqual(len(legs), 2)
        raw = next(l for l in legs if 'merge_type' not in l)          # start-of-day row shares the group key
        self.assertEqual(raw['activity_id'], '1001')
        g = next(l for l in legs if 'merge_type' in l)
        self.assertEqual((g['merge_type'], g['merged_fragments'], g['distance']), ('GPS fix', 1, 426917.0))
        self.assertEqual(g['expected_travel_time'], 'PT4H26M55S')
        self.assertEqual((g['previous_activity_id'], g['previous_visit_id']), ('1001', '1'))
        self.assertEqual((g['start_location_id'], g['end_location_id']), ('', 'L-1002'))
        self.assertEqual(g['start_time'], at('17:26'))
        self.assertEqual((g['gps_fix'], g['fix_lat'], g['fix_lon'], g['fix_time']), (True, 52.25, -106.25, at('17:00')))
        self.assertEqual((g['prefix_distance_km'], g['prefix_travel_min']), (98.98, 57.82))
        self.assertEqual((c['fix_legs'], c['fix_tagged_rows'], c['fix_tagged_accounted']), (1, 2, 2))
        self.assertEqual(w, [])

    def test_prefix_moved_before_overlapping_post_fix(self):
        legs, plain, c, w = run(''.join([
            travel(start='17:20', end='17:25', sloc='L-1001', prev='1001', dist='3000', ett='PT3M', event='R1-FIX'),
            travel(start='17:10', end='17:40', eloc='L-1002', act='1002', dist='9000', ett='PT9M', event='R1-FIX'),
        ]))
        self.assertEqual(len(legs), 1)
        g = legs[0]
        self.assertEqual((g['merge_type'], g['prefix_distance_km'], g['distance']), ('GPS fix', 3.0, 9000.0))
        self.assertEqual(g['start_time'], at('17:10'))
        self.assertEqual((c['fix_dead_ends'], c['fragments_left_out']), (0, 0))

    def test_synthetic_chain_takes_latest_fix_and_last_activity_before_it(self):
        legs, plain, c, w = run(START_OF_DAY + ''.join([
            travel(start='17:26', end='17:28', prev='R1!%!%FIX', dist='1022.5', ett='PT2M13S'),
            travel(start='17:28', end='17:34', prev='R1!%!%FIX', eloc='L-1002', act='1002', dist='3977.5', ett='PT6M'),
        ]))
        self.assertEqual([r['activity_id'] for r in plain], ['1001'])     # no tagged row: no group keys
        self.assertEqual(len(legs), 1)
        g = legs[0]
        self.assertEqual((g['merge_type'], g['merged_fragments'], g['distance']), ('GPS fix', 2, 5000.0))
        self.assertEqual(g['expected_travel_time'], 'PT8M13S')
        self.assertEqual((g['previous_activity_id'], g['previous_visit_id']), ('1001', '1'))
        self.assertEqual((g['fix_lat'], g['prefix_distance_km'], g['prefix_travel_min']), (52.25, None, None))
        self.assertEqual((c['synthetic_rows'], c['synthetic_chained']), (2, 2))
        self.assertEqual(w, [])

    def test_dead_end_at_the_fix(self):
        legs, plain, c, w = run(travel(start='16:00', end='16:30', sloc='L-1001', prev='1001', dist='3000',
                                       ett='PT3M', event='R1-FIX'))
        self.assertEqual(legs, [])
        self.assertEqual((c['fix_dead_ends'], c['fragments_left_out'], c['fix_tagged_accounted']), (1, 0, 1))
        self.assertEqual(w, ['Travel ends at the GPS position (no destination after the fix, no leg reported): '
                             'resource R1, shift S1, 1 fragment(s) from %s to %s' % (at('16:00'), at('16:30'))])

    def test_middle_fragment_with_no_start_is_left_out(self):
        legs, plain, c, w = run(''.join([
            travel(start='15:00', end='15:10', prev='1001', dist='100', ett='PT1M'),
            travel(start='16:00', end='16:30', sloc='L-1001', prev='1001', dist='3000', ett='PT3M', event='R1-FIX'),
        ]))
        self.assertEqual(c['fragments_left_out'], 1)
        self.assertEqual(w[0], 'travel fragment left out (middle fragment with no start fragment): resource R1, '
                               'shift S1, %s to %s, previous activity 1001' % (at('15:00'), at('15:10')))

    def test_end_fragment_with_no_start_is_left_out(self):
        legs, plain, c, w = run(''.join([
            travel(start='15:00', end='15:10', prev='1001', eloc='L-1002', act='1002', dist='100', ett='PT1M'),
            travel(start='16:00', end='16:30', sloc='L-1001', prev='1001', dist='3000', ett='PT3M', event='R1-FIX'),
        ]))
        self.assertEqual(c['fragments_left_out'], 1)
        self.assertIn('(no start fragment)', w[0])

    def test_rows_outside_fix_shifts_are_plain(self):
        legs, plain, c, w = run(travel(shift='S9', start='14:00', sloc='L-S', eloc='L-1001', act='1001'))
        self.assertEqual((len(legs), len(plain)), (0, 1))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_fix_chains`
Expected: `AttributeError: module 'plan_travels' has no attribute 'merge_fix_chains'`.

- [ ] **Step 3: Implement**

Add after `resolve_fragments` (inventory section 5 "GPS FIX chains", translated; `index_of` compares by identity because two rows can hold equal values):

```python
SYNTHETIC = '!%!%'


def is_synthetic(i):
    return bool(i) and SYNTHETIC in i


def fix_tagged(ex, r):
    e = r['schedule_event_id']
    return bool(e) and e in ex.fixes


def pre_fix(ex, r):
    return bool(r['start_location_id']) and not r['end_location_id'] and fix_tagged(ex, r)


def fixy(ex, r):
    return fix_tagged(ex, r) or is_synthetic(r['previous_activity_id'])


def index_of(rows, x):
    return next(i for i, r in enumerate(rows) if r is x)


def _shift_key(r):
    return '%s|%s' % (r['resource_id'], r['shift_id'])


def _group_key(r):
    return '%s|%s|%s' % (_shift_key(r), r['previous_activity_id'], r['previous_visit_id'])


def leave_out(rows, why, c, warnings):
    for r in rows:
        c['fragments_left_out'] += 1
        warnings.append('travel fragment left out (%s): resource %s, shift %s, %s to %s, previous activity %s'
                        % (why, r['resource_id'], r['shift_id'], r['start_time'], r['end_time'],
                           r['previous_activity_id']))


def close_open_chain(ex, chain, c, warnings):
    if not any(fixy(ex, r) for r in chain):
        leave_out(chain, 'chain never reached a destination', c, warnings)
        return
    c['fix_dead_ends'] += 1
    for r in chain:
        if fix_tagged(ex, r):
            c['fix_tagged_accounted'] += 1
        if is_synthetic(r['previous_activity_id']):
            c['synthetic_chained'] += 1
    warnings.append('Travel ends at the GPS position (no destination after the fix, no leg reported): resource %s, '
                    'shift %s, %d fragment(s) from %s to %s' % (chain[0]['resource_id'], chain[0]['shift_id'],
                                                                 len(chain), chain[0]['start_time'],
                                                                 chain[-1]['end_time']))


def last_activity_before(rows, when):
    """The row with an activity and the greatest start_time before when (string compare), first wins a tie."""
    best, best_start = None, ''
    for r in rows:
        aid, st = r['activity_id'], r['start_time']
        if not aid or not st or st >= when:
            continue
        if st > best_start:
            best, best_start = r, st
    return best


def complete_fix_chain(ex, chain, shift_rows, c, warnings, legs):
    prefix = next((r for r in chain if pre_fix(ex, r)), None)
    post = chain if prefix is None else chain[index_of(chain, prefix) + 1:]
    fix = ex.fixes.get(prefix['schedule_event_id']) if prefix is not None else None
    if fix is None:
        fix = next((ex.fixes[r['schedule_event_id']] for r in chain if fix_tagged(ex, r)), None)
    has_synthetic = False
    for r in chain:
        if is_synthetic(r['previous_activity_id']):
            has_synthetic = True
            c['synthetic_chained'] += 1
    if fix is None and has_synthetic:
        fix = ex.fix_by_resource.get(chain[0]['resource_id'])
    c['fix_tagged_accounted'] += sum(1 for r in chain if fix_tagged(ex, r))
    tail = chain[-1]
    if fix is None and len(chain) == 1:
        legs.append(tail)
        return
    fr = resolve_fragments(post, 'resource %s, shift %s, activity %s'
                           % (tail['resource_id'], tail['shift_id'], tail['activity_id']), warnings)
    starts = [r['start_time'] for r in post if r['start_time']]
    prev_id = prev_visit = ''
    for r in chain:
        p = r['previous_activity_id']
        if p and not is_synthetic(p):
            prev_id, prev_visit = p, r['previous_visit_id']
            break
    if not prev_id and fix is not None:
        last = last_activity_before(shift_rows, fix['time'])
        if last is not None:
            prev_id, prev_visit = last['activity_id'], last['visit_id']
    start_loc = ''
    if fix is None:
        start_loc = next((r['start_location_id'] for r in chain if r['start_location_id']), '')
    else:
        c['fix_legs'] += 1
    kind = 'GPS fix' if fix is not None else ('Break' if len(post) > 1 else '')
    suffix = {'trip': ' (slice dropped)', 'duplicate': ' (duplicate)'}.get(fr['rule'], '')
    prefix_m = dist_value(prefix['distance']) if prefix is not None else None
    legs.append(dict(
        tail,
        distance=fr['distance'],
        start_time=min(starts) if starts else '',
        start_location_id=start_loc,
        previous_activity_id=prev_id,
        previous_visit_id=prev_visit,
        expected_travel_time=format_iso_duration(fr['seconds']),
        merged_fragments=len(post),
        merge_type=kind + suffix,
        gps_fix=fix is not None,
        fix_lat=fix['lat'] if fix else None,
        fix_lon=fix['lon'] if fix else None,
        fix_time=fix['time'] if fix else None,
        prefix_distance_km=ps_round(prefix_m / 1000, 2) if prefix_m is not None else None,
        prefix_travel_min=ps_round(travel_seconds(prefix['expected_travel_time']) / 60, 2) if prefix is not None else None,
    ))


def merge_fix_chains(ex, c, warnings):
    shift_keys, group_keys, tagged_resources = set(), set(), set()
    for r in ex.travel:
        tagged, synthetic = fix_tagged(ex, r), is_synthetic(r['previous_activity_id'])
        if not (tagged or synthetic):
            continue
        if tagged:
            c['fix_tagged_rows'] += 1
            tagged_resources.add(r['resource_id'])
        if synthetic:
            c['synthetic_rows'] += 1
        shift_keys.add(_shift_key(r))
        if not synthetic:
            group_keys.add(_group_key(r))
    c['fix_tagged_resources'] = len(tagged_resources)
    chain_rows, shift_rows, plain = {}, {}, []
    for r in ex.travel:
        sk = _shift_key(r)
        if sk not in shift_keys:
            plain.append(r)
            continue
        shift_rows.setdefault(sk, []).append(r)
        if fixy(ex, r) or _group_key(r) in group_keys:
            chain_rows.setdefault(sk, []).append(r)
        else:
            plain.append(r)
    legs = []
    for sk, chained in chain_rows.items():
        rows = sorted(chained, key=lambda r: (r['start_time'], r['end_time']))
        for p in [r for r in rows if pre_fix(ex, r)]:
            first = next((k for k, r in enumerate(rows) if r is not p and (
                (fix_tagged(ex, r) and r['schedule_event_id'] == p['schedule_event_id'])
                or is_synthetic(r['previous_activity_id']))), -1)
            at = index_of(rows, p)
            if 0 <= first < at:
                rows.pop(at)
                rows.insert(first, p)
        chain = None
        for r in rows:
            has_start, has_end = bool(r['start_location_id']), bool(r['end_location_id'])
            if has_start and not has_end:
                if chain:
                    close_open_chain(ex, chain, c, warnings)
                chain = [r]
            elif has_end:
                if not chain:
                    if has_start or fixy(ex, r):
                        chain = []
                    else:
                        leave_out([r], 'no start fragment', c, warnings)
                        continue
                chain.append(r)
                complete_fix_chain(ex, chain, shift_rows[sk], c, warnings, legs)
                chain = None
            else:
                if not chain:
                    if fixy(ex, r):
                        chain = []
                    else:
                        leave_out([r], 'middle fragment with no start fragment', c, warnings)
                        continue
                chain.append(r)
        if chain:
            close_open_chain(ex, chain, c, warnings)
    return legs, plain
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/test_fix_chains.py
git commit -m "Plan travels: GPS fix chains (pre-fix catch-up, synthetic ids, dead ends)"
```

---

### Task 4: Start-of-day pairs (second pass)

**Files:**
- Modify: `plan-travels/plan_travels.py`
- Create: `tests/plan_travels/test_start_of_day.py`

**Interfaces:**
- Consumes: `resolve_fragments`, `dist_value`, `travel_seconds`, `format_iso_duration`, `ps_round` (Task 2); `plain` from `merge_fix_chains` (Task 3).
- Produces:
```python
def pair_start_of_day(plain, c, warnings) -> (sod_legs: list, break_pool: list)
    # c adds sod_split, sod_duplicate, sod_slice, sod_summed, sod_unpaired
    # a paired leg is dict(last_row) overridden with distance (float), start_time, start_location_id,
    #   expected_travel_time, merged_fragments, merge_type, preshift_distance_km, preshift_travel_min
```

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_start_of_day.py`:

```python
"""Start-of-day pairs: trip plus slice, duplicate, genuine split, unpaired, dropped."""
import collections
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, at, read  # noqa: E402


def run(rows_xml):
    ex = read(rows_xml)
    c, w = collections.Counter(), []
    legs, pool = pt.pair_start_of_day(ex.travel, c, w)
    return legs, pool, c, w


def pair(first_dist, first_ett, last_dist, last_ett):
    return (travel(start='13:45', end='14:00', sloc='L-S', act='1001', dist=first_dist, ett=first_ett)
            + travel(start='14:00', end='15:01', eloc='L-1001', act='1001', dist=last_dist, ett=last_ett))


class StartOfDayTests(unittest.TestCase):
    def test_trip_plus_slice_fills_pre_shift_columns(self):
        legs, pool, c, w = run(pair('22163.7', 'PT15M', '90860', 'PT1H1M42S'))
        self.assertEqual(len(legs), 1)
        g = legs[0]
        self.assertEqual((g['merge_type'], g['merged_fragments']), ('Start of day', 2))
        self.assertEqual((g['distance'], g['expected_travel_time']), (90860.0, 'PT1H1M42S'))
        self.assertEqual((g['preshift_distance_km'], g['preshift_travel_min']), (22.16, 15.0))
        self.assertEqual((g['start_time'], g['start_location_id'], g['end_location_id']), (at('13:45'), 'L-S', 'L-1001'))
        self.assertEqual((c['sod_split'], c['sod_slice'], c['sod_duplicate']), (1, 1, 0))

    def test_duplicate_counted_once(self):
        legs, pool, c, w = run(pair('14200', 'PT15M35S', '14200', 'PT15M35S'))
        g = legs[0]
        self.assertEqual((g['merge_type'], g['distance'], g['expected_travel_time']),
                         ('Start of day (duplicate)', 14200.0, 'PT15M35S'))
        self.assertEqual((g['preshift_distance_km'], g['preshift_travel_min']), (None, None))
        self.assertEqual(c['sod_duplicate'], 1)

    def test_genuine_split_is_summed(self):
        legs, pool, c, w = run(pair('13668.449197860962', 'PT15M', '531.550802139038', 'PT35S'))
        g = legs[0]
        self.assertEqual((g['merge_type'], g['expected_travel_time']), ('Start of day', 'PT15M35S'))
        self.assertAlmostEqual(g['distance'], 14200.0, places=6)
        self.assertIsNone(g['preshift_distance_km'])
        self.assertEqual(c['sod_summed'], 1)

    def test_middle_fragments_join_in_start_order(self):
        legs, pool, c, w = run(travel(start='13:40', end='13:50', sloc='L-S', act='1001', dist='100.25', ett='PT1M')
                               + travel(start='13:55', end='14:00', act='1001', dist='200.5', ett='PT2M')
                               + travel(start='13:50', end='13:55', act='1001', dist='300.25', ett='PT3M')
                               + travel(start='14:00', end='14:10', eloc='L-1001', act='1001', dist='399', ett='PT4M'))
        self.assertEqual(legs[0]['merged_fragments'], 4)
        self.assertEqual(legs[0]['merge_type'], 'Start of day')     # 399 whole, the rest fractional: trip
        self.assertEqual(legs[0]['distance'], 399.0)

    def test_unpaired_rows_pass_through_with_a_warning(self):
        legs, pool, c, w = run(travel(start='13:45', sloc='L-S', act='1001', dist='1', ett='PT1M')
                               + travel(start='13:50', sloc='L-S', act='1001', dist='2', ett='PT1M'))
        self.assertEqual(len(legs), 2)
        self.assertTrue(all('merge_type' not in l for l in legs))
        self.assertEqual(c['sod_unpaired'], 2)
        self.assertTrue(w[0].startswith('start-of-day travel fragment with no partner (reported as it is): '
                                        'resource R1, shift S1, activity 1001,'))

    def test_no_previous_activity_and_no_activity_is_dropped(self):
        legs, pool, c, w = run(travel(start='13:45', end='14:00', sloc='L-S', dist='1', ett='PT1M'))
        self.assertEqual((legs, pool, c['sod_unpaired']), ([], [], 1))
        self.assertEqual(w, ['travel fragment with no previous activity and no activity: resource R1, shift S1, '
                             '%s to %s' % (at('13:45'), at('14:00'))])

    def test_rows_with_previous_activity_or_both_locations_go_to_break_pool(self):
        legs, pool, c, w = run(travel(start='15:00', prev='1001', sloc='L-1001', eloc='L-1002', act='1002')
                               + travel(start='14:00', sloc='L-S', eloc='L-1001', act='1001'))
        self.assertEqual((len(legs), len(pool)), (0, 2))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_start_of_day`
Expected: `AttributeError: module 'plan_travels' has no attribute 'pair_start_of_day'`.

- [ ] **Step 3: Implement**

Add after `merge_fix_chains` (inventory section 5 "Start-of-day pairs"):

```python
def pair_start_of_day(plain, c, warnings):
    groups, pool, legs = {}, [], []
    for r in plain:
        has_s, has_e = bool(r['start_location_id']), bool(r['end_location_id'])
        if r['previous_activity_id'] or (has_s and has_e):
            pool.append(r)
            continue
        if not r['activity_id']:
            c['sod_unpaired'] += 1
            warnings.append('travel fragment with no previous activity and no activity: resource %s, shift %s, '
                            '%s to %s' % (r['resource_id'], r['shift_id'], r['start_time'], r['end_time']))
            continue
        key = '%s|%s|%s|%s' % (r['resource_id'], r['shift_id'], r['activity_id'], r['visit_id'])
        groups.setdefault(key, []).append(r)
    for g in groups.values():
        firsts = [r for r in g if r['start_location_id'] and not r['end_location_id']]
        lasts = [r for r in g if r['end_location_id'] and not r['start_location_id']]
        if len(firsts) != 1 or len(lasts) != 1:
            for r in g:
                c['sod_unpaired'] += 1
                warnings.append('start-of-day travel fragment with no partner (reported as it is): resource %s, '
                                'shift %s, activity %s, %s to %s' % (r['resource_id'], r['shift_id'],
                                                                     r['activity_id'], r['start_time'], r['end_time']))
                legs.append(r)
            continue
        first, last = firsts[0], lasts[0]
        middle = sorted((r for r in g if not r['start_location_id'] and not r['end_location_id']),
                        key=lambda r: r['start_time'])
        chain = [first] + middle + [last]
        fr = resolve_fragments(chain, 'resource %s, shift %s, activity %s'
                               % (last['resource_id'], last['shift_id'], last['activity_id']), warnings)
        pre_km = pre_min = None
        if fr['rule'] == 'duplicate':
            c['sod_duplicate'] += 1
        else:
            c['sod_split'] += 1
            if fr['rule'] == 'trip':
                slice_m = 0.0
                slice_s = 0.0
                for r in fr['slices']:
                    v = dist_value(r['distance'])
                    if v is not None:
                        slice_m += v
                    slice_s += travel_seconds(r['expected_travel_time'])
                pre_km, pre_min = ps_round(slice_m / 1000, 2), ps_round(slice_s / 60, 1)
                c['sod_slice'] += 1
            else:
                c['sod_summed'] += 1
        earliest = first['start_time']
        for r in chain:
            if r['start_time'] and r['start_time'] < earliest:
                earliest = r['start_time']
        legs.append(dict(
            last,
            distance=fr['distance'],
            start_time=earliest,
            start_location_id=first['start_location_id'],
            expected_travel_time=format_iso_duration(fr['seconds']),
            merged_fragments=len(chain),
            merge_type='Start of day (duplicate)' if fr['rule'] == 'duplicate' else 'Start of day',
            preshift_distance_km=pre_km,
            preshift_travel_min=pre_min,
        ))
    return legs, pool
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/test_start_of_day.py
git commit -m "Plan travels: start-of-day pairs with pre-shift slices"
```

---

### Task 5: Break merge (third pass)

**Files:**
- Modify: `plan-travels/plan_travels.py`
- Create: `tests/plan_travels/test_breaks.py`

**Interfaces:**
- Consumes: `resolve_fragments`, `dist_value`, `travel_seconds`, `format_iso_duration` (Task 2); `pool` from `pair_start_of_day` (Task 4).
- Produces:
```python
def merge_breaks(pool, c, warnings) -> (legs: list, merged_keys: dict)
    # merged_keys: 'resource|previous_activity_id|previous_visit_id' -> orphans + 1 (read by Step 4)
    # c adds break_merged, break_absorbed, break_not_summed
    # a merged leg is dict(terminal) overridden with distance (float), start_time, start_location_id,
    #   expected_travel_time, merge_type; it has NO 'merged_fragments' key (Step 4 looks it up)
```

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_breaks.py`:

```python
"""Break merge: sum, trip plus slice, duplicate, conflicts left alone."""
import collections
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, at, read  # noqa: E402


def run(rows_xml):
    ex = read(rows_xml)
    c, w = collections.Counter(), []
    legs, keys = pt.merge_breaks(ex.travel, c, w)
    return legs, keys, c, w


def orphan(dist, ett, sloc='L-1001', start='15:00'):
    return travel(start=start, end='15:15', sloc=sloc, prev='1001', dist=dist, ett=ett)


def terminal(dist, ett, sloc='L-1001', start='15:45'):
    return travel(start=start, end='16:00', sloc=sloc, eloc='L-1002', act='1002', prev='1001', dist=dist, ett=ett)


class BreakTests(unittest.TestCase):
    def test_break_split_is_summed(self):
        legs, keys, c, w = run(orphan('25207.4', 'PT14M55S') + terminal('25629.6', 'PT15M10S'))
        self.assertEqual(len(legs), 1)
        g = legs[0]
        self.assertEqual((g['merge_type'], g['expected_travel_time'], g['start_time']), ('Break', 'PT30M5S', at('15:00')))
        self.assertAlmostEqual(g['distance'], 50837.0, places=6)
        self.assertNotIn('merged_fragments', g)
        self.assertEqual(keys, {'R1|1001|1': 2})
        self.assertEqual((c['break_merged'], c['break_absorbed'], c['break_not_summed']), (1, 1, 0))
        self.assertEqual(w, [])

    def test_trip_plus_slice_drops_the_slice(self):
        legs, keys, c, w = run(orphan('13668.45', 'PT14M') + terminal('50837', 'PT30M5S'))
        g = legs[0]
        self.assertEqual((g['merge_type'], g['distance'], g['expected_travel_time']),
                         ('Break (slice dropped)', 50837.0, 'PT30M5S'))
        self.assertEqual(c['break_not_summed'], 1)

    def test_duplicate_counted_once(self):
        legs, keys, c, w = run(orphan('50837', 'PT30M5S') + terminal('50837', 'PT30M5S'))
        self.assertEqual((legs[0]['merge_type'], legs[0]['distance']), ('Break (duplicate)', 50837.0))

    def test_terminal_without_start_takes_orphan_origin(self):
        legs, keys, c, w = run(orphan('100.5', 'PT1M') + terminal('199.5', 'PT2M', sloc=None))
        self.assertEqual(legs[0]['start_location_id'], 'L-1001')

    def test_conflicting_origins_left_alone(self):
        legs, keys, c, w = run(orphan('100.5', 'PT1M', sloc='L-S') + terminal('199.5', 'PT2M'))
        self.assertEqual((len(legs), keys), (2, {}))
        self.assertTrue(all('merge_type' not in l for l in legs))

    def test_two_terminals_left_alone(self):
        legs, keys, c, w = run(terminal('100', 'PT1M') + terminal('200', 'PT2M', start='16:30'))
        self.assertEqual((len(legs), keys), (2, {}))

    def test_single_row_unchanged(self):
        legs, keys, c, w = run(terminal('100', 'PT1M'))
        self.assertEqual((len(legs), 'merge_type' in legs[0]), (1, False))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_breaks`
Expected: `AttributeError: module 'plan_travels' has no attribute 'merge_breaks'`.

- [ ] **Step 3: Implement**

Add after `pair_start_of_day` (inventory section 5 "Break merge"; the sum runs terminal first, then orphans, as the script does, so float totals match):

```python
def merge_breaks(pool, c, warnings):
    groups = {}
    for r in pool:
        key = '%s|%s|%s' % (r['resource_id'], r['previous_activity_id'], r['previous_visit_id'])
        groups.setdefault(key, []).append(r)
    out, merged_keys = [], {}
    for key, g in groups.items():
        if len(g) == 1:
            out.append(g[0])
            continue
        orphans = [r for r in g if not r['end_location_id']]
        terminals = [r for r in g if r['end_location_id']]
        if orphans and len(terminals) == 1:
            t = terminals[0]
            t_origin = t['start_location_id']
            same_origin = all(not o['start_location_id'] or not t_origin or o['start_location_id'] == t_origin
                              for o in orphans)
            if same_origin:
                v = dist_value(t['distance'])
                total_d = v if v is not None else 0.0
                total_s = travel_seconds(t['expected_travel_time'])
                earliest, start_loc = t['start_time'], t_origin
                for o in orphans:
                    v = dist_value(o['distance'])
                    if v is not None:
                        total_d += v
                    total_s += travel_seconds(o['expected_travel_time'])
                    if o['start_time'] and o['start_time'] < earliest:
                        earliest = o['start_time']
                    if not start_loc and o['start_location_id']:
                        start_loc = o['start_location_id']
                fr = resolve_fragments(orphans + [t], 'resource %s, previous activity %s, activity %s'
                                       % (t['resource_id'], t['previous_activity_id'], t['activity_id']), warnings)
                kind = 'Break'
                if fr['rule'] in ('trip', 'duplicate'):
                    total_d, total_s = fr['distance'], fr['seconds']
                    kind = 'Break (slice dropped)' if fr['rule'] == 'trip' else 'Break (duplicate)'
                    c['break_not_summed'] += 1
                out.append(dict(t, distance=total_d, start_time=earliest, start_location_id=start_loc,
                                expected_travel_time=format_iso_duration(total_s), merge_type=kind))
                c['break_merged'] += 1
                c['break_absorbed'] += len(orphans)
                merged_keys[key] = len(orphans) + 1
                continue
        out.extend(g)
    return out, merged_keys
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/test_breaks.py
git commit -m "Plan travels: break merge"
```

---

### Task 6: Step 4 filtering, direction, exclusions and enrichment

**Files:**
- Modify: `plan-travels/plan_travels.py`, `tests/plan_travels/pt_fixtures.py`
- Create: `tests/plan_travels/test_rows.py`

**Interfaces:**
- Consumes: Tasks 1 to 5.
- Produces:
```python
DIRECTIONS = ('Start Location -> Activity', 'Activity -> Activity', 'Activity -> End Location')
MERGE_TYPES = ('Break', 'Break (slice dropped)', 'Break (duplicate)', 'Start of day', 'Start of day (duplicate)',
               'GPS fix', 'GPS fix (slice dropped)', 'GPS fix (duplicate)')
COLUMNS: list[tuple[str, str]]          # the 30 (CSV column, legs.json key) pairs, in order
GOOGLE_COLUMNS: list[tuple[str, str]]   # the 11 Google pairs
EXTRA_KEYS = ('start_time', 'end_time', 'shift_id', 'route_key')
LEG_KEYS: tuple                         # 30 + EXTRA_KEYS + 11 Google keys (the 45 legs.json keys)
def pso_seconds(s) -> int | None
def fmt_num(v) -> str                   # shortest round-trip, no '.0' on whole values, '' for None
def route_key(o_lat, o_lng, d_lat, d_lng) -> str | None
def build_rows(ex, legs, merged_keys, dates: set, c) -> list[dict]   # dicts with every LEG_KEYS key
def build(ex, dates: list, warnings) -> (rows, c)                     # the three passes plus Step 4, unsorted
```
- `pt_fixtures.legs_for(rows_xml, date='2026-10-05', days=1) -> (rows, c, warnings)`.

- [ ] **Step 1: Fixture helper**

Append to `tests/plan_travels/pt_fixtures.py`:

```python
def legs_for(rows_xml, date='2026-10-05', days=1):
    ex = read(rows_xml)
    warnings = []
    rows, c = pt.build(ex, pt.date_list(date, days), warnings)
    return rows, c, warnings
```

- [ ] **Step 2: Failing tests**

`tests/plan_travels/test_rows.py`:

```python
"""Step 4: date filter after merging, exclusions, direction, coordinates, enrichment, Merge Type lookup."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, at, legs_for  # noqa: E402

DAY = (travel(start='14:00', end='14:20', sloc='L-S', eloc='L-1001', act='1001', dist='2966', ett='PT6M54S')
       + travel(start='15:00', end='15:16', prev='1001', sloc='L-1001', eloc='L-1002', act='1002', dist='14200',
                ett='PT15M35S')
       + travel(start='16:00', end='16:08', prev='1002', sloc='L-1002', eloc='L-E', dist='3330', ett='PT7M22S'))


def by_dest(rows, aid, merge_type=None):
    return next(r for r in rows if r['activity_id'] == aid and (merge_type is None or r['merge_type'] == merge_type))


class RowTests(unittest.TestCase):
    def test_every_row_has_the_45_keys(self):
        rows, c, w = legs_for(DAY)
        self.assertEqual(len(pt.LEG_KEYS), 45)
        self.assertEqual([k for _, k in pt.COLUMNS][:3], ['resource_id', 'resource_name', 'direction'])
        for r in rows:
            self.assertEqual(set(r), set(pt.LEG_KEYS))

    def test_start_of_day_leg_enriched(self):
        rows, c, w = legs_for(DAY)
        r = by_dest(rows, '1001')
        self.assertEqual((r['resource_id'], r['resource_name'], r['direction']), ('R1', 'Ann Lee', 'Start Location -> Activity'))
        self.assertEqual((r['previous_activity_id'], r['previous_gb'], r['previous_activity_type']), ('', None, None))
        self.assertEqual((r['destination_gb'], r['destination_activity_type'], r['destination_activity_description']),
                         ('E3', 'SERVICE_ORDER', 'Job one'))
        self.assertEqual((r['start_lat'], r['start_lng'], r['end_lat'], r['end_lng']), (52.0, -106.0, 52.1, -106.1))
        self.assertEqual((r['distance_km'], r['travel_sec'], r['travel_min']), (2.97, 414, 6.9))
        self.assertEqual((r['merged_fragments'], r['merge_type'], r['gps_fix'], r['gps_fix_time']), (1, None, False, None))
        self.assertEqual(r['maps_link'], 'https://www.google.com/maps/dir/?api=1&origin=52,-106&destination=52.1,-106.1'
                                         '&travelmode=driving')
        self.assertEqual(r['route_key'], '52.000000,-106.000000|52.100000,-106.100000')
        self.assertEqual((r['plan_id'], r['call_id'], r['siebel_order_number']), ('P1', 'C-2', '1-555'))
        self.assertEqual((r['start_time'], r['end_time'], r['shift_id']), (at('14:00'), at('14:20'), 'S1'))
        self.assertTrue(all(r[k] is None for _, k in pt.GOOGLE_COLUMNS))

    def test_activity_to_activity_and_end_of_day(self):
        rows, c, w = legs_for(DAY)
        a = by_dest(rows, '1002')
        self.assertEqual((a['direction'], a['previous_gb'], a['destination_gb']), ('Activity -> Activity', 'E3', 'XB1L'))
        self.assertEqual((a['previous_activity_type'], a['previous_activity_description']), ('SERVICE_ORDER', 'Job one'))
        self.assertEqual((a['distance_km'], a['travel_sec'], a['travel_min'], a['call_id']), (14.2, 935, 15.6, None))
        e = by_dest(rows, '')
        self.assertEqual((e['direction'], e['destination_gb'], e['destination_activity_type']),
                         ('Activity -> End Location', None, None))
        self.assertEqual((e['end_lat'], e['end_lng']), (52.05, -106.05))
        self.assertEqual((c['end_of_day'], c['rows_in_range'], len(rows)), (1, 3, 3))

    def test_exclusions_on_each_end(self):
        rows, c, w = legs_for(''.join([
            travel(start='15:00', prev='1001', sloc='L-1001', eloc='L-1003', act='9001'),            # PRIVATE
            travel(start='15:10', prev='1002', sloc='L-1002', eloc='L-1003', act='9002'),            # non-availability
            travel(start='15:20', prev='9001', sloc='L-1003', eloc='L-1002', act='1002'),            # origin PRIVATE
            travel(start='15:30', prev='9002', sloc='L-1003', eloc='L-E'),                           # end of day
            travel(start='15:40', prev='1003', sloc='L-1003', eloc='L-1002', act='7777'),            # not in file
        ]))
        self.assertEqual((c['excluded_destination'], c['excluded_origin']), (2, 2))
        self.assertEqual([r['activity_id'] for r in rows], ['7777'])
        self.assertEqual((rows[0]['destination_gb'], rows[0]['destination_activity_type']), (None, None))

    def test_empty_activity_that_is_not_end_of_day_is_dropped(self):
        rows, c, w = legs_for(travel(start='15:00', prev='1001', sloc='L-1001', dist='1', ett='PT1M'))
        self.assertEqual((rows, c['rows_in_range']), ([], 1))

    def test_date_filter_runs_on_the_merged_start(self):
        xml = (travel(day='2026-10-04', start='23:50', end='23:59', sloc='L-1001', prev='1001', dist='100.5', ett='PT1M')
               + travel(start='00:20', end='00:40', sloc='L-1001', eloc='L-1002', act='1002', prev='1001',
                        dist='199.5', ett='PT2M'))
        self.assertEqual(legs_for(xml, date='2026-10-05')[0], [])
        rows = legs_for(xml, date='2026-10-04')[0]
        self.assertEqual((len(rows), rows[0]['merge_type'], rows[0]['merged_fragments']), (1, 'Break', 2))
        self.assertEqual(len(legs_for(xml, date='2026-10-04', days=2)[0]), 1)

    def test_merged_fragments_from_break_key(self):
        rows, c, w = legs_for(''.join([
            # shift S1: a break split R1|1001|1
            travel(start='15:00', end='15:15', sloc='L-1001', prev='1001', dist='25207.4', ett='PT14M55S'),
            travel(start='15:20', end='15:35', sloc='L-1001', eloc='L-1002', act='1002', prev='1001',
                   dist='25629.6', ett='PT15M10S'),
            # shift S2: a fix chain, and a raw row in the fix group with the same break key
            travel(shift='S2', start='16:30', end='17:00', sloc='L-1002', prev='1001', dist='5000', ett='PT5M',
                   event='R1-FIX'),
            travel(shift='S2', start='17:05', end='17:12', eloc='L-1003', act='1003', dist='7000', ett='PT7M',
                   event='R1-FIX'),
            travel(shift='S2', start='18:00', end='18:10', sloc='L-1001', eloc='L-1003', act='1003', prev='1001',
                   dist='8000', ett='PT8M'),
        ]))
        s1 = by_dest(rows, '1002')
        self.assertEqual((s1['merge_type'], s1['merged_fragments'], s1['distance_km'], s1['travel_sec']),
                         ('Break', 2, 50.84, 1805))
        raw = by_dest(rows, '1003', 'Break')                    # script behaviour, kept: key lookup reaches it
        self.assertEqual((raw['merged_fragments'], raw['distance_km'], raw['start_time']), (2, 8.0, at('18:00')))
        fix = by_dest(rows, '1003', 'GPS fix')
        self.assertEqual(fix['merged_fragments'], 1)

    def test_gps_leg_starts_at_the_fix(self):
        rows, c, w = legs_for(travel(start='16:30', end='17:00', sloc='L-1002', prev='1001', dist='5000', ett='PT5M',
                                     event='R1-FIX')
                              + travel(start='17:05', end='17:12', eloc='L-1003', act='1003', dist='7000',
                                       ett='PT7M', event='R1-FIX'))
        r = rows[0]
        self.assertEqual((r['direction'], r['previous_activity_id']), ('Activity -> Activity', '1001'))
        self.assertEqual((r['start_lat'], r['start_lng'], r['gps_fix'], r['gps_fix_time']),
                         (52.25, -106.25, True, at('17:00')))
        self.assertEqual((r['prefix_distance_km'], r['prefix_travel_min']), (5.0, 5.0))
        self.assertIn('origin=52.25,-106.25&', r['maps_link'])

    def test_missing_coordinates_give_blanks_and_no_link(self):
        rows, c, w = legs_for(travel(start='14:00', sloc='L-S', eloc='L-NOPE', act='1001', dist='1', ett='PT1M')
                              + travel(resource='R2', start='14:00', sloc='L-BAD', eloc='L-1001', act='1001'))
        self.assertEqual(len(rows), 2)
        for r in rows:
            self.assertIsNone(r['maps_link'])
            self.assertIsNone(r['route_key'])
        self.assertEqual((rows[0]['end_lat'], rows[0]['end_lng']), (None, None))
        self.assertEqual(next(r for r in rows if r['resource_id'] == 'R2')['resource_name'], 'Bo')

    def test_pso_time_with_days_or_fractions_is_none(self):
        rows, c, w = legs_for(travel(start='14:00', sloc='L-S', eloc='L-1001', act='1001', dist='1', ett='P1DT1H')
                              + travel(resource='R2', start='14:00', sloc='L-S', eloc='L-1001', act='1001', dist='1',
                                       ett='PT1H2M3.5S'))
        self.assertEqual([(r['travel_sec'], r['travel_min']) for r in rows], [(None, None), (None, None)])
        self.assertEqual([pt.pso_seconds(s) for s in ('PT1H2M3S', 'pt5m', 'PT', '', None)], [3723, 300, 0, None, None])

    def test_route_key_six_decimals(self):
        rows, c, w = legs_for(travel(start='14:00', sloc='L-FINE', eloc='L-1001', act='1001', dist='1', ett='PT1M'))
        self.assertEqual(rows[0]['route_key'], '52.123457,-106.500000|52.100000,-106.100000')
        self.assertIsNone(pt.route_key(52.1, None, 52.0, -106.0))

    def test_fmt_num(self):
        self.assertEqual([pt.fmt_num(v) for v in (None, 52.0, 14.2, 442, 52.809507318624306, 0.0, -106.5)],
                         ['', '52', '14.2', '442', '52.809507318624306', '0', '-106.5'])


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 3: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_rows`
Expected: `AttributeError: module 'plan_travels' has no attribute 'build'`.

- [ ] **Step 4: Implement**

Add after `merge_breaks` (inventory sections 4 "Step 4" and 6):

```python
DIRECTIONS = ('Start Location -> Activity', 'Activity -> Activity', 'Activity -> End Location')
MERGE_TYPES = ('Break', 'Break (slice dropped)', 'Break (duplicate)', 'Start of day', 'Start of day (duplicate)',
               'GPS fix', 'GPS fix (slice dropped)', 'GPS fix (duplicate)')
COLUMNS = [
    ('Resource ID', 'resource_id'), ('Resource Name', 'resource_name'), ('Direction', 'direction'),
    ('Previous Activity ID', 'previous_activity_id'), ('GB (Previous Activity)', 'previous_gb'),
    ('Previous Activity Type', 'previous_activity_type'),
    ('Previous Activity Description', 'previous_activity_description'),
    ('Activity ID (Destination)', 'activity_id'), ('GB (Destination Activity)', 'destination_gb'),
    ('Destination Activity Type', 'destination_activity_type'),
    ('Destination Activity Description', 'destination_activity_description'),
    ('Start Location Latitude', 'start_lat'), ('Start Location Longitude', 'start_lng'),
    ('End Location Latitude', 'end_lat'), ('End Location Longitude', 'end_lng'),
    ('Distance (km)', 'distance_km'), ('Expected Travel Time (min)', 'travel_min'),
    ('Expected Travel Time (sec)', 'travel_sec'), ('Merged Fragments', 'merged_fragments'),
    ('Merge Type', 'merge_type'), ('GPS Fix', 'gps_fix'), ('GPS Fix Time', 'gps_fix_time'),
    ('Pre-Fix Distance (km)', 'prefix_distance_km'), ('Pre-Fix Travel (min)', 'prefix_travel_min'),
    ('Pre-Shift Distance (km)', 'preshift_distance_km'), ('Pre-Shift Travel (min)', 'preshift_travel_min'),
    ('Google Maps Directions Link', 'maps_link'), ('Plan ID', 'plan_id'), ('Call ID', 'call_id'),
    ('Siebel Order Number', 'siebel_order_number'),
]
GOOGLE_COLUMNS = [
    ('Google Duration (min)', 'google_min'), ('Google Duration (sec)', 'google_sec'),
    ('Google Distance (km)', 'google_km'), ('Google Source', 'google_source'), ('Ratio (Google / PSO)', 'ratio'),
    ('% Divergence', 'pct_divergence'), ('Abs Divergence (min)', 'abs_divergence_min'), ('Difference', 'difference'),
    ('Flag', 'flag'), ('Estimated Weighting Adjustment', 'weighting'), ('Validation Error', 'validation_error'),
]
EXTRA_KEYS = ('start_time', 'end_time', 'shift_id', 'route_key')
LEG_KEYS = tuple(k for _, k in COLUMNS) + EXTRA_KEYS + tuple(k for _, k in GOOGLE_COLUMNS)
MAPS = 'https://www.google.com/maps/dir/?api=1&origin=%s,%s&destination=%s,%s&travelmode=driving'
_PSO_TIME = re.compile(r'^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$', re.I)     # PowerShell -match is case-insensitive


def pso_seconds(s):
    m = _PSO_TIME.match(s or '')
    if not m:
        return None
    h, mi, se = (int(x) if x else 0 for x in m.groups())
    return h * 3600 + mi * 60 + se


def fmt_num(v):
    """A number as pwsh prints it: shortest round-trip, no '.0' on a whole value; '' for None."""
    if v is None:
        return ''
    if isinstance(v, bool):
        return 'Yes' if v else ''
    if isinstance(v, int):
        return str(v)
    if v == int(v) and abs(v) < 1e15:
        return str(int(v))
    return repr(v)


def route_key(o_lat, o_lng, d_lat, d_lng):
    if None in (o_lat, o_lng, d_lat, d_lng):
        return None
    return '%.6f,%.6f|%.6f,%.6f' % (o_lat, o_lng, d_lat, d_lng)


def private_or_non_availability(ex, aid):
    if not aid:
        return False
    a = ex.activities.get(aid)
    if a is None:
        return False
    return a['class'].lower() == 'private' or a['type'].lower() == 'non-availability'


def activity_gb(ex, aid):
    if not aid:
        return None
    a = ex.activities.get(aid)
    if a is None or not a['location_id']:
        return None
    return ex.location_region.get(a['location_id'])


def build_rows(ex, legs, merged_keys, dates, c):
    rows = []
    for leg in legs:
        st = leg['start_time']
        if not in_range(st, dates):
            continue
        c['rows_in_range'] += 1
        aid, prev = leg['activity_id'] or '', leg['previous_activity_id'] or ''
        eod = not aid and bool(prev) and bool(leg['end_location_id'])
        if not aid and not eod:
            continue
        if not eod and private_or_non_availability(ex, aid):
            c['excluded_destination'] += 1
            continue
        if prev and private_or_non_availability(ex, prev):
            c['excluded_origin'] += 1
            continue
        if eod:
            c['end_of_day'] += 1
        act = None if eod else ex.activities.get(aid)
        prev_act = ex.activities.get(prev) if prev else None
        o_lat, o_lng = ex.locations.get(leg['start_location_id'] or '', (None, None))
        is_fix = bool(leg.get('gps_fix'))
        if is_fix:
            o_lat, o_lng = leg['fix_lat'], leg['fix_lon']
        d_lat, d_lng = ex.locations.get(leg['end_location_id'] or '', (None, None))
        dist = dist_value(leg['distance'])
        sec = pso_seconds(leg['expected_travel_time'])
        mkey = '%s|%s|%s' % (leg['resource_id'], prev, leg['previous_visit_id'] or '')
        frags = leg['merged_fragments'] if 'merged_fragments' in leg else merged_keys.get(mkey, 1)
        mtype = leg['merge_type'] if 'merge_type' in leg else ('Break' if frags > 1 else None)
        attr = ex.attributes.get(aid) if aid else None
        links = None not in (o_lat, o_lng, d_lat, d_lng)
        row = dict.fromkeys(LEG_KEYS)
        row.update(
            resource_id=leg['resource_id'], resource_name=ex.resources.get(leg['resource_id']),
            direction=DIRECTIONS[2] if eod else (DIRECTIONS[0] if not prev else DIRECTIONS[1]),
            previous_activity_id=prev, previous_gb=activity_gb(ex, prev),
            previous_activity_type=prev_act['type'] if prev_act else None,
            previous_activity_description=prev_act['description'] if prev_act else None,
            activity_id=aid, destination_gb=activity_gb(ex, aid),
            destination_activity_type=act['type'] if act else None,
            destination_activity_description=act['description'] if act else None,
            start_lat=o_lat, start_lng=o_lng, end_lat=d_lat, end_lng=d_lng,
            distance_km=ps_round(dist / 1000, 2) if dist is not None else None,
            travel_sec=sec, travel_min=ps_round(sec / 60, 1) if sec is not None else None,
            merged_fragments=frags, merge_type=mtype or None,
            gps_fix=is_fix,
            gps_fix_time=leg['fix_time'] if is_fix else None,
            prefix_distance_km=leg['prefix_distance_km'] if is_fix else None,
            prefix_travel_min=leg['prefix_travel_min'] if is_fix else None,
            preshift_distance_km=leg.get('preshift_distance_km'),
            preshift_travel_min=leg.get('preshift_travel_min'),
            maps_link=MAPS % (fmt_num(o_lat), fmt_num(o_lng), fmt_num(d_lat), fmt_num(d_lng)) if links else None,
            plan_id=leg['plan_id'] or '',
            call_id=attr['call_id'] if attr else None,
            siebel_order_number=attr['order_no'] if attr else None,
            start_time=st, end_time=leg['end_time'] or '', shift_id=leg['shift_id'] or '',
            route_key=route_key(o_lat, o_lng, d_lat, d_lng),
        )
        rows.append(row)
    return rows


def build(ex, dates, warnings):
    """The three merge passes in the script's order, then Step 4. Rows come back unsorted."""
    c = collections.Counter()
    fix_legs, plain = merge_fix_chains(ex, c, warnings)
    sod_legs, pool = pair_start_of_day(plain, c, warnings)
    merged, merged_keys = merge_breaks(pool, c, warnings)
    rows = build_rows(ex, merged + fix_legs + sod_legs, merged_keys, set(dates), c)
    return rows, c
```

- [ ] **Step 5: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/pt_fixtures.py tests/plan_travels/test_rows.py
git commit -m "Plan travels: Step 4 date filter, exclusions, direction and enrichment"
```

---

### Task 7: Sort, meta, CSV, legs.json, page filling, summary and warnings

**Files:**
- Modify: `plan-travels/plan_travels.py`, `tests/plan_travels/pt_fixtures.py`, `tests/plan_travels/test_reader.py` (remove `test_build_reads_rows`)
- Create: `tests/plan_travels/test_outputs.py`

**Interfaces:**
- Consumes: `build`, `COLUMNS`, `GOOGLE_COLUMNS`, `LEG_KEYS`, `fmt_num` (Task 6).
- Produces:
```python
NOTES: list[str]
def row_sort_key(r) -> tuple
def make_meta(ex, dates, rows, c, args, warnings) -> dict      # the meta schema under Interfaces
def summary_line(meta) -> str
def csv_rows(data) -> list[list[str]]
def render_html(template, data) -> str
def write_outputs(data, out_dir, template_path) -> list[str]   # legs.json, plan_travels.csv, plan_travels.html
```
- `pt_fixtures.template_path() -> str` (a tiny template for tests; the real one arrives in Task 8).

- [ ] **Step 1: Fixture template**

Append to `tests/plan_travels/pt_fixtures.py`:

```python
TEST_TEMPLATE = '<!doctype html><title>__TITLE__</title><script>\nconst DATA = __DATA__;\n</script>'


def template_path():
    path = os.path.join(tmpdir(), 'template.html')
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(TEST_TEMPLATE)
    return path


def build_cli(rows_xml, date='2026-10-05', days=1, extra=None, zip_input=False):
    """Run 'build' on a synthetic export; returns (code, out, err, out_dir)."""
    path = write_zip(rows_xml) if zip_input else write_export(rows_xml)
    out_dir = tmpdir()
    code, out, err = run_main(['build', '--export', path, '--date', date, '--days', str(days), '--out-dir', out_dir,
                               '--template', template_path()] + (extra or []))
    return code, out, err, out_dir
```

- [ ] **Step 2: Failing tests**

`tests/plan_travels/test_outputs.py`:

```python
"""Outputs: files, CSV columns and number format, legs.json, sort, zero legs, warnings, summary, zip input."""
import csv
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, row, at, build_cli, write_export, run_main, tmpdir  # noqa: E402

DAY = (travel(start='14:00', end='14:20', sloc='L-S', eloc='L-1001', act='1001', dist='2966', ett='PT6M54S')
       + travel(start='15:00', end='15:16', prev='1001', sloc='L-1001', eloc='L-1002', act='1002', dist='14200',
                ett='PT15M35S')
       + travel(start='16:00', end='16:08', prev='1002', sloc='L-1002', eloc='L-E', dist='3330', ett='PT7M22S'))
HEADER = ['Resource ID', 'Resource Name', 'Direction', 'Previous Activity ID', 'GB (Previous Activity)',
          'Previous Activity Type', 'Previous Activity Description', 'Activity ID (Destination)',
          'GB (Destination Activity)', 'Destination Activity Type', 'Destination Activity Description',
          'Start Location Latitude', 'Start Location Longitude', 'End Location Latitude', 'End Location Longitude',
          'Distance (km)', 'Expected Travel Time (min)', 'Expected Travel Time (sec)', 'Merged Fragments',
          'Merge Type', 'GPS Fix', 'GPS Fix Time', 'Pre-Fix Distance (km)', 'Pre-Fix Travel (min)',
          'Pre-Shift Distance (km)', 'Pre-Shift Travel (min)', 'Google Maps Directions Link', 'Plan ID', 'Call ID',
          'Siebel Order Number']


def read_csv(out_dir):
    with open(os.path.join(out_dir, 'plan_travels.csv'), encoding='utf-8-sig', newline='') as fh:
        return list(csv.reader(fh))


def read_json(out_dir):
    with open(os.path.join(out_dir, 'legs.json'), encoding='utf-8') as fh:
        return json.load(fh)


class OutputTests(unittest.TestCase):
    def test_writes_three_files_and_header(self):
        code, out, err, d = build_cli(DAY, extra=['--dataset-name', 'NORTH'])
        self.assertEqual(code, 0, err)
        for name in ('legs.json', 'plan_travels.csv', 'plan_travels.html'):
            self.assertTrue(os.path.exists(os.path.join(d, name)), name)
        rows = read_csv(d)
        self.assertEqual(rows[0], HEADER)
        self.assertEqual(len(rows), 4)
        with open(os.path.join(d, 'plan_travels.csv'), 'rb') as fh:
            self.assertTrue(fh.read().startswith(b'\xef\xbb\xbf"Resource ID","Resource Name"'))

    def test_csv_numbers_print_like_pwsh(self):
        code, out, err, d = build_cli(DAY)
        rows = read_csv(d)
        rec = dict(zip(rows[0], next(r for r in rows[1:] if r[7] == '1001')))
        self.assertEqual((rec['Start Location Latitude'], rec['Distance (km)'], rec['Expected Travel Time (sec)']),
                         ('52', '2.97', '414'))
        self.assertEqual((rec['Expected Travel Time (min)'], rec['Merged Fragments'], rec['GPS Fix'], rec['Merge Type']),
                         ('6.9', '1', '', ''))

    def test_legs_json_keys_and_meta(self):
        code, out, err, d = build_cli(DAY, extra=['--dataset-name', 'NORTH', '--review-minutes', '15'])
        data = read_json(d)
        self.assertEqual(list(data), ['meta', 'legs'])
        for leg in data['legs']:
            self.assertEqual(list(leg), list(pt.LEG_KEYS))
        m = data['meta']
        self.assertEqual((m['tool'], m['schema_version'], m['export_name'], m['dataset_name']),
                         ('plan_travels', 1, 'export.xml', 'NORTH'))
        self.assertEqual((m['date_from'], m['date_to'], m['days']), ('2026-10-05', '2026-10-05', 1))
        self.assertEqual(m['thresholds'], {'review_minutes': 15.0, 'review_pct': 0.0})
        self.assertEqual((m['google'], m['google_counts']), (False, None))
        k = m['counts']
        self.assertEqual((k['plan_travel_rows'], k['legs'], k['resources'], k['end_of_day']), (3, 3, 1, 1))
        self.assertEqual(k['by_direction'], {'Start Location -> Activity': 1, 'Activity -> Activity': 1,
                                             'Activity -> End Location': 1})
        self.assertEqual(set(k['by_merge_type']), set(pt.MERGE_TYPES))
        self.assertEqual((k['merged_legs'], k['missing_coordinates'], k['missing_pso_time'], k['missing']), (0, 0, 0, 0))
        self.assertEqual((k['review'], k['ok'], k['average_ratio'], k['fix_events']), (None, None, None, 1))
        self.assertEqual(m['notes'], pt.NOTES)

    def test_rows_sorted_resource_start_activity(self):
        xml = ''.join([
            travel(resource='10', start='09:00', sloc='L-S', eloc='L-1001', act='1001'),
            travel(resource='2', start='15:00', prev='1001', sloc='L-1001', eloc='L-1002', act='1002'),
            travel(resource='2', start='09:00', sloc='L-S', eloc='L-1002', act='1003'),
            travel(resource='2', start='09:00', sloc='L-S', eloc='L-1002', act='1002', shift='S2'),
        ])
        code, out, err, d = build_cli(xml)
        order = [(l['resource_id'], l['start_time'][11:16], l['activity_id']) for l in read_json(d)['legs']]
        self.assertEqual(order, [('2', '09:00', '1002'), ('2', '09:00', '1003'), ('2', '15:00', '1002'),
                                 ('10', '09:00', '1001')])

    def test_zero_legs_writes_header_only_csv(self):
        code, out, err, d = build_cli(DAY, date='2026-12-01')
        self.assertEqual(code, 0, err)
        self.assertEqual(read_csv(d), [HEADER])
        self.assertEqual(read_json(d)['legs'], [])
        self.assertEqual(out.strip().splitlines()[-1], 'summary: No travel legs on 2026-12-01')

    def test_warnings_go_to_stderr_and_meta(self):
        xml = (travel(start='13:45', sloc='L-S', act='1001', dist='1', ett='PT1M')
               + travel(start='13:50', sloc='L-S', act='1001', dist='2', ett='PT1M'))
        code, out, err, d = build_cli(xml)
        self.assertEqual(code, 0, err)
        warn = [l for l in err.splitlines() if l.startswith('warning: ')]
        self.assertEqual(len(warn), 2)
        self.assertTrue(warn[0].startswith('warning: start-of-day travel fragment with no partner'))
        self.assertEqual(read_json(d)['meta']['warnings'], [w[len('warning: '):] for w in warn])
        self.assertNotIn('warning:', out)

    def test_summary_is_last_stdout_line(self):
        code, out, err, d = build_cli(DAY, days=2)
        self.assertEqual(out.strip().splitlines()[-1], 'summary: 3 legs for 1 resource, 2026-10-05 to 2026-10-06')

    def test_zip_input(self):
        code, out, err, d = build_cli(DAY, zip_input=True)
        self.assertEqual(code, 0, err)
        self.assertEqual(len(read_json(d)['legs']), 3)

    def test_html_is_filled_and_escapes_script_close(self):
        xml = row('Activity', id='1004', activity_class_id='CALL', activity_type_id='X',
                  description='&lt;/script&gt;&lt;b&gt;', location_id='L-1001')
        xml += travel(start='14:00', sloc='L-S', eloc='L-1001', act='1004')
        code, out, err, d = build_cli(xml)
        page = Path(d, 'plan_travels.html').read_text(encoding='utf-8')
        self.assertIn('<title>Plan travels 2026-10-05</title>', page)
        self.assertIn('const DATA = {"meta":', page)
        self.assertIn('\\u003c/script>\\u003cb>', page)
        self.assertNotIn('</script><b>', page)

    def test_missing_template_exit_1(self):
        code, out, err = run_main(['build', '--export', write_export(DAY), '--date', '2026-10-05', '--out-dir',
                                   tmpdir(), '--template', '/nope/t.html'])
        self.assertEqual(code, 1)
        self.assertIn('Stopped during: reading the arguments: The page template t.html was not found.', err)


if __name__ == '__main__':
    unittest.main()
```

In `tests/plan_travels/test_reader.py`: delete `test_build_reads_rows`; add `template_path` to its `from pt_fixtures import ...` line; and append `'--template', template_path()` to the argv of every `run_main(['build', ...])` call in `CliTests` (the four remaining tests), because `main()` now checks the template before anything else and the real template only arrives in Task 8. For example:

```python
        code, out, err = run_main(['build', '--export', '/nope/plan.xml', '--date', '2026-10-05', '--out-dir', tmpdir(),
                                   '--template', template_path()])
```

- [ ] **Step 3: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_outputs`
Expected: failures (no `legs.json` written; `summary: read ...`).

- [ ] **Step 4: Implement**

Add after `build` (copy the escaping in `render_html` from `trace_activity.py` lines 3176 to 3187):

```python
NOTES = [
    'Times are UTC. A leg belongs to the day its travel starts (the first 10 characters of its start time).',
    'Legs that touch a private activity or a non-availability block at either end are left out.',
    'Journeys PSO split into several Plan_Travel rows are joined into one leg. Merge Type says how: Break, '
    'Start of day or GPS fix. "Slice dropped" and "duplicate" mean the whole-metre trip was kept and the other '
    'pieces were left out.',
    'A GPS fix leg starts at the technician\'s GPS position. The catch-up travel to that position is shown in '
    'the Pre-Fix columns, not added to the leg.',
    'A leg with a PSO time of 0 seconds and a Google time is flagged Missing PSO Time (the Windows script said '
    'Missing Google Time).',
    'Estimated Weighting Adjustment is the Google time divided by the PSO time, on Review legs only: below 1 '
    'means PSO allows more time than Google.',
    'Rows are sorted by resource, then leg start time, then destination activity.',
]


def _natural(s):
    s = s or ''
    return (0, int(s), '') if s.isascii() and s.isdigit() else (1, 0, s)


def row_sort_key(r):
    return (_natural(r['resource_id']), r['start_time'] or '', _natural(r['activity_id']))


def make_meta(ex, dates, rows, c, args, warnings):
    by_dir = dict.fromkeys(DIRECTIONS, 0)
    by_type = dict.fromkeys(MERGE_TYPES, 0)
    for r in rows:
        by_dir[r['direction']] += 1
        if r['merge_type']:
            by_type[r['merge_type']] += 1
    missing_pso = sum(1 for r in rows if r['travel_sec'] is None)
    counts = {
        'plan_travel_rows': len(ex.travel), 'legs': len(rows), 'resources': len({r['resource_id'] for r in rows}),
        'by_direction': by_dir, 'by_merge_type': by_type,
        'merged_legs': sum(1 for r in rows if r['merged_fragments'] > 1),
        'missing_coordinates': sum(1 for r in rows if r['route_key'] is None),
        'missing_pso_time': missing_pso,
        'fix_events': len(ex.fixes), 'sod_pairs': c['sod_split'] + c['sod_duplicate'],
    }
    for k in ('rows_in_range', 'excluded_destination', 'excluded_origin', 'end_of_day', 'fragments_left_out',
              'fix_legs', 'fix_dead_ends', 'fix_tagged_rows', 'fix_tagged_resources', 'fix_tagged_accounted',
              'synthetic_rows', 'synthetic_chained', 'sod_slice', 'sod_summed', 'sod_duplicate', 'sod_unpaired',
              'break_merged', 'break_absorbed', 'break_not_summed'):
        counts[k] = c[k]
    counts.update(review=None, ok=None, skipped=None, missing_google_time=None, missing=missing_pso,
                  average_ratio=None)
    return {
        'tool': 'plan_travels', 'schema_version': 1,
        'export_name': os.path.basename(args.export), 'dataset_name': args.dataset_name,
        'generated': dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'date_from': dates[0], 'date_to': dates[-1], 'days': len(dates),
        'thresholds': {'review_minutes': args.review_minutes, 'review_pct': args.review_pct},
        'google': False, 'google_counts': None, 'counts': counts,
        'warnings': list(warnings), 'notes': list(NOTES),
    }


def _plural(n, word):
    return '%s %s%s' % (format(n, ','), word, '' if n == 1 else 's')


def _range(meta):
    if meta['date_from'] == meta['date_to']:
        return meta['date_from']
    return '%s to %s' % (meta['date_from'], meta['date_to'])


def summary_line(meta):
    k = meta['counts']
    if not k['legs']:
        one_day = meta['date_from'] == meta['date_to']
        return 'No travel legs %s%s' % ('on ' if one_day else 'from ', _range(meta))
    text = '%s for %s, %s' % (_plural(k['legs'], 'leg'), _plural(k['resources'], 'resource'), _range(meta))
    if meta['google']:
        text += '; %s to review, %s missing' % (format(k['review'], ','), format(k['missing'], ','))
    return text


def _cell(v):
    if v is None:
        return ''
    if isinstance(v, (bool, int, float)):
        return fmt_num(v)
    return str(v)


def csv_rows(data):
    cols = COLUMNS + (GOOGLE_COLUMNS if data['meta']['google'] else [])
    return [[c for c, _ in cols]] + [[_cell(leg[k]) for _, k in cols] for leg in data['legs']]


def page_title(meta):
    bits = ['Plan travels', meta['dataset_name'] or '', _range(meta)]
    return ' '.join(b for b in bits if b)


def render_html(template, data):
    """__TITLE__ and __DATA__ filled in. Every '<' in the data is written as \\u003c, so no value can close or
    comment out the script block."""
    title = html.escape(page_title(data['meta']))
    blob = json.dumps(data, ensure_ascii=False, separators=(',', ':')).replace('<', '\\u003c')
    blob = blob.replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
    head, sep, tail = template.partition('__DATA__')
    if not sep:
        raise PlanTravelsError('The page template has no __DATA__ placeholder.')
    return head.replace('__TITLE__', title) + blob + tail


def write_outputs(data, out_dir, template_path):
    os.makedirs(out_dir, exist_ok=True)
    json_path = os.path.join(out_dir, 'legs.json')
    with open(json_path, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, indent=1, ensure_ascii=False)
    csv_path = os.path.join(out_dir, 'plan_travels.csv')
    with open(csv_path, 'w', encoding='utf-8-sig', newline='') as fh:     # BOM and quoting as Export-Csv
        csv.writer(fh, quoting=csv.QUOTE_ALL).writerows(csv_rows(data))
    html_path = os.path.join(out_dir, 'plan_travels.html')
    with open(template_path, encoding='utf-8') as fh:
        page = render_html(fh.read(), data)
    with open(html_path, 'w', encoding='utf-8') as fh:
        fh.write(page)
    return [json_path, csv_path, html_path]
```

Replace `main()`:

```python
def main(argv=None):
    stage = 'reading the arguments'
    try:
        args = build_parser().parse_args(argv)
        if not os.path.isfile(args.template):
            raise PlanTravelsError('The page template %s was not found.' % os.path.basename(args.template))
        if args.command != 'build':
            raise _ArgError('merge-google is not available yet')
        dates = date_list(args.date, args.days)
        stage = 'reading the export'
        ex = read_export(args.export, Progress)
        stage = 'building the legs'
        warnings = []
        rows, c = build(ex, dates, warnings)
        rows.sort(key=row_sort_key)
        for w in warnings:
            sys.stderr.write('warning: %s\n' % w)
        stage = 'writing the outputs'
        data = {'meta': make_meta(ex, dates, rows, c, args, warnings), 'legs': rows}
        write_outputs(data, args.out_dir, args.template)
        print('summary: %s' % summary_line(data['meta']))
        return 0
    except (_ArgError, PlanTravelsError) as e:
        sys.stderr.write('Stopped during: %s: %s\n' % (stage, e))
        return 1
    except Exception as e:  # noqa: BLE001 - every other failure is exit 1 with the stage
        sys.stderr.write('Stopped during: %s\n%s: %s\n' % (stage, type(e).__name__, e))
        return 1
```

- [ ] **Step 5: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/
git commit -m "Plan travels: legs.json, plan_travels.csv, page filling, warnings and summary"
```

---

### Task 8: The page template

**Files:**
- Create: `plan-travels/plan_travels_template.html`, `tests/plan_travels/test_page.py`

**Interfaces:**
- Consumes: `legs.json` shape (Interfaces), `render_html` (Task 7).
- Produces: `plan_travels_template.html` with `__TITLE__` once and `const DATA = __DATA__;` once; element ids `theme`, `download-csv`, `sub`, `google-line`, `tiles`, `s-warnings`, `warnings`, `f-resource`, `f-direction`, `f-merge`, `f-flag`, `f-search`, `f-clear`, `shown`, `legs`, `more`, `s-empty`, `notes`, `foot`.

The page (spec 4.4), in the house style of `activity-trace/trace_template.html` (colour tokens lines 8 to 28, base type lines 29 to 40, theme toggle lines 432 to 434 with the shared localStorage key `pso-audit-theme`):

- Header: "Plan travels"; a sub line joining dataset name (when set), the date range ("2026-10-05" or "2026-10-05 to 2026-10-09"), export name and leg count with " · "; a Google line: with Google, "Checked against Google Routes: N from the shared cache, N fetched from Google, N skipped. A leg is flagged Review when PSO and Google differ by at least M minutes" plus " and P%" when `review_pct > 0`; without Google, "Not checked against Google Routes."
- Top actions: theme button; "Download CSV" linking to `plan_travels.csv`, shown only when the page is opened from a file (`location.protocol === 'file:'`, the CLI case: the CSV sits beside the page). In the web app the run page puts its own Download CSV and Download JSON buttons above the iframe (pso-test-tools plan), so the in-page link is hidden there.
- Tiles: Legs; Resources; Review legs ("Not checked" without Google); Missing legs (`counts.missing`, caption "No PSO time" without Google, "No PSO or Google time" with); Average ratio, Google / PSO (2 dp, "Not checked" without Google).
- Warnings panel, shown when `meta.warnings` is not empty: first 20, then a "Show all N warnings" button. Warnings are on the page, never only in tooltips.
- Filters: resource (id plus name), direction, merge type (with "No merge"), flag (hidden without Google), free-text search over every cell, Clear filters, and "Showing X of Y legs".
- Leg table: every column in CSV order (30, plus 11 with Google), then "Check in PSO". Click a header to sort (again to reverse); numbers sort as numbers, empty cells last, text with `localeCompare(..., {numeric: true})`. Rows render 500 at a time with "Show more". Google Maps link cell reads "Map", opens in a new tab (`target="_blank" rel="noopener"`). "Check in PSO" links to `/travel-analyzer?lat_from=&long_from=&lat_to=&long_to=` (new tab) when all four coordinates exist and the page is not a file (in the sandboxed iframe a root-relative link resolves against the app). Flag cells are coloured pills with the word always visible: Review amber, Missing PSO Time and Missing Google Time red, OK green, Skipped grey.
- Zero legs: the filters and table are hidden and `s-empty` says "No travel legs in this export for the dates asked for. Check the first date and the number of days."
- Notes list (`meta.notes`) and a footer "Generated <time> UTC from <export name>."
- Self-contained: no external scripts, stylesheets or fonts (spec 7: the only outbound links are Google Maps and the Travel Analyzer). Plain English, no em dashes, never "placed". Layout works at phone width (16px side gutter, the table scrolls inside its panel).

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_page.py`:

```python
"""The page template: placeholders, theme key, house wording, self-contained, script parses."""
import os
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, write_export, run_main, tmpdir  # noqa: E402

TEMPLATE = Path(pt.__file__).with_name('plan_travels_template.html')
IDS = ('theme', 'download-csv', 'sub', 'google-line', 'tiles', 's-warnings', 'warnings', 'f-resource', 'f-direction',
       'f-merge', 'f-flag', 'f-search', 'f-clear', 'shown', 'legs', 'more', 's-empty', 'notes', 'foot')


class PageTests(unittest.TestCase):
    def setUp(self):
        self.t = TEMPLATE.read_text(encoding='utf-8')

    def test_placeholders_once(self):
        self.assertEqual(self.t.count('__TITLE__'), 1)
        self.assertEqual(self.t.count('__DATA__'), 1)
        self.assertIn('const DATA = __DATA__;', self.t)

    def test_shared_theme_key_and_dark_mode(self):
        self.assertIn('pso-audit-theme', self.t)
        self.assertIn('prefers-color-scheme: dark', self.t)
        self.assertIn(':root[data-theme="dark"]', self.t)

    def test_house_wording(self):
        self.assertNotIn('\u2014', self.t)
        self.assertNotIn('\u2013', self.t)
        self.assertIsNone(re.search(r'\bplaced\b', self.t, re.I))

    def test_self_contained(self):
        self.assertNotIn('<script src', self.t)
        self.assertNotIn('<link', self.t)
        self.assertNotIn('@import', self.t)

    def test_ids_present(self):
        for i in IDS:
            self.assertIn('id="%s"' % i, self.t, i)

    def test_default_template_used_by_build(self):
        out = tmpdir()
        code, so, se = run_main(['build', '--export', write_export(travel(sloc='L-S', eloc='L-1001', act='1001')),
                                 '--date', '2026-10-05', '--out-dir', out])
        self.assertEqual(code, 0, se)
        page = Path(out, 'plan_travels.html').read_text(encoding='utf-8')
        self.assertIn('const DATA = {"meta":', page)

    @unittest.skipUnless(shutil.which('node'), 'node not installed')
    def test_page_script_parses(self):
        scripts = re.findall(r'<script>(.*?)</script>', self.t, re.S)
        js = '\n'.join(scripts).replace('__DATA__', '{"meta":{},"legs":[]}')
        path = os.path.join(tmpdir(), 'page.js')
        Path(path).write_text(js, encoding='utf-8')
        r = subprocess.run(['node', '--check', path], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_page`
Expected: `FileNotFoundError` on `plan_travels_template.html`.

- [ ] **Step 3: Write the template**

`plan-travels/plan_travels_template.html`:

```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITLE__</title>
<style>
:root{--bg:#F3F5F7;--surface:#FFFFFF;--ink:#18212B;--muted:#586474;--line:#D9DFE6;--soft:#EAEEF2;--accent:#12737A;
  --ok:#2E7D62;--review:#B86E0F;--missing:#C8443B;--skip:#7C8796}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bg:#0F141A;--surface:#171E26;--ink:#E6EBF1;
  --muted:#9AA7B6;--line:#2A3541;--soft:#1F2832;--accent:#4FB8BF;--ok:#4FB38F;--review:#F0A23C;--missing:#E8685F;--skip:#9AA7B6}}
:root[data-theme="dark"]{--bg:#0F141A;--surface:#171E26;--ink:#E6EBF1;--muted:#9AA7B6;--line:#2A3541;--soft:#1F2832;
  --accent:#4FB8BF;--ok:#4FB38F;--review:#F0A23C;--missing:#E8685F;--skip:#9AA7B6}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:400 15px/1.5 "Public Sans",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-variant-numeric:tabular-nums}
.wrap{max-width:1400px;margin:0 auto;padding:28px 16px 64px}
h1{font-size:28px;line-height:1.2;margin:0 0 6px;font-weight:700}
h2{font-size:20px;margin:28px 0 8px;font-weight:600}
.sub{color:var(--muted);margin:0 0 6px}
.top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start}
.top-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{font:inherit;font-size:14px;padding:6px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);cursor:pointer;text-decoration:none}
.btn:hover{border-color:var(--accent)}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:20px}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.tile .v{font-size:26px;font-weight:700}
.tile .l{color:var(--muted);font-size:13px}
.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:0 0 8px}
.filters label{display:flex;flex-direction:column;font-size:13px;color:var(--muted);gap:2px}
.filters select,.filters input{font:inherit;font-size:14px;padding:5px 8px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);min-width:150px}
.scrollx{overflow-x:auto;max-height:75vh;overflow-y:auto;padding:0}
table{border-collapse:collapse;font-size:13px;width:max-content;min-width:100%}
th,td{padding:6px 8px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap;vertical-align:top}
th{position:sticky;top:0;background:var(--soft);cursor:pointer;user-select:none;font-weight:600}
th[aria-sort="ascending"]::after{content:" \25B2"}
th[aria-sort="descending"]::after{content:" \25BC"}
td.n{text-align:right}
td.desc{white-space:normal;min-width:220px;max-width:360px}
a{color:var(--accent)}
.flag{display:inline-block;padding:1px 8px;border-radius:999px;font-weight:600;font-size:12px;color:#fff}
.f-ok{background:var(--ok)}.f-review{background:var(--review)}.f-missing{background:var(--missing)}.f-skip{background:var(--skip)}
ul{margin:0;padding-left:20px}
footer{margin-top:28px}
@media (max-width:600px){h1{font-size:22px}.filters label{flex:1 1 100%}.filters select,.filters input{width:100%}}
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <h1>Plan travels</h1>
      <p class="sub" id="sub"></p>
      <p class="sub" id="google-line"></p>
    </div>
    <div class="top-actions">
      <a class="btn" id="download-csv" href="plan_travels.csv" download hidden>Download CSV</a>
      <button type="button" class="btn" id="theme">Light or dark</button>
    </div>
  </header>
  <section class="tiles" id="tiles"></section>
  <section id="s-warnings" hidden>
    <h2>Warnings</h2>
    <div class="panel"><ul id="warnings"></ul><p><button type="button" class="btn" id="warn-more" hidden></button></p></div>
  </section>
  <section id="s-legs">
    <h2>Legs</h2>
    <div class="filters">
      <label>Resource<select id="f-resource"></select></label>
      <label>Direction<select id="f-direction"></select></label>
      <label>Merge type<select id="f-merge"></select></label>
      <label id="f-flag-wrap">Flag<select id="f-flag"></select></label>
      <label>Search<input type="search" id="f-search" placeholder="Activity, resource, description"></label>
      <button type="button" class="btn" id="f-clear">Clear filters</button>
    </div>
    <p class="sub" id="shown"></p>
    <div class="panel scrollx"><table id="legs"><thead></thead><tbody></tbody></table></div>
    <p><button type="button" class="btn" id="more" hidden>Show more</button></p>
  </section>
  <section id="s-empty" hidden>
    <div class="panel"><p>No travel legs in this export for the dates asked for. Check the first date and the number of days.</p></div>
  </section>
  <section><h2>Notes</h2><div class="panel"><ul id="notes"></ul></div></section>
  <footer class="sub" id="foot"></footer>
</div>
<script>
const DATA = __DATA__;
</script>
<script>
"use strict";
const $=s=>document.querySelector(s);
const M=DATA.meta||{}, LEGS=DATA.legs||[], G=!!M.google, K=M.counts||{}, FILE=location.protocol==="file:";
const esc=s=>String(s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fmt=v=>v==null?"":String(v);
const plural=(n,w)=>(n||0).toLocaleString("en")+" "+w+(n===1?"":"s");
const COLS=[["resource_id","Resource ID"],["resource_name","Resource Name"],["direction","Direction"],
 ["previous_activity_id","Previous Activity ID"],["previous_gb","GB (Previous Activity)"],["previous_activity_type","Previous Activity Type"],
 ["previous_activity_description","Previous Activity Description"],["activity_id","Activity ID (Destination)"],
 ["destination_gb","GB (Destination Activity)"],["destination_activity_type","Destination Activity Type"],
 ["destination_activity_description","Destination Activity Description"],["start_lat","Start Location Latitude"],
 ["start_lng","Start Location Longitude"],["end_lat","End Location Latitude"],["end_lng","End Location Longitude"],
 ["distance_km","Distance (km)"],["travel_min","Expected Travel Time (min)"],["travel_sec","Expected Travel Time (sec)"],
 ["merged_fragments","Merged Fragments"],["merge_type","Merge Type"],["gps_fix","GPS Fix"],["gps_fix_time","GPS Fix Time"],
 ["prefix_distance_km","Pre-Fix Distance (km)"],["prefix_travel_min","Pre-Fix Travel (min)"],
 ["preshift_distance_km","Pre-Shift Distance (km)"],["preshift_travel_min","Pre-Shift Travel (min)"],
 ["maps_link","Google Maps Directions Link"],["plan_id","Plan ID"],["call_id","Call ID"],["siebel_order_number","Siebel Order Number"]];
const GCOLS=[["google_min","Google Duration (min)"],["google_sec","Google Duration (sec)"],["google_km","Google Distance (km)"],
 ["google_source","Google Source"],["ratio","Ratio (Google / PSO)"],["pct_divergence","% Divergence"],
 ["abs_divergence_min","Abs Divergence (min)"],["difference","Difference"],["flag","Flag"],
 ["weighting","Estimated Weighting Adjustment"],["validation_error","Validation Error"]];
const SHOW=G?COLS.concat(GCOLS):COLS;
const DESC=new Set(["previous_activity_description","destination_activity_description","difference","validation_error"]);
const FLAGCLASS={"OK":"f-ok","Review":"f-review","Missing PSO Time":"f-missing","Missing Google Time":"f-missing","Skipped":"f-skip"};

(function(){const root=document.documentElement;const ap=t=>{if(t)root.setAttribute("data-theme",t);else root.removeAttribute("data-theme");};
  try{ap(localStorage.getItem("pso-audit-theme"));}catch(e){}
  $("#theme").addEventListener("click",()=>{const cur=root.getAttribute("data-theme")||(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");const nx=cur==="dark"?"light":"dark";ap(nx);try{localStorage.setItem("pso-audit-theme",nx);}catch(e){}});})();

const range=M.date_from===M.date_to?M.date_from:(M.date_from+" to "+M.date_to);
$("#sub").textContent=[M.dataset_name,range,M.export_name,plural(K.legs,"leg")].filter(Boolean).join(" \u00b7 ");
if(G){const gc=M.google_counts||{},t=M.thresholds||{};
  $("#google-line").textContent="Checked against Google Routes: "+(gc.cache||0)+" from the shared cache, "+(gc.google||0)+
    " fetched from Google, "+(gc.skipped||0)+" skipped. A leg is flagged Review when PSO and Google differ by at least "+
    t.review_minutes+" minutes"+(t.review_pct>0?" and "+t.review_pct+"%":"")+".";}
else $("#google-line").textContent="Not checked against Google Routes.";
$("#download-csv").hidden=!FILE;

const tile=(v,l)=>'<div class="tile"><div class="v">'+esc(v)+'</div><div class="l">'+esc(l)+'</div></div>';
$("#tiles").innerHTML=tile((K.legs||0).toLocaleString("en"),"Legs")+tile((K.resources||0).toLocaleString("en"),"Resources")+
  tile(G?(K.review||0).toLocaleString("en"):"Not checked","Review legs")+
  tile((K.missing||0).toLocaleString("en"),G?"Missing legs (no PSO or Google time)":"Missing legs (no PSO time)")+
  tile(G&&K.average_ratio!=null?K.average_ratio.toFixed(2):"Not checked","Average ratio, Google / PSO");

(function(){const w=M.warnings||[];if(!w.length)return;$("#s-warnings").hidden=false;
  const draw=all=>{$("#warnings").innerHTML=(all?w:w.slice(0,20)).map(x=>"<li>"+esc(x)+"</li>").join("");
    const b=$("#warn-more");b.hidden=all||w.length<=20;b.textContent="Show all "+w.length+" warnings";};
  $("#warn-more").addEventListener("click",()=>draw(true));draw(false);})();
$("#notes").innerHTML=(M.notes||[]).map(x=>"<li>"+esc(x)+"</li>").join("");
$("#foot").textContent="Generated "+(M.generated||"")+" UTC from "+(M.export_name||"the export")+".";

if(!LEGS.length){$("#s-legs").hidden=true;$("#s-empty").hidden=false;}

const opts=(sel,values,all)=>{sel.innerHTML='<option value="">'+esc(all)+'</option>'+values.map(([v,l])=>'<option value="'+esc(v)+'">'+esc(l)+'</option>').join("");};
const uniq=f=>[...new Set(LEGS.map(f))];
const names={};LEGS.forEach(l=>{names[l.resource_id]=l.resource_name;});
opts($("#f-resource"),Object.keys(names).sort((a,b)=>a.localeCompare(b,undefined,{numeric:true})).map(r=>[r,names[r]?r+" "+names[r]:r]),"All resources");
opts($("#f-direction"),uniq(l=>l.direction).sort().map(d=>[d,d]),"All directions");
opts($("#f-merge"),[["-","No merge"]].concat(uniq(l=>l.merge_type).filter(Boolean).sort().map(t=>[t,t])),"All");
if(G)opts($("#f-flag"),uniq(l=>l.flag).filter(Boolean).sort().map(f=>[f,f]),"All flags");else $("#f-flag-wrap").hidden=true;

const sortState={key:null,dir:1};let limit=500;
const cmp=(a,b)=>{const x=a[sortState.key],y=b[sortState.key];
  if(x==null||x==="")return(y==null||y==="")?0:1;if(y==null||y==="")return -1;
  if(typeof x==="number"&&typeof y==="number")return(x-y)*sortState.dir;
  return String(x).localeCompare(String(y),undefined,{numeric:true})*sortState.dir;};
const text=l=>SHOW.map(([k])=>fmt(l[k])).join(" ").toLowerCase();
const SEARCH=LEGS.map(text);

function filtered(){const r=$("#f-resource").value,d=$("#f-direction").value,m=$("#f-merge").value,f=$("#f-flag").value,
    q=$("#f-search").value.trim().toLowerCase();
  const out=[];LEGS.forEach((l,i)=>{if(r&&l.resource_id!==r)return;if(d&&l.direction!==d)return;
    if(m==="-"&&l.merge_type)return;if(m&&m!=="-"&&l.merge_type!==m)return;if(f&&l.flag!==f)return;
    if(q&&SEARCH[i].indexOf(q)<0)return;out.push(l);});
  if(sortState.key)out.sort(cmp);return out;}

function cell(l,k){const v=l[k];
  if(k==="maps_link")return v?'<td><a href="'+esc(v)+'" target="_blank" rel="noopener">Map</a></td>':"<td></td>";
  if(k==="gps_fix")return"<td>"+(v?"Yes":"")+"</td>";
  if(k==="flag")return v?'<td><span class="flag '+(FLAGCLASS[v]||"f-skip")+'">'+esc(v)+"</span></td>":"<td></td>";
  if(typeof v==="number")return'<td class="n">'+esc(v)+"</td>";
  return'<td'+(DESC.has(k)?' class="desc"':"")+">"+esc(fmt(v))+"</td>";}
function checkLink(l){if(FILE||[l.start_lat,l.start_lng,l.end_lat,l.end_lng].some(v=>v==null))return"<td></td>";
  const q="lat_from="+l.start_lat+"&long_from="+l.start_lng+"&lat_to="+l.end_lat+"&long_to="+l.end_lng;
  return'<td><a href="/travel-analyzer?'+esc(q)+'" target="_blank" rel="noopener">Check in PSO</a></td>';}

function drawHead(){$("#legs thead").innerHTML="<tr>"+SHOW.map(([k,h])=>'<th data-k="'+k+'"'+
  (sortState.key===k?' aria-sort="'+(sortState.dir>0?"ascending":"descending")+'"':"")+">"+esc(h)+"</th>").join("")+"<th>Check in PSO</th></tr>";}
function draw(){const rows=filtered();
  $("#legs tbody").innerHTML=rows.slice(0,limit).map(l=>"<tr>"+SHOW.map(([k])=>cell(l,k)).join("")+checkLink(l)+"</tr>").join("");
  $("#shown").textContent="Showing "+rows.length.toLocaleString("en")+" of "+plural(LEGS.length,"leg")+".";
  const more=$("#more");more.hidden=rows.length<=limit;more.textContent="Show more ("+(rows.length-limit).toLocaleString("en")+" left)";}

$("#legs thead").addEventListener("click",e=>{const th=e.target.closest("th[data-k]");if(!th)return;const k=th.dataset.k;
  if(sortState.key===k)sortState.dir=-sortState.dir;else{sortState.key=k;sortState.dir=1;}drawHead();draw();});
["#f-resource","#f-direction","#f-merge","#f-flag"].forEach(s=>$(s).addEventListener("change",()=>{limit=500;draw();}));
$("#f-search").addEventListener("input",()=>{limit=500;draw();});
$("#f-clear").addEventListener("click",()=>{["#f-resource","#f-direction","#f-merge","#f-flag"].forEach(s=>{$(s).value="";});$("#f-search").value="";limit=500;draw();});
$("#more").addEventListener("click",()=>{limit+=500;draw();});
if(LEGS.length){drawHead();draw();}
</script>
</body>
</html>
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK` (`test_page_script_parses` runs when `node` is installed).

- [ ] **Step 5: Browser check on the CLI output**

```bash
python3 plan-travels/plan_travels.py build --export ~/pso-data/ACME/1005/north_travel_1005.xml --date 2026-10-05 \
  --days 5 --dataset-name NORTH --out-dir output/ACME/NORTH/plan-travels
open output/ACME/NORTH/plan-travels/plan_travels.html
```

Check: header line and tiles read correctly; filters narrow the table and "Showing X of Y legs" updates; header click sorts both ways; Map opens Google Maps in a new tab; Download CSV opens the CSV beside the page; "Check in PSO" is blank (file page); theme button flips light and dark and the choice survives a reload; at a 375 px window the page has no sideways scroll outside the table panel. Fix anything found in the template and re-run Step 4.

- [ ] **Step 6: Commit**

```bash
git add plan-travels/plan_travels_template.html tests/plan_travels/test_page.py
git commit -m "Plan travels: the page (tiles, warnings, filters, sortable leg table)"
```

---

### Task 9: `merge-google` and the review flags

**Files:**
- Modify: `plan-travels/plan_travels.py`
- Create: `tests/plan_travels/test_google.py`

**Interfaces:**
- Consumes: `legs.json` (Task 7), `google.json` (Interfaces), `ps_round` (Task 2), `write_outputs`, `summary_line`, `fmt_num` (Tasks 6, 7).
- Produces:
```python
NO_COORDINATES = 'Missing/invalid coordinates -- skipped'
NO_RESULT = 'No Google result for this trip'
SOURCE_LABELS = {'cache': 'Cache', 'google': 'API'}
def compare(leg, result, review_minutes, review_pct) -> dict   # the 11 Google keys
def merge_google(data, google, review_minutes, review_pct) -> dict   # updates data in place and returns it
def read_json(path, what) -> object
```

- [ ] **Step 1: Failing tests**

`tests/plan_travels/test_google.py`:

```python
"""merge-google: flags, comparison values, weighting, meta counts, CLI round trip."""
import csv
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, travel, build_cli, run_main, template_path, tmpdir  # noqa: E402


def leg(key, sec):
    d = dict.fromkeys(pt.LEG_KEYS)
    d.update(resource_id='R1', direction=pt.DIRECTIONS[1], previous_activity_id='1001', activity_id='1002',
             plan_id='P1', merged_fragments=1, gps_fix=False, start_time='2026-10-05T15:00:00+00:00', end_time='',
             shift_id='S1', route_key=key, travel_sec=sec, travel_min=None if sec is None else round(sec / 60, 1))
    return d


def ok(sec, km=1.0, source='cache'):
    return {'durationSec': sec, 'distanceKm': km, 'source': source}


class CompareTests(unittest.TestCase):
    def flag(self, l, r, minutes=20.0, pct=0.0):
        return pt.compare(l, r, minutes, pct)

    def test_ok_under_the_minutes(self):
        g = self.flag(leg('k', 1200), ok(1500))
        self.assertEqual((g['flag'], g['ratio'], g['pct_divergence'], g['abs_divergence_min']), ('OK', 1.25, 25.0, 5.0))
        self.assertEqual((g['difference'], g['weighting'], g['google_min'], g['google_sec']),
                         ('PSO is 5 min shorter than Google', None, 25.0, 1500))
        self.assertEqual((g['google_source'], g['validation_error']), ('Cache', None))

    def test_review_on_minutes_only(self):
        g = self.flag(leg('k', 3600), ok(5400, source='google'))
        self.assertEqual((g['flag'], g['weighting'], g['difference'], g['google_source']),
                         ('Review', 1.5, 'PSO is 30 min shorter than Google', 'API'))

    def test_review_needing_the_percent_too(self):
        l = leg('k', 6000)
        self.assertEqual(self.flag(l, ok(4500), pct=30.0)['flag'], 'OK')          # 25 min but only -25%
        g = self.flag(l, ok(4500), pct=20.0)
        self.assertEqual((g['flag'], g['weighting'], g['difference']), ('Review', 0.75, 'PSO is 25 min longer than Google'))

    def test_weighting_rounds_the_rounded_ratio(self):
        g = self.flag(leg('k', 3000), ok(4374))
        self.assertEqual((g['ratio'], g['pct_divergence'], g['abs_divergence_min'], g['weighting']),
                         (1.458, 45.8, 22.9, 1.46))

    def test_same(self):
        self.assertEqual(self.flag(leg('k', 600), ok(600))['difference'], 'Same')

    def test_skipped(self):
        g = self.flag(leg(None, 600), None)
        self.assertEqual((g['flag'], g['validation_error']), ('Skipped', 'Missing/invalid coordinates -- skipped'))
        g = self.flag(leg('k', 600), {'error': 'No route returned'})
        self.assertEqual((g['flag'], g['validation_error'], g['google_sec']), ('Skipped', 'No route returned', None))
        g = self.flag(leg('k', 600), None)
        self.assertEqual((g['flag'], g['validation_error']), ('Skipped', 'No Google result for this trip'))

    def test_missing_google_time(self):
        g = self.flag(leg('k', 10), ok(0))
        self.assertEqual((g['flag'], g['google_sec'], g['google_min'], g['ratio']), ('Missing Google Time', 0, None, None))

    def test_missing_pso_time(self):
        self.assertEqual(self.flag(leg('k', None), ok(600))['flag'], 'Missing PSO Time')

    def test_zero_pso_time_with_google_time_is_missing_pso_time(self):
        self.assertEqual(self.flag(leg('k', 0), ok(600))['flag'], 'Missing PSO Time')
        self.assertEqual(self.flag(leg('k', 0), ok(0))['flag'], 'Missing Google Time')


class MergeTests(unittest.TestCase):
    def test_meta_counts_after_merge(self):
        data = {'meta': {'google': False, 'google_counts': None, 'thresholds': {'review_minutes': 20.0, 'review_pct': 0.0},
                         'counts': {'legs': 4, 'missing_pso_time': 1}},
                'legs': [leg('a', 1200), leg('b', 3600), leg(None, 60), leg('d', None)]}
        google = {'a': ok(1500), 'b': ok(5400, source='google'), 'd': ok(60), 'x': {'error': 'No route returned'}}
        pt.merge_google(data, google, 15.0, 0.0)
        m = data['meta']
        self.assertEqual((m['google'], m['thresholds']), (True, {'review_minutes': 15.0, 'review_pct': 0.0}))
        self.assertEqual(m['google_counts'], {'trips': 4, 'cache': 2, 'google': 1, 'error': 0, 'skipped': 1})
        k = m['counts']
        self.assertEqual((k['review'], k['ok'], k['skipped'], k['missing_pso_time'], k['missing_google_time'], k['missing']),
                         (1, 1, 1, 1, 0, 1))
        self.assertEqual(k['average_ratio'], 1.375)

    def test_bad_google_value_is_an_error(self):
        g = pt.compare(leg('k', 600), {'durationSec': 'abc'}, 20.0, 0.0)
        self.assertEqual((g['flag'], g['validation_error']), ('Skipped', 'Unreadable Google result'))


class CliTests(unittest.TestCase):
    def test_cli_merge_google_rewrites_three_files(self):
        xml = (travel(start='14:00', end='14:20', sloc='L-S', eloc='L-1001', act='1001', dist='2966', ett='PT6M54S')
               + travel(start='15:00', end='15:16', prev='1001', sloc='L-1001', eloc='L-1002', act='1002',
                        dist='14200', ett='PT15M35S'))
        code, out, err, d = build_cli(xml)
        legs = json.loads(Path(d, 'legs.json').read_text(encoding='utf-8'))['legs']
        google = {legs[0]['route_key']: ok(414), legs[1]['route_key']: ok(3000, source='google')}
        gpath = os.path.join(tmpdir(), 'google.json')
        with open(gpath, 'w', encoding='utf-8') as fh:
            json.dump(google, fh)
        code, out, err = run_main(['merge-google', '--legs', os.path.join(d, 'legs.json'), '--google', gpath,
                                   '--out-dir', d, '--template', template_path()])
        self.assertEqual(code, 0, err)
        rows = list(csv.reader(Path(d, 'plan_travels.csv').read_text(encoding='utf-8-sig').splitlines()))
        self.assertEqual(len(rows[0]), 41)
        self.assertEqual(rows[0][30:], [c for c, _ in pt.GOOGLE_COLUMNS])
        rec = [dict(zip(rows[0], r)) for r in rows[1:]]
        self.assertEqual([r['Flag'] for r in rec], ['OK', 'Review'])
        self.assertEqual((rec[1]['Google Source'], rec[1]['Estimated Weighting Adjustment']), ('API', '3.21'))
        self.assertEqual(out.strip().splitlines()[-1], 'summary: 2 legs for 1 resource, 2026-10-05; 1 to review, 0 missing')
        self.assertIn('"google":true', Path(d, 'plan_travels.html').read_text(encoding='utf-8'))

    def test_missing_or_bad_files_exit_1(self):
        code, out, err = run_main(['merge-google', '--legs', '/nope/legs.json', '--google', '/nope/g.json',
                                   '--out-dir', tmpdir(), '--template', template_path()])
        self.assertEqual(code, 1)
        self.assertIn('Stopped during: reading the legs: The legs file legs.json was not found.', err)
        bad = os.path.join(tmpdir(), 'g.json')
        with open(bad, 'w', encoding='utf-8') as fh:
            fh.write('[1, 2]')
        code, out, err, d = build_cli(travel(sloc='L-S', eloc='L-1001', act='1001'))
        code, out, err = run_main(['merge-google', '--legs', os.path.join(d, 'legs.json'), '--google', bad,
                                   '--out-dir', d, '--template', template_path()])
        self.assertEqual(code, 1)
        self.assertIn('Stopped during: reading the Google results: The Google results file is not a JSON object '
                      'keyed by trip.', err)


if __name__ == '__main__':
    unittest.main()
```

(3000 / 935 = 3.2085..., ratio 3.209, weighting 3.21.)

- [ ] **Step 2: Run to see them fail**

Run: `python3 -m unittest tests.plan_travels.test_google`
Expected: `AttributeError: module 'plan_travels' has no attribute 'compare'`.

- [ ] **Step 3: Implement**

Add after `write_outputs` (inventory section 7 comparison, with spec deviation 5):

```python
NO_COORDINATES = 'Missing/invalid coordinates -- skipped'
NO_RESULT = 'No Google result for this trip'
UNREADABLE = 'Unreadable Google result'
SOURCE_LABELS = {'cache': 'Cache', 'google': 'API'}
GOOGLE_KEYS = tuple(k for _, k in GOOGLE_COLUMNS)


def _google_result(result):
    """(seconds, km, source label, error) from one google.json value."""
    if result is None:
        return None, None, None, NO_RESULT
    if not isinstance(result, dict):
        return None, None, None, UNREADABLE
    if 'error' in result:
        return None, None, None, str(result['error']) or UNREADABLE
    sec = result.get('durationSec')
    if isinstance(sec, bool) or not isinstance(sec, (int, float)):
        return None, None, None, UNREADABLE
    km = result.get('distanceKm')
    km = km if isinstance(km, (int, float)) and not isinstance(km, bool) else None
    src = result.get('source')
    return int(sec), km, SOURCE_LABELS.get(src, src), None


def compare(leg, result, review_minutes, review_pct):
    out = dict.fromkeys(GOOGLE_KEYS)
    if leg['route_key'] is None:
        g, km, src, err = None, None, None, NO_COORDINATES
    else:
        g, km, src, err = _google_result(result)
    p = leg['travel_sec']
    if g and p:
        ratio = ps_round(g / p, 3)
        pct = ps_round((ratio - 1) * 100, 1)
        abs_min = ps_round(abs(g - p) / 60, 1)
        signed = ps_round((p - g) / 60, 1)
        if signed > 0:
            diff = 'PSO is %s min longer than Google' % fmt_num(signed)
        elif signed < 0:
            diff = 'PSO is %s min shorter than Google' % fmt_num(abs(signed))
        else:
            diff = 'Same'
        if abs_min >= review_minutes and (review_pct <= 0 or abs(pct) >= review_pct):
            flag, weighting = 'Review', ps_round(ratio, 2)
        else:
            flag, weighting = 'OK', None
        out.update(ratio=ratio, pct_divergence=pct, abs_divergence_min=abs_min, difference=diff, weighting=weighting)
    elif err:
        flag = 'Skipped'
    elif p is None:
        flag = 'Missing PSO Time'
    elif p == 0 and g:
        flag = 'Missing PSO Time'          # spec deviation 5 (the script fell through to Missing Google Time)
    else:
        flag = 'Missing Google Time'
    out.update(google_min=ps_round(g / 60, 1) if g else None, google_sec=g, google_km=km, google_source=src,
               flag=flag, validation_error=err)
    return out


def merge_google(data, google, review_minutes, review_pct):
    legs, meta = data['legs'], data['meta']
    gc = {'trips': len(google), 'cache': 0, 'google': 0, 'error': 0, 'skipped': 0}
    for leg in legs:
        res = compare(leg, google.get(leg['route_key']) if leg['route_key'] else None, review_minutes, review_pct)
        leg.update(res)
        if res['google_source'] == 'Cache':
            gc['cache'] += 1
        elif res['google_source'] == 'API':
            gc['google'] += 1
        if res['validation_error'] and res['validation_error'] != NO_COORDINATES:
            gc['error'] += 1
        if res['flag'] == 'Skipped':
            gc['skipped'] += 1
    k = meta['counts']
    flags = collections.Counter(l['flag'] for l in legs)
    ratios = [l['ratio'] for l in legs if l['ratio'] is not None]
    k.update(review=flags['Review'], ok=flags['OK'], skipped=flags['Skipped'],
             missing_pso_time=flags['Missing PSO Time'], missing_google_time=flags['Missing Google Time'],
             missing=flags['Missing PSO Time'] + flags['Missing Google Time'],
             average_ratio=ps_round(sum(ratios) / len(ratios), 3) if ratios else None)
    meta.update(google=True, google_counts=gc,
                thresholds={'review_minutes': review_minutes, 'review_pct': review_pct})
    return data


def read_json(path, what):
    base = os.path.basename(path)
    if not os.path.isfile(path):
        raise PlanTravelsError('The %s file %s was not found.' % (what, base))
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except (ValueError, UnicodeDecodeError) as e:
        raise PlanTravelsError('The %s file %s is not valid JSON (%s).' % (what, base, e))
```

In `main()`, replace `raise _ArgError('merge-google is not available yet')` with the merge-google branch:

```python
        if args.command == 'merge-google':
            stage = 'reading the legs'
            data = read_json(args.legs, 'legs')
            if not isinstance(data, dict) or not isinstance(data.get('meta'), dict) or not isinstance(data.get('legs'), list):
                raise PlanTravelsError('The legs file is not the output of plan_travels.py build.')
            stage = 'reading the Google results'
            google = read_json(args.google, 'Google results')
            if not isinstance(google, dict):
                raise PlanTravelsError('The Google results file is not a JSON object keyed by trip.')
            stage = 'merging the Google results'
            merge_google(data, google, args.review_minutes, args.review_pct)
            stage = 'writing the outputs'
            write_outputs(data, args.out_dir, args.template)
            print('summary: %s' % summary_line(data['meta']))
            return 0
```

- [ ] **Step 4: Run to pass**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add plan-travels/plan_travels.py tests/plan_travels/test_google.py
git commit -m "Plan travels: merge-google with review flags and weighting"
```

---

### Task 10: Golden test and performance on the customer export

**Files:**
- Create: `tests/plan_travels/test_golden.py`
- Modify: `plan-travels/plan_travels.py` only if a mismatch is a real porting bug

**Interfaces:**
- Consumes: the whole `build` command; `~/pso-data/ACME/1005/north_travel_1005.xml` and `~/pso-data/ACME/1005/golden/plan_travels_2026-10-05_5d.csv` (local only, never committed).
- Produces: a test that skips when either file is absent and otherwise pins every one of the 30 columns of all 1,781 rows.

- [ ] **Step 1: The golden test**

`tests/plan_travels/test_golden.py` (skip pattern from `tests/activity_trace/test_golden.py` lines 22 to 31):

```python
"""Golden run on real customer data (local only, never committed).

Runs when ~/pso-data/ACME/1005/north_travel_1005.xml and the pwsh golden CSV exist (skips otherwise):
build --date 2026-10-05 --days 5, rows sorted on Resource ID, Activity ID (Destination), Previous Activity ID,
Plan ID, every one of the 30 columns equal, numbers compared as decimals.

    python3 -m unittest tests.plan_travels.test_golden
"""
import collections
import csv
import decimal
import json
import re
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pt_fixtures import pt, run_main, tmpdir  # noqa: E402

DATA = Path.home() / 'pso-data' / 'ACME' / '1005'
EXPORT = DATA / 'north_travel_1005.xml'
GOLDEN = DATA / 'golden' / 'plan_travels_2026-10-05_5d.csv'
SORT = ('Resource ID', 'Activity ID (Destination)', 'Previous Activity ID', 'Plan ID')
NUMERIC = {'Start Location Latitude', 'Start Location Longitude', 'End Location Latitude', 'End Location Longitude',
           'Distance (km)', 'Expected Travel Time (min)', 'Expected Travel Time (sec)', 'Merged Fragments',
           'Pre-Fix Distance (km)', 'Pre-Fix Travel (min)', 'Pre-Shift Distance (km)', 'Pre-Shift Travel (min)'}
LINK = 'Google Maps Directions Link'
_LINK = re.compile(r'origin=([^,]+),([^&]+)&destination=([^,]+),([^&]+)&')
# With ps_round no differences are expected (checked while writing the plan: 0 of 53,430 cells). If one appears at
# an exact half, it is reported, not hidden: add (Resource ID, Activity ID, Previous Activity ID, Plan ID, column) here with the raw
# value in a comment, and the test prints them as notes on every run.
KNOWN_HALF_DIFFS = set()


def norm(col, v):
    if col in NUMERIC:
        return (bool(v), decimal.Decimal(v) if v else decimal.Decimal(0))
    if col == LINK:
        m = _LINK.search(v)
        return tuple(decimal.Decimal(x) for x in m.groups()) if m else ()
    return v


def load(path):
    with open(path, encoding='utf-8-sig', newline='') as fh:
        rows = list(csv.DictReader(fh))
    cols = list(rows[0]) if rows else []
    return sorted(rows, key=lambda r: tuple(r[c] for c in SORT) + tuple(norm(c, r[c]) for c in cols)), cols


@unittest.skipUnless(EXPORT.exists() and GOLDEN.exists(), 'no %s or %s' % (EXPORT, GOLDEN))
class GoldenTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.out = tmpdir()
        cls.code, cls.stdout, cls.stderr = run_main(['build', '--export', str(EXPORT), '--date', '2026-10-05',
                                                     '--days', '5', '--out-dir', cls.out])
        cls.golden, cls.cols = load(GOLDEN)
        cls.ours, _ = load(Path(cls.out, 'plan_travels.csv'))
        cls.data = json.loads(Path(cls.out, 'legs.json').read_text(encoding='utf-8'))

    def test_run_ok_and_row_count(self):
        self.assertEqual(self.code, 0, self.stderr)
        self.assertEqual(self.cols, [c for c, _ in pt.COLUMNS])
        self.assertEqual((len(self.golden), len(self.ours)), (1781, 1781))

    def test_every_column_matches(self):
        diffs, notes = [], []
        for g, o in zip(self.golden, self.ours):
            for c in self.cols:
                if norm(c, g[c]) != norm(c, o[c]):
                    key = tuple(g[k] for k in SORT) + (c,)
                    (notes if key in KNOWN_HALF_DIFFS else diffs).append('%s: golden %r, ours %r' % (key, g[c], o[c]))
        for n in notes:
            sys.stderr.write('note: known half-rounding difference %s\n' % n)
        self.assertEqual(diffs[:20], [], '%d cells differ' % len(diffs))

    def test_inventory_counts(self):
        direction = collections.Counter(r['Direction'] for r in self.ours)
        self.assertEqual(direction, {'Activity -> Activity': 765, 'Start Location -> Activity': 515,
                                     'Activity -> End Location': 501})
        merge = collections.Counter(r['Merge Type'] for r in self.ours if r['Merge Type'])
        self.assertEqual(merge, {'Break': 13, 'GPS fix': 8, 'Start of day (duplicate)': 6, 'Start of day': 1})
        self.assertEqual(sum(1 for r in self.ours if r['Merged Fragments'] == '2'), 21)
        self.assertEqual(sum(1 for r in self.ours if not r[LINK]), 0)

    def test_meta_agrees_with_the_csv(self):
        k = self.data['meta']['counts']
        self.assertEqual((k['legs'], k['resources'], k['merged_legs'], k['missing_coordinates']), (1781, 146, 21, 0))
        self.assertEqual(k['by_direction']['Activity -> End Location'], 501)
        self.assertEqual((k['plan_travel_rows'], k['fix_events']), (5081, 97))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run it**

Run: `python3 -m unittest tests.plan_travels.test_golden -v`
Expected: 4 tests pass (checked while writing this plan with the plan's code: all 1,781 rows and 30 columns equal, about 2.5 s). If `test_every_column_matches` fails, read the first 20 differences: a difference in a merge column (19, 20, 23 to 26), a direction or a count is a porting bug, so fix it against the inventory and the matching script lines (`scripts/Build-PlanTravelsReport.ps1` lines 434 to 1001 for merging, 1468 to 1700 for Step 4) and add a synthetic test for it in the task file it belongs to. A difference of one unit in the last decimal of a rounded column means a `round(` slipped in where `ps_round(` belongs; fix that first. Only a difference that survives `ps_round` and sits exactly on a half goes into `KNOWN_HALF_DIFFS`, with the raw value in a comment and named in the commit message.

- [ ] **Step 3: Performance**

```bash
/usr/bin/time -l python3 plan-travels/plan_travels.py build --export ~/pso-data/ACME/1005/north_travel_1005.xml \
  --date 2026-10-05 --days 5 --out-dir output/ACME/NORTH/plan-travels-perf 2>&1 | \
  grep -E 'real|maximum resident set size|summary:'
```

Pass: `real` under 30 s and `maximum resident set size` under 209715200 bytes (200 MB; macOS reports bytes). The plan's code measured 2.3 s and 52,625,408 bytes on this Mac, with these whole-file counts in `meta.counts`, which match the script's changelog: `sod_slice` 12, `sod_duplicate` 18, `sod_summed` 4, `fix_events` 97, `break_merged` 73. Record both numbers in the commit message. If either is over: confirm `read_export` only builds field dicts for `WANTED` tables and clears every depth-1 element and the root; then profile with `python3 -m cProfile -s cumtime plan-travels/plan_travels.py build ...` and remove the top non-parser cost. Re-run Step 2 after any change.

- [ ] **Step 4: Full suite and commit**

Run: `python3 -m unittest discover -s tests/plan_travels -t .`
Expected: `OK` (golden tests run, not skipped, on this Mac).

```bash
git status --short     # no .xml, .zip, .csv or output/ files listed
git add tests/plan_travels/test_golden.py plan-travels/plan_travels.py
git commit -m "Plan travels: golden test against the pwsh CSV (1,781 rows); build <N> s, <M> MB on the 135 MB export"
```

---

### Task 11: Docs (CLAUDE.md tree and parity note, scripts/INDEX.md)

**Files:**
- Modify: `CLAUDE.md`, `scripts/INDEX.md`

**Interfaces:**
- Consumes: the finished tool.
- Produces: docs only.

- [ ] **Step 1: CLAUDE.md repository tree**

Insert after the `activity-trace/` block (before `make-windows-bundle.sh`):

```
  plan-travels/
    plan_travels.py             plan export -> every travel leg for a date range (legs.json, plan_travels.csv,
                                plan_travels.html); merge-google adds Google Routes results and review flags
                                (Python only; the reference for scripts/Build-PlanTravelsReport.ps1, run by the
                                web app). Tests: python3 -m unittest discover -s tests/plan_travels -t .
    plan_travels_template.html  the page it fills with legs.json
```

and under `tests/`, after the `activity_trace/` lines:

```
    plan_travels/               plan travels tests on synthetic exports; test_golden.py also checks the 1,781 legs of
                                ~/pso-data/ACME/1005/north_travel_1005.xml against the pwsh golden CSV when present
```

- [ ] **Step 2: CLAUDE.md parity rule**

Replace the paragraph starting "The activity trace is the exception:" with:

```
The activity trace and plan travels are the exceptions: `activity-trace/trace_activity.py` and
`plan-travels/plan_travels.py` are the reference implementations (run by the web app), and
`Build-AppointmentTimeline.ps1`, `Get-PsoTaskTimeline.ps1` and `Build-PlanTravelsReport.ps1` stay unchanged as their
Windows versions until the web app replaces them, so Python changes there do not ship to PowerShell. Plan travels
keeps one link to its script: the golden test compares every column of the 30 with a pwsh run of the script.
```

- [ ] **Step 3: scripts/INDEX.md**

Insert directly under the heading `## travel\Build-PlanTravelsReport.ps1`:

```
`plan-travels/plan_travels.py` in the pso-tools repo is now the reference implementation: the web app runs it
to build the same legs from a plan export (same 30 columns, same merge rules), shows them as a page with a CSV
download, and checks them against Google Routes through pso-services' shared cache. This script stays as the
Windows version, with its own Google cache CSV and `-BuildImportXml`, until the web app replaces it. It is not
kept at parity with the Python, apart from the golden test on one Acme export.
```

- [ ] **Step 4: Check and commit**

Run: `grep -nP '\x{2014}|\x{2013}' CLAUDE.md scripts/INDEX.md plan-travels/* tests/plan_travels/*.py` (expect no output) and `python3 -m unittest discover -s tests/plan_travels -t .` (expect `OK`).

```bash
git status --short
git add CLAUDE.md scripts/INDEX.md
git commit -m "Plan travels: CLAUDE.md tree and parity note, scripts/INDEX.md"
```

No PowerShell file changed, so no Windows test is needed and the bundle is unchanged. Leave the branch for review; merge to main is Ish's call (the pso-services and pso-test-tools plans need it on main before the box can run it).

---

## Self-review against the spec

| Spec requirement | Task |
|---|---|
| 1 Goal: same legs as the script, optional Google check, page and CSV | 3 to 7, 8, 9, 10 |
| 2 Python is the reference; PowerShell unchanged; `-BuildImportXml` out of scope | Global Constraints, 11 |
| 3 Architecture: `build` then `merge-google` as child processes; files the web reads | Interfaces, 7, 9 |
| 4 Standard library, Python 3.9+, streaming `iterparse`, flat memory, `.xml` or `.zip` | 1, 10 (RSS) |
| 4.1 `build` options (`--date`, `--days 1..31`, `--out-dir`, `--template`, `--review-minutes`, `--review-pct`) | 1, 7 |
| 4.1 `merge-google` options and rewriting all three files | 9 |
| 4.1 Exit 0 with zero legs (header-only CSV, page says so) | 7 (`test_zero_legs_writes_header_only_csv`), 8 (`s-empty`) |
| 4.1 Exit 1 on missing file or wrong root | 1 (`test_missing_file_exit_1`, `test_root_must_be_dsScheduleData`), 9 |
| 4.1 Warnings on stderr with `warning: ` and in `legs.json` | 7 (`test_warnings_go_to_stderr_and_meta`) |
| 4.2 Three-pass order (fix, start of day, break) | 6 (`build`) |
| 4.2 Resolve-Fragments whole-metre rule | 2 |
| 4.2 GPS fix chain scan, Complete-FixChain, dead ends, fragments left out | 3 |
| 4.2 Start-of-day pairing with Pre-Shift columns | 4 |
| 4.2 Break merge and merged-key lookup | 5, 6 |
| 4.2 Nine Merge Type values | 3, 4, 5, `MERGE_TYPES` in 6 |
| 4.2 PRIVATE / NON-AVAILABILITY exclusion on either end | 6 (`test_exclusions_on_each_end`) |
| 4.2 End-of-day leg, Direction labels | 6 |
| 4.2 Coordinate sources (Location, or the FIX) | 6 (`test_gps_leg_starts_at_the_fix`) |
| 4.2 Siebel Call ID and Order Number from Additional_Attribute | 1, 6 |
| 4.2 Google Maps link | 6 |
| 4.2 Per-column rounding | 3, 4, 6, 9 |
| 4.2 Deviation 1 case rules | 1 (`fix` case, labels), 6 (`non-availability`) |
| 4.2 Deviation 2 invariant parse, shortest round-trip print | 1 (`to_float`), 6 (`fmt_num`), 7 |
| 4.2 Deviation 3 rounding (changed: .NET-style `ps_round`, see Global Constraints), half differences reported | 2 (`test_ps_round_matches_dotnet`), 10 (`KNOWN_HALF_DIFFS`) |
| 4.2 Deviation 4 row order | 7 (`test_rows_sorted_resource_start_activity`) |
| 4.2 Deviation 5 PSO 0 s with Google time | 9 (`test_zero_pso_time_with_google_time_is_missing_pso_time`), page note in 7 |
| 4.3 30 columns verbatim, then 11 Google columns | Interfaces, 6, 7, 9 |
| 4.3 `legs.json` snake_case rows plus meta (export, dates, counts, thresholds, Google, warnings) | Interfaces, 7 |
| 4.4 Page: header, tiles, filters, sort, Maps link, Check in PSO, warnings, Download CSV, light and dark | 8 |
| 4.5 Synthetic tests: break sum, break trip plus slice, break duplicate | 5 |
| 4.5 Start-of-day trip plus slice, duplicate, unpaired | 4 |
| 4.5 GPS chain with pre-fix catch-up, synthetic chain, dead end | 3 |
| 4.5 Exclusions on each end, end-of-day leg, missing coordinates | 6 |
| 4.5 Zero legs, zip input | 7, 1 |
| 4.5 Golden test with sort, decimals and the inventory counts | 10 |
| 4.5 merge-google test: OK, Review minutes only, Review with percent, Skipped, Missing Google, Missing PSO, weighting | 9 |
| 6.3 `google.json` key and value shape (consumed here) | Interfaces, 9 |
| 7 Key never in pso-tools; cache data not here; page links only Google Maps and the analyzer; no external assets | 8 (`test_self_contained`), Interfaces |
| 8 Under 30 s and 200 MB on the 135 MB file | 10 Step 3 |
| CLAUDE.md tree and scripts/INDEX.md note | 11 |

Gaps found and fixed while writing: the plan's code was assembled in a scratch folder and run against all planned tests and the golden CSV; Python `round` failed 163 golden cells, so rounding follows .NET (`ps_round`) and spec 4.2 item 3 needs the same change; the page header needs a dataset name the spec's CLI did not carry, so `build` takes an optional `--dataset-name` (Interfaces says so for the pso-test-tools plan); pso-test-tools would otherwise recompute the six-decimal key from floats in PHP, so `legs.json` carries `route_key` and `google.json` is keyed by it; the script's "unplaced" warning wording breaks the house rule, so warnings say "left out".
