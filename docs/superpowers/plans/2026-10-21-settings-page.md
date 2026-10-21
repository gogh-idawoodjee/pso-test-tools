# Settings Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One Settings page in the Admin group that holds the message texts upload, the Load audit defaults, the stored ParamDefinitions.csv and the Google Routes cache card, replacing the four places those live today.

**Architecture:** A key-value `app_settings` table read and written only through `App\Support\Settings\AppSettings` (typed accessors, one cache entry). Two fixed-key files on the exports disk through `SettingsFileStore`. A queued `ImportMessageTextsJob` that fills the existing `pso_message_texts` table and records its outcome in the settings row the page polls. The page is a Filament page with one form (audit defaults) and modal actions (uploads, clear, remove, refresh).

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest, MySQL (SQLite in tests), the exports disk (R2, `Storage::fake('r2')` in tests).

**Spec:** `docs/superpowers/specs/2026-10-21-settings-page-design.md`

## Global Constraints

- Project conventions in `CLAUDE.md`: no abbreviated variable names, `app()` over `new` for Filament/Laravel classes, no `final`/`readonly` on classes extending Filament, `static fn` where `$this` is unused, constructor promotion, explicit types everywhere, Filament namespaces as listed there.
- Settings are shared by everyone; no per-user rows, no `AdminViewable` on the page.
- Shipped audit defaults: noise ids `3041,1767,2959,1964,1965,24578`, grace hours `LoadAuditCommand::DEFAULT_GRACE_HOURS` (24), flag activities `DEFAULT_FLAG_ACTIVITIES` (5), flag deletions `DEFAULT_FLAG_DELETIONS` (30).
- Language export upload: `xml` or `zip`, 50 MB cap. ParamDefinitions upload: `csv`.
- A blank noise ids value means no noise ids. Only an absent option (`null` / key missing) falls back to the setting.
- The official catalog `pso_parameters_reference.csv` is untouched.
- Pint: run only on files you touched (`vendor/bin/pint <paths>`). Never on whole directories.
- Commit after every task with the attribution lines the session reminder gives.

## Review Focus

1. **A setting saved as the wrong type** (a numeric string for grace hours, `"3041, 24578 "` with spaces for noise ids): the accessors must return an `int` and the trimmed, comma-joined string. Task 1 tests it.
2. **Two languages uploaded in turn**: the page must list both from the table while naming only the last file. Task 6 tests it.
3. **A ParamDefinitions.csv with the wrong columns**: refused with the parser's message, and the previously stored file and settings row stay. Task 7 tests it.
4. **Prod after deploy, before anyone opens the page**: a Load audit form and a compare run must work with no `app_settings` rows at all. Tasks 2 and 5 test the no-row path.
5. **The import job fails or the file vanishes**: the settings row must show `failed` with a message rather than stay `importing` forever. Task 3 tests it.

---

### Task 1: AppSettings store

**Files:**
- Create: `database/migrations/2026_10_22_000001_create_app_settings_table.php`
- Create: `app/Models/AppSetting.php`
- Create: `app/Support/Settings/AppSettings.php`
- Test: `tests/Feature/Settings/AppSettingsTest.php` (Feature, because only that suite uses `RefreshDatabase` in `tests/Pest.php`)

**Interfaces:**
- Produces `App\Models\AppSetting`: table `app_settings`, `$primaryKey = 'key'`, `$keyType = 'string'`, `$incrementing = false`, `const CREATED_AT = null`, `value` cast `json` (it holds a scalar or an array).
- Produces `App\Support\Settings\AppSettings` with:
  - constants `NOISE_EVENT_IDS = 'load_audit.noise_event_ids'`, `COMPLETE_GRACE_HOURS = 'load_audit.complete_grace_hours'`, `FLAG_ACTIVITIES = 'load_audit.flag_activities'`, `FLAG_DELETIONS = 'load_audit.flag_deletions'`, `MESSAGE_TEXTS = 'message_texts'`, `PARAM_DEFINITIONS = 'param_definitions'`, `CACHE_KEY = 'app_settings'`.
  - `public function get(string $key): mixed` (saved value, else the shipped default, else `null`; throws `InvalidArgumentException` for an unknown key).
  - `public function put(string $key, mixed $value, ?int $userId = null): void` and `public function forget(string $key): void` (both forget the cache).
  - `public function noiseEventIds(): string`, `completeGraceHours(): int`, `flagActivities(): int`, `flagDeletions(): int`.
  - `public function saveLoadAuditDefaults(string $noiseEventIds, int $completeGraceHours, int $flagActivities, int $flagDeletions, ?int $userId): void` which normalises the ids with `LoadAuditCommand::normalisedIds()` (make that method `public static` if it is not already).
  - `public function messageTexts(): ?array`, `public function paramDefinitions(): ?array` returning the stored array or null.
  - `public function updatedAt(string $key): ?CarbonImmutable` and `public function updatedByName(string $key): ?string` (joins `users.name`).

