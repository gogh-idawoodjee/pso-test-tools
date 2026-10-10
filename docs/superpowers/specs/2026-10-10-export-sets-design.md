# Design: export sets, tool runs and dataset settings

**Date:** 2026-10-10
**Status:** Approved in conversation, pending spec review
**Repo:** pso-test-tools only. Builds on `2026-10-10-dataset-reference-values-design.md` (datasets as the
unit of work).

## 1. Problem

The PSO tools in the `pso-tools` repo (load audit, schedule review and its roll-ups, region availability,
appointment timeline, plan travels) each read one or more PSO exports from a folder: `load.xml`,
`plan.xml`, `eod.xml`, `sys.xml` and an `audits.zip`, 100 MB+ apiece, and write a dashboard plus a data
file next to them. Porting them into the web app one at a time would give each its own upload path,
storage rules and retention, as the sys compare and the gateway upload already have (one on local disk,
one on R2, different purge rules, different summaries).

This spec puts the shared part in once: how an export reaches the app, where it lives, how long, what
is recorded about it, how a tool runs against it, what a run leaves behind, and where the per-customer
settings those tools read now live. The tool ports are separate specs on top of this one.

## 2. Decisions already made

- **Upload only.** A daily LOAD is a point in time (the morning file that went into PSO); the gateway's
  Data GET returns the current state, so it cannot replace an export. No live fetch in this spec.
- **Raw exports live on R2**, in the existing `r2` disk, and are **deleted 12 hours after upload**. The
  production container has 512 MB of RAM and about 1 GB of free disk; raw files never settle there.
