# Task: add a "PSO Sys File Compare" tool to pso-test-tools

Paste everything below into Claude Code, from the root of the pso-test-tools repo. Put this
whole folder in the repo at `docs/sys-compare-handoff/` first (see "Privacy" - the folder
contains no customer data).

---

## Goal

Turn an existing PowerShell script into a feature of the pso-test-tools web app.

A user uploads two or more PSO system data exports ("sys files", `DsSystemData` XML, ~1-5 MB
each). They mark exactly ONE file as the **baseline** and give every other file a name
(e.g. ACC, STG, TST). The app compares them and returns:

1. a self-contained **HTML report** (viewable in the browser and downloadable),
2. the **CSVs** (zipped),
3. an **Excel workbook**,

with a loud banner if the environments are not all on the same PSO version.

## Step 0 - look before you build

Do NOT start coding yet.

1. Read the repo and tell me, in a short summary: the stack (language, framework, build,
   test runner), how existing tools are structured, how file uploads and downloads are
   handled today, how auth works (if at all), how it is deployed, and any conventions
   (lint, formatting, folder layout, commit style) you will follow.
2. Read the reference material below.
3. Ask me the open questions at the end of this file (only the ones the repo does not already
   answer).
4. Propose a short plan (modules, API shape, UI screens, test plan) and wait for my OK.

## Reference material in this folder

| Path | What it is | How to use it |
|---|---|---|
| `reference/Compare-PsoSysFiles.ps1` | The working PowerShell implementation (~1,400 lines) | **Source of truth for comparison logic, output formats and wording.** If this prompt and the script disagree, stop and ask me. |
| `reference/workbook_builder_reference.py` | Python that generates the Excel workbook | Reference for sheet layout, formulas, conditional formatting. Throwaway code (hardcoded paths) - port the ideas, not the file. |
| `reference/sys_loader_reference.py` | Tiny XML loader used by the Python reference | Loads whole file into memory. Production code should stream (see "Parsing"). |
| `data/pso_parameters_reference.csv` | IFS parameter catalog (736 parameters: application, data type, default value, official description) | **Required input for the comparison.** Supplies defaults so "unset" can be compared with "explicitly set to the default" (see "Unset parameters = default"), and official descriptions. Ship it as data. Defaults can change between PSO versions - see the open questions. |
| `data/ParamDefinitions.csv` | 60 rows: full definitions for the 28 parameters the catalog does not cover, plus short **Notes** (KB / field knowledge) appended to the official description of 32 others | Ship as data. Columns `Parameter,Definition,Note,Basis`. Must be user-extensible. |
| `data/definition_patterns.json` | 15 case-insensitive name patterns used only when nothing else describes a parameter | Ship as data. |
| `reference/effective_values_reference.py` | Small Python implementation of the effective-value (default) logic | Reference for the algorithm and normalisation; the script is still the source of truth. |
| `expected/expected_results.json` | Golden numbers, spot checks and mutation tests | Turn into automated tests. |
| `expected/PSO_environment_comparison.reference.xlsx` | The workbook the Python reference produced from the four sample files | Visual and structural reference for the Excel export. |

The four sample sys files (prod/acc/stg/tst) are NOT in this folder. I keep them locally at
`<PATH-TO-SAMPLE-FILES>` - ask me for the path when you need them for tests, and keep them
out of the repo.

## What the tool compares (the engine)

Build the engine as a pure library with no web or file-system dependencies:
`compare(environments, options) -> result model`, plus separate renderers for HTML, CSV and
Excel. That keeps it testable and lets a CLI reuse it later.

Compare these areas, each as a matrix with one column per environment and a Same/DIFF status
per row (details and exact formats are in the script):

| Area | Source tables | Key |
|---|---|---|
| Parameters | `Profile_Parameter` + the parameter catalog | profile + parameter_id + application type; compared as EFFECTIVE values (unset = default) |
| Exception Types | `Org_Schedule_Exception_Type` | profile + type id; value = on/off / attn / activation |
| Group Permissions | `Group_Permission` | group (case-insensitive) + permission; value = allow / allow_edit as T/F |
| Groups | `Groups` (+ counts from Group_Permission) | group; attributes: present-as, parent, description, row count |
| Org Permissions | `Organisation_Permission` | permission |
| Lists | `Organisation_List` + `List` + `List_Entry` + `Entry` | org-default list type + position, matched on CONTENT (ids are GUIDs that differ per environment) |
| Travel | `Profile_Parameter` (travel params) + `Travel_Time_*` + `Polygon` | fixed items; polygons summarised by count/range, not row-by-row |
| Profiles & Other | `Profile`, `Terminology_Organisation`, `Org_Schedule_Exc_Type_Data`, `Organisation` | various |
| Table Counts | all tables | row counts per environment + a scope note per table |

