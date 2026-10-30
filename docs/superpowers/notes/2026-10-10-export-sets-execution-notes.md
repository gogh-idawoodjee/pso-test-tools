# Export sets: execution notes

Plan: `docs/superpowers/plans/2026-10-10-export-sets.md`. Spec: `docs/superpowers/specs/2026-10-10-export-sets-design.md`.
Branch `feat/export-sets`, executed inline task by task (TDD, suite run after each task, `ExampleTest` excluded because it fails on main), then one fresh whole-branch review and one fix pass.

## Rulings made during execution

Where the plan and the spec or the real code disagreed, the spec and the code won.

### Data model and scope
- **No morph map.** `scope_type` stores the class name (`App\Models\Dataset`), as spec 3.1 says. `enforceMorphMap` would also throw for the Sanctum and activity-log User morphs and change `activity_log.subject_type`. Object keys use the lowercase class basename (`exports/dataset/...`). Cost if wrong: a later morph map needs `ExportKind::allowedScope()`, the saving hook and `ExportSlotRequest::SCOPES` revisited.
- **Run input ownership.** `RunToolJob` checks each input file sits on the run's scope, or on a dataset run's environment for SYS. It reads files without user scopes, so a crafted `inputs` map could otherwise read another user's export. Tested.
- **Foreign dataset or environment answers 404** (not 403): does not leak existence.

### Summariser
- **SYS layout.** The summary reads the real SYS layout (`System_Version.version_id`, `Profile_Parameter`, `Org_Schedule_Exception_Type` counted at depth 1 with `XMLReader`), not the plan's invented `<Version>`/`<Parameter>` elements. Tests build files with `tests/Support/SysFileBuilder`.
- **Zip members** are streamed to a uuid-named file next to the zip (never `extractTo` by member name); audits are read through `zip://` URIs.
- **Content type is sniffed from the bytes** (zip / json / xml) when parsing. The `content_type` column keeps the named type, because changing it would change `ExportStorage::objectKey()` and orphan the R2 object.

### UI
- **Purge countdown** reads "Purges in 9 h 0 m" (spec section 7 example), not `diffForHumans`.
- **One "Contents" column** on the Exports table covers activities/resources, SYS version/parameters, and audit messages.
- **Run page** is one Filament page with slug `{scope}/{scopeId}/runs/{run}` (`datasets` or `environments`, anything else 404), giving `/datasets/{id}/runs/{run}` and `/environments/{slug}/runs/{run}`.
- **Runs relation manager has the named key `toolRuns`** so `?relation=toolRuns` links to it from the Additional Tools entry pages.
- **Run form exposes no options yet**; each tool port adds its own option fields.

### Plan test defects corrected in the tests
- Unit tests that create factory rows moved to `tests/Feature` (Unit has no `RefreshDatabase`).
- `$dataset->environment` read unauthenticated returned null (`UserOwnedModel`); environments are built explicitly.
- Endpoint tests use an `@mac.com` user (`User::canAccessPanel`).
- `runFor()` passes `run_date => null`; the factory default only passed because of today's date.
- Presigned-URL tests install a Mockery `FilesystemAdapter` with `Storage::set` (typed `disk()` return).
- The countdown test freezes time.

### Left undone on purpose
- Plan Task 8 step 5 (manual browser drop): standing preference not to browser-test.
- Plan Task 10 step 5 (dev database `migrate` and `datasets:seed-settings`): the dev database has no ACME or GLBX datasets and migrating it is the user's to run.

## Final review

Fresh reviewer on the whole branch: 0 Critical, 5 Important, 11 Minor. All five Review Focus scenarios verified.

### Fixed (failing test first)
| Finding | Test |
| --- | --- |
| I-1 SYS summary undercounted rows on compact XML (no whitespace between rows) | counts every SYS row when the xml has no whitespace between rows |
| I-3 Unreadable schedule file or JSON over 20 MB went `ready` with zero counts | fails a schedule file it could read nothing from; fails a JSON schedule file too large |
| I-4 No cap on a zip member's uncompressed size | refuses a zip member larger than the uncompressed cap (`exports.max_member_megabytes`, 600) |
| I-5 A zip named `.xml` was read as XML | reads a zip that was named as xml by what it is |