- **Results live in the database**, small (JSON, the size of today's data files), **encrypted at rest**
  with Laravel's `encrypted:array` cast, kept until the user deletes them. Dashboards are rendered from
  the result on request, not stored.
- **Minimise at the parser, do not scrub the file.** Each tool keeps only what its checks need. SYS
  credentials never leave the parser; the audits parser drops customer contact fields.
- **Per-customer settings** (today `accounts/SATE.json` and `ROGE.json` in pso-tools) become a typed
  `settings` JSON column on the dataset, with a form section, seeded once from those two files.
- **Scope follows the input.** SYS exports and the sys compare belong to the **environment**; LOAD, plan,
  EOD and audits, and the tools that read them, belong to the **dataset**. XML utilities and the travel
  tuning report, which work on arbitrary files, stay under Additional Tools.

## 3. Model

### 3.1 `export_files`

One uploaded export. The "set" a tool runs against is a selection of these, not its own table.

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | |
| scope_type / scope_id | string / uuid | morph: `App\Models\Dataset` or `App\Models\Environment` |
| kind | string | enum `ExportKind`: `load`, `plan`, `eod`, `audits`, `sys` |
| uploaded_by_user_id | fk users | |
| original_filename | string | |
| stored_path | string | R2 object key, see 4.2 |
| file_size_bytes | bigint | |
| content_type | string | `xml`, `json` or `zip` as detected, not as named |
| summary | json, nullable | per kind, see 3.2 |
| status | string | enum `ExportFileStatus`: `uploading`, `summarising`, `ready`, `failed`, `purged` |
| error_message | text, nullable | |
| expires_at | datetime | `created_at + 12h`; the purge key |
| timestamps | | |

Rules:

- `sys` is only valid with an Environment scope; the other four only with a Dataset scope. Enforced in
  the model's `saving` hook and in the upload request.
- A dataset's files are scoped to the user through `DatasetOwnedModel`; an environment's through
  `UserOwnedModel`. A global scope on `ExportFile` resolves by scope type.
- `purged` rows stay (they are the history: "a LOAD for 10 October was uploaded at 07:12 and used by
  run X"); only the R2 object goes.

### 3.2 Summaries, per kind

Written by `ExportSummariser` from a streamed read of the stored object, never the whole body:

- `load`, `plan`, `eod`: `dataset_id`, `input_reference_datetime`, `activity_count`, `resource_count`
  (what `ScheduleDataSummaryExtractor` already produces), plus `export_date` (the input reference date
  in the dataset's time zone) and `root` (`dsScheduleData` or `DsSystemData`, so a misnamed upload is
  caught; see the "Verify export contents" rule in the pso-tools memory: zips are often misnamed).
- `sys`: `pso_version`, `parameter_count`, `exception_type_count` (from the sys compare's `QuickRead`),
  nothing from the user or credential tables.
- `audits`: `message_count`, `first_message_at`, `last_message_at`, `appointment_request_count`.

A file whose detected root does not match its declared kind (a plan uploaded as a LOAD is fine, both
are `dsScheduleData`; a SYS uploaded as a LOAD is not) goes to `failed` with a plain message.

### 3.3 `tool_runs`

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | |
| scope_type / scope_id | morph | dataset or environment, as the tool's scope |
| tool | string | enum `Tool`: `load_audit`, `schedule_review`, `schedule_rollup`, `region_availability`, `appointment_timeline`, `plan_travels`, `sys_compare` |
| run_date | date | the export date the run is about (schedule review: the day; roll-up: week or month start) |
| inputs | json | `{kind: export_file_id}` map, plus tool options (e.g. roll-up period, activity id for the timeline) |
| started_by_user_id | fk users | |
| status | string | enum `ToolRunStatus`: `queued`, `running`, `succeeded`, `failed` |
| result | json, nullable, **encrypted** | the tool's data file; the only thing a dashboard needs |
| warnings | json, nullable | plain-English warnings the dashboard shows on the page (never only in tooltips) |
| error_message | text, nullable | |
| queued_at / started_at / finished_at | datetime | |
| timestamps | | |

Rules:

- `result` uses the `encrypted:array` cast. Backups and dumps show ciphertext. Rotating `APP_KEY`
  therefore needs a re-encryption command; documented, not built here.
- A run keeps its `inputs` map even after those files are purged, so history reads "ran against the
  07:12 LOAD and the 21:40 EOD".
- Unique on (`scope_type`, `scope_id`, `tool`, `run_date`, `started_at`) is not needed; re-runs are
  allowed and listed newest first.

### 3.4 Dataset settings

`datasets.settings` (json, nullable) with a fixed key list, cast through a small value object
`DatasetSettings` so tools read typed values and unknown keys are rejected by the form:

| Key | Type | From account file |
| --- | --- | --- |
| display_name | string | `display_name` |
| dataset_display_name | string, nullable | `dataset_name` |
| coverage_km | number | `coverage_km` |
| eod_cutoff_hour | int, nullable | `eod_cutoff_hour` |
| exclude_resource_types | string[] | `exclude_resource_types` |
| resource_type_names | map string to string | `resource_type_names` |
| attribute_labels | map string to string | `attributes` |
| region_pivot | `{red_below, amber_below}` | `region_pivot` |
| region_labels | `{district_label, district_label_plural}` | `regions` |
| supervisor_label / supervisor_label_plural | string, nullable | same |
| notes | map string to string | `notes` |
| windows_time_zone | string, nullable | kept for parity with the PowerShell scripts; unused on the web |
| time_zone | IANA string | new: the dataset's local zone, needed for `export_date` |

Form: a "Settings" section on the dataset edit form with one field per key (key-value fields for the
maps, tags input for the list). A one-off artisan command `datasets:seed-settings {dataset} {file}`
loads a pso-tools account JSON into a dataset; run once for SATE/NORTH and ROGE/SOUTHERNONTARIO.

## 4. Upload path

### 4.1 Why not the sys compare's path or Livewire's

The sys compare uploads through a plain controller to a **local** private disk, deliberately avoiding
Livewire's temporary uploads because those land on the shared R2 bucket. Here R2 is the destination,
so the concern is reversed, and the real constraint is size: 100 MB+ through PHP on a 512 MB container,
behind `post_max_size = 200M`, with Livewire holding the file in its temporary area first, is two copies
too many.

### 4.2 Direct-to-R2 with a signed URL

1. The browser asks the app for an upload slot: `POST /exports/slots` with scope, kind, file name, size
   and type. The app validates (scope ownership, kind allowed for the scope, size under
   `exports.max_file_megabytes`, default 400, extension in `xml|json|zip`), creates the `export_files` row
   in `uploading`, and returns a **presigned PUT URL** for the object key
   `exports/{scope_type}/{scope_id}/{export_file_id}.{ext}` valid for 15 minutes, plus the row id.
2. The browser PUTs the file straight to R2 (the bucket needs a CORS rule for the app origin, PUT, and
   the `Content-Type` header; documented in the README). Progress comes from the XHR, so the drop zone
   shows a real percentage.
3. The browser calls `POST /exports/{id}/complete`. The app checks the object exists and its size matches,
   sets `summarising`, and dispatches `SummariseExportFileJob`.
4. The job streams the object (`readStream`) to a scratch file under `storage/app/private/exports/{id}/`,
   runs the summariser (zip entries are read through `ZipArchive` on the scratch file, picking the largest
   `.xml` or `.json` member, as `ScheduleDataZipExtractor` does), writes `summary`, sets `ready`, and
   deletes the scratch folder in a `finally`.

A slot that is never completed, or whose object never appears, is purged with everything else at
`expires_at`. The signed URL is the only way in: no app route accepts a file body, so `post_max_size`
stops mattering.

### 4.3 Drop zone

A reusable Livewire component `ExportDropZone` with props `scope` (model) and `kinds` (list), rendered
on the Exports tab of a dataset (four kinds) and of an environment (`sys`). It wraps the small JS from the
sys compare drop zone (drag and drop, per-file rows, dismiss) and the three calls above. The sys compare
page keeps its own drop zone until it is rebuilt on export files (section 7).

## 5. Storage and purge

- `ExportStorage` (one class) owns the object key layout, the presigned URL, `exists` and `size`
  checks, `readStream`, and delete. Object keys are built from uuids only; nothing user-supplied reaches
  a path (same rule as `GatewayUploadPath` and `SysCompareStorage`).
- `exports:purge` runs every 10 minutes (next to `sys-compare:purge`): for every `export_files` row with
  `expires_at` past and status not `purged`, delete the object (ignore "not found") and set `purged`.
  Scratch folders older than 2 hours under `storage/app/private/exports/` are removed too, in case a job
  died mid-way.
- `exports.ttl_hours` (default 12) and `exports.max_file_megabytes` (default 400) in `config/exports.php`.

## 6. Running a tool

- `RunToolJob` takes a `tool_runs` id. It streams each input object to the scratch folder, calls the
  tool's `Tool` implementation (`App\Tools\<Name>\...`, each port adds one), stores `result` and
  `warnings`, and deletes the scratch folder in a `finally`. Timeout 900 seconds, one try, failure
  recorded on the row in plain words.
- A tool declares `requiredKinds()` and `optionalKinds()`. The Runs tab's "Run" action for a tool lists
  the scope's `ready` files per kind (newest first, grouped by `export_date`) and preselects the newest
  set for one day. A dataset tool that needs a SYS (the load audit) takes it from the dataset's
  environment: `optionalKinds()` may include `sys` and the picker then lists the environment's SYS files.
- Dashboards are Filament pages at `/datasets/{dataset}/runs/{run}` (and the environment equivalent),
  one Blade view per tool, rendered from `result`. The two dashboards that link to each other today
  (load audit and schedule review) link through the runs list of the same dataset and date.
- The tools' plain-English rules carry over unchanged: check names say what they find, "allocated"
  never "placed", areas are Load data, Configuration, PSO system and Engine performance, every red or
  amber finding has a recommendation, warnings on the page, no em dashes.

## 7. Screens

- **Dataset page** gains two tabs: **Exports** (drop zone for `load`, `plan`, `eod`, `audits`; a table of
  files with kind, export date, input reference time, counts, who, status, "purges in 9 h 12 m", delete)
  and **Runs** (table of tool runs newest first with tool, run date, status, open dashboard, delete; a
  "Run" action per dataset tool).
- **Environment page** gains the same two tabs scoped to `sys` and the environment tools. The existing
  Load from File tab stays as it is.
- **Additional Tools** entries for each dataset tool become thin pages: pick one of your datasets, jump to
  its Runs tab with the Run action opened. Sys compare keeps its page for now; a later spec moves it onto
  environment SYS files and the environment page.

## 8. Privacy summary

| Data | Where | How long | Protection |
| --- | --- | --- | --- |
| Raw exports | R2, private bucket | 12 h | Cloudflare encryption at rest; uuid keys; signed URLs 15 min |
| Scratch copies | container disk | life of one job | deleted in `finally`; sweeper every 10 min |
| Summaries | DB `export_files.summary` | with the row | counts, ids and times only |
| Results | DB `tool_runs.result` | until deleted | `encrypted:array` (APP_KEY); parsers keep only what checks need |
| SYS credentials | never stored past the raw file | 12 h | parser never emits them; sys compare masks keys |
| Audit contact fields | never stored | 12 h | audits parser drops them |

## 9. Testing

- Unit: `ExportSummariser` per kind on small synthetic files (xml, json, zip with a misnamed member, SYS
  uploaded as LOAD rejected, V1 and V2 root names). `DatasetSettings` round trip and unknown-key
  rejection. `ExportStorage` key layout and that a non-uuid id is refused.
- Feature: slot request (ownership, kind-scope rule, size cap, extension), complete (size mismatch
  fails the row), purge (object deleted, row `purged`, scratch sweeper), `SummariseExportFileJob` and
  `RunToolJob` with `Storage::fake('r2')`, run picker preselects the newest set of one day, dashboards
  need the owning user, settings form saves typed values, seed command loads SATE.json.
- Golden: when `EXPORTS_SAMPLE_DIR` points at a folder with a real `load.zip`, the summariser's counts
  match a sidecar expected file; skipped otherwise (same pattern as the reference values golden test).

## 10. Out of scope

- The tool ports themselves (one spec each: load audit first, with `tests/reference/` JSON as golden data).
- Moving the sys compare onto export files and the environment page.
- Live fetch from the gateway.
- APP_KEY rotation tooling for encrypted results.
- The deployment pipeline (paused; see memory notes).