Then a **PSO version** section from `System_Version` (below) and a **tally** of rows differing
from the baseline per area, per environment, plus "not identical across all".

### Rules that matter (each one was learned the hard way)

- **Unset parameters = default (critical).** The export lists only parameters that were
  explicitly set; a parameter that is absent is using its default. Comparing raw presence
  produces false differences (e.g. `CommittedActivitiesConstraintsOption` unset vs `1` when the
  default is 1, or `AllowSplitTravel` explicit `True` vs unset when the default is True). So:
  - Resolve each cell to an **effective value**: explicit value -> catalog default ->
    `(absent)` when the parameter is not in the catalog. **Profiles do NOT inherit from each
    other** (confirmed): a parameter unset in ANY profile uses its catalog default, never the
    DEFAULT profile's value. If the profile itself does not exist in that environment show
    `(no profile)`.
  - Display `(default: x)` for resolved values (muted); `(default: blank)`
    when the default is empty. Never show a masked key's default.
  - Compare **normalised** values, only to decide sameness: BOOLEAN case-insensitive
    (`True` = `false`-style defaults), INTEGER/DOUBLE numerically, TIMESPAN as ISO-8601
    durations in seconds (`PT5M` = `PT0H5M0S`, `P2D`), STRING exactly (case- and
    whitespace-sensitive). Match the catalog by parameter_id (case-insensitive) and
    application type; if the id exists under several applications and none matches, use the first.
  - Row status: `Same` (shown values identical), `Same (default)` (shown values differ only
    because of defaults - hidden by default in the HTML), `DIFF` (effective values differ). Only
    `DIFF` counts as differing in the tally and gets amber highlighting.
  - If the catalog is not available, fall back to raw comparison and say so loudly in the UI
    and the report.

- **Case-sensitive and whitespace-sensitive** comparison. Show leading/trailing spaces with the
  visible marker U+2423. Treat an empty element and a missing element differently:
  `(null)` vs `(absent)`.
- **Group names that differ only by case** (ST_Ops_Mgr vs ST_Ops_MGR) are ONE group; flag the
  spelling difference. Everything else is case-sensitive.
- **`Group_Permission` rows include explicit denies** (`allow=false`) - in the sample, 241 of
  341 PROD rows are denies. Never label a row count as "permissions". Label it
  "permission rows (allow + deny)". Show allow/allow_edit as `T / F`.