- [ ] **Step 1: Write the migration.** Columns per the spec: `key` string primary, `value` json, `updated_by` nullable foreign id to `users` with `nullOnDelete()`, `updated_at` timestamp nullable.

- [ ] **Step 2: Write the failing tests** in `tests/Feature/Settings/AppSettingsTest.php`:

```php
it('returns the shipped defaults when nothing is saved', function () {
    $settings = app(AppSettings::class);
    expect($settings->noiseEventIds())->toBe('3041,1767,2959,1964,1965,24578')
        ->and($settings->completeGraceHours())->toBe(24)
        ->and($settings->flagActivities())->toBe(5)
        ->and($settings->flagDeletions())->toBe(30)
        ->and($settings->messageTexts())->toBeNull()
        ->and($settings->paramDefinitions())->toBeNull();
});

it('returns the saved value, typed and normalised', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    app(AppSettings::class)->saveLoadAuditDefaults(' 3041, 24578 ', 12, 7, 40, $user->id);
    $settings = app(AppSettings::class);
    expect($settings->noiseEventIds())->toBe('3041,24578')
        ->and($settings->completeGraceHours())->toBe(12)
        ->and($settings->updatedByName(AppSettings::NOISE_EVENT_IDS))->toBe('Ada')
        ->and($settings->updatedAt(AppSettings::NOISE_EVENT_IDS))->not->toBeNull();
});

it('caches reads and forgets the cache on every write', function () {
    $settings = app(AppSettings::class);
    $settings->noiseEventIds();
    expect(Cache::has(AppSettings::CACHE_KEY))->toBeTrue();
    $settings->put(AppSettings::NOISE_EVENT_IDS, '1');
    expect(Cache::has(AppSettings::CACHE_KEY))->toBeFalse()->and($settings->noiseEventIds())->toBe('1');
    $settings->forget(AppSettings::NOISE_EVENT_IDS);
    expect($settings->noiseEventIds())->toBe('3041,1767,2959,1964,1965,24578');
});

it('rejects a key it does not know', function () {
    app(AppSettings::class)->get('nope');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 3: Run them to verify they fail.** `php artisan test tests/Feature/Settings/AppSettingsTest.php --compact`. Expected: class not found.

- [ ] **Step 4: Implement the model and `AppSettings`.** One cache entry holds every row as `key => value`, loaded with `Cache::rememberForever`. Defaults live in one private `const DEFAULTS` array keyed by the constants above (the two json keys default to `null`).

- [ ] **Step 5: Run the tests; expect PASS.** Then `vendor/bin/pint` on the new files.

- [ ] **Step 6: Commit.** `git add` the three files and the test; message `Settings: app settings store`.

---

### Task 2: Load audit defaults come from settings

**Files:**
- Modify: `app/Tools/LoadAudit/LoadAuditCommand.php:68-77` (the noise ids and flag fallbacks)
- Modify: `app/Tools/LoadAudit/LoadAuditTool.php:46-70` (option field defaults)
- Modify: `config/tools.php:11-12` (remove `noise_event_ids`)
- Modify: `tests/Unit/LoadAudit/LoadAuditCommandTest.php:86-93`
- Modify: `tests/Feature/LoadAudit/LoadAuditToolTest.php` (add one test)

**Interfaces:**
- Consumes `AppSettings` from Task 1.
- `LoadAuditCommand::build()` keeps its signature. Fallback rule: for each of `noise_event_ids`, `complete_grace_hours`, `flag_activities`, `flag_deletions`, `array_key_exists($option, $options) && $options[$option] !== null` means use that value (a blank noise string means no `--noise-ids`); otherwise use the `AppSettings` accessor. Grace hours stays wired as today apart from the fallback.

- [ ] **Step 1: Rewrite the noise test** at `LoadAuditCommandTest.php:86-93`. The `LoadAuditCommandTest` is a Unit test with no database, so the `AppSettings` read must be stubbed: `app()->instance(AppSettings::class, $stub)` where the stub is a small anonymous subclass overriding the four accessors, or `Mockery::mock(AppSettings::class)` with `shouldReceive('noiseEventIds')->andReturn(...)`. Three cases:

```php
it('uses the settings default when the noise option is absent, none when it is blank', function () {
    stubSettings(noise: '3041,24578');
    $absent = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml', 'pso_events' => '/s/e.json'], [], 'UTC', null);
    expect($absent)->toContain('--noise-ids')->toContain('3041,24578');

    $blank = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml', 'pso_events' => '/s/e.json'], ['noise_event_ids' => '  '], 'UTC', null);
    expect($blank)->not->toContain('--noise-ids');

    stubSettings(noise: '');
    $none = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml', 'pso_events' => '/s/e.json'], [], 'UTC', null);
    expect($none)->not->toContain('--noise-ids');
});

