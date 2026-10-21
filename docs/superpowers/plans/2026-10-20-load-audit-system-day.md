# Load audit "System day" leg: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A load audit run can take the day's audit zip, the PSO system events export and the Appointment History export, and its page gains a System day section: every stream on one zoomable clock, with findings in the audit's own style.

**Architecture:** Two repos, two independent tracks. In pso-test-tools: three new export kinds, one global message-texts table filled on upload, three run options, new command flags, the inputs CSV kept on the exports disk with a download route. In pso-tools: a new `load-audit/system_day.py` module (standard library, streaming) that `audit_load.py` calls when any new flag is given, writing `system_day.json` and `system_day_inputs.csv` beside the page, with the section rendered from that JSON by a renderer adapted from the hand-off file. The tracks meet only at the command line (flags and output file names fixed below), so they can be built in parallel and the app side is tested with a fake script.

**Tech Stack:** Laravel 13 / Filament v5 / Pest (app); Python 3 standard library + unittest, Node for page-function tests (engine).

**Spec:** `docs/superpowers/specs/2026-10-20-load-audit-system-day-design.md` (status: approved 2026-10-20 evening). Hand-off: `docs/system-day-handoff/`.

## Global Constraints

- Engine: standard library only; streaming reads; `PROGRESS` lines through the existing `ReadProgress` pattern with stages `audits`, `events`, `appointments`. Nothing in legs 1 to 4 changes; a run without the new flags writes byte-identical findings JSON.
- Engine house wording (checked by existing tests): no em or en dashes in page text, never the word "placed".
- App conventions (CLAUDE.md): no abbreviated variable names, `app()` over `new` for Filament/Laravel classes, `static fn` where `$this` is unused, explicit return types, Filament namespaces as listed there. Pint on touched files only.
- Customer data never enters either repo. Fixtures are hand-written and tiny.
- Command-line contract (both tracks build to this exactly): `--audits <zip>`, `--events <json|zip>`, `--appointments <csv|zip>`, `--messages <json>`, `--noise-ids <comma ints>`, `--flag-activities <int>`, `--flag-deletions <int>`. Outputs beside `--html`: `system_day.json`, `system_day_inputs.csv`. Defaults: noise `3041,1767,2959,1964,1965,24578`, flag activities `5`, flag deletions `30`, appointment timeout threshold `30` seconds.
- `messages.json` shape: `{"<message_id>": "<text with {0} placeholders>"}`, ids as strings.
- `system_day.json` shape (section 4.4/4.8 of the spec): `{"day": {"start": ms, "end": ms, "tz": "<IANA>", "offset_minutes": int, "dataset": str|null}, "events": [...], "appointments": [...], "inputs": [...], "per_minute": {"inputs": {...}, "events": {...}}, "windows": [...], "counts": {"events_by_id": {...}, "dropped_other_datasets": int, "dropped_other_days": int}, "texts": {"<id>": "<text>"}, "findings": [...]}`. Every row carries `"dataset"`.
- Branch work only: app branch `feat/system-day-leg`, pso-tools branch `feat/system-day`. Nothing merges to `main` tonight (the prod cron deploys `main`).

## Review Focus

1. An events JSON whose `loggedAt` values carry the `\d` prefix (`\d2026-10-20T18:24:01.123Z`) must land on the same clock as the audit file names; Task 8 tests it.
2. An events export that spans two days: rows outside the LOAD's day are dropped and counted, never plotted at the wrong end; Task 9 tests it.
3. A message id present in events but absent from the texts must never break a lane title or a finding; Task 7 and Task 11 test it.
4. An Unfiled run with every file and no dataset: lanes labelled by `externalTaskId`, clock in the run's zone, toggle to UTC; Task 4 (command), Task 10 (engine) and Task 11 (page) each test their part.
5. A message-texts file whose element layout differs from the one the spec guessed (`Message_Text` children named `id`/`message_id`, `text`/`message_text`): the importer reads either, and a file with no `Message_Text` rows is refused with a plain message; Task 2 tests it.

---

## Track A: pso-test-tools (branch `feat/system-day-leg`)

### Task 1: The three export kinds

