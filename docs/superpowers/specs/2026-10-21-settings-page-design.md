# Design: a Settings page

**Date:** 2026-10-21
**Status:** Built 2026-10-22
**Repo:** pso-test-tools only. Nothing in pso-tools changes.
Builds on `2026-10-20-load-audit-system-day-design.md` (the message texts table and its upload) and the
PSO Sys File Compare page (the per-run ParamDefinitions.csv upload).

## 1. Problem

Four things that every user shares, and that change rarely, each live somewhere a user would not look:

- **Message texts** are loaded by uploading a PSO language export under the "Message texts" kind in
  My files. The user who asked for this feature did not know that existed. Nothing shows what is loaded
  now.
- **The Load audit's default noise event ids** come from `config/tools.php` through an env variable.
  Changing them means editing a file on the server.
- **Parameter definitions** (ParamDefinitions.csv) are uploaded again for every PSO Sys File Compare run
  and deleted when the run finishes.
- **The Google Routes cache** summary sits at the top of the Environments list, where it has nothing to do
  with environments.

Success: one Settings page in the Admin group that holds all four. A consultant opens it, sees what is
loaded and what the defaults are, and changes any of them without touching a file or asking where it is.

## 2. Goals, non-goals, anti-goals

Goals

- One page, four sections, shared by everyone. Every change takes effect at once for every user.
- Each section shows its current state before offering a change: what is loaded, by whom, and when.
- The old routes go away, so there is exactly one way to do each thing.

Non-goals

- Per-user settings. Nothing on this page is personal.
- Editing the official parameter catalog (`pso_parameters_reference.csv`). It ships with the app and
  the pso-tools checkout as reference data and stays built in.
- Settings for other tools. The page is built so a section can be added later, but only the Load audit
  gets one now.
- A settings package. Four scalars and two stored files do not need one.

Anti-goals

- A page that needs a deploy to change a value. That is the problem being solved.
- Losing the per-run override. A user must still be able to change the noise ids for one audit run.

## 3. The page

`App\Filament\Pages\Settings`, slug `settings`, title "Settings", navigation group Admin, icon
`Heroicon::OutlinedCog6Tooth`. Every signed-in user can open it and change everything; the app has no
roles, and the people who reach the panel are already the people who tune it.

Four sections, in this order. Each is a Filament section with its own save or upload action; there is no
page-wide Save button, because the sections are independent and two of them are uploads.

### 3.1 Message texts

Shows: the languages loaded and the row count per language, read from the `pso_message_texts` table
itself, and beneath that the last upload: file name, who uploaded it and when, from the settings row.
The two are kept apart on purpose: the importer replaces only the languages present in the file it is
given, so after an English upload and then a French one the table holds both while the last upload was
French. When the table is empty: "No message texts loaded. The Load audit's System day tab shows event
ids without their text until a language export is uploaded."

Actions: **Upload language export**, a Filament file upload accepting `xml` and `zip` up to 50 MB (a
language export is a few MB; it does not need the direct-to-R2 path My files uses for 400 MB LOADs). The
import is the existing `MessageTextsImporter`, run in a queued job of its own with the file in scratch.
While it runs the section shows "Importing <file name>..." and polls; when it finishes it shows the new
state or the importer's error message in the section itself. **Clear**, with confirmation, empties the
table and the settings row.

The table the importer fills and the way the Load audit reads it do not change.

### 3.2 Load audit defaults

A small form with four fields, saved together with a **Save** button:

| Field | Validation | Shipped value |
| --- | --- | --- |
| Noise event ids | comma-separated whole numbers, may be empty (empty means no noise ids) | `3041,1767,2959,1964,1965,24578` |
| Finished work kept for (hours) | whole number, 0 to 168 | `24` |
| Flag an appointment with this many activities | whole number, 1 to 100000 | `5` |
| Flag a resource with this many deletions | whole number, 1 to 100000 | `30` |

Labels and helper texts are the ones the Load audit form already uses. Below the form: "These are the
starting values on the Load audit page. Any of them can still be changed for one run."

The Load audit's option fields (`LoadAuditTool::optionFields()`) read their defaults from these settings
instead of config and class constants. The shipped values live in `AppSettings::DEFAULTS` (24, 5, 30 and
the noise list above); the `LoadAuditCommand::DEFAULT_*` constants were removed. The
`tools.load_audit.noise_event_ids` config entry and its env variable are removed.

Empty means empty. Today `LoadAuditCommand` treats a blank per-run noise ids value as "use the config
default", which would make it impossible to run one audit with no noise ids while the shared default is
set. From now on: an option that is absent from the run (`null`, as for a rerun or a caller that never
set it) falls back to the setting; an option that is present and blank means no noise ids. The same rule
applies to the other three: absent falls back to the setting, which itself falls back to the shipped
value in `AppSettings::DEFAULTS`.

### 3.3 Parameter definitions

Shows: the stored file's name, how many parameters it covers, who uploaded it and when. When nothing is
stored: "PSO Sys File Compare uses the built-in definitions. Upload a ParamDefinitions.csv to add to them."
The description of the CSV's columns and rules moves here from the compare page.