it('falls back to the settings flag thresholds when the options are absent', function () {
    stubSettings(flagActivities: 9, flagDeletions: 99);
    $argv = LoadAuditCommand::build(checkoutForCommand(), '/s', ['load' => '/s/l.xml', 'pso_events' => '/s/e.json'], [], 'UTC', null);
    expect($argv)->toContain('--flag-activities')->toContain('9')->toContain('--flag-deletions')->toContain('99');
});
```

- [ ] **Step 2: Add a tool test** in `LoadAuditToolTest.php` (Feature, has the database):

```php
it('prefills the option fields from the shared settings', function () {
    app(AppSettings::class)->saveLoadAuditDefaults('1,2', 10, 6, 31, null);
    $defaults = collect((new LoadAuditTool)->optionFields())->mapWithKeys(fn ($field) => [$field->getName() => $field->getDefaultState()]);
    expect($defaults['options.noise_event_ids'])->toBe('1,2')
        ->and($defaults['options.complete_grace_hours'])->toBe(10)
        ->and($defaults['options.flag_activities'])->toBe(6)
        ->and($defaults['options.flag_deletions'])->toBe(31);
});
```

- [ ] **Step 3: Run both files; expect the new tests to fail.**

- [ ] **Step 4: Implement.** In `LoadAuditTool::optionFields()` use `->default(static fn (): string|int => app(AppSettings::class)->...())` for the four fields. In `LoadAuditCommand::build()` apply the fallback rule above; delete the `config('tools.load_audit.noise_event_ids')` read. Remove the config entry and its comment from `config/tools.php`. Grep `TOOLS_LOAD_AUDIT_NOISE_EVENT_IDS` across the repo (`.env.example`, docs) and remove it.

- [ ] **Step 5: Run `php artisan test tests/Unit/LoadAudit tests/Feature/LoadAudit --compact`; expect PASS.** Pint the touched files.

- [ ] **Step 6: Commit** `Settings: Load audit defaults read from app settings`.

---

### Task 3: SettingsFileStore and the message texts import job

**Files:**
- Create: `app/Support/Settings/SettingsFileStore.php`
- Create: `app/Jobs/ImportMessageTextsJob.php`
- Test: `tests/Feature/Settings/SettingsFileStoreTest.php`, `tests/Feature/Settings/ImportMessageTextsJobTest.php`

**Interfaces:**
- Consumes `AppSettings` (Task 1), `MessageTextsImporter::import(string $localPath): array{languages: list<string>, rows: int}`, `ExportStorage::scratchDirectoryFor(string $uuid)` and `removeScratch(string)`.
- Produces `SettingsFileStore`:
  - constants `PARAM_DEFINITIONS = 'settings/param-definitions.csv'`, `MESSAGE_TEXTS_PREFIX = 'settings/message-texts/'`.
  - `public function disk(): Filesystem` (same disk as `ExportStorage`, `config('exports.disk', 'r2')`).
  - `public function putFromLocal(string $key, string $localPath): void`, `public function readToScratch(string $key, string $scratchDirectory): string` (returns the local path, named `basename($key)`), `public function delete(string $key): void`, `public function exists(string $key): bool`.
  - `public static function messageTextsKey(string $extension): string` returning `MESSAGE_TEXTS_PREFIX.Str::uuid().'.'.$extension`.
  - Every method asserts the key is `PARAM_DEFINITIONS` or matches `^settings/message-texts/[0-9a-f-]{36}\.(xml|zip)$`, else `InvalidArgumentException`.
- Produces `ImportMessageTextsJob` with constructor `(public string $objectKey, public string $fileName, public ?int $userId)`, `$tries = 1`, `$timeout = 600`. It writes the `AppSettings::MESSAGE_TEXTS` row as:
  `['status' => 'importing'|'ready'|'failed', 'file_name' => string, 'uploaded_by' => ?int, 'uploaded_at' => ISO string, 'languages' => list<string>, 'rows' => int, 'error' => ?string]`.
  Flow: put `importing` (the page does this before dispatch too, so the row exists at once), read to scratch, import, put `ready` with languages and rows, `finally` delete the object and remove scratch. On `InvalidExportException|RuntimeException` put `failed` with the message; on any other `Throwable` put `failed` with `'The file could not be imported: '.$exception->getMessage()`. `failed(Throwable)` also writes `failed` so a timeout never leaves `importing`.

- [ ] **Step 1: Write the store tests:**

```php
beforeEach(function () { Storage::fake('r2'); config(['exports.disk' => 'r2']); });