**Files:**
- Modify: `app/Enums/ExportKind.php`
- Test: `tests/Unit/Exports/ExportKindTest.php` (create if absent)

**Interfaces:**
- Produces: `ExportKind::PsoEvents = 'pso_events'`, `ExportKind::AppointmentHistory = 'appointment_history'`, `ExportKind::MessageTexts = 'message_texts'`.

- [ ] **Step 1: Write the failing tests**

```php
it('scopes the new kinds', function () {
    expect(ExportKind::PsoEvents->allows(Dataset::class))->toBeTrue()
        ->and(ExportKind::AppointmentHistory->allows(User::class))->toBeTrue()
        ->and(ExportKind::MessageTexts->allows(User::class))->toBeTrue()
        ->and(ExportKind::MessageTexts->allows(Dataset::class))->toBeFalse()
        ->and(ExportKind::MessageTexts->allows(Environment::class))->toBeFalse();
});
it('names the extensions and roots of the new kinds', function () {
    expect(ExportKind::PsoEvents->extensions())->toBe(['json', 'zip'])
        ->and(ExportKind::AppointmentHistory->extensions())->toBe(['csv', 'zip'])
        ->and(ExportKind::MessageTexts->extensions())->toBe(['xml', 'zip'])
        ->and(ExportKind::MessageTexts->expectedRoot())->toBe('DsSystemData')
        ->and(ExportKind::PsoEvents->expectedRoot())->toBeNull()
        ->and(ExportKind::PsoEvents->getLabel())->toBe('PSO system events')
        ->and(ExportKind::AppointmentHistory->getLabel())->toBe('Appointment history')
        ->and(ExportKind::MessageTexts->getLabel())->toBe('Message texts');
});
```

- [ ] **Step 2: Run, expect failure** `php artisan test tests/Unit/Exports/ExportKindTest.php` (undefined enum case).
- [ ] **Step 3: Add the cases and the match arms.** `allows()`: `Dataset::class => ! in_array($this, [self::Sys, self::MessageTexts], true)`, `Environment::class => $this === self::Sys`, User stays true.
- [ ] **Step 4: Run, expect pass.** Then `php artisan test tests/Unit tests/Feature` to see nothing else changed (ExampleTest is a known pre-existing failure).
- [ ] **Step 5: Commit** `Export kinds: PSO system events, Appointment history and Message texts`.

### Task 2: Global message texts table, importer and summary

**Files:**
- Create: `database/migrations/2026_10_21_000001_create_pso_message_texts_table.php` (`id`, `language_id` string(16), `message_id` unsignedInteger, `text` text, timestamps; unique `language_id, message_id`).
- Create: `app/Models/PsoMessageText.php` (fillable `language_id, message_id, text`; `public static function asMap(string $languageId = 'en'): array` returning `[message_id (string) => text]`).
- Create: `app/Support/Exports/MessageTextsImporter.php`.
- Modify: `app/Support/Exports/ExportSummariser.php` (new branch for `MessageTexts`), `app/Jobs/SummariseExportFileJob.php` (after a `MessageTexts` summary succeeds, run the importer).
- Test: `tests/Unit/Exports/MessageTextsImporterTest.php`, `tests/Feature/Exports/SummariseMessageTextsTest.php`.

**Interfaces:**
- Produces: `MessageTextsImporter::import(string $localPath): array{languages: list<string>, rows: int}` reads XML or a zip holding one XML; for each `Message_Text` element reads `message_id` (or `id`), `language_id` (default `en`), `text` (or `message_text`); deletes that language's rows then inserts in chunks of 500 inside one transaction; throws `InvalidExportException('No Message_Text rows in this file.')` when none.
- Produces: summary values `['root' => 'DsSystemData', 'message_text_count' => int, 'languages' => list<string>]`.

