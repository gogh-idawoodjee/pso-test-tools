# Unfiled scope: file-only tools without an environment and dataset

Issue #45. Agreed with Ish 2026-10-16.

## 1. Problem

Trace an activity, Load Health Audit and Plan travels work on a file. Which environment or dataset the
file came from does not matter to them, and the file may well be from production (nothing is sent
anywhere; the prod refusal only guards live sends). Today the wizard's Where step still forces an
environment and a dataset, because exports are stored under a dataset and the time zone comes from it.
Consultants end up inventing a dataset to hold a file, and a prod audit filed under a TST dataset is
misleading.

## 2. Decision

A personal **Unfiled** scope. The user model becomes a third export and run scope next to Dataset and
Environment. Tools say whether they accept it. For an accepting tool the Where step offers "Unfiled" as
the default, and the run takes a time zone option instead of the dataset's.

Accepting tools: Trace an activity, Load Health Audit, Plan travels, and the trim export tool (#28) when it
is built. Not accepting: Visualize Resource Regions (it needs the dataset's account settings: excluded
resource types, labels, pivot thresholds) and Sys compare (environment scoped by nature, it compares
environments). Nothing changes for a run started under a dataset.

Rejected: environment-only scope (environments carry URLs and credentials, so it mixes analysis with
live-send setup, and the environment is still the wrong label for a prod file); keeping the step and
relying on the remembered dataset (one click, but still the wrong home for the file).

## 3. Data model

- `ExportFile.scope` and `ToolRun.scope` are morphs already. The new scope is `App\Models\User`.
  No migration: the morph columns take the user's morph class and key.
- `ExportKind::allowedScope(): string` becomes `ExportKind::allows(string $scopeClass): bool`:
  SYS allows Environment and User; every other kind allows Dataset and User. `forScope()` keeps its
  signature and uses `allows()`. Nothing allows Environment except SYS, as now.
- `Tool::scope(): string` stays (Dataset, or Environment for Sys compare). New
  `Tool::acceptsUnfiled(): bool`, true for `AppointmentTimeline` (Trace an activity), `LoadAudit`
  and `PlanTravels`. The trim export tool adds itself when it lands.
- `User::last_tool_context` gains two keys: `unfiled` (bool) and `time_zone` (IANA name or null).
  `rememberToolContext(?string $environmentId, ?string $datasetId, bool $unfiled = false, ?string $timeZone = null)`.
- Expiry and purge are unchanged: an unfiled export gets the same `expires_at`, `PurgeExports` finds it
  by status and date, not by scope.

## 4. Uploads

- `ExportSlotRequest::SCOPES` gains `'unfiled' => User::class`. For `unfiled` the scope is the
  authenticated user and `scope_id` is ignored (it is sent as the string `me`). The kind check uses
  `ExportKind::allows()`.
- `ExportSlotController` resolves `unfiled` to `auth()->user()`.
- `ExportDropZone` accepts a User scope; `scopeType` becomes `match (true) { Dataset => 'dataset',
  Environment => 'environment', default => 'unfiled' }` and the mount check uses `allows()`.
- `RunInputPicker::candidates()`: when the scope is a User, every kind is read from the user scope
  (a SYS export for an unfiled load audit is uploaded unfiled too). The time zone for labels comes from
  `scope instanceof Dataset ? $scope->timeZone() : config('app.timezone')` as now; unfiled labels
  therefore show the app zone, which is acceptable for a file list.

## 5. The wizard

For a tool with `acceptsUnfiled()`:

- The Where step starts with a `Radio` `where` with two options: **Unfiled** ("Just the file. Nothing is
  sent to PSO and the run is listed under My files.") and **A dataset** ("The exports belong to an
  environment and dataset you have set up."). Default: the remembered choice, else Unfiled. The
  environment and dataset selects are shown only for "A dataset" and keep their current behaviour.
  The step's description becomes "Where the exports live."
- `hasScope()` is true for Unfiled. `scopeModel()` returns `auth()->user()` for Unfiled.
  `isDatasetScoped()` keeps its meaning (the tool's dataset scope) and is used only to decide which
  selects to show.
- The Options step gains, for Unfiled runs only, a `Select` `options.time_zone` ("Time zone", "The zone
  the pages show times in.") listing `DateTimeZone::listIdentifiers()`, searchable, required, default
  the remembered `time_zone`, else `config('app.timezone')`. Tools whose option fields are otherwise
  empty get an Options step for this one field. The select is appended by the wizard, not by the tools.
- `start()` passes the user as scope; `StartToolRun::start()` remembers the context with
  `unfiled: true` and the chosen zone. The dataset path remembers `unfiled: false` and no zone.
- `runWeek()` (Region availability) is untouched: that tool does not accept Unfiled.
- For a tool without `acceptsUnfiled()` the step is exactly as today.

## 6. Running

`RunToolJob`:

- Time zone: `scope instanceof Dataset ? $scope->timeZone() : (string) ($run->inputs['options']['time_zone'] ?? config('app.timezone'))`.
  `timeZoneIsDefault` stays a dataset-only flag (an unfiled run always has an explicit zone).
- Settings: null for a User scope, as for an Environment today.
- `ToolContext` is unchanged; tools already handle `settings === null` and a non-Dataset scope.

Per tool:

- Load audit: the page header uses the file's original name when `settings?->displayName` is null and
  the scope is not a Dataset. Today it passes `displayName` (nullable) to the script; the script falls
  back to the account name. Add the file name as the fallback label where the tool builds argv.
- Plan travels: `datasetName` falls back to the LOAD's original file name when the scope is a User.
- Trace an activity: the dataset name passed for the page is the audits file's original name for a
  User scope; the time zone branch for a non-Dataset scope already returns null and the context zone is
  used.
- Region availability and Sys compare: no change, they never see a User scope.

The job's `resolveInputs()` reads files by the run's scope and does not care which model it is.

## 7. Finding the runs and files again

- `ToolRunPage` slug `{scope}/{scopeId}/runs/{run}` gains `unfiled` as a scope. `scopeId` is `me`.
  `urlFor()` maps a User scope to `['scope' => 'unfiled', 'scopeId' => 'me']`. `loadToolRun()` resolves
  `unfiled` to `auth()->user()`; the existing ToolRun global scope already hides other users' runs.
- `ToolRunPageController` routes: the group is `datasets/{dataset}/runs/{run}` today. It becomes
  `{scopeType}/{scopeId}/runs/{run}` where `scopeType` is `datasets` or `unfiled` (`scopeId` is `me`
  for unfiled), with the same route names, and `find()` resolves the scope accordingly. Views build
  these URLs with `[$dataset, $run]` today; they call a helper
  `ToolRunPageController::params(Model $scope, ToolRun $run)` that returns the two route parameters,
  so the page, JSON and CSV links work for both scopes. Views affected: every `tool-runs/*.blade.php`
  that calls `route('tool-runs.*')`.
- Download file names: `fileName()` uses `unfiled` in place of `<account>_<dataset>`.
- `RecentRuns` widget: the "Dataset or environment" column becomes "Where" and shows `Unfiled` for a
  User scope (the user's own runs only, which the global scope already ensures).
- New page **My files** under the Analysis tools group, slug `my-files`: the user's unfiled exports
  (a drop zone for every kind that allows User, and the same file table the dataset Exports tab uses,
  with purge countdown and delete) and the user's unfiled runs (the same table as a dataset's Runs tab,
  opening `ToolRunPage`). If those tables live in RelationManagers today, extract the table definitions
  into shared classes rather than duplicate them.
- The home page tool catalogue and the recent runs widget are the other entry points; no sidebar change
  beyond the My files page.

## 8. Out of scope

- Moving a file or run from Unfiled into a dataset later.
- Unfiled Sys compare or Region availability.
- Changing how dataset-scoped runs look or behave.

## 9. Testing

- `ExportKind::allows()` table test.
- Upload slot: `unfiled` resolves to the current user; a kind check still refuses what it refused before;
  `scope_id` is ignored for unfiled.
- Drop zone mounts with a User scope and posts `scopeType: unfiled`.
- Wizard, for an accepting tool: Where shows the radio, defaults to Unfiled, hides the selects; choosing
  "A dataset" shows them; the Inputs step lists the user's unfiled files; Options shows the time zone
  select with the remembered default; `start()` creates a run scoped to the user with
  `options.time_zone`; the context is remembered. For Region availability the step is unchanged.
- `RunToolJob` passes the option zone to the tool for a User scope and the dataset zone otherwise.
- `ToolRunPage` opens an unfiled run at `unfiled/me/runs/{id}` and 404s another user's run.
- Page and download routes work for an unfiled run and the file name says `unfiled`.
- My files page lists the user's exports and runs and not another user's.
- Load audit, Plan travels and Trace: the page label falls back to the file name for a User scope
  (unit level, on the argv or the name passed to the script).

## 10. Corrections found while planning (2026-10-16)

- The ownership global scope on exports and runs would hide every user-filed row, the user's own included; it gets a branch for the User scope.
- `ExportStorage::objectKey()` only accepts uuid scope ids; unfiled files use `exports/user/<id>/<uuid>.<ext>` so purge and runs do not throw.
- User ids are integers in a char morph column: every scope id comparison casts to string (MySQL would otherwise compare numerically).
- Plan travels names an Unfiled run after its Plan file (the spec said LOAD; the tool takes only a Plan export).
- The time zone select always sits on the accepting tools' Options step and is hidden for dataset runs, because Filament builds the form once per request.
- A dataset run keeps the previously remembered time zone (spec section 5 said no zone); friendlier for the next Unfiled run.