### Not fixed (rulings)
- **I-2 Timestamps without an offset: resolved 2026-10-10 (branch `fix/pso-times-utc`).** Ish confirmed PSO always writes exported data in UTC. The schedule extractor (also used by the gateway upload) and the audits summary now read a time without an offset as UTC; `export_date` is that instant converted to the dataset zone.
- **I-4 remainder:** no free-disk check before streaming inputs to scratch, only the member cap. Several large concurrent jobs can still fill a small container.

### Deferred minors
- M-1 Raw exception text from non-plain Throwables is shown as `error_message`.
- M-2 `ToolRunPage::getToolRun` re-queries about six times per render.
- M-3 `ToolContext->scope` Dataset has a null environment inside queue jobs (`UserOwnedModel`, no auth).
- M-4 `scope_type` is compared to `Dataset::class` literals; breaks if a morph map is ever registered.
- M-5 Expired, never-completed `uploading` slot rows are kept forever as `purged`.
- M-6 `complete` is not an atomic status transition and ignores the slot window.
- M-7 Drop zone JS forwards all presigned headers (array values, `Host`) instead of only `Content-Type`.
- M-8 Audit zip members with `#` in the name are silently skipped.
- M-9 `ExportStorage::readToScratch` does not guard `fopen` returning false.
- M-10 Spec section 9 golden test (`EXPORTS_SAMPLE_DIR`) not built.
- M-11 `tool_runs.inputs` is `{files, options}`, not the spec's flat `{kind: id}` map.
- M-12 `ExportSummariser::largestMember` caps on the size the zip header claims; the copy itself is unbounded. Bound the copy and fail when the stream is not exhausted.
- M-13 The Exports tab polls every 10 s while any row is `uploading`, so a slot that never finished keeps the tab polling until the purge (12 h).
- M-14 The Exports tab's input reference time column formats in the app zone (America/Toronto) while the export date beside it uses the dataset zone.
- M-15 `RunInputPicker::defaultsFor` matches by export date, which the audit summary lacks, so an audits zip is never preselected when a LOAD exists.

## Found in production testing

- **A real plan export uploaded as a LOAD was rejected with "no Input_Reference block".** A PSO plan export (root `dsScheduleData`) has allocations and a `Plan` block but no `Input_Reference`, `Activity` or `Resources`; its dataset id and reference time are on the `Plan` block. The review fix that stopped unreadable files going `ready` with zero counts also caught these. Now: a plan-shaped file is summarised from its `Plan` block (`dataset_id`, `input_reference_datetime`, `export_date`, `allocation_count`) under Plan and EOD; uploaded as a LOAD it fails with "This file is a plan export ... Upload it under Plan or End of day."
- EOD exports are plan-shaped too (confirmed by the owner), with an input reference of type load. The summariser does not yet tell a Plan from an EOD; it trusts the kind chosen at upload.
- **Plan must have a Plan block** (owner's rule). A file under Plan with no `Plan` element is rejected ("This file has no Plan block ... Upload it as LOAD or End of day instead"); a file with both the input and a Plan block is accepted and summarised from its `Input_Reference`. JSON plans over 20 MB cannot be checked and are rejected.
- **LOAD and EOD cannot be told apart by content** (both are Input_Reference + Activity + Resources, with or without allocations), so their kind is whatever slot the file was dropped in.
- Real samples checked (Oct 2026): a LOAD with allocations, an EOD, a LOAD-only file and a plan-only file all summarise correctly; all stream in about a second at about 70 MB peak.
- PSO writes explicit offsets on its timestamps (`+00:00`), so review finding I-2 (offset-less times) has not shown up in a real file.

## To do after merge
- `php artisan migrate`, then `php artisan datasets:seed-settings <dataset id> <account file> --time-zone=...` for ACME and GLBX.
- R2 bucket CORS rule for the app origin (PUT, GET, `Content-Type`); see README.
- Check the drop zone in the browser (upload, progress, complete) with R2 credentials.