- [ ] **Step 1: Failing importer tests.** A fixture string with two `en` rows and one `fr` row: `import()` returns `['languages' => ['en', 'fr'], 'rows' => 3]`; `PsoMessageText::asMap()` is `['2217' => 'Broadcast to {0} returned status code {1}.', '2879' => 'Unexpected changes detected when merging clusters.']`. Second test: re-import a file with one changed `en` row leaves `fr` untouched and replaces `en` (count 2). Third: a layout with `id` and `message_text` child names imports the same. Fourth: a `DsSystemData` file with no rows throws `InvalidExportException` with the message above.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement migration, model, importer** (XMLReader at depth 1, the same `openReader`/zip handling the summariser uses; extract a shared `ExportFormat` helper only if one already exists for opening a zip member).
- [ ] **Step 4: Run importer tests, expect pass.**
- [ ] **Step 5: Failing job test.** Dispatch `SummariseExportFileJob` for an Unfiled `message_texts` file (storage faked as the existing summariser feature tests do): file ends `Ready` with `summary.message_text_count === 3`, and `PsoMessageText::count() === 3`.
- [ ] **Step 6: Implement** the summariser branch (count + languages) and the job hook (importer runs after the summary, before `Ready`; an importer exception marks the file failed like any other).
- [ ] **Step 7: Run both tests and the suites, expect pass. Commit** `Message texts: one global table filled from an Unfiled upload`.

### Task 3: Summaries for events and appointment history

**Files:**
- Modify: `app/Support/Exports/ExportSummariser.php`
- Test: `tests/Unit/Exports/SummariseSystemDayKindsTest.php`

**Interfaces:**
- Produces: `pso_events` summary `['row_count' => int, 'first_at' => iso UTC, 'last_at' => iso UTC, 'by_message_id' => [id => n], 'datasets' => list<string>]`; `appointment_history` summary `['row_count' => int, 'first_at' => iso UTC, 'last_at' => iso UTC, 'failed_count' => int, 'datasets' => list<string>]`.

- [ ] **Step 1: Failing tests.** (a) A 3-row events JSON with one `loggedAt` of `\d2026-10-20T18:24:01.123Z` and two plain: `first_at` is `2026-10-20T18:24:01+00:00`, `by_message_id` is `['2217' => 2, '2879' => 1]`. (b) The same JSON inside a zip gives the same summary. (c) A 4-row appointment CSV with one `Success` of `false`: `failed_count` 1, `row_count` 4, `first_at` from `Request Time` read in `$timeZone` and kept as UTC. (d) A JSON that is not an array throws `InvalidExportException`.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.** Events: `json_decode` of the whole file is acceptable (exports are a few MB); strip a leading `\d` before parsing the time. Appointments: `fgetcsv` with a header map; `Request Time` parsed with `Carbon::parse($value, $timeZone)->utc()`.
- [ ] **Step 4: Run, expect pass. Commit** `Export summaries for PSO system events and Appointment history`.

### Task 4: Load audit inputs, options, command flags and result

**Files:**
- Modify: `app/Tools/LoadAudit/LoadAuditTool.php`, `app/Tools/LoadAudit/LoadAuditCommand.php`
- Modify: `tests/Fixtures/pso-tools/load-audit/audit_load.py` (the fake script: accept the new flags, write `system_day.json` and `system_day_inputs.csv` when `--events` is given)
- Create: `app/Support/Tools/ToolRunFiles.php`
- Test: `tests/Unit/LoadAudit/LoadAuditCommandTest.php`, `tests/Feature/Tools/LoadAuditSystemDayTest.php`

**Interfaces:**
- Produces: `LoadAuditTool::optionalKinds()` = `[Plan, Sys, Audits, PsoEvents, AppointmentHistory]`.
- Produces: option fields `options.noise_event_ids` (TextInput, default `'3041, 1767, 2959, 1964, 1965, 24578'`, regex `/^\s*(\d+\s*(,\s*\d+\s*)*)?$/`, helper "Event ids kept in the counts but left out of the lanes and findings"), `options.flag_activities` (integer, default 5, min 1, max 100000), `options.flag_deletions` (integer, default 30, min 1, max 100000).
- Produces: `LoadAuditCommand::build(..., array $inputPaths, array $options, ..., ?string $messagesPath)`: after the existing `--sys` block, for `audits`, `pso_events` → `--events`, `appointment_history` → `--appointments` when present; `--messages <path>` when `$messagesPath` is a non-empty string; then `--noise-ids <normalised list or empty string omitted>`, `--flag-activities`, `--flag-deletions` (defaults applied when blank), all placed before `--review`.
- Produces: `ToolRunFiles::put(ToolRun $run, string $name, string $localPath): void`, `::get(ToolRun $run, string $name): ?string`, `::deleteAll(ToolRun $run): void`, keys `tool-runs/{run id}/{name}` on `ExportStorage::disk()`; `ToolRun::booted()` deletes them on `deleted`.
- Produces: result adds `'system_day' => array|null` (decoded `system_day.json` when the script wrote one) and the CSV stored under name `system_day_inputs.csv`; `summary` adds `'system_day' => bool`.