- **Not compared by design:** `Users` and all per-user tables (`User_*`, `Application_Data`).
  They hold per-account data (saved filters, memberships, per-user overrides) and the user
  sets differ per environment. State this plainly in the report, including that group
  *membership* is therefore not compared. Show them in Table Counts with a plain-English scope
  note (see the script's `$ScopeMap`). Do not even retain their contents in memory.
- **Mask secrets:** any `parameter_id` containing "key" (case-insensitive) with a value is
  shown as `[API key set]`. The raw value must never reach any output, log or error message.
  If the key value differs between environments, show a warning (the values stay masked).
- **Baseline:** exactly one. Amber highlight = differs from baseline. Tally is relative to it.
  Column order = the order the user gives, with the baseline marked "(baseline)".
- **Parameter definitions:** the **official description from the parameter catalog is the
  definition** (it is more accurate than anything hand-written). `ParamDefinitions.csv` adds to it:
  a non-empty `Definition` replaces the official text (used for parameters the catalog does not
  cover, or as a user override); a `Note` is appended after the official text as
  `... Note: <note>`. Lookup is by parameter_id, case-insensitive. If neither exists, try the name
  patterns (`data/definition_patterns.json`), else show "(no definition yet)". Definitions whose
  basis starts with "Inference" are shown with a leading `[Inferred]` and greyed. No separate
  "Basis" column. Users can upload their own `ParamDefinitions.csv` (same columns; their rows
  win), and the app offers a downloadable `ParamDefinitions_Template.csv` listing every parameter
  found that has no definition. The Parameters CSV/HTML also gets a **Default** column.

### PSO version section (from `System_Version`)

- **Current version** = the highest `version_id`, compared NUMERICALLY by dotted parts
  (6.13.0.9 is older than 6.13.0.67). Never sort versions as text, and do not trust record
  order or timestamps for this.
- **Last upgrade** = most recent record of type `Upgrade from ...` (by `version_stamp`);
  show from -> to and the date. **Last patch** = most recent record of type `Update ...`.
  **Created** = the `Creation` record. All timestamps are UTC as stored.
- Ignore `SYSTEM_USER`-stamped rows for dates: they carry the same vendor timestamp in every
  environment and say nothing about when an environment was upgraded.
- Status per environment: LATEST (equals the highest version) / BEHIND / UNKNOWN (no
  System_Version data).
- If any environment is BEHIND: a **large red banner at the top** of the HTML and at the top
  of the UI results page: "NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION", naming the
  environments that are behind and saying whether it is *different releases* or *same release,
  different patch builds*. If all match: a green banner. If fewer than two environments have
  version data: a neutral warning, never a green banner.
- Also export the full version history (all records, oldest first).

## UI

One screen:

- Drag-and-drop zone accepting multiple `.xml` files (also a normal file picker).
- A row per file: filename + size, a **name** field (default = filename without extension,
  upper-cased), a **Baseline** radio (exactly one; default the first file), reorder
  (drag or up/down), remove.
- Validation before the Run button enables: at least 2 files; exactly 1 baseline; names are
  non-empty and unique; each file parses as a `DsSystemData` document (check the root element,
  ignore the namespace). Per-file errors shown inline, in plain English. Sensible size limit
  (propose one) with a clear message.
- Optional: attach a `ParamDefinitions.csv`.
- **Run comparison** with progress feedback (parsing takes a few seconds per file).
- Results view: the version banner first, then the tally, then the report (embed the generated
  HTML, or render natively - propose which in your plan), plus download buttons:
  HTML, CSV bundle (.zip), Excel (.xlsx), and the missing-definitions template.
- Keep it consistent with the existing pso-test-tools look and feel.

## Outputs (match the script and reference workbook)

**HTML** (`PSO_Compare_Summary.html`): self-contained (inline CSS and JS, no external
requests). Sections in order: PSO version (banner + table + collapsible full history),
Environments, Scope note, tally table, Quick read, one section per area showing only DIFF rows
by default (full data in the CSV), Table row counts. Features to keep: amber cells vs
baseline, muted (absent)/(null), parameter names never break mid-word, **click-to-sort on the
detail tables** (asc, desc, reset; numeric-aware; not on the tally table), a "Click a column
header to sort" hint. The script contains the exact CSS and JS - reuse them.

**CSVs** (UTF-8 with BOM so Excel shows the U+2423 marker correctly): `01_Parameters.csv`,
`02_ExceptionTypes.csv`, `03_GroupPermissions.csv`, `04_Groups.csv`, `05_OrgPermissions.csv`,
`06_Lists.csv`, `07_Travel.csv`, `08_ProfilesAndOther.csv`, `09_TableCounts.csv`,
`10_Versions.csv`, `11_VersionHistory.csv`, `Summary_Tally.csv`,
`ParamDefinitions_Template.csv`. Every row is included (not just DIFF rows).

**Excel** (`PSO_environment_comparison.xlsx`): same tabs as the reference workbook - Summary,
Versions, Parameters, Exception Types, Group Permissions, Groups, Org Permissions, Lists,
Travel, Profiles & Other, Table Counts, Version History. Filters, frozen headers, amber
cells vs baseline, red/green version banner. Decide (and tell me) how to handle formulas:
formula-driven Status columns are nice but a library that does not compute values leaves the
cells empty in previewers (Quick Look, email, SharePoint preview). My preference: write the
computed values, and keep formulas only where they add live behaviour (e.g. days since
upgrade). The Excel must open with no errors in Excel and LibreOffice.

## Parsing

- Stream-parse (SAX/iterparse or your stack's equivalent). Files are a few MB so memory is not
  the issue, but do not keep the big irrelevant tables: read only the tables listed above,
  and only COUNT the rest.
- The XML has a default namespace. Strip the namespace explicitly on element names; do not rely
  on a parser doing it for you.
- Keep every value exactly as stored (including whitespace). Empty element = empty string;
  missing element = absent.
- Handle a missing table gracefully (e.g. STG has no `Org_Schedule_Exc_Type_Data`).
- Never fail the whole run for one bad file without saying which file and why.

## Privacy and security (important)

- The sys files contain **customer data**: the `Users` table, per-user data, and a live routing
  API key (the same key appears in every environment). Treat uploads as sensitive.
- Process uploads in memory or in a private temp dir and **delete them when the run finishes**.
  Do not persist uploads or generated reports unless I explicitly choose to (see questions).
  If results must be retrievable for a while, store only the generated outputs (which contain
  no Users data and masked keys), with an expiry and an unguessable id.
- Do not log file contents, parameter values or keys.
- No outbound network calls from the engine or from the HTML report.
- Do not commit any sample sys files to the repo. Test fixtures that need real exports must
  live outside the repo or be sanitised (Users and `User_*` stripped, keys replaced).
- Escape all values when generating HTML (parameter values contain `{}`, `<`, `|`, URLs).

## Tests (write the engine tests first)

1. **Golden test:** with the four sample files, the catalog and PROD as baseline, the tally must
   equal `expected/expected_results.json` exactly (837 rows compared; ACC 212 / STG 298 /
   TST 371 differ; 473 not identical across all; per-area numbers are in the file). Parameters
   alone: 49 rows, ACC 15 / STG 7 / TST 14, 21 DIFF, 13 `Same (default)`, 15 `Same`.
2. **Spot checks** from the same file (AllowSplitTravel, CommitToAllocatedShift, the merged
   ST_Ops_Mgr group, the 241 deny rows, the whitespace marker, masked key, etc.).
3. **Mutation tests** from the same file (version patch mismatch, release mismatch, numeric
   version ordering, missing System_Version, unknown parameter, duplicate names, two baselines).
4. **No-leak test:** run on files with a known fake key and assert the key string appears in
   no generated artifact (HTML, every CSV, the xlsx, logs).
5. **Renderer tests:** the HTML parses and contains no external URLs; CSVs open with a BOM;
   the xlsx loads in a library without errors and has the expected sheets.
6. A UI/e2e test for the happy path and for each blocking validation message.

If you can run the PowerShell script on a machine that has it, also confirm the engine and
the script agree on the golden numbers; otherwise rely on the golden file.

## Out of scope for now (note them, do not build)

- Saved comparison history / trend of version drift over time.
- Comparing per-user data or group membership.
- An "effective permissions" view (allowed only, after defaults) - the export does not carry
  permission defaults.
- Dataset-level parameter overrides and resource/shift travel-profile links (not in the sys
  file).
- A definitions editor UI (for now: upload a CSV, or edit `ParamDefinitions.csv` in the repo).

## Open questions - ask me these first

1. Where does this live (which route/section of pso-test-tools) and who can use it - is there
   auth, and is the app hosted somewhere shared?
2. Should results be ephemeral (gone when the page closes) or kept for N days with a share
   link? My default: ephemeral, with downloads only.
3. Max number of files (suggest 8) and max file size (suggest 25 MB each)?
4. Do you want the HTML report rendered inside the app, or only offered as a download?
5. Excel: values-only, formulas, or a mix (see Outputs)?
6. Keep `Compare-PsoSysFiles.ps1` in the repo as a standalone CLI, or retire it once the
   golden tests pass?
7. The parameter catalog is for one PSO version and defaults can change between versions. Do you want to ship one catalog, one per PSO version (selected from the detected `System_Version`), or let users upload a catalog? (My default: ship one, allow an upload override, and warn when the detected PSO version differs from the catalog version.)
8. Anything in the existing app (shared UI components, logging, error handling) I should
   reuse instead of creating new?

## Working agreement

- Small, reviewable commits; engine + tests first, then renderers, then API, then UI.
- Do not change unrelated code. If you find a bug elsewhere, tell me instead of fixing it.
- Be explicit when something is an assumption versus confirmed. If the reference script
  looks wrong to you, say so with evidence rather than silently "fixing" it.