it('stores, reads back and deletes the fixed definitions file', function () {
    $local = tempnam(sys_get_temp_dir(), 'defs'); file_put_contents($local, "Parameter,Definition\nMode,x\n");
    $store = app(SettingsFileStore::class);
    $store->putFromLocal(SettingsFileStore::PARAM_DEFINITIONS, $local);
    expect($store->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeTrue();
    $scratch = app(ExportStorage::class)->scratchDirectoryFor((string) Str::uuid());
    expect(file_get_contents($store->readToScratch(SettingsFileStore::PARAM_DEFINITIONS, $scratch)))->toContain('Mode,x');
    $store->delete(SettingsFileStore::PARAM_DEFINITIONS);
    expect($store->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeFalse();
});

it('refuses a key outside the settings folder', function () {
    app(SettingsFileStore::class)->exists('exports/user/1/x.xml');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Write the job tests** (reuse the XML from `tests/Feature/Exports/SummariseMessageTextsTest.php`):

```php
it('imports the texts, records the outcome and removes the upload', function () {
    $key = SettingsFileStore::messageTextsKey('xml');
    Storage::disk('r2')->put($key, $xmlWithEnAndFr);
    (new ImportMessageTextsJob($key, 'MessageTexts-en.xml', 1))->handle(app(SettingsFileStore::class), app(ExportStorage::class), app(MessageTextsImporter::class), app(AppSettings::class));
    $record = app(AppSettings::class)->messageTexts();
    expect($record['status'])->toBe('ready')->and($record['languages'])->toBe(['en', 'fr'])->and($record['rows'])->toBe(3)
        ->and(PsoMessageText::count())->toBe(3)
        ->and(Storage::disk('r2')->exists($key))->toBeFalse();
});

it('records a failure in plain words and still removes the upload', function () {
    $key = SettingsFileStore::messageTextsKey('xml');
    Storage::disk('r2')->put($key, '<DsSystemData></DsSystemData>');
    (new ImportMessageTextsJob($key, 'bad.xml', 1))->handle(...);
    expect(app(AppSettings::class)->messageTexts()['status'])->toBe('failed')
        ->and(app(AppSettings::class)->messageTexts()['error'])->toBe('No Message_Text rows in this file.')
        ->and(Storage::disk('r2')->exists($key))->toBeFalse();
});

it('marks a vanished upload as failed', function () {
    (new ImportMessageTextsJob(SettingsFileStore::messageTextsKey('xml'), 'gone.xml', 1))->handle(...);
    expect(app(AppSettings::class)->messageTexts()['status'])->toBe('failed');
});
```

- [ ] **Step 3: Run; expect failures for missing classes.**

- [ ] **Step 4: Implement both classes.** `readToScratch` mirrors `ExportStorage::readToScratch` (stream copy; throw `RuntimeException('The file is no longer in storage.')` when the stream is null).

- [ ] **Step 5: Run `php artisan test tests/Feature/Settings --compact`; expect PASS.** Pint.

- [ ] **Step 6: Commit** `Settings: file store and message texts import job`.

---

### Task 4: Retire the Message texts export kind

**Files:**
- Modify: `app/Enums/ExportKind.php` (remove `MessageTexts` and its match arms)
- Modify: `app/Support/Exports/ExportSummariser.php:56-58, 282-…` (remove `summariseMessageTexts` and its branch)
- Modify: `app/Jobs/SummariseExportFileJob.php:12, 51-53`
- Modify: `app/Filament/Tables/ExportFilesTable.php:79-81`
- Modify: `app/Models/PsoMessageText.php` docblock (now "refilled from the Settings page")
- Create: `database/migrations/2026_10_22_000002_retire_message_texts_export_kind.php`
- Delete: `tests/Feature/Exports/SummariseMessageTextsTest.php`
- Modify: `tests/Unit/Exports/SummariseSystemDayKindsTest.php:110` (drop the texts row), `tests/Unit/ExportKindAllowsTest.php` (drop any MessageTexts assertions), `tests/Unit/Exports/MessageTextsImporterTest.php` stays.
- Test: `tests/Feature/Settings/RetireMessageTextsMigrationTest.php`

**Interfaces:**
- Consumes `AppSettings::MESSAGE_TEXTS` record shape from Task 3.

- [ ] **Step 1: Write the migration test.** Insert into `export_files` (use `DB::table`, since the enum case is gone and the model would refuse it) two rows with `kind = 'message_texts'`, `scope_type` the user morph class, one `ready` (newer, `original_filename = 'MessageTexts-en.xml'`, `uploaded_by_user_id = $user->id`) and one `failed`; put objects on the fake r2 disk at `exports/user/{id}/{uuid}.xml`. Run the migration class's `up()` directly (`(require database_path('migrations/...'))->up()`). Assert: no `message_texts` rows remain, both objects are gone, and `app(AppSettings::class)->messageTexts()` is `['status' => 'ready', 'file_name' => 'MessageTexts-en.xml', 'uploaded_by' => $user->id, 'uploaded_at' => <row created_at ISO>, 'languages' => [], 'rows' => 0, 'error' => null]`. Add a second case: with no rows at all, the setting stays null.

- [ ] **Step 2: Run; expect failure (migration file missing).**

- [ ] **Step 3: Write the migration.** Pure query builder and `Storage::disk(config('exports.disk', 'r2'))`; deleting an object that is missing is fine, wrap the loop in try/catch and continue. Insert the settings row with `DB::table('app_settings')->updateOrInsert(['key' => 'message_texts'], ['value' => json_encode(...), 'updated_by' => ..., 'updated_at' => now()])`. `down()` is a no-op.

- [ ] **Step 4: Remove the kind** and every branch listed above; delete the obsolete test; fix the two Unit tests. Grep `MessageTexts` and `message_text` in `app`, `tests`, `resources` to confirm only `MessageTextsImporter` and `PsoMessageText` remain.

- [ ] **Step 5: Run the full suite `php artisan test --compact`; expect PASS.** Pint touched files.

- [ ] **Step 6: Commit** `Settings: retire the Message texts export kind`.

---

### Task 5: Sys file compare uses the stored definitions

**Files:**
- Modify: `app/Jobs/RunSysCompareJob.php:52-61, 139-152, 185-192` (drop `$definitions`, load from `SettingsFileStore`)
- Modify: `app/Filament/Pages/SysFileCompare.php:67-74, 144-152, 184-189, 207-215, 250-264, 461` (remove the definitions section, state, methods, job argument; add the "in use" line)
- Modify: `app/Http/Controllers/SysCompare/SysCompareUploadController.php:23, 39` (only `sys`)
- Modify: `resources/views/filament/pages/sys-file-compare/dropzone.blade.php` (only the sys kind; drop the `$isSys` branches)
- Modify: `tests/Feature/RunSysCompareJobTest.php:40-45, 97-106, 145-150`, `tests/Feature/SysFileCompareTest.php:208-233`, `tests/Feature/SysCompareUploadTest.php:90-100`, `tests/Feature/SysCompareDropzoneTest.php:43`

**Interfaces:**
- Consumes `SettingsFileStore::PARAM_DEFINITIONS`, `readToScratch`, `exists` (Task 3); `AppSettings::paramDefinitions()` (Task 1) whose record shape Task 7 fixes as `['file_name' => string, 'parameter_count' => int, 'uploaded_by' => ?int, 'uploaded_at' => ISO]`.
- `RunSysCompareJob::__construct(string $jobId, int $userId, string $runId, array $files, string $baseline)`.

- [ ] **Step 1: Rewrite the job tests.** `runJob()` loses its third argument. Replace "applies a user-supplied definitions csv" with:

```php
it('applies the stored definitions csv on top of the built-in ones', function (): void {
    Storage::fake('r2'); config(['exports.disk' => 'r2']);
    Storage::disk('r2')->put(SettingsFileStore::PARAM_DEFINITIONS, "Parameter,Definition,Basis\nMode,What mode means,\n");
    $runId = runJob([jobUpload('PROD', jobBuilder('6.16.1'), 'prod.xml')], 'PROD');
    expect(Storage::disk('sys-compare')->get("runs/7/{$runId}/".SysCompareArtifact::Report->fileName()))->toContain('What mode means')
        ->and(Storage::disk('r2')->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeTrue();
});
```
(Adjust the report path to whatever the existing test at line 97 reads.) Replace "reports a bad definitions file by name" with the stored-file variant: the failure message must contain `ParamDefinitions.csv`. Keep "writes the missing definitions template only when something is undefined" unchanged.

- [ ] **Step 2: Rewrite the page tests.** Delete the two definitions tests at `SysFileCompareTest.php:208-233`; add:

```php
it('says which definitions are in use', function (): void {
    Livewire::test(SysFileCompare::class)->assertSee('Built-in parameter definitions');
    app(AppSettings::class)->put(AppSettings::PARAM_DEFINITIONS, ['file_name' => 'Mine.csv', 'parameter_count' => 3, 'uploaded_by' => null, 'uploaded_at' => now()->toIso8601String()]);
    Livewire::test(SysFileCompare::class)->assertSee('Mine.csv')->assertSee('3 parameters');
});
```
In the upload test, the `definitions` kind case becomes: posting `kind=definitions` with a csv is rejected with "please choose a .xml file." The dropzone test dataset becomes `['sys']` only.

- [ ] **Step 3: Run the four files; expect the new tests to fail.**

- [ ] **Step 4: Implement.** In the job, `loadDefinitions()` returns `ParamDefinitions::builtIn()` when `! $store->exists(PARAM_DEFINITIONS)`, else reads the file to a scratch directory (`ExportStorage::scratchDirectoryFor($this->runId)` is wrong because runId is a ulid not a uuid; use `scratchDirectoryFor((string) Str::uuid())` and remove it in `finally`) and applies `DefinitionsCsv::parse($path, $record['file_name'] ?? 'ParamDefinitions.csv')`. On the page, replace the definitions `Section` with a `Filament\Schemas\Components\Text` (or a `View`) under the run button reading `Built-in parameter definitions.` or `Parameter definitions: <file_name> (<n> parameters).`, each followed by a link to `Settings::getUrl()` labelled "Change in Settings". Delete `removeDefinitions()`, the `definitions` branch of `addUploadedFile()`, the definitions check in `run()`, and the `definitions` key in `resetForm()`. The `Settings` page class does not exist until Task 6, so the link target is the hard-coded relative URL `/settings`.

- [ ] **Step 5: Run `php artisan test tests/Feature/SysFileCompareTest.php tests/Feature/RunSysCompareJobTest.php tests/Feature/SysCompareUploadTest.php tests/Feature/SysCompareDropzoneTest.php --compact`; expect PASS.** Pint.

- [ ] **Step 6: Commit** `Settings: Sys file compare reads the stored parameter definitions`.

---

### Task 6: Settings page with the audit defaults, message texts and Google sections

**Files:**
- Create: `app/Filament/Pages/Settings.php`
- Create: `resources/views/filament/pages/settings.blade.php`
- Modify: `app/Filament/Resources/EnvironmentResource/Pages/ListEnvironments.php:6, 19-26` (remove the widget)
- Modify: `app/Filament/Widgets/GoogleRoutesCache.php` (add `refresh()`), `resources/views/filament/widgets/google-routes-cache.blade.php` (a Refresh button)
- Modify: `tests/Feature/Setup/GoogleRoutesCacheWidgetTest.php:31-43`, `tests/Feature/NavigationGroupsTest.php` (add the page to the Admin rows)
- Test: `tests/Feature/Settings/SettingsPageTest.php`

**Interfaces:**
- Consumes `AppSettings` accessors and `saveLoadAuditDefaults` (Task 1), `SettingsFileStore::messageTextsKey` and `putFromLocal` (Task 3), `ImportMessageTextsJob` (Task 3), `PsoMessageText`.
- Produces `App\Filament\Pages\Settings`: slug `settings`, title `Settings`, group `Groups::ADMIN`, icon `Heroicon::OutlinedCog6Tooth`, `$navigationSort = 99`. Public properties: `?array $auditDefaults` (form state). Public methods:
  - `form(Schema $form): Schema` with the four audit fields on `auditDefaults.*`, labels and helper texts copied from `LoadAuditTool::optionFields()`, same validation rules, a `Section` heading `Load audit defaults` whose description is the spec's sentence "These are the starting values on the Load audit page. Any of them can still be changed for one run."
  - `saveAuditDefaults(): void` (validates, calls `saveLoadAuditDefaults`, `Notification::make()->success()->title('Load audit defaults saved')`).
  - `uploadMessageTextsAction(): Action` named `uploadMessageTexts`, label `Upload language export`, modal form with one `FileUpload::make('file')->acceptedFileTypes(['text/xml','application/xml','application/zip','application/x-zip-compressed'])->maxSize(51200)->required()` (Livewire temp disk is already r2). Action body: `$extension` from the client name (`xml`/`zip`), `$key = SettingsFileStore::messageTextsKey($extension)`, copy the temporary file to the store with `putFromLocal($key, $temporaryFile->getRealPath())`, write the `importing` record, dispatch `ImportMessageTextsJob`.
  - `clearMessageTextsAction(): Action` named `clearMessageTexts`, `->requiresConfirmation()`, truncates `pso_message_texts` and `forget(AppSettings::MESSAGE_TEXTS)`.
  - `messageTextsLoaded(): array<string, int>` language => row count from the table (`groupBy('language_id')`, `count`).
  - `messageTextsRecord(): ?array`, `messageTextsBusy(): bool` (status `importing`).
- `GoogleRoutesCache::refresh(): void` forgets `CACHE_KEY`.

- [ ] **Step 1: Write the page tests:**

```php
beforeEach(function () {
    Storage::fake('r2'); config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']); $this->actingAs($this->user);
});

it('sits under Admin at /settings and renders every section', function () {
    expect(Settings::getNavigationGroup())->toBe(Groups::ADMIN)->and(Settings::getUrl())->toEndWith('/settings');
    $this->get(Settings::getUrl())->assertOk()
        ->assertSee('Message texts')->assertSee('Load audit defaults')->assertSee('Parameter definitions')->assertSee('Google Routes cache')
        ->assertSee('No message texts loaded.')->assertSeeLivewire(GoogleRoutesCache::class);
});

it('starts the audit defaults form from the shipped values and saves new ones', function () {
    Livewire::test(Settings::class)
        ->assertSet('auditDefaults.noise_event_ids', '3041,1767,2959,1964,1965,24578')
        ->assertSet('auditDefaults.complete_grace_hours', 24)
        ->set('auditDefaults.noise_event_ids', ' 1, 2 ')->set('auditDefaults.flag_activities', 8)
        ->call('saveAuditDefaults')->assertHasNoErrors();
    expect(app(AppSettings::class)->noiseEventIds())->toBe('1,2')->and(app(AppSettings::class)->flagActivities())->toBe(8);
});

it('validates the audit defaults like the audit form does', function () {
    Livewire::test(Settings::class)->set('auditDefaults.noise_event_ids', 'a,b')->set('auditDefaults.complete_grace_hours', 169)
        ->call('saveAuditDefaults')->assertHasErrors(['auditDefaults.noise_event_ids', 'auditDefaults.complete_grace_hours']);
});

it('lists every loaded language from the table and names only the last upload', function () {
    PsoMessageText::insert([...two en rows, one fr row...]);
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, ['status' => 'ready', 'file_name' => 'MessageTexts-fr.xml', 'uploaded_by' => $this->user->id, 'uploaded_at' => now()->toIso8601String(), 'languages' => ['fr'], 'rows' => 1, 'error' => null]);
    $this->get(Settings::getUrl())->assertSee('en')->assertSee('2 texts')->assertSee('fr')->assertSee('1 text')->assertSee('MessageTexts-fr.xml')->assertSee($this->user->name);
});

it('stores the upload, records importing and queues the import', function () {
    Queue::fake();
    Livewire::test(Settings::class)->callAction('uploadMessageTexts', ['file' => UploadedFile::fake()->createWithContent('MessageTexts-en.xml', '<DsSystemData/>')])->assertHasNoActionErrors();
    $record = app(AppSettings::class)->messageTexts();
    expect($record['status'])->toBe('importing')->and($record['file_name'])->toBe('MessageTexts-en.xml');
    Queue::assertPushed(ImportMessageTextsJob::class, fn (ImportMessageTextsJob $job) => $job->fileName === 'MessageTexts-en.xml' && Storage::disk('r2')->exists($job->objectKey));
});

it('refuses a file that is not xml or zip', function () {
    Livewire::test(Settings::class)->callAction('uploadMessageTexts', ['file' => UploadedFile::fake()->create('texts.csv', 10, 'text/csv')])->assertHasActionErrors(['file']);
});

it('shows the import error and polls while importing', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, ['status' => 'importing', 'file_name' => 'x.xml', ...]);
    $this->get(Settings::getUrl())->assertSee('Importing x.xml')->assertSee('wire:poll', false);
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, ['status' => 'failed', 'error' => 'No Message_Text rows in this file.', ...]);
    $this->get(Settings::getUrl())->assertSee('No Message_Text rows in this file.')->assertDontSee('wire:poll', false);
});

it('clears the loaded texts', function () {
    PsoMessageText::insert([...]); app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, [...ready...]);
    Livewire::test(Settings::class)->callAction('clearMessageTexts');
    expect(PsoMessageText::count())->toBe(0)->and(app(AppSettings::class)->messageTexts())->toBeNull();
});
```

- [ ] **Step 2: Move the Google widget tests.** Change "shows the card on the Environments page" to `Settings::getUrl()` and add `$this->get(ListEnvironments::getUrl())->assertOk()->assertDontSee('Google Routes cache')`. Add:

```php
it('reads pso-services again after Refresh', function () {
    Http::fake(['*' => Http::response(routesStatsBody())]);
    Livewire::test(GoogleRoutesCache::class)->call('refresh');
    Http::assertSentCount(2);
});
```
Add `[Pages\Settings::class, Groups::ADMIN]` to `NavigationGroupsTest`.

- [ ] **Step 3: Run; expect failures (page missing).**

- [ ] **Step 4: Implement the page, view and widget change.** The view: a `<x-filament-panels::page>` with, in order, a `<x-filament::section heading="Message texts">` (loaded table or empty sentence, last-upload line, `Importing <file>...` with `wire:poll.5s` on the section root while busy, the error in a `<x-filament::badge color="danger">` when failed, the two action buttons `{{ $this->uploadMessageTextsAction }}` and `{{ $this->clearMessageTextsAction }}`, the latter `->visible(fn () => $this->messageTextsLoaded() !== [])`), then `{{ $this->form }}` with a Save button `wire:click="saveAuditDefaults"`, then a placeholder section `Parameter definitions` that Task 7 fills (render the heading only so the test in Step 1 passes), then `@livewire(GoogleRoutesCache::class)`. Add `<x-filament::button wire:click="refresh" size="sm" color="gray">Refresh</x-filament::button>` to the widget view. Check the Filament v5 docs (Context7, `/filamentphp/filament`) for the action-with-modal-form and `FileUpload` signatures if anything in the test's `callAction` shape does not line up.

- [ ] **Step 5: Run `php artisan test tests/Feature/Settings tests/Feature/Setup tests/Feature/NavigationGroupsTest.php --compact`; expect PASS.** Pint.

- [ ] **Step 6: Commit** `Settings: page with message texts, Load audit defaults and the Google Routes card`.

---

### Task 7: Parameter definitions section

**Files:**
- Modify: `app/Filament/Pages/Settings.php`, `resources/views/filament/pages/settings.blade.php`
- Modify: `tests/Feature/Settings/SettingsPageTest.php`

**Interfaces:**
- Consumes `DefinitionsCsv::parse(string $path, string $displayName): list<array>` (throws `InvalidDefinitionsFile`), `SettingsFileStore::PARAM_DEFINITIONS`, `putFromLocal`, `delete`, `exists`.
- Produces on `Settings`: `uploadParamDefinitionsAction(): Action` named `uploadParamDefinitions`, label `Upload ParamDefinitions.csv`, modal `FileUpload::make('file')->acceptedFileTypes(['text/csv','text/plain','application/vnd.ms-excel'])->maxSize(10240)->required()`. Body: parse the temporary file first; on `InvalidDefinitionsFile` throw a `ValidationException` on `file` with the parser's message (nothing stored); else `putFromLocal`, then `put(PARAM_DEFINITIONS, ['file_name' => <client name>, 'parameter_count' => count($rows), 'uploaded_by' => auth id, 'uploaded_at' => now ISO])`. `removeParamDefinitionsAction(): Action` named `removeParamDefinitions`, `->requiresConfirmation()`, deletes the object and forgets the setting. `paramDefinitionsRecord(): ?array`.
- Section copy: the description moves verbatim from `SysFileCompare.php:145`. Empty state: `PSO Sys File Compare uses the built-in definitions. Upload a ParamDefinitions.csv to add to them.`

- [ ] **Step 1: Write the tests:**

```php
it('stores a good ParamDefinitions.csv and shows what it covers', function () {
    Livewire::test(Settings::class)->callAction('uploadParamDefinitions', ['file' => UploadedFile::fake()->createWithContent('Mine.csv', "Parameter,Definition,Basis\nMode,x,\nFoo,y,\n")])->assertHasNoActionErrors();
    expect(Storage::disk('r2')->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeTrue()
        ->and(app(AppSettings::class)->paramDefinitions()['parameter_count'])->toBe(2);
    $this->get(Settings::getUrl())->assertSee('Mine.csv')->assertSee('2 parameters');
});

it('refuses a bad csv and keeps the stored one', function () {
    Storage::disk('r2')->put(SettingsFileStore::PARAM_DEFINITIONS, "Parameter,Definition\nMode,x\n");
    app(AppSettings::class)->put(AppSettings::PARAM_DEFINITIONS, ['file_name' => 'Old.csv', 'parameter_count' => 1, 'uploaded_by' => null, 'uploaded_at' => now()->toIso8601String()]);
    Livewire::test(Settings::class)->callAction('uploadParamDefinitions', ['file' => UploadedFile::fake()->createWithContent('Bad.csv', "Wrong,Columns\n1,2\n")])->assertHasActionErrors(['file']);
    expect(Storage::disk('r2')->get(SettingsFileStore::PARAM_DEFINITIONS))->toContain('Mode,x')
        ->and(app(AppSettings::class)->paramDefinitions()['file_name'])->toBe('Old.csv');
});

it('removes the stored definitions', function () {
    ...store as above...
    Livewire::test(Settings::class)->callAction('removeParamDefinitions');
    expect(Storage::disk('r2')->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeFalse()->and(app(AppSettings::class)->paramDefinitions())->toBeNull();
    $this->get(Settings::getUrl())->assertSee('uses the built-in definitions');
});
```

- [ ] **Step 2: Run; expect failures.**

- [ ] **Step 3: Implement** the two actions, the accessor and the section view (file name, parameter count, uploaded by, when; the two buttons; Remove only visible when a record exists).

- [ ] **Step 4: Run `php artisan test tests/Feature/Settings --compact`; expect PASS.** Pint.

- [ ] **Step 5: Commit** `Settings: stored parameter definitions`.

---

### Task 8: Docs, env and full-suite check

**Files:**
- Modify: `docs/superpowers/specs/2026-10-21-settings-page-design.md` status line to `Built 2026-10-22`.
- Modify: any `README`/docs lines that mention `TOOLS_LOAD_AUDIT_NOISE_EVENT_IDS`, uploading message texts through My files, or the compare page's definitions upload (grep `noise_event_ids`, `Message texts`, `ParamDefinitions` under `docs/` and `README.md`).

- [ ] **Step 1: Grep and fix the docs.**
- [ ] **Step 2: Run `php artisan test --compact`; expect every test to pass.** Paste the summary line in the commit body.
- [ ] **Step 3: Commit** `Settings: docs and env cleanup`, then push the branch and open a PR against `main` titled `Settings page`.