- [ ] **Step 1: Failing command tests.** Extend the full-command test with all five inputs and `$messagesPath = '/scratch/run1/messages.json'`, options `['noise_event_ids' => '3041, 24578', 'flag_activities' => 7, 'flag_deletions' => 40]`, asserting the exact argv (new flags after `--sys`, before `--accounts-dir`). Second: no new inputs and `null` messages path gives no new flags except the three option flags with defaults (`--noise-ids 3041,1767,2959,1964,1965,24578`). Third: a blank noise list omits `--noise-ids` entirely.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement the command, the kinds and the option fields.**
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Failing feature test.** Run the tool through `StartToolRun` (as `tests/Feature/Tools/LoadAuditToolTest.php` or the nearest existing test does, with the fixture checkout) for an Unfiled run with `load`, `audits`, `pso_events`, `appointment_history` and five `PsoMessageText` rows: the run finishes, `result['system_day']` is an array whose `day.tz` is the run's zone, `ToolRunFiles::get($run, 'system_day_inputs.csv')` starts with `file_id,`, and `messages.json` was written to the scratch folder with the five ids. Second: with no message texts in the table and no events, `result['system_day']` is null, no CSV stored, no `--messages` flag.
- [ ] **Step 6: Implement** `ToolRunFiles`, the `messages.json` write in `LoadAuditTool::run()` (only when `PsoMessageText::count() > 0`), the result reading, the delete hook, and the fake script.
- [ ] **Step 7: Run, expect pass. Pint on touched files. Commit** `Load audit: the System day inputs, options and command flags`.

### Task 5: Inputs CSV download and the run page

**Files:**
- Modify: `routes/web.php` (`download/system-day-csv` → `downloadSystemDayCsv`, name `tool-runs.download-system-day-csv`), `app/Http/Controllers/ToolRunPageController.php`, `resources/views/filament/tool-runs/load_audit.blade.php`
- Test: `tests/Feature/Tools/SystemDayCsvDownloadTest.php`

- [ ] **Step 1: Failing tests.** Owner gets 200 with `Content-Type: text/csv` and the file name ending `_system_day_inputs.csv`; another user gets 403/404 as the other downloads do; a run with no stored CSV gets 404.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement** the controller method (reads through `ToolRunFiles::get`, streams), the route, and the blade button "Download inputs CSV" shown when `$run->summary['system_day'] ?? false`.
- [ ] **Step 4: Run, expect pass. Commit** `Load audit: download the System day inputs CSV`.

### Task 6: Status note

- [ ] Add a comment to GitHub issue 47 listing what Track A delivered and the PR link (done by the orchestrator at the end, not a subagent).

## Track B: pso-tools (branch `feat/system-day`)

Working directory `/Users/idawoodjee/Documents/Projects/PSO Tools`. Test command: `python3 -m unittest discover -s tests/load_audit -t .`. Fixtures live in `tests/load_audit/system_day_fixtures.py` (new) and build files into a temp folder: a 3-file audit zip (`file1-CHANGE-2026_10_20_18_24_05.xml` with 6 Activity and 35 Object_Deletion rows of two object types, `file2-PLAN_CHANGE-2026_10_20_18_25_10.xml`, `file3-COMPLETE-2026_10_20_18_30_00.xml`), a 20-row events JSON (2217 ×6 consecutive 18:24 to 18:27 with `parameters` `["https://x/IncomingSchedules", "500"]`, 2494 ×2 depth 12 then 31, 2493 ×1, 1974 ×2, 1754 ×1 with params `["A1", "REQ7"]`, 2879 ×6 in one minute, 3041 ×1 noise, 9999 ×1 with no text, one row of another dataset `SOUTH`, one row dated 2026-10-21), a 6-row appointment CSV (one `success=false` with `Output Time Taken` 0 and `Time Taken` 31, request ids REQ1..REQ6, `Input Internal ID` matching the 1974 parameter for one row), and a 10-row messages JSON with the table in spec 4.2.