Actions: **Upload ParamDefinitions.csv** (replaces the stored file; the upload is parsed with
`DefinitionsCsv::parse` before it is accepted, and a bad file is refused with the parser's message and
leaves the old one in place) and **Remove**, which goes back to the built-in definitions.

Every PSO Sys File Compare run now uses the stored file on top of the built-in definitions. The
"Parameter definitions (optional)" section on the compare page, the `definitions` form state, and the
job's `definitions` upload argument are removed. The compare page gets one line under its run button
saying which definitions file is in use, linking to Settings.

### 3.4 Google Routes cache

The existing `GoogleRoutesCache` widget, moved here unchanged, with one addition: a **Refresh** action
that forgets the cached stats and reads them again, since the widget otherwise shows numbers up to five
minutes old. It is removed from the Environments list page.

## 4. Storage

One new table, `app_settings`:

| Column | Type |
| --- | --- |
| `key` | string, primary key |
| `value` | json |
| `updated_by` | nullable foreign key to users, null on delete |
| `updated_at` | timestamp |

Rows are written through one class, `App\Support\Settings\AppSettings`, which knows the keys, their
shipped values and their types, so the rest of the app never touches the table or a bare string key:

- `load_audit.noise_event_ids` (string), `load_audit.complete_grace_hours`, `load_audit.flag_activities`,
  `load_audit.flag_deletions` (ints). Absent row means the shipped value.
- `message_texts` (json: file name, languages with row counts, uploaded by, uploaded at). The rows
  themselves stay in `pso_message_texts`.
- `param_definitions` (json: file name, parameter count, uploaded by, uploaded at, storage key).

Reads are cached in the application cache under one key and forgotten on every write, so a Load audit
form or a compare run never queries the table more than once.

The ParamDefinitions.csv itself is stored on the exports disk under `settings/param-definitions.csv`, a
fixed key: there is only ever one. `RunSysCompareJob` reads it to scratch through `ExportStorage` the way
it reads uploads today. The language export is not kept after import; its summary in the settings row is
the record of it.

## 5. What goes away

- `ExportKind::MessageTexts`, its branches in `ExportSummariser`, `SummariseExportFileJob` and
  `ExportFilesTable`, and its tests. A migration first copies the newest ready `export_files` row of that
  kind (file name, owner, created date) into the `message_texts` settings row, so the page describes the
  texts prod already holds, then deletes the rows of that kind and their stored objects. The
  `pso_message_texts` table and `MessageTextsImporter` stay.
- `tools.load_audit.noise_event_ids` in `config/tools.php` and `TOOLS_LOAD_AUDIT_NOISE_EVENT_IDS`.
- The definitions upload on the PSO Sys File Compare page and the `definitions` argument of
  `RunSysCompareJob`.
- The `GoogleRoutesCache` header widget on `ListEnvironments`.

## 6. Testing

- Unit: `AppSettings` returns shipped values with no rows, the saved value after a write, forgets its
  cache on write, and rejects a key it does not know.
- Feature: the Settings page renders for a panel user; saving the Load audit defaults validates each
  field and the Load audit form then shows the saved values; uploading a language export dispatches the
  import job and the section shows the result; uploading a bad ParamDefinitions.csv is refused and the
  old file stays; removing it returns the compare run to built-in definitions; the Google widget appears
  on Settings and no longer on the Environments list.
- Existing tests that upload a Message texts kind are removed with the kind. Existing compare tests that
  pass a definitions upload change to store the file through `AppSettings` first.

## 7. Left to the builder

- Section layout within Filament v5 (one `Section` per part, or a tab per part) and the exact wording of
  the empty states.
- Whether the message texts import reuses `SummariseExportFileJob`'s scratch handling or gets a small job
  of its own. A job of its own is expected to be simpler now that there is no `ExportFile` row.
- The polling mechanism for the import, as long as it is the one other pages on the app already use.

## 8. Decisions made in the conversation

- Shared settings for everyone, not per user.
- Message texts, Load audit defaults, parameter definitions and the Google Routes cache all live on the
  one page.
- The three other audit defaults sit beside the noise ids (my call, raised and not objected to).
- The per-run definitions upload is replaced, not kept alongside the stored one (my call: two sources
  for the same thing would need a rule for which wins).

Settled after the builder check, all my calls:

- The Message texts section reads languages and counts from the table and only the last-upload details
  from the settings row; uploads keep replacing per language; a Clear action is added; the migration
  carries the existing upload's details across.
- The language export goes through a plain Filament upload capped at 50 MB, not the direct-to-R2 path.
- A blank noise ids value means no noise ids, both in Settings and for one run. Only an absent option
  falls back to the setting.

## 9. Deploying

Let the queue drain before deploying: queued Sys file compare jobs carry the old definitions property,
and queued message-texts summarise jobs fail once the migration deletes their rows. Then run
`php artisan migrate`. Old `TOOLS_LOAD_AUDIT_NOISE_EVENT_IDS` lines in `.env` are ignored and can be
removed.