### Task 7: Message texts and terminology

**Files:**
- Create: `load-audit/system_day.py`
- Test: `tests/load_audit/test_system_day_texts.py`

**Interfaces:**
- Produces: `load_messages(path: str) -> dict[str, str]`; `render_text(template: str, params: list[str]) -> str` filling `{0}`.. and resolving `{word|u}` (capitalise) and `{word|p}` (pluralise: `+s`, `status` → `statuses`, `y` → `ies`); `text_for(message_id, texts, params) -> str` returning the rendered text or `Message <id>` when unknown.

- [ ] **Step 1: Failing tests** for each function, including `render_text('{activity|u} {0} has further {status|p}.', ['A1'])` → `Activity A1 has further statuses.` and `text_for('9999', {}, [])` → `Message 9999`.
- [ ] **Step 2: Run, expect failure. Step 3: Implement. Step 4: Run, expect pass. Step 5: Commit** `System day: message texts and terminology`.

### Task 8: Events and appointments readers

**Files:**
- Modify: `load-audit/system_day.py`
- Test: `tests/load_audit/test_system_day_readers.py`

**Interfaces:**
- Produces: `parse_logged_at(s: str) -> int` (epoch ms; strips a leading `\d`; accepts `Z` or an offset); `read_events(path, day: tuple[int, int], dataset: str | None, noise: set[int], progress) -> tuple[list[dict], dict]` where each row is `{t, severity, app_type, message_id, dataset, instance, message, exception, params, text?}` and the second value is `{'dropped_other_datasets': n, 'dropped_other_days': n, 'by_id': {id: n}}`; rows of another dataset dropped when `dataset` is given, rows outside `day` always dropped and counted; noise rows kept in `by_id` but absent from the list.
- Produces: `read_appointments(path, tz: str, progress) -> list[dict]` rows `{t, dataset, request_id, input_internal_id, success: bool, time_taken, output_time_taken, input_time_taken, requested_slots, offered_slots}`; `t` from `Request Time` interpreted in `tz`.
- Produces: `join_activities(appointments, events) -> None` setting `activity` on each appointment from event 1754 (`params[1]` == request_id → `params[0]`) or 1974 (`params[0]` == input_internal_id → the activity named in the same minute's 1754, else None); `is_timeout(row, threshold=30) -> bool`.
- Both readers accept a `.zip` holding the file.

- [ ] **Step 1: Failing tests** covering: `\d` prefix on the clock (review focus 1), the other-dataset and other-day drops with their counts (review focus 2), noise excluded from rows but counted, the zip variant, both joins, `is_timeout` at 29.9 false and 30 true.
- [ ] **Step 2 to 5** as above. Commit `System day: events and appointment readers`.

### Task 9: Audit index, series, windows, findings

**Files:**
- Modify: `load-audit/system_day.py`
- Test: `tests/load_audit/test_system_day_build.py`

**Interfaces:**
- Produces: `index_audits(zip_path, progress) -> list[dict]` rows `{file_id, type, t, bytes, input_id, input_type, process_type, schedule_data, load_status, input_datetime, elements: {tag: n}, deletions: {object_type: n}, n_activities, n_deletions}` sorted by `t`, read straight from the zip with `iterparse` on each member; `write_inputs_csv(rows, path)` with the column order of the reference script plus `n_activities`.
- Produces: `windows(events, appointments, inputs, flag_deletions) -> list[dict]` `{start, end, label}` per the four rules in spec 4.5, merged when overlapping, padding in ms as the spec states.
- Produces: `findings(events, appointments, inputs, texts, opts) -> list[dict]` with the audit's item shape (`leg='5'`, `check`, `severity`, `count`, `of`, `samples`, `note`, `recommendation`) and the thresholds of spec 4.6; the timeout finding's note names `30 s`.
- Produces: `build(audits_zip, events_path, appointments_path, messages_path, day, tz, dataset, opts, progress) -> dict` returning the `system_day.json` shape from Global Constraints; inputs embed `elements`/`deletions` only when flagged or inside a window.

- [ ] **Step 1: Failing tests**: index row shape and counts from the fixture zip; the 2217 run opens a window `18:21 to 18:30` labelled `2217 x6, 18:24 to 18:27`; the 2879 burst opens one; the flagged deletion input opens one; the 3 failed appointments rule at boundary (2 → none, 3 → one); every finding threshold at its boundary (2217 run 2:00 is RED, 1:59 AMBER; queue 20 RED, 19 AMBER; timeouts 2% RED; 10 for one activity RED; 5 DSE errors in a minute RED); the INFO finding for unknown ids lists `9999`; `build()` embeds `elements` for the flagged input only.
- [ ] **Step 2 to 5** as above. Commit `System day: audit index, windows and findings`.

### Task 10: Wire into audit_load.py

**Files:**
- Modify: `load-audit/audit_load.py` (args; `LEG_NAMES['5'] = 'System day'`; after `leg4`, when any new input flag is given call `system_day.build(...)` with `day` from `local_day(...)` bounds in `ctx['tz']`, `dataset=D.ir.get('dataset_id')`; append its findings to `F`; write `system_day.json` and `system_day_inputs.csv` beside `a.html`; `build_data` adds `'day': <the dict or None>`).
- Modify: `tests/load_audit/test_progress.py` or new `tests/load_audit/test_system_day_cli.py`.

- [ ] **Step 1: Failing tests**: with the new flags the run exits 0, writes both files, progress stages include `audits`, `events`, `appointments` in that order, and the findings JSON contains an item with `leg == '5'`; without the flags the findings JSON is byte-identical to a run on the unchanged main branch (compute by running the test fixture before and after, or assert no item has `leg == '5'` and no `system_day.json` exists); an Unfiled-style run (no dataset in the LOAD? the LOAD always has one: pass `--tz UTC` and assert `day.tz == 'UTC'`).
- [ ] **Step 2 to 5** as above. Commit `Load audit: optional System day leg`.

### Task 11: The System day section on the page

**Files:**
- Modify: `load-audit/load_audit_template.html` (nav entry and `<section id="s-day">` after `s-sys`; CSS tokens for lanes; the renderer adapted from `docs/system-day-handoff/reference/timeline_renderer.js` with the day bounds and offset taken from `ALLD.day`, the `minuteSeries` date removed, a `Local / UTC` toggle (`<button data-clock>`), first draw on the section's first intersection (`IntersectionObserver`) and redraw on `resize`); the six lanes of spec 4.7; the tables below; the reference block collapsed.
- Test: `tests/load_audit/test_system_day_page.py` using the `_page_function` approach from `tests/activity_trace/test_outputs.py` (copy that helper into `tests/load_audit/page_functions.py`) and Node.

**Interfaces:**
- Produces: page functions `dayClock(ms, utc: bool) -> 'HH:MM:SS'`, `laneDefs(day) -> list` (lane titles; an id with no text gets `Message <id>`), `windowButtonsHtml(windows)`.

- [ ] **Step 1: Failing tests**: the template holds `id="s-day"`, a nav link to it, `data-clock`; `dayClock(day.start + 3600e3, false)` with offset −360 gives the local hour; `laneDefs` with the fixture data gives titles in the spec's order with `Message 9999` for the unknown id (review focus 3) and no lane for a stream that is absent; the section is hidden (`hidden` attribute) when `ALLD.day` is null; house wording test (no dashes, no "placed") applies to the new markup.
- [ ] **Step 2 to 5** as above. Commit `Load audit page: the System day section`.

### Task 12: Measure the zipped indexer

- [ ] Run `index_audits` against a real day's audit zip from `~/pso-data` (never copied into the repo) and record files, bytes and seconds in the PR description. If it exceeds 120 s, note it for Ish rather than changing the timeout.

## Finish

- [ ] Both suites green (list the pre-existing ExampleTest failure by name). Open one PR per repo with the spec link, the measurement, and what was left out, if anything. Do not merge.
