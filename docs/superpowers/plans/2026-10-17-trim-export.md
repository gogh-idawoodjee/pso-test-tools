# Trim Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One analysis tool, Trim export, that streams one LOAD, plan or EOD export (XML or JSON, zipped or not) in two passes, removes whole tables and, optionally, rows by region, resource, activity type, activity and appointment window with a referential cascade, and files the result as a new export next to the input (or only counts, for Count only), replacing Filter Load File.

**Architecture:** A format-agnostic engine in `app/Support/TrimExport/`: two readers turn a file into `Segment`s (head, table open, row, table close, tail) whose bytes concatenate back to the file exactly; `KeyCollector` (pass one) reads only the key fields of the tables the filters need and resolves `KeepSets` (ids only); `TrimEngine` (pass two) decides keep or drop per segment and hands it to a writer that copies kept bytes verbatim and repairs JSON separators; `ReferenceTally` lists what is left dangling. `TrimExportTool` runs this inside the standard `RunToolJob`, stores the output through `ExportStorage` as a new `ExportFile` and queues its summary. The wizard page `TrimExportEntry` adds an "export to trim" choice on Inputs and two option steps (Tables, Rows) fed by `ExportFileScanner` (stored `table_counts`, or a cached, encrypted streamed scan).

**Tech Stack:** Laravel 13, PHP 8.5, Filament v5.10 (Schemas, Wizard, CheckboxList, Select, DatePicker, DateTimePicker, Radio, Toggle, Text), Livewire 4, Pest 4, MySQL in production, SQLite in memory for tests. Tests run with `vendor/bin/pest <paths>`.

**Spec:** `docs/superpowers/specs/2026-10-17-trim-export-design.md` (issue #28, closes #19). The spec is the authority; this plan argues from it. Where the plan departs from a word of the spec it says so in the task and in "Settled departures" below.

## Global Constraints

- Scope ids are compared as strings: `(string) $scope->getKey()` on every query and every `scope_id` written (a char column; MySQL compares it numerically with an integer, SQLite in the tests hides that).
- Customer identifiers (activity, resource, location, shift, availability ids, names, region ids, file names) never go into `ToolRun.summary`, `ToolRun.warnings` or a log line. They live only in the encrypted `ToolRun.result` (the dangling list, the new export id) and in the encrypted cache of filter choices. Warnings and summary lines carry table names and counts only.
- Never decode a whole file. Every read is streamed (byte scanner for XML, `JsonTokenReader` for JSON); memory holds ids only. The 100 MB cap of Filter Load File goes away with it (Task 12).
- Outputs are written to the run's scratch folder as `<name>.part`, renamed when complete, then stored with `ExportStorage::putFromLocal()`. A failed write deletes the `.part` file; a failed store deletes the new `ExportFile` row.
- Removing anything from a dsScheduleData must cascade through every dependent row (any `activity_id`, `activity_id1`, `activity_id2`, `resource_id`, `location_id`, `shift_id` not kept), and every run ends with the referential check (the dangling list).
- `Availability`, `Resource_Region_Availability` and `Resource_Skill_Availability` are never filtered by date. `Shift` is (only with an appointment window, as Filter Load File did).
- Tables first, then rows: a table removed by the Tables step is never used to cascade, and row filters never cascade on a table the run removed.
- Input_Reference is always kept unless the run sets `allow_input_reference_removal` (Remove-PsoTables `-DropInputReference`).
- Filament v5 namespaces only: form fields `Filament\Forms\Components\` (`CheckboxList`, `Select`, `Radio`, `Toggle`, `DatePicker`, `DateTimePicker`), layout `Filament\Schemas\Components\` (`Grid`, `Group`, `Text`, `Wizard\Step`), `Get`/`Set` from `Filament\Schemas\Components\Utilities\`, actions `Filament\Actions\` (never sub-namespaces), `Filament\Support\Exceptions\Halt`.
- House conventions (CLAUDE.md): no abbreviated variable names; `app()` over `new` for Filament and Laravel classes (plain value objects and the writers may use `new`); never `final` or `readonly` on classes that extend Filament; `static fn` for closures that do not use `$this`; constructor property promotion; explicit return types and parameter types.
- UI copy, warnings and summary lines are plain English for a customer audience. No em dashes anywhere (copy, comments, commit messages, this plan's code). Say "Allocated", never "placed".
- Before each commit run `vendor/bin/pint --dirty`, then `git diff` and revert any whitespace-only hunk outside the lines you changed.
- Stage only the paths the task lists. Never `git add -A`, `git add .` or `git add docs/`: untracked customer exports sit in `docs/`.
- Tests that read real customer data (`~/pso-data/...`) skip themselves when the files are absent and never copy them into the repo.
- The known failing `ExampleTest` is ignored. Do not open, curl or drive the app in a browser; Ish checks the UI himself.
- Every commit message ends with these two lines:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
  ```

## Settled departures from the spec's wording

1. **Input kinds.** The spec says the tool declares `requiredKinds()` as LOAD with plan and EOD optional. With LOAD required, `StartToolRun` and `RunToolJob` refuse a run on a plan or EOD alone, and the catalogue would say "Needs a LOAD export". The tool therefore declares `requiredKinds() = []`, `optionalKinds() = [Load, Plan, Eod]`, and a new contract `TakesOneInput::oneOfKinds()`; `StartToolRun::start()` refuses none or more than one (the spec's rule), and the wizard shows one picker for the chosen kind.
2. **XML reading.** The spec names XMLReader for pass one and XMLWriter for the writer. XMLReader cannot hand back a row's raw bytes and XMLWriter re-serialises, so neither can give the byte-verbatim copy the spec requires. Both passes use the byte scanner of `XmlScheduleDataRewriter` (so both passes see exactly the same rows) and the writer writes raw bytes.
3. **Zip output.** "Same format as the input": a zipped input gives a zip holding one member, `<member name>.trimmed.<xml|json>`, stored as `<input name>.trimmed.zip`.
4. **Old summaries.** A file summarised before this change has no `table_counts`; the streamed scan that fills the list counts rows anyway, so the list shows counts there too (cached, like the filter choices).
5. **Export download.** There is no export download route today. Task 10 adds `exports.download` (owner only, through the ownership scope; never for a SYS export, which holds live credentials) and a Download action on the Exports table, and the run page links to it.
6. **Golden numbers.** The spec quotes "Location 3928 to 1362" with region V1; that figure came from the #26 check with 20 resources, not V1 (issue #26 comment). The golden test pins 85 resources and 8 activities (the #42 commit), Location before 3,928 and every kept location reference resolving, not 1,362.

## Review Focus

1. **The picker preselects a LOAD, a plan and an EOD of the same day** (the `RunInputPicker` default): the run must carry only the kind chosen under "Export to trim", never all three, or `start()` refuses it. (Task 8 test "sends only the chosen kind even when a LOAD, plan and End of day are all preselected".)
2. **A 6.15+ JSON export with native number ids** (`"id": 14420`) filtered by ids the picker returns as strings (`"14420"`): the row must be kept exactly as the XML twin's would be. (Task 5 test "matches number ids in a JSON export to the string ids the filters carry".)
3. **An Activity_Status of a kept activity fixed to a resource outside the filter:** the status row stays (an activity without its status breaks the LOAD) and is listed as dangling, as Filter Load File did; it is not swept away by the generic resource cascade. (Task 6 test "keeps the status of a kept activity fixed to a removed resource and lists it as dangling".)
4. **The first row of a JSON table, or the first table of the wrapper, is dropped while later ones stay:** the output must still be valid JSON with no leading comma. (Task 4 test "writes valid JSON when the first row or the first table is dropped".)
5. **A zipped input, and a zip whose name says `.xml`:** the output is a zip again with one trimmed member, and the stored export's content type follows the bytes, not the name. (Task 7 test "gives a zip back for a zip, reading a zip named .xml as a zip".)

---

## File structure

Create:
- `app/Support/TrimExport/TableDependencies.php` the Remove-PsoTables lists (cleanup preset, `$DependsOn`) and the dependency warnings.
- `app/Support/TrimExport/TrimPlan.php` the resolved request.
- `app/Support/TrimExport/Segment.php` one piece of a file (head, table open, row, table close, tail).
- `app/Support/TrimExport/Readers/TableReader.php`, `XmlTableReader.php`, `JsonTableReader.php`.
- `app/Support/TrimExport/Writers/OutputFile.php`, `TableWriter.php`, `XmlTableWriter.php`, `JsonTableWriter.php`.
- `app/Support/TrimExport/ExportSource.php`, `ExportSourceFile.php` open a scratch copy (a zip's largest member).
- `app/Support/TrimExport/KeyCollector.php` (pass one), `KeepSets.php`.
- `app/Support/TrimExport/ReferenceTally.php`, `TrimReport.php`, `TrimEngine.php` (pass two).
- `app/Support/TrimExport/TrimmedExportStore.php` files the output as a new export.
- `app/Support/TrimExport/TableCounter.php`, `TableChoices.php`, `FilterChoices.php`, `ExportFileScanner.php` (wizard data).
- `app/Support/Exports/ExportFormat.php` format sniffing and zip member extraction, moved out of `ExportSummariser`.
- `app/Tools/Contracts/TakesOneInput.php`, `app/Tools/TrimExport/TrimExportTool.php`.
- `app/Filament/Pages/Tools/TrimExportEntry.php`, `app/Filament/Pages/Tools/TrimExport/TrimExportFields.php`.
- `app/Http/Controllers/Exports/ExportFileDownloadController.php`.
- `resources/views/filament/tool-runs/trim_export.blade.php`.
- Tests: `tests/Support/TrimExportFixtures.php`, `tests/Unit/TrimExport/*`, `tests/Feature/TrimExport/*`, `tests/Feature/ExportFileDownloadTest.php`.

Modify:
- `app/Enums/Tool.php`, `app/Providers/AppServiceProvider.php`
- `app/Support/ScheduleDataSummaryExtractor.php`, `app/Support/Exports/ExportSummariser.php`, `app/Support/Exports/ExportStorage.php`
- `app/Support/Tools/StartToolRun.php`, `app/Support/Tools/UnusableInputException.php`
- `app/Filament/Pages/Tools/ToolWizardPage.php` (four small hooks, `timeZoneField()` protected)
- `app/Filament/Tables/ExportFilesTable.php`, `app/Filament/Widgets/ToolCatalogue.php`, `routes/web.php`, `config/filesystems.php`, `README.md`
- `tests/Pest.php`, `tests/Unit/ToolAcceptsUnfiledTest.php`, `tests/Feature/NavigationGroupsTest.php`, `tests/Feature/ExportSummariserTest.php`, `tests/Unit/ScheduleDataSummaryExtractorTest.php`, `tests/Feature/ExportStorageTest.php`

Delete (Task 12): `app/Filament/Pages/FilterLoadFile.php`, `resources/views/filament/pages/filter-load-file.blade.php`, `app/Jobs/ProcessResourceFile.php`, `app/Jobs/DeleteTemporaryFiles.php`, `app/Services/ResourceActivityFilterService.php`, `app/Support/PreviewSummaryFormatter.php`, `app/Support/HasScopedCache.php`, and the tests `tests/Feature/FilamentJobMonitoringTest.php`, `FilteredDownloadTest.php`, `FilterLoadFileDanglingWarningTest.php`, `FilterLoadFileResourceScopingTest.php`, `ProcessResourceFileCancellationTest.php`, `ResourceActivityFilterCascadeTest.php`.

---

### Task 1: The tool id, the table lists, the request, and a registered stub

**Files:**
- Modify: `app/Enums/Tool.php` (new case `TrimExport`, its label, question, icon, `acceptsUnfiled()`)
- Create: `app/Support/TrimExport/TableDependencies.php`
- Create: `app/Support/TrimExport/TrimPlan.php`
- Create: `app/Tools/TrimExport/TrimExportTool.php` (stub; Task 7 fills `run()`)
- Modify: `app/Providers/AppServiceProvider.php:32-37` (register it)
- Create: `tests/Unit/TrimExport/TableDependenciesTest.php`, `tests/Unit/TrimExport/TrimPlanTest.php`, `tests/Feature/TrimExport/TrimExportRegistrationTest.php`
- Modify: `tests/Unit/ToolAcceptsUnfiledTest.php`

**Interfaces:**
- Consumes: `App\Tools\ToolRegistry::register()`, `App\Tools\Contracts\Tool`.
- Produces:
  - `App\Enums\Tool::TrimExport` (value `trim_export`, label `Trim export`, `acceptsUnfiled()` true, `scope()` Dataset).
  - `TableDependencies::CLEANUP_TABLES` (list<string>, 27 names), `TableDependencies::DEPENDS_ON` (array<string, list<string>>, the script's 26 entries), `TableDependencies::INPUT_REFERENCE = 'Input_Reference'`.
  - `TableDependencies::cleanupPresent(array $presentTables): array` (list<string>, in CLEANUP_TABLES order).
  - `TableDependencies::warnings(array $presentTables, array $droppedTables): array` (list<string>, sorted).
  - `TrimPlan` with public properties `string $mode`, `array $tables`, `bool $allowInputReferenceRemoval`, `array $regionIds`, `array $resourceIds`, `array $activityTypeIds`, `array $activityIds`, `?int $windowStart`, `?int $windowEnd` (UTC seconds), `?string $inputReferenceDatetime` (`Y-m-d\TH:i:s`, UTC), `bool $countOnly`; constants `MODE_REMOVE = 'remove'`, `MODE_KEEP = 'keep'`.
  - `TrimPlan::fromOptions(array $options, string $timeZone): TrimPlan` reading the option keys `table_mode`, `tables`, `allow_input_reference_removal`, `region_ids`, `resource_ids`, `activity_type_ids`, `activity_ids`, `window_start`, `window_end` (`Y-m-d`), `input_reference_datetime`, `count_only`. Any other key (`preset`, `time_zone`) is ignored.
  - `TrimPlan::dropsTable(string $table): bool`, `TrimPlan::droppedTables(array $presentTables): array`, `TrimPlan::filtersRows(): bool`.
  - `App\Tools\TrimExport\TrimExportTool` registered for `Tool::TrimExport`, `requiredKinds() = []`, `optionalKinds() = [Load, Plan, Eod]`, `optionFields() = []`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TrimExport/TableDependenciesTest.php`:

```php
<?php

use App\Support\TrimExport\TableDependencies;

it('copies the Remove-PsoTables cleanup list verbatim', function () {
    expect(TableDependencies::CLEANUP_TABLES)->toBe([
        'Plan', 'Plan_Resource', 'Plan_Route', 'Plan_Travel', 'Plan_Break', 'Allocation', 'Allocation_Data',
        'Schedule_Event', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Exception_Response',
        'Appointment_Request', 'Appointment_Offer', 'Appointment_Offer_Response', 'Appointment_Summary',
        'Explanation_Request', 'Explanation_Request_Change', 'Broadcast', 'Broadcast_Parameter',
        'Additional_Attribute', 'Activity_Custom_URL', 'Resource_Custom_URL', 'URL_String', 'URL_Parameter',
        'Object_Group', 'Source_Data', 'Source_Data_Parameter',
    ])->and(TableDependencies::DEPENDS_ON['Allocation'])->toBe(['Activity', 'Resources', 'Plan'])
        ->and(TableDependencies::DEPENDS_ON['Resource_Region_Availability'])->toBe(['Resource_Region'])
        ->and(TableDependencies::DEPENDS_ON)->toHaveCount(26);
});

it('ticks only the cleanup tables that are in the file', function () {
    expect(TableDependencies::cleanupPresent(['Activity', 'Plan_Travel', 'Input_Reference', 'Plan', 'Broadcast']))
        ->toBe(['Plan', 'Plan_Travel', 'Broadcast']);
});

it('warns when a kept table loses a parent that was in the file', function () {
    $present = ['Activity', 'Activity_Status', 'Activity_SLA', 'Allocation', 'Plan', 'Resources', 'Region', 'Input_Reference'];

    expect(TableDependencies::warnings($present, ['Activity', 'Plan']))->toBe([
        'Activity_SLA is kept but Activity is removed, so its rows will point at nothing.',
        'Activity_Status is kept but Activity is removed, so its rows will point at nothing.',
        'Allocation is kept but Activity and Plan are removed, so its rows will point at nothing.',
    ]);
});

it('says nothing about a parent the file never had, or a child that goes too', function () {
    // Resource_Region depends on Resources and Region; Region is not in the file.
    expect(TableDependencies::warnings(['Resource_Region', 'Resources'], []))->toBe([])
        ->and(TableDependencies::warnings(['Activity', 'Activity_Status'], ['Activity', 'Activity_Status']))->toBe([]);
});
```

Create `tests/Unit/TrimExport/TrimPlanTest.php`:

```php
<?php

use App\Support\TrimExport\TrimPlan;

it('removes the ticked tables in Remove mode and keeps only them in Keep mode', function () {
    $remove = TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Plan', 'Plan_Travel']], 'UTC');
    $keep = TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity']], 'UTC');
    $present = ['Input_Reference', 'Activity', 'Plan', 'Plan_Travel'];

    expect($remove->droppedTables($present))->toBe(['Plan', 'Plan_Travel'])
        ->and($keep->droppedTables($present))->toBe(['Plan', 'Plan_Travel'])
        ->and($keep->dropsTable('Activity'))->toBeFalse()
        ->and(TrimPlan::fromOptions([], 'UTC')->mode)->toBe(TrimPlan::MODE_REMOVE);
});

it('keeps Input_Reference in both modes until removal is allowed', function () {
    expect(TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Input_Reference']], 'UTC')->dropsTable('Input_Reference'))->toBeFalse()
        ->and(TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity']], 'UTC')->dropsTable('Input_Reference'))->toBeFalse()
        ->and(TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => ['Input_Reference'], 'allow_input_reference_removal' => true], 'UTC')->dropsTable('Input_Reference'))->toBeTrue()
        ->and(TrimPlan::fromOptions(['table_mode' => 'keep', 'tables' => ['Activity'], 'allow_input_reference_removal' => true], 'UTC')->dropsTable('Input_Reference'))->toBeTrue();
});

it('filters rows only when a filter is set, and carries every id as a string', function () {
    expect(TrimPlan::fromOptions(['tables' => ['Plan']], 'UTC')->filtersRows())->toBeFalse();

    $plan = TrimPlan::fromOptions(['region_ids' => ['V1', '', null], 'activity_ids' => [14420, '14420', 'A2'], 'resource_ids' => 'not a list'], 'UTC');

    expect($plan->filtersRows())->toBeTrue()
        ->and($plan->regionIds)->toBe(['V1'])
        ->and($plan->activityIds)->toBe(['14420', 'A2'])
        ->and($plan->resourceIds)->toBe([]);
});

it('takes the window as whole days in the run zone, both days or neither', function () {
    $plan = TrimPlan::fromOptions(['window_start' => '2026-10-05', 'window_end' => '2026-10-09'], 'America/Regina');

    expect($plan->windowStart)->toBe((new DateTimeImmutable('2026-10-05 06:00:00', new DateTimeZone('UTC')))->getTimestamp())
        ->and($plan->windowEnd)->toBe((new DateTimeImmutable('2026-10-10 05:59:59', new DateTimeZone('UTC')))->getTimestamp())
        ->and($plan->filtersRows())->toBeTrue()
        ->and(TrimPlan::fromOptions(['window_start' => '2026-10-05'], 'UTC')->windowStart)->toBeNull()
        ->and(TrimPlan::fromOptions(['window_start' => '2026-10-09', 'window_end' => '2026-10-05'], 'UTC')->windowStart)->toBeNull();
});

it('writes the override the way PSO writes times, in UTC with no offset', function () {
    expect(TrimPlan::fromOptions(['input_reference_datetime' => '2026-10-04 08:15'], 'America/Regina')->inputReferenceDatetime)->toBe('2026-10-04T08:15:00')
        ->and(TrimPlan::fromOptions(['input_reference_datetime' => 'not a time'], 'UTC')->inputReferenceDatetime)->toBeNull()
        ->and(TrimPlan::fromOptions(['count_only' => true], 'UTC')->countOnly)->toBeTrue();
});
```

Create `tests/Feature/TrimExport/TrimExportRegistrationTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Tools\ToolRegistry;
use App\Tools\TrimExport\TrimExportTool;
use Filament\Support\Icons\Heroicon;

it('registers Trim export, taking one LOAD, plan or End of day export', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::TrimExport);

    expect($tool)->toBeInstanceOf(TrimExportTool::class)
        ->and($tool->requiredKinds())->toBe([])
        ->and($tool->optionalKinds())->toBe([ExportKind::Load, ExportKind::Plan, ExportKind::Eod])
        ->and($tool->optionFields())->toBe([])
        ->and(ToolId::TrimExport->getLabel())->toBe('Trim export')
        ->and(ToolId::TrimExport->question())->toBe('Can I cut this export down to the tables and rows I need?')
        ->and(ToolId::TrimExport->icon())->toBe(Heroicon::OutlinedScissors)
        ->and(ToolId::TrimExport->scope())->toBe(Dataset::class)
        ->and(ToolId::TrimExport->acceptsUnfiled())->toBeTrue();
});
```

In `tests/Unit/ToolAcceptsUnfiledTest.php` replace the expectation with:

```php
    expect($accepting)->toBe([Tool::LoadAudit, Tool::AppointmentTimeline, Tool::PlanTravels, Tool::TrimExport]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport tests/Feature/TrimExport tests/Unit/ToolAcceptsUnfiledTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\TableDependencies" not found` and `Undefined constant App\Enums\Tool::TrimExport`.

- [ ] **Step 3: Add the tool id**

In `app/Enums/Tool.php` add the case after `SysCompare`:

```php
    case TrimExport = 'trim_export';
```

Change `acceptsUnfiled()` to:

```php
    /** Tools that only read the files: they may run Unfiled, with no environment or dataset. */
    public function acceptsUnfiled(): bool
    {
        return in_array($this, [self::LoadAudit, self::AppointmentTimeline, self::PlanTravels, self::TrimExport], true);
    }
```

Add one arm to each match: in `question()` `self::TrimExport => 'Can I cut this export down to the tables and rows I need?',`; in `getLabel()` `self::TrimExport => 'Trim export',`; in `icon()` `self::TrimExport => Heroicon::OutlinedScissors,`.

- [ ] **Step 4: Write the table lists**

Create `app/Support/TrimExport/TableDependencies.php`:

```php
<?php

namespace App\Support\TrimExport;

/**
 * The table lists of pso-tools scripts/Remove-PsoTables.ps1, copied verbatim: the tables the Cleanup for
 * input preset removes ($CleanupTables) and the tables a table's rows point at ($DependsOn), which drive
 * the "kept a table without its parent" warnings. Keep both in step with the script.
 */
class TableDependencies
{
    public const string INPUT_REFERENCE = 'Input_Reference';

    /** @var list<string> */
    public const array CLEANUP_TABLES = [
        'Plan', 'Plan_Resource', 'Plan_Route', 'Plan_Travel', 'Plan_Break', 'Allocation', 'Allocation_Data',
        'Schedule_Event', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Exception_Response',
        'Appointment_Request', 'Appointment_Offer', 'Appointment_Offer_Response', 'Appointment_Summary',
        'Explanation_Request', 'Explanation_Request_Change', 'Broadcast', 'Broadcast_Parameter',
        'Additional_Attribute', 'Activity_Custom_URL', 'Resource_Custom_URL', 'URL_String', 'URL_Parameter',
        'Object_Group', 'Source_Data', 'Source_Data_Parameter',
    ];

    /** @var array<string, list<string>> child table => the tables its rows point at */
    public const array DEPENDS_ON = [
        'Activity_Status' => ['Activity'], 'Activity_SLA' => ['Activity'], 'Activity_Skill' => ['Activity'], 'Activity_Custom_URL' => ['Activity'],
        'Additional_Attribute' => ['Activity', 'Resources'], 'Allocation' => ['Activity', 'Resources', 'Plan'], 'Allocation_Data' => ['Allocation'],
        'Plan_Route' => ['Plan', 'Resources'], 'Plan_Travel' => ['Plan', 'Resources'], 'Plan_Break' => ['Plan', 'Resources'], 'Plan_Resource' => ['Plan', 'Resources'],
        'Shift' => ['Resources'], 'Shift_Break' => ['Shift'], 'Resource_Skill' => ['Resources'], 'Resource_Region' => ['Resources', 'Region'],
        'Resource_Region_Availability' => ['Resource_Region'], 'Resource_Skill_Availability' => ['Resource_Skill'], 'Availability' => ['Resources'],
        'Location_Region' => ['Location', 'Region'], 'Schedule_Exception_Data' => ['Schedule_Exception'], 'Broadcast_Parameter' => ['Broadcast'],
        'URL_Parameter' => ['URL_String'], 'Source_Data_Parameter' => ['Source_Data'],
        'Appointment_Offer' => ['Appointment_Request'], 'Appointment_Offer_Response' => ['Appointment_Request'], 'Appointment_Summary' => ['Appointment_Request'],
    ];

    /**
     * @param  list<string>  $presentTables
     * @return list<string> the cleanup tables that are in the file, in the script's order
     */
    public static function cleanupPresent(array $presentTables): array
    {
        return array_values(array_intersect(self::CLEANUP_TABLES, $presentTables));
    }

    /**
     * One warning per kept table whose parent (a table the file has) is removed, as the script warns.
     *
     * @param  list<string>  $presentTables  tables in the file
     * @param  list<string>  $droppedTables  tables the run removes
     * @return list<string>
     */
    public static function warnings(array $presentTables, array $droppedTables): array
    {
        $present = array_flip($presentTables);
        $dropped = array_flip($droppedTables);
        $warnings = [];

        foreach (self::DEPENDS_ON as $child => $parents) {
            if (! isset($present[$child]) || isset($dropped[$child])) {
                continue;
            }

            $lost = array_values(array_filter($parents, static fn (string $parent): bool => isset($present[$parent], $dropped[$parent])));
            if ($lost === []) {
                continue;
            }

            $warnings[] = $child.' is kept but '.implode(' and ', $lost).(count($lost) === 1 ? ' is' : ' are').' removed, so its rows will point at nothing.';
        }

        sort($warnings, SORT_STRING);

        return $warnings;
    }
}
```

- [ ] **Step 5: Write the request**

Create `app/Support/TrimExport/TrimPlan.php`:

```php
<?php

namespace App\Support\TrimExport;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One Trim export request, resolved from the run's options: which tables go (Remove ticked or Keep ticked,
 * Input_Reference locked unless allowed), the row filters (ids as strings, the appointment window as UTC
 * seconds), the Input_Reference datetime override and Count only.
 */
class TrimPlan
{
    public const string MODE_REMOVE = 'remove';

    public const string MODE_KEEP = 'keep';

    /**
     * @param  list<string>  $tables  the ticked tables
     * @param  list<string>  $regionIds
     * @param  list<string>  $resourceIds
     * @param  list<string>  $activityTypeIds
     * @param  list<string>  $activityIds
     */
    public function __construct(
        public string $mode = self::MODE_REMOVE,
        public array $tables = [],
        public bool $allowInputReferenceRemoval = false,
        public array $regionIds = [],
        public array $resourceIds = [],
        public array $activityTypeIds = [],
        public array $activityIds = [],
        public ?int $windowStart = null,
        public ?int $windowEnd = null,
        public ?string $inputReferenceDatetime = null,
        public bool $countOnly = false,
    ) {}

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options, string $timeZone): self
    {
        [$windowStart, $windowEnd] = self::window($options['window_start'] ?? null, $options['window_end'] ?? null, $timeZone);

        return new self(
            mode: ($options['table_mode'] ?? null) === self::MODE_KEEP ? self::MODE_KEEP : self::MODE_REMOVE,
            tables: self::strings($options['tables'] ?? []),
            allowInputReferenceRemoval: (bool) ($options['allow_input_reference_removal'] ?? false),
            regionIds: self::strings($options['region_ids'] ?? []),
            resourceIds: self::strings($options['resource_ids'] ?? []),
            activityTypeIds: self::strings($options['activity_type_ids'] ?? []),
            activityIds: self::strings($options['activity_ids'] ?? []),
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            inputReferenceDatetime: self::override($options['input_reference_datetime'] ?? null),
            countOnly: (bool) ($options['count_only'] ?? false),
        );
    }

    public function dropsTable(string $table): bool
    {
        if ($table === TableDependencies::INPUT_REFERENCE && ! $this->allowInputReferenceRemoval) {
            return false;
        }

        $ticked = in_array($table, $this->tables, true);

        return $this->mode === self::MODE_KEEP ? ! $ticked : $ticked;
    }

    /**
     * @param  list<string>  $presentTables
     * @return list<string>
     */
    public function droppedTables(array $presentTables): array
    {
        return array_values(array_filter($presentTables, fn (string $table): bool => $this->dropsTable($table)));
    }

    /** Leaving every filter empty means rows are untouched: the run is tables only. */
    public function filtersRows(): bool
    {
        return $this->regionIds !== [] || $this->resourceIds !== [] || $this->activityTypeIds !== []
            || $this->activityIds !== [] || $this->windowStart !== null;
    }

    /** @return list<string> unique, non-empty, as strings (a JSON export may carry number ids) */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $strings[] = trim((string) $value);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Both days or neither, as Filter Load File did: the start of the first day to the end of the last, in the run's zone.
     *
     * @return array{0: ?int, 1: ?int} UTC seconds
     */
    private static function window(mixed $start, mixed $end, string $timeZone): array
    {
        if (! is_string($start) || ! is_string($end) || trim($start) === '' || trim($end) === '') {
            return [null, null];
        }

        try {
            $zone = new DateTimeZone($timeZone);
            $from = new DateTimeImmutable(substr(trim($start), 0, 10).' 00:00:00', $zone);
            $to = new DateTimeImmutable(substr(trim($end), 0, 10).' 23:59:59', $zone);
        } catch (Throwable) {
            return [null, null];
        }

        return $from <= $to ? [$from->getTimestamp(), $to->getTimestamp()] : [null, null];
    }

    /** The picker's value is UTC (its label says so); PSO writes offsetless UTC times. */
    private static function override(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 6: Write the stub tool and register it**

Create `app/Tools/TrimExport/TrimExportTool.php`:

```php
<?php

namespace App\Tools\TrimExport;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Trim export: one LOAD, plan or End of day export in, a trimmed copy filed next to it out. Its options sit on
 * two wizard steps (Tables, Rows) built by TrimExportEntry, because they depend on the chosen file.
 */
class TrimExportTool implements Tool
{
    public static function id(): ToolId
    {
        return ToolId::TrimExport;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];
    }

    public function optionFields(): array
    {
        return [];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        throw new RuntimeException('Trim export is not ready yet.');
    }
}
```

In `app/Providers/AppServiceProvider.php` add `use App\Tools\TrimExport\TrimExportTool;` and, after the `PlanTravels` line in the `afterResolving` closure:

```php
            $registry->register(ToolId::TrimExport, TrimExportTool::class);
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport tests/Feature/TrimExport tests/Unit/ToolAcceptsUnfiledTest.php tests/Unit/ToolIconTest.php tests/Unit/ToolQuestionTest.php tests/Feature/DashboardTest.php`
Expected: PASS. (The catalogue skips Trim export until Task 10 adds its wizard page to `wizardPageFor()`.)

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Enums/Tool.php app/Support/TrimExport/TableDependencies.php app/Support/TrimExport/TrimPlan.php app/Tools/TrimExport/TrimExportTool.php app/Providers/AppServiceProvider.php tests/Unit/TrimExport/TableDependenciesTest.php tests/Unit/TrimExport/TrimPlanTest.php tests/Feature/TrimExport/TrimExportRegistrationTest.php tests/Unit/ToolAcceptsUnfiledTest.php
git commit -m "$(cat <<'EOF'
Trim export: tool id, Remove-PsoTables table lists, the request object and a registered stub (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 2: Table counts in the export summary

**Files:**
- Modify: `app/Support/ScheduleDataSummaryExtractor.php` (count rows per depth-1 element or wrapper member)
- Modify: `app/Support/Exports/ExportSummariser.php:75-90` (store `table_counts` in both schedule summaries)
- Modify: `tests/Unit/ScheduleDataSummaryExtractorTest.php`, `tests/Feature/ExportSummariserTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `ScheduleDataSummaryExtractor::extract()` returns a fifth key `table_counts` (array<string, int>, table name => rows, in file order; `[]` for a file it could not read). A LOAD, plan or EOD `ExportFile.summary` written from now on carries `table_counts`; one written before has no such key (Task 8 falls back to a scan).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/ScheduleDataSummaryExtractorTest.php`:

```php
it('counts the rows of every table, xml rows being the elements just inside the root', function () {
    $path = scheduleDataSummaryFixturePath('summary-tables.xml');
    file_put_contents($path, <<<'XML'
        <dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">
            <Input_Reference><datetime>2026-09-10T12:26:05Z</datetime><dataset_id>DCU</dataset_id></Input_Reference>
            <Activity><id>A1</id><Activity_Status><id>nested, not a row</id></Activity_Status></Activity>
            <Activity><id>A2</id></Activity>
            <Plan_Travel><resource_id>R1</resource_id></Plan_Travel>
        </dsScheduleData>
        XML);

    expect(ScheduleDataSummaryExtractor::extract($path, 'xml')['table_counts'])
        ->toBe(['Input_Reference' => 1, 'Activity' => 2, 'Plan_Travel' => 1]);
});

it('counts json tables whether they are a list, one row object, or empty', function () {
    $path = scheduleDataSummaryFixturePath('summary-tables.json');
    file_put_contents($path, json_encode(['dsScheduleData' => [
        'Input_Reference' => ['datetime' => '2026-09-10T12:26:05Z', 'dataset_id' => 'DCU'],
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
        'Resources' => [],
    ]]));

    expect(ScheduleDataSummaryExtractor::extract($path, 'json')['table_counts'])
        ->toBe(['Input_Reference' => 1, 'Activity' => 3, 'Resources' => 0]);
});

it('reports no tables for a file it cannot read', function () {
    $path = scheduleDataSummaryFixturePath('summary-broken.json');
    file_put_contents($path, '{"something": "else"}');

    expect(ScheduleDataSummaryExtractor::extract($path, 'json')['table_counts'])->toBe([]);
});
```

Append to `tests/Feature/ExportSummariserTest.php`:

```php
it('stores the row count of every table for a LOAD and a plan export', function () {
    $load = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'xml'), scheduleXml(activities: 3, resources: 2), 'UTC')->toArray();
    $plan = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Plan, 'xml'), planXml(allocations: 2), 'UTC')->toArray();

    expect($load['table_counts'])->toBe(['Input_Reference' => 1, 'Activity' => 3, 'Resources' => 2])
        ->and($plan['table_counts'])->toBe(['Allocation_Data' => 2, 'Allocation' => 2, 'Plan' => 1]);
});

it('stores table counts for a json LOAD', function () {
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.json';
    file_put_contents($path, json_encode(['dsScheduleData' => [
        'Input_Reference' => ['datetime' => '2026-10-10T07:12:00', 'dataset_id' => 'NORTH'],
        'Activity' => [['id' => 'A1'], ['id' => 'A2']],
        'Resources' => [],
    ]]));

    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'json'), $path, 'UTC')->toArray();

    expect($summary['table_counts'])->toBe(['Input_Reference' => 1, 'Activity' => 2, 'Resources' => 0]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ScheduleDataSummaryExtractorTest.php tests/Feature/ExportSummariserTest.php`
Expected: FAIL with `Undefined array key "table_counts"`.

- [ ] **Step 3: Count tables in the extractor**

In `app/Support/ScheduleDataSummaryExtractor.php`:

Change the `@return` of `extract()` to `array{dataset_id: ?string, input_reference_datetime: ?CarbonImmutable, activity_count: int, resource_count: int, table_counts: array<string, int>}`.

In `extractFromXml()`, declare `$tableCounts = [];` next to `$resourceCount = 0;`, and directly after the `if ($reader->nodeType !== XMLReader::ELEMENT) { continue; }` block add:

```php
                // A table row is an element just inside the root; nested elements of the same name are not rows.
                if ($reader->depth === 1) {
                    $tableCounts[$reader->localName] = ($tableCounts[$reader->localName] ?? 0) + 1;
                }
```

and add `'table_counts' => $tableCounts,` to its return array.

In `extractFromJson()`, declare `$tableCounts = [];` next to `$resourceCount = 0;`. Replace the depth-2 and depth-3 tally branches with:

```php
                    } elseif ($insideWrapper && $depth === 2 && $entityName !== null) {
                        $entityIsArray = $token === '[';

                        if ($entityIsArray) {
                            // An empty list is a table with no rows.
                            $tableCounts[$entityName] ??= 0;
                        } else {
                            self::tally($entityName, $activityCount, $resourceCount, $tableCounts);
                        }
                    } elseif ($insideWrapper && $depth === 3 && $entityIsArray && $token === '{') {
                        self::tally($entityName, $activityCount, $resourceCount, $tableCounts);
                    }
```

and add `'table_counts' => $tableCounts,` to its final return array. Replace `tally()` with:

```php
    /** @param array<string, int> $tableCounts */
    private static function tally(?string $entityName, int &$activityCount, int &$resourceCount, array &$tableCounts): void
    {
        if ($entityName === null) {
            return;
        }

        $tableCounts[$entityName] = ($tableCounts[$entityName] ?? 0) + 1;

        match ($entityName) {
            'Activity' => $activityCount++,
            'Resources' => $resourceCount++,
            default => null,
        };
    }
```

In `empty()` add `'table_counts' => [],` and add `table_counts: array{}` to its `@return` shape.

- [ ] **Step 4: Store the counts on the summary**

In `app/Support/Exports/ExportSummariser.php::summariseSchedule()`, add `'table_counts' => $extracted['table_counts'],` as the last key of both `new ExportSummary([...])` arrays (the plan-export branch and the LOAD/EOD branch).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ScheduleDataSummaryExtractorTest.php tests/Feature/ExportSummariserTest.php tests/Feature/SummariseExportFileJobTest.php tests/Feature/ActivityTrace`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/ScheduleDataSummaryExtractor.php app/Support/Exports/ExportSummariser.php tests/Unit/ScheduleDataSummaryExtractorTest.php tests/Feature/ExportSummariserTest.php
git commit -m "$(cat <<'EOF'
Export summary: keep the row count of every table (table_counts) for LOAD, plan and EOD exports (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 3: Readers that cut a file into segments, and opening a zipped export

**Files:**
- Create: `app/Support/Exports/ExportFormat.php` (moved out of `ExportSummariser`: `sniffFormat`, `isReadableMember`, `largestMember`)
- Modify: `app/Support/Exports/ExportSummariser.php` (call `ExportFormat`, delete the three private methods)
- Create: `app/Support/TrimExport/Segment.php`
- Create: `app/Support/TrimExport/Readers/TableReader.php`, `XmlTableReader.php`, `JsonTableReader.php`
- Create: `app/Support/TrimExport/ExportSource.php`, `app/Support/TrimExport/ExportSourceFile.php`
- Create: `tests/Support/TrimExportFixtures.php`; Modify: `tests/Pest.php` (require it)
- Create: `tests/Unit/TrimExport/XmlTableReaderTest.php`, `tests/Unit/TrimExport/JsonTableReaderTest.php`, `tests/Unit/TrimExport/ExportSourceTest.php`

**Interfaces:**
- Consumes: `App\Support\ScheduleData\JsonTokenReader::tokens($handle)`, `App\Support\Exports\InvalidExportException`, config `exports.max_member_megabytes`.
- Produces:
  - `ExportFormat::sniff(string $path): ?string` (`zip`, `json`, `xml` or null), `ExportFormat::isReadableMember(string $name, ?string $only = null): bool`, `ExportFormat::largestMember(string $zipPath): array{0: string, 1: string, 2: string}` (copy path next to the zip, format by extension, member name).
  - `Segment` with constants `HEAD`, `TABLE_OPEN`, `ROW`, `TABLE_CLOSE`, `TAIL` and public `string $type`, `string $table`, `string $prefix`, `string $body`, `int $offset`, `bool $singleObject`, `bool $scalar`. Concatenating `prefix.body` of every segment gives the file back exactly.
  - `TableReader` interface: `segments(string $path): Generator<int, Segment>`, `fieldsOf(string $body, array $names): array<string, string>` (non-empty values only, trimmed strings), `replaceField(string $body, string $field, string $value): string`.
  - `XmlTableReader` (never yields TABLE_OPEN or TABLE_CLOSE) and `JsonTableReader` (constructor takes `JsonTokenReader`).
  - `ExportSource::open(string $localPath): ExportSourceFile`; `ExportSourceFile` with `string $path`, `string $format` (`xml` or `json`), `?string $memberName`, `zipped(): bool`.
  - Test helpers `trimFixtureFile(string $contents, string $extension = 'xml'): string`, `joinSegments(iterable $segments): string`, `segmentShape(iterable $segments): array`.

- [ ] **Step 1: Write the test helpers**

Create `tests/Support/TrimExportFixtures.php`:

```php
<?php

use App\Support\TrimExport\Segment;

/** Writes $contents to a temporary file and returns its path. */
function trimFixtureFile(string $contents, string $extension = 'xml'): string
{
    $path = tempnam(sys_get_temp_dir(), 'trim_').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @param iterable<Segment> $segments */
function joinSegments(iterable $segments): string
{
    $bytes = '';
    foreach ($segments as $segment) {
        $bytes .= $segment->prefix.$segment->body;
    }

    return $bytes;
}

/**
 * @param  iterable<Segment>  $segments
 * @return list<string> "type table" for each segment
 */
function segmentShape(iterable $segments): array
{
    $shape = [];
    foreach ($segments as $segment) {
        $shape[] = trim($segment->type.' '.$segment->table);
    }

    return $shape;
}
```

In `tests/Pest.php`, below `require_once __DIR__.'/Support/ExportFixtures.php';`, add:

```php
require_once __DIR__.'/Support/TrimExportFixtures.php';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/TrimExport/XmlTableReaderTest.php`:

```php
<?php

use App\Support\TrimExport\Readers\XmlTableReader;

it('cuts an export into head, rows and tail and gives it back byte for byte', function () {
    $xml = "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<!-- exported -->\r\n<ns:dsScheduleData xmlns:ns=\"http://360Scheduling.com/Schema/dsScheduleData.xsd\">\r\n"
        ."  <ns:Input_Reference><ns:datetime>2026-10-04T08:00:00</ns:datetime></ns:Input_Reference>\r\n"
        ."  <ns:Activity note=\"a > b\"><ns:id>A1</ns:id><ns:description><![CDATA[<b>keep</b>]]></ns:description></ns:Activity>\r\n"
        ."  <ns:Activity/>\r\n"
        ."  <!-- between rows -->\r\n"
        ."  <ns:Plan><ns:id>P1</ns:id></ns:Plan>\r\n"
        ."</ns:dsScheduleData>\r\n";

    $segments = iterator_to_array(app(XmlTableReader::class)->segments(trimFixtureFile($xml)), false);

    expect(joinSegments($segments))->toBe($xml)
        ->and(segmentShape($segments))->toBe(['head', 'row Input_Reference', 'row Activity', 'row Activity', 'row Plan', 'tail'])
        ->and($segments[0]->body)->toEndWith('dsScheduleData.xsd">')
        ->and($segments[2]->prefix)->toBe("\r\n  ")
        ->and($segments[2]->body)->toStartWith('<ns:Activity note="a > b">')->toEndWith('</ns:Activity>')
        ->and($segments[3]->body)->toBe('<ns:Activity/>')
        ->and($segments[4]->prefix)->toBe("\r\n  <!-- between rows -->\r\n  ")
        ->and($segments[5]->body)->toBe("\r\n</ns:dsScheduleData>\r\n")
        ->and($segments[5]->offset)->toBe(strlen($xml));
});

it('reads an empty root as head and tail', function () {
    $xml = '<?xml version="1.0"?><dsScheduleData/>';
    $segments = iterator_to_array(app(XmlTableReader::class)->segments(trimFixtureFile($xml)), false);

    expect(segmentShape($segments))->toBe(['head', 'tail'])->and(joinSegments($segments))->toBe($xml);
});

it('reads across read chunks without losing or splitting a row', function () {
    $rows = '';
    for ($index = 0; $index < 30000; $index++) {
        $rows .= "\n  <Activity><id>A{$index}</id><description>".str_repeat('x', 20).'</description></Activity>';
    }
    $xml = '<dsScheduleData>'.$rows."\n</dsScheduleData>";
    $reader = app(XmlTableReader::class);

    $segments = iterator_to_array($reader->segments(trimFixtureFile($xml)), false);

    expect(strlen($xml))->toBeGreaterThan(1048576)
        ->and($segments)->toHaveCount(30002)
        ->and(joinSegments($segments))->toBe($xml)
        ->and($reader->fieldsOf($segments[29999]->body, ['id']))->toBe(['id' => 'A29998']);
});

it('reads the fields of a row, decoding entities and skipping empty ones', function () {
    $body = '<ns:Activity><ns:id> A&amp;1 </ns:id><ns:location_id/><ns:activity_type_id></ns:activity_type_id><ns:description>x</ns:description></ns:Activity>';

    expect(app(XmlTableReader::class)->fieldsOf($body, ['id', 'location_id', 'activity_type_id']))->toBe(['id' => 'A&1']);
});

it('replaces one field of a row, opening an empty element to take it', function () {
    $reader = app(XmlTableReader::class);

    expect($reader->replaceField('<Input_Reference><id>x</id><datetime>2026-10-04T08:00:00</datetime></Input_Reference>', 'datetime', '2026-10-05T06:00:00'))
        ->toBe('<Input_Reference><id>x</id><datetime>2026-10-05T06:00:00</datetime></Input_Reference>')
        ->and($reader->replaceField('<ns:Input_Reference><ns:datetime/></ns:Input_Reference>', 'datetime', 'A&B'))
        ->toBe('<ns:Input_Reference><ns:datetime>A&amp;B</ns:datetime></ns:Input_Reference>')
        ->and($reader->replaceField('<Input_Reference><input_reference_datetime>x</input_reference_datetime></Input_Reference>', 'datetime', 'y'))
        ->toBe('<Input_Reference><input_reference_datetime>x</input_reference_datetime></Input_Reference>');
});

it('refuses a file that is not schedule data, a truncated one and UTF-16', function () {
    $reader = app(XmlTableReader::class);

    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('<DsSystemData><X/></DsSystemData>'))))
        ->toThrow(RuntimeException::class, 'not PSO schedule data');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('<dsScheduleData><Activity><id>1</id>'))))
        ->toThrow(RuntimeException::class, 'looks truncated');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile("\xFF\xFE<\x00d\x00"))))
        ->toThrow(RuntimeException::class, 'UTF-16');
});
```

Create `tests/Unit/TrimExport/JsonTableReaderTest.php`:

```php
<?php

use App\Support\TrimExport\Readers\JsonTableReader;

it('cuts a json export into head, tables, rows and tail and gives it back byte for byte', function () {
    $json = "{\n  \"dsScheduleData\": {\n    \"Input_Reference\": {\"datetime\": \"2026-10-04T08:00:00\"},\n"
        ."    \"Activity\": [\n      {\"id\": \"A1\", \"note\": \"a } b\"},\n      {\"id\": \"A2\"}\n    ],\n"
        ."    \"Resources\": [],\n    \"Odd\": null\n  }\n}\n";

    $segments = iterator_to_array(app(JsonTableReader::class)->segments(trimFixtureFile($json, 'json')), false);

    expect(joinSegments($segments))->toBe($json)
        ->and(segmentShape($segments))->toBe([
            'head', 'table_open Input_Reference', 'row Input_Reference', 'table_close Input_Reference',
            'table_open Activity', 'row Activity', 'row Activity', 'table_close Activity',
            'table_open Resources', 'table_close Resources', 'table_open Odd', 'table_close Odd', 'tail',
        ])
        ->and($segments[0]->body)->toBe("{\n  \"dsScheduleData\": {")
        ->and($segments[1]->singleObject)->toBeTrue()
        ->and($segments[1]->prefix)->toBe("\n    ")
        ->and($segments[1]->body)->toBe('"Input_Reference": ')
        ->and($segments[4]->prefix)->toBe(",\n    ")
        ->and($segments[4]->body)->toBe('"Activity": [')
        ->and($segments[5]->prefix)->toBe("\n      ")
        ->and($segments[6]->prefix)->toBe(",\n      ")
        ->and($segments[7]->prefix)->toBe("\n    ")
        ->and($segments[7]->body)->toBe(']')
        ->and($segments[10]->scalar)->toBeTrue()
        ->and($segments[10]->body)->toBe('"Odd": null')
        ->and($segments[12]->body)->toBe("\n  }\n}\n")
        ->and($segments[12]->offset)->toBe(strlen($json));
});

it('reads the 6.15 ScheduleData wrapper', function () {
    $json = '{"ScheduleData":{"Activity":[{"id":14420},{"id":14421}]}}';
    $segments = iterator_to_array(app(JsonTableReader::class)->segments(trimFixtureFile($json, 'json')), false);

    expect(segmentShape($segments))->toBe(['head', 'table_open Activity', 'row Activity', 'row Activity', 'table_close Activity', 'tail'])
        ->and(joinSegments($segments))->toBe($json);
});

it('reads native types as strings, skipping null and empty values', function () {
    expect(app(JsonTableReader::class)->fieldsOf('{"id": 14420, "split_allowed": false, "duration": 1.5, "location_id": null, "activity_type_id": ""}', ['id', 'split_allowed', 'duration', 'location_id', 'activity_type_id']))
        ->toBe(['id' => '14420', 'split_allowed' => 'false', 'duration' => '1.5']);
});

it('replaces one field of a json row and leaves a row without it alone', function () {
    $reader = app(JsonTableReader::class);

    expect($reader->replaceField('{"id": "x", "datetime" : "2026-10-04T08:00:00", "dataset_id": "NORTH"}', 'datetime', '2026-10-05T06:00:00'))
        ->toBe('{"id": "x", "datetime" : "2026-10-05T06:00:00", "dataset_id": "NORTH"}')
        ->and($reader->replaceField('{"id": "x"}', 'datetime', 'y'))->toBe('{"id": "x"}');
});

it('refuses json with no dsScheduleData wrapper, and a truncated file', function () {
    $reader = app(JsonTableReader::class);

    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('{"Other": {"Activity": []}}', 'json'))))
        ->toThrow(RuntimeException::class, 'no dsScheduleData wrapper');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('{"dsScheduleData": {"Activity": [{"id": "A1"}', 'json'))))
        ->toThrow(RuntimeException::class, 'looks truncated');
});
```

Create `tests/Unit/TrimExport/ExportSourceTest.php`:

```php
<?php

use App\Support\Exports\InvalidExportException;
use App\Support\TrimExport\ExportSource;

it('opens a plain export where it is, by what its bytes are', function () {
    $path = trimFixtureFile('{"dsScheduleData": {}}', 'xml');

    $source = app(ExportSource::class)->open($path);

    expect($source->path)->toBe($path)
        ->and($source->format)->toBe('json')
        ->and($source->memberName)->toBeNull()
        ->and($source->zipped())->toBeFalse();
});

it('copies the largest member out of a zip next to it, whatever the zip is called', function () {
    $zip = zipOf(trimFixtureFile('<dsScheduleData><Activity/></dsScheduleData>'), 'File_2026_10_04.xml');
    $misnamed = $zip.'.xml';
    rename($zip, $misnamed);

    $source = app(ExportSource::class)->open($misnamed);

    expect($source->format)->toBe('xml')
        ->and($source->memberName)->toBe('File_2026_10_04.xml')
        ->and($source->zipped())->toBeTrue()
        ->and(dirname($source->path))->toBe(dirname($misnamed))
        ->and(file_get_contents($source->path))->toBe('<dsScheduleData><Activity/></dsScheduleData>');
});

it('refuses a file that is not xml, json or a zip', function () {
    expect(fn () => app(ExportSource::class)->open(trimFixtureFile('hello', 'txt')))
        ->toThrow(InvalidExportException::class, 'cannot be trimmed');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/XmlTableReaderTest.php tests/Unit/TrimExport/JsonTableReaderTest.php tests/Unit/TrimExport/ExportSourceTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\Readers\XmlTableReader" not found`.

- [ ] **Step 4: Move format sniffing and zip members out of the summariser**

Create `app/Support/Exports/ExportFormat.php`:

```php
<?php

namespace App\Support\Exports;

use Illuminate\Support\Str;
use ZipArchive;

/** What an export is by its first bytes, and the readable member of a zip: shared by the summariser and Trim export. */
class ExportFormat
{
    /** zip, json or xml by the first bytes of the file; null when it is none of them. Zips are often misnamed, so the bytes win. */
    public static function sniff(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $head = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        return match (true) {
            str_starts_with($head, "PK\x03\x04") => 'zip',
            str_starts_with($head, '{') => 'json',
            str_starts_with($head, '<') => 'xml',
            default => null,
        };
    }

    public static function isReadableMember(string $name, ?string $only = null): bool
    {
        if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains(basename($name), '._')) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $only !== null ? $extension === $only : in_array($extension, ['xml', 'json'], true);
    }

    /**
     * Copies the largest .xml or .json member of a zip next to it.
     *
     * @return array{0: string, 1: string, 2: string} the copy's path, its format (by extension), the member's name
     */
    public static function largestMember(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidExportException('The zip could not be opened. It may be damaged or incomplete.');
        }

        try {
            $bestIndex = null;
            $bestSize = -1;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (! self::isReadableMember($name) || $stat === false) {
                    continue;
                }
                if ($stat['size'] > $bestSize) {
                    $bestSize = $stat['size'];
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                throw new InvalidExportException('The zip holds no .xml or .json file.');
            }

            $capMegabytes = (float) config('exports.max_member_megabytes', 600);
            if ($bestSize > $capMegabytes * 1024 * 1024) {
                throw new InvalidExportException('The file inside the zip is too large to read here ('.round($bestSize / 1024 / 1024).' MB uncompressed; the limit is '.round($capMegabytes).' MB).');
            }

            $memberName = (string) $zip->getNameIndex($bestIndex);
            $format = strtolower(pathinfo($memberName, PATHINFO_EXTENSION));
            $target = dirname($zipPath).'/member-'.Str::uuid().'.'.$format;
            $source = $zip->getStreamIndex($bestIndex);
            $destination = fopen($target, 'wb');
            try {
                stream_copy_to_stream($source, $destination);
            } finally {
                fclose($destination);
                fclose($source);
            }

            return [$target, $format, $memberName];
        } finally {
            $zip->close();
        }
    }
}
```

In `app/Support/Exports/ExportSummariser.php`:
- replace `$contentType = $this->sniffFormat($localPath) ?? $file->content_type;` with `$contentType = ExportFormat::sniff($localPath) ?? $file->content_type;`
- replace `? $this->largestMember($localPath)` with `? array_slice(ExportFormat::largestMember($localPath), 0, 2)`
- in `summariseAudits()` replace `$this->isReadableMember($name, 'xml')` with `ExportFormat::isReadableMember($name, 'xml')`
- delete the private methods `largestMember()`, `sniffFormat()` and `isReadableMember()`, and the `use Illuminate\Support\Str;` import (nothing else in the class uses it).

- [ ] **Step 5: Write the segment and the reader contract**

Create `app/Support/TrimExport/Segment.php`:

```php
<?php

namespace App\Support\TrimExport;

/**
 * One piece of a schedule data file as a reader cuts it. Concatenating every segment's prefix and body, in
 * order, gives the file back byte for byte. A row's prefix is what sat between it and the previous row (the
 * whitespace, a JSON comma, an XML comment); a writer that drops the row drops its prefix with it.
 */
class Segment
{
    /** Everything before the first table: declaration, root or wrapper opening. */
    public const string HEAD = 'head';

    /** JSON only: a wrapper member's key up to its value (`"Activity": [`, or the whole `"Odd": null`). */
    public const string TABLE_OPEN = 'table_open';

    public const string ROW = 'row';

    /** JSON only: the whitespace and `]` that close a table's list ('' after a one-row object). */
    public const string TABLE_CLOSE = 'table_close';

    /** Everything after the last row: root or wrapper closing and the rest of the file. */
    public const string TAIL = 'tail';

    public function __construct(
        public string $type,
        public string $table = '',
        public string $prefix = '',
        public string $body = '',
        /** Bytes of the file read once this segment is complete, for progress. */
        public int $offset = 0,
        /** JSON: the table was one row object, not a list (the older dsScheduleData shape). */
        public bool $singleObject = false,
        /** JSON: the member's value was a scalar such as null, so the table has no rows. */
        public bool $scalar = false,
    ) {}
}
```

Create `app/Support/TrimExport/Readers/TableReader.php`:

```php
<?php

namespace App\Support\TrimExport\Readers;

use App\Support\TrimExport\Segment;
use Generator;

/** Cuts a dsScheduleData file into segments (streamed) and reads or edits single rows. */
interface TableReader
{
    /**
     * @return Generator<int, Segment>
     *
     * @throws \RuntimeException when the file is not schedule data or ends early
     */
    public function segments(string $path): Generator;

    /**
     * @param  list<string>  $names
     * @return array<string, string> the named fields of one row that have a non-empty value, as trimmed strings
     */
    public function fieldsOf(string $body, array $names): array;

    /** The row with one field's value replaced; unchanged when the row has no such field. */
    public function replaceField(string $body, string $field, string $value): string;
}
```

- [ ] **Step 6: Write the XML reader**

Create `app/Support/TrimExport/Readers/XmlTableReader.php`:

```php
<?php

namespace App\Support\TrimExport\Readers;

use App\Support\TrimExport\Segment;
use Generator;
use RuntimeException;

/**
 * Cuts a dsScheduleData XML file into segments by scanning the markup just far enough to track element depth
 * (the scanner of XmlScheduleDataRewriter): every element just inside the root is a row of the table it is
 * named after. Bytes are never re-serialised, so a writer copies kept rows verbatim and both passes see the
 * same rows. Memory stays at one read chunk plus the longest row.
 */
class XmlTableReader implements TableReader
{
    private const array ROOT_NAMES = ['dsScheduleData', 'ScheduleData'];

    private const int READ_CHUNK_BYTES = 1048576;

    private const string NAME = '[A-Za-z_][\w.\-]*';

    public function segments(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export could not be opened.');
        }

        try {
            $buffer = '';
            $endOfFile = false;
            $this->readChunk($handle, $buffer, $endOfFile);
            $start = substr($buffer, 0, 2);
            if ($start === "\xFF\xFE" || $start === "\xFE\xFF" || $start === "<\x00" || $start === "\x00<") {
                throw new RuntimeException('UTF-16 exports are not supported. Save the file as UTF-8.');
            }

            $consumed = 0; // bytes already cut from the front of the buffer
            $position = 0; // scan position in the buffer
            $segmentStart = 0; // first byte of the segment being collected
            $bodyStart = 0; // the '<' of the row being collected
            $depth = 0;
            $rootSeen = false;
            $table = null;

            while (true) {
                $tagStart = strpos($buffer, '<', $position);
                $tagEnd = false;

                // Nine bytes tell a comment and CDATA apart; at the end of the file, use what is there.
                if ($tagStart !== false && ($endOfFile || strlen($buffer) - $tagStart >= 9)) {
                    $tagEnd = $this->constructEnd($buffer, $tagStart);
                }

                if ($tagEnd === false) {
                    if ($endOfFile) {
                        break;
                    }

                    // Drop what has been handed out, keep the segment being collected, read on.
                    $resumeAt = $tagStart === false ? strlen($buffer) : $tagStart;
                    $buffer = substr($buffer, $segmentStart);
                    $consumed += $segmentStart;
                    $position = $resumeAt - $segmentStart;
                    $bodyStart -= $segmentStart;
                    $segmentStart = 0;
                    $this->readChunk($handle, $buffer, $endOfFile);

                    continue;
                }

                $position = $tagEnd + 1;
                $marker = $buffer[$tagStart + 1];

                if ($marker === '!' || $marker === '?') {
                    // Comment, CDATA, processing instruction, DOCTYPE or declaration: part of the current segment.
                    continue;
                }

                if ($marker === '/') {
                    $depth--;

                    if ($depth === 1 && $table !== null) {
                        yield $this->row($table, $buffer, $segmentStart, $bodyStart, $position, $consumed);
                        $segmentStart = $position;
                        $table = null;
                    } elseif ($depth === 0) {
                        yield $this->tail($handle, substr($buffer, $segmentStart), $endOfFile);

                        return;
                    }

                    continue;
                }

                $selfClosing = $buffer[$tagEnd - 1] === '/';
                $localName = $this->localName($buffer, $tagStart);

                if ($depth === 0) {
                    if (! in_array($localName, self::ROOT_NAMES, true)) {
                        throw new RuntimeException("This file is a <{$localName}> file, not PSO schedule data (<dsScheduleData>).");
                    }

                    $rootSeen = true;
                    yield new Segment(Segment::HEAD, body: substr($buffer, $segmentStart, $position - $segmentStart), offset: $consumed + $position);
                    $segmentStart = $position;

                    if ($selfClosing) {
                        yield $this->tail($handle, substr($buffer, $segmentStart), $endOfFile);

                        return;
                    }

                    $depth = 1;

                    continue;
                }

                if ($depth === 1) {
                    $table = $localName;
                    $bodyStart = $tagStart;

                    if ($selfClosing) {
                        yield $this->row($table, $buffer, $segmentStart, $bodyStart, $position, $consumed);
                        $segmentStart = $position;
                        $table = null;

                        continue;
                    }

                    $depth = 2;

                    continue;
                }

                if (! $selfClosing) {
                    $depth++;
                }
            }

            throw new RuntimeException($rootSeen
                ? 'The export ended inside dsScheduleData. It looks truncated.'
                : 'This file has no dsScheduleData root, so it is not PSO schedule data.');
        } finally {
            fclose($handle);
        }
    }

    public function fieldsOf(string $body, array $names): array
    {
        $wanted = array_flip($names);
        $fields = [];

        // Rows are flat: each field is a child element holding text. An empty or self-closing one has no value.
        preg_match_all('#<(?:'.self::NAME.':)?('.self::NAME.')(?:\s[^>]*)?>([^<]*)</#', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $text]) {
            if (! isset($wanted[$name]) || isset($fields[$name])) {
                continue;
            }

            $value = trim(str_contains($text, '&') ? html_entity_decode($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') : $text);
            if ($value !== '') {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    public function replaceField(string $body, string $field, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $name = '((?:'.self::NAME.':)?'.preg_quote($field, '#').')';

        $replaced = preg_replace_callback(
            '#(<'.$name.'(?:\s[^>]*)?>)[^<]*(</\2\s*>)#',
            static fn (array $match): string => $match[1].$escaped.$match[3],
            $body, 1, $count,
        );
        if ($count === 1) {
            return (string) $replaced;
        }

        // <datetime/>: open it up to take the value.
        $replaced = preg_replace_callback(
            '#<'.$name.'(\s[^>]*)?/>#',
            static fn (array $match): string => '<'.$match[1].($match[2] ?? '').'>'.$escaped.'</'.$match[1].'>',
            $body, 1, $count,
        );

        return $count === 1 ? (string) $replaced : $body;
    }

    private function row(string $table, string $buffer, int $segmentStart, int $bodyStart, int $end, int $consumed): Segment
    {
        return new Segment(
            Segment::ROW,
            $table,
            substr($buffer, $segmentStart, $bodyStart - $segmentStart),
            substr($buffer, $bodyStart, $end - $bodyStart),
            $consumed + $end,
        );
    }

    /** @param resource $handle */
    private function tail($handle, string $rest, bool $endOfFile): Segment
    {
        while (! $endOfFile) {
            $this->readChunk($handle, $rest, $endOfFile);
        }

        return new Segment(Segment::TAIL, body: $rest, offset: (int) ftell($handle));
    }

    private function localName(string $buffer, int $tagStart): string
    {
        $name = substr($buffer, $tagStart + 1, strcspn($buffer, " \t\r\n/>", $tagStart + 1));
        $colon = strpos($name, ':');

        return $colon === false ? $name : substr($name, $colon + 1);
    }

    /** @param resource $handle */
    private function readChunk($handle, string &$buffer, bool &$endOfFile): void
    {
        $chunk = fread($handle, self::READ_CHUNK_BYTES);
        if ($chunk === false) {
            throw new RuntimeException('The export could not be read.');
        }

        $buffer .= $chunk;
        $endOfFile = $chunk === '' || feof($handle);
    }

    /** The offset of the last byte of the markup construct starting at the given '<', or false when it does not end inside the buffer. */
    private function constructEnd(string $buffer, int $tagStart): int|false
    {
        if ($tagStart + 1 >= strlen($buffer)) {
            return false;
        }

        $next = $buffer[$tagStart + 1];

        if ($next === '!') {
            if (substr_compare($buffer, '<!--', $tagStart, 4) === 0) {
                $end = strpos($buffer, '-->', $tagStart + 4);

                return $end === false ? false : $end + 2;
            }

            if (substr_compare($buffer, '<![CDATA[', $tagStart, 9) === 0) {
                $end = strpos($buffer, ']]>', $tagStart + 9);

                return $end === false ? false : $end + 2;
            }

            // DOCTYPE: skip quoted strings and the internal subset.
            return $this->quotedEnd($buffer, $tagStart + 2, true);
        }

        if ($next === '?') {
            $end = strpos($buffer, '?>', $tagStart + 2);

            return $end === false ? false : $end + 1;
        }

        if ($next === '/') {
            return strpos($buffer, '>', $tagStart + 2);
        }

        $end = strpos($buffer, '>', $tagStart + 1);
        if ($end === false) {
            return false;
        }

        // Fast path: no quoted attribute value that could hide a '>'.
        if (strcspn($buffer, "\"'", $tagStart + 1, $end - $tagStart - 1) === $end - $tagStart - 1) {
            return $end;
        }

        return $this->quotedEnd($buffer, $tagStart + 1, false);
    }

    private function quotedEnd(string $buffer, int $from, bool $allowSubset): int|false
    {
        $length = strlen($buffer);
        $subsetDepth = 0;
        $index = $from;

        while (true) {
            $index += strcspn($buffer, $allowSubset ? "\"'>[]" : "\"'>", $index);
            if ($index >= $length) {
                return false;
            }

            $character = $buffer[$index];
            if ($character === '>' && $subsetDepth === 0) {
                return $index;
            }

            if ($character === '"' || $character === "'") {
                $close = strpos($buffer, $character, $index + 1);
                if ($close === false) {
                    return false;
                }
                $index = $close + 1;

                continue;
            }

            if ($character === '[') {
                $subsetDepth++;
            } elseif ($character === ']') {
                $subsetDepth--;
            }

            $index++;
        }
    }
}
```

- [ ] **Step 7: Write the JSON reader**

Create `app/Support/TrimExport/Readers/JsonTableReader.php`:

```php
<?php

namespace App\Support\TrimExport\Readers;

use App\Support\ScheduleData\JsonTokenReader;
use App\Support\TrimExport\Segment;
use Generator;
use RuntimeException;

/**
 * Cuts a schedule data JSON file into segments with JsonTokenReader: each member of the dsScheduleData (or
 * 6.15+ ScheduleData) wrapper is a table, each object in its list a row. Older files write a one-row table as
 * a plain object; that row comes as a single-object table. Only one row is ever held.
 */
class JsonTableReader implements TableReader
{
    private const array WRAPPER_KEYS = ['dsScheduleData', 'ScheduleData'];

    public function __construct(private JsonTokenReader $tokenReader) {}

    public function segments(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export could not be opened.');
        }

        try {
            $offset = 0;
            $state = 'head'; // head, key, colon, value, rows, row, tail
            $head = '';
            $depth = 0;
            $previous = '';
            $wrapperPending = false;
            $prefix = ''; // whitespace and commas before the next table or row
            $open = ''; // the table's key, colon and the whitespace before its value
            $table = '';
            $body = '';
            $rowDepth = 0;
            $single = false;
            $tail = '';

            foreach ($this->tokenReader->tokens($handle) as $token) {
                $offset += strlen($token);
                $whitespace = strspn($token[0], " \t\r\n") === 1;

                if ($state === 'head') {
                    $head .= $token;
                    if ($whitespace) {
                        continue;
                    }

                    $before = $previous;
                    $previous = $token;

                    if ($wrapperPending && $token !== ':' && $token !== '{') {
                        $wrapperPending = false;
                    }

                    if ($token === '{' && $wrapperPending && $depth === 1) {
                        yield new Segment(Segment::HEAD, body: $head, offset: $offset);
                        $state = 'key';

                        continue;
                    }

                    if ($token === '{' || $token === '[') {
                        $depth++;
                    } elseif ($token === '}' || $token === ']') {
                        $depth--;
                    } elseif ($token[0] === '"' && $depth === 1 && ($before === '{' || $before === ',')) {
                        $wrapperPending = in_array(json_decode($token), self::WRAPPER_KEYS, true);
                    }

                    continue;
                }

                if ($state === 'key') {
                    if ($whitespace || $token === ',') {
                        $prefix .= $token;

                        continue;
                    }

                    if ($token === '}') {
                        $tail = $prefix.'}';
                        $prefix = '';
                        $state = 'tail';

                        continue;
                    }

                    $decoded = json_decode($token);
                    $table = is_string($decoded) ? $decoded : '';
                    $open = $token;
                    $state = 'colon';

                    continue;
                }

                if ($state === 'colon') {
                    $open .= $token;
                    if ($token === ':') {
                        $state = 'value';
                    }

                    continue;
                }

                if ($state === 'value') {
                    if ($whitespace) {
                        $open .= $token;

                        continue;
                    }

                    if ($token === '[') {
                        yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open.'[', $offset);
                        $prefix = '';
                        $state = 'rows';

                        continue;
                    }

                    if ($token === '{') {
                        yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open, $offset, singleObject: true);
                        $prefix = '';
                        $body = '{';
                        $rowDepth = 1;
                        $single = true;
                        $state = 'row';

                        continue;
                    }

                    // A scalar value (null): a table with no rows, copied whole.
                    yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open.$token, $offset, scalar: true);
                    yield new Segment(Segment::TABLE_CLOSE, $table, offset: $offset, scalar: true);
                    $prefix = '';
                    $state = 'key';

                    continue;
                }

                if ($state === 'rows') {
                    if ($whitespace || $token === ',') {
                        $prefix .= $token;

                        continue;
                    }

                    if ($token === ']') {
                        yield new Segment(Segment::TABLE_CLOSE, $table, $prefix, ']', $offset);
                        $prefix = '';
                        $state = 'key';

                        continue;
                    }

                    if ($token === '{' || $token === '[') {
                        $body = $token;
                        $rowDepth = 1;
                        $single = false;
                        $state = 'row';

                        continue;
                    }

                    // A bare value in a table's list: a row of its own.
                    yield new Segment(Segment::ROW, $table, $prefix, $token, $offset);
                    $prefix = '';

                    continue;
                }

                if ($state === 'row') {
                    $body .= $token;
                    if ($token === '{' || $token === '[') {
                        $rowDepth++;
                    } elseif ($token === '}' || $token === ']') {
                        $rowDepth--;
                    }

                    if ($rowDepth > 0) {
                        continue;
                    }

                    yield new Segment(Segment::ROW, $table, $prefix, $body, $offset);
                    $prefix = '';
                    $body = '';

                    if ($single) {
                        yield new Segment(Segment::TABLE_CLOSE, $table, offset: $offset, singleObject: true);
                        $single = false;
                        $state = 'key';
                    } else {
                        $state = 'rows';
                    }

                    continue;
                }

                $tail .= $token;
            }

            if ($state !== 'tail') {
                throw new RuntimeException($state === 'head'
                    ? 'This file has no dsScheduleData wrapper, so it is not PSO schedule data.'
                    : 'The export ended inside dsScheduleData. It looks truncated.');
            }

            yield new Segment(Segment::TAIL, body: $tail, offset: $offset);
        } finally {
            fclose($handle);
        }
    }

    public function fieldsOf(string $body, array $names): array
    {
        $row = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (! is_array($row)) {
            return [];
        }

        $fields = [];
        foreach ($names as $name) {
            $value = $row[$name] ?? null;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $fields[$name] = $text;
                }
            }
        }

        return $fields;
    }

    public function replaceField(string $body, string $field, string $value): string
    {
        $replaced = preg_replace_callback(
            '/("'.preg_quote($field, '/').'"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|null|true|false|-?\d[\d.eE+\-]*)/',
            static fn (array $match): string => $match[1].json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $body, 1, $count,
        );

        return $count === 1 ? (string) $replaced : $body;
    }
}
```

- [ ] **Step 8: Write the source opener**

Create `app/Support/TrimExport/ExportSourceFile.php`:

```php
<?php

namespace App\Support\TrimExport;

/** The schedule data file to trim: the scratch copy itself, or the member copied out of a zip. */
class ExportSourceFile
{
    public function __construct(
        public string $path,
        /** xml or json */
        public string $format,
        /** The member's name inside the zip; null for a plain file. */
        public ?string $memberName,
    ) {}

    public function zipped(): bool
    {
        return $this->memberName !== null;
    }
}
```

Create `app/Support/TrimExport/ExportSource.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Support\Exports\ExportFormat;
use App\Support\Exports\InvalidExportException;

/** Opens a scratch copy of an export for trimming: what its bytes are wins over its name; a zip gives its largest member. */
class ExportSource
{
    public function open(string $localPath): ExportSourceFile
    {
        $format = ExportFormat::sniff($localPath);

        if ($format === 'zip') {
            [$path, $memberFormat, $memberName] = ExportFormat::largestMember($localPath);
            $sniffed = ExportFormat::sniff($path);

            return new ExportSourceFile($path, in_array($sniffed, ['xml', 'json'], true) ? $sniffed : $memberFormat, $memberName);
        }

        if ($format === null) {
            throw new InvalidExportException('This file is not XML, JSON or a zip, so it cannot be trimmed.');
        }

        return new ExportSourceFile($localPath, $format, null);
    }
}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport tests/Feature/ExportSummariserTest.php tests/Feature/SummariseExportFileJobTest.php tests/Feature/ActivityTrace`
Expected: PASS (the summariser tests prove the move changed nothing).

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Exports/ExportFormat.php app/Support/Exports/ExportSummariser.php app/Support/TrimExport/Segment.php app/Support/TrimExport/Readers app/Support/TrimExport/ExportSource.php app/Support/TrimExport/ExportSourceFile.php tests/Support/TrimExportFixtures.php tests/Pest.php tests/Unit/TrimExport/XmlTableReaderTest.php tests/Unit/TrimExport/JsonTableReaderTest.php tests/Unit/TrimExport/ExportSourceTest.php
git commit -m "$(cat <<'EOF'
Trim export: streamed XML and JSON readers that cut an export into byte-exact segments; zip opening shared with the summariser (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 4: Writers that copy kept bytes verbatim

**Files:**
- Create: `app/Support/TrimExport/Writers/OutputFile.php`, `TableWriter.php`, `XmlTableWriter.php`, `JsonTableWriter.php`
- Modify: `tests/Support/TrimExportFixtures.php` (add `trimXml`, `trimJson`, `trimmedRows`, `trimCopy`)
- Create: `tests/Unit/TrimExport/TableWritersTest.php`

**Interfaces:**
- Consumes: `Segment`, `TableReader`, `XmlTableReader`, `JsonTableReader` (Task 3).
- Produces:
  - `TableWriter` interface: `open(string $path): void` (writes to `$path.'.part'`), `write(Segment $segment, bool $keep, ?string $body = null): void` (every segment is passed, kept or not; `$body` replaces the segment's body when given), `close(): int` (bytes written; renames `.part` to `$path`), `abort(): void` (deletes `.part`).
  - `XmlTableWriter`, `JsonTableWriter` (both `new`-able, no constructor arguments).
  - Test helpers `trimXml(array $tables): string`, `trimJson(array $tables, array $singleObjects = [], string $wrapper = 'dsScheduleData'): string`, `trimmedRows(string $path, string $format): array<string, list<array<string, string>>>` (DOM or `json_decode`, never the code under test; tables with no rows are left out), `trimCopy(TableReader $reader, TableWriter $writer, string $source, string $target, ?Closure $keepRow = null, ?Closure $keepTable = null): int`.

- [ ] **Step 1: Add the test helpers**

Append to `tests/Support/TrimExportFixtures.php` (add `use App\Support\TrimExport\Readers\TableReader;` and `use App\Support\TrimExport\Writers\TableWriter;` under the existing `use`):

```php
/**
 * A pretty-printed dsScheduleData XML file of these tables, in the given order.
 *
 * @param  array<string, list<array<string, string>>>  $tables
 */
function trimXml(array $tables): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".'<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">';
    foreach ($tables as $table => $rows) {
        foreach ($rows as $row) {
            $xml .= "\n  <{$table}>";
            foreach ($row as $field => $value) {
                $xml .= "\n    <{$field}>".htmlspecialchars((string) $value, ENT_XML1).'</'.$field.'>';
            }
            $xml .= "\n  </{$table}>";
        }
    }

    return trimFixtureFile($xml."\n</dsScheduleData>\n", 'xml');
}

/**
 * The same tables as pretty-printed JSON. A table named in $singleObjects that has one row is written as a
 * plain object, the older dsScheduleData shape.
 *
 * @param  array<string, list<array<string, string|int>>>  $tables
 * @param  list<string>  $singleObjects
 */
function trimJson(array $tables, array $singleObjects = [], string $wrapper = 'dsScheduleData'): string
{
    $data = [];
    foreach ($tables as $table => $rows) {
        $data[$table] = in_array($table, $singleObjects, true) && count($rows) === 1 ? $rows[0] : $rows;
    }

    return trimFixtureFile(json_encode([$wrapper => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 'json');
}

/**
 * Every row of an output file by table, each as field => string value, read with DOM or json_decode (never
 * with the code under test). Tables with no rows are left out, so XML and JSON results compare equal.
 *
 * @return array<string, list<array<string, string>>>
 */
function trimmedRows(string $path, string $format): array
{
    $tables = [];

    if ($format === 'xml') {
        $document = new DOMDocument;
        if (! @$document->load($path, LIBXML_NONET)) {
            throw new RuntimeException('Not well-formed XML: '.$path);
        }
        foreach ($document->documentElement->childNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }
            $row = [];
            foreach ($rowNode->childNodes as $fieldNode) {
                if ($fieldNode instanceof DOMElement) {
                    $row[$fieldNode->localName] = $fieldNode->textContent;
                }
            }
            $tables[$rowNode->localName][] = $row;
        }

        return $tables;
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    foreach (reset($decoded) ?: [] as $table => $rows) {
        if (! is_array($rows)) {
            continue;
        }
        foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
            $tables[$table][] = array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $row);
        }
    }

    return $tables;
}

/**
 * Reads $source and writes it to $target, keeping a row when $keepRow says so and a table when $keepTable does.
 *
 * @param  Closure(Segment): bool|null  $keepRow
 * @param  Closure(string): bool|null  $keepTable
 */
function trimCopy(TableReader $reader, TableWriter $writer, string $source, string $target, ?Closure $keepRow = null, ?Closure $keepTable = null): int
{
    $writer->open($target);
    foreach ($reader->segments($source) as $segment) {
        $tableKept = $keepTable === null || $segment->table === '' || $keepTable($segment->table);
        $keep = match ($segment->type) {
            Segment::ROW => $tableKept && ($keepRow === null || $keepRow($segment)),
            Segment::TABLE_OPEN, Segment::TABLE_CLOSE => $tableKept,
            default => true,
        };
        $writer->write($segment, $keep);
    }

    return $writer->close();
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/TrimExport/TableWritersTest.php`:

```php
<?php

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\Segment;
use App\Support\TrimExport\Writers\JsonTableWriter;
use App\Support\TrimExport\Writers\XmlTableWriter;

beforeEach(function () {
    $this->target = sys_get_temp_dir().'/trim-out-'.uniqid();
});

afterEach(function () {
    @unlink($this->target);
    @unlink($this->target.'.part');
});

it('copies every byte of an xml export when nothing is dropped', function () {
    $xml = "\xEF\xBB\xBF<?xml version=\"1.0\"?>\r\n<ns:dsScheduleData xmlns:ns=\"urn:x\">\r\n  <ns:Activity a=\"1 > 0\"><ns:id>A1</ns:id><![CDATA[<x>]]></ns:Activity>\r\n  <!-- note -->\r\n  <ns:Plan/>\r\n</ns:dsScheduleData>\r\n";
    $source = trimFixtureFile($xml);

    $bytes = trimCopy(app(XmlTableReader::class), new XmlTableWriter, $source, $this->target);

    expect(file_get_contents($this->target))->toBe($xml)->and($bytes)->toBe(strlen($xml));
});

it('copies every byte of a json export when nothing is dropped', function () {
    $json = "{\n  \"dsScheduleData\": {\n    \"Input_Reference\": {\"datetime\": \"x\"},\n    \"Activity\": [\n      {\"id\": \"A1\"},\n      {\"id\": \"A2\"}\n    ],\n    \"Resources\": [],\n    \"Odd\": null\n  }\n}\n";
    $source = trimFixtureFile($json, 'json');

    trimCopy(app(JsonTableReader::class), new JsonTableWriter, $source, $this->target);

    expect(file_get_contents($this->target))->toBe($json);
});

it('drops an xml row with the whitespace before it, and a whole table', function () {
    $source = trimXml([
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
        'Plan' => [['id' => 'P1']],
    ]);
    $reader = app(XmlTableReader::class);

    trimCopy($reader, new XmlTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A2',
        keepTable: static fn (string $table): bool => $table !== 'Plan');

    expect(trimmedRows($this->target, 'xml'))->toBe(['Activity' => [['id' => 'A1'], ['id' => 'A3']]])
        ->and(file_get_contents($this->target))->not->toContain("\n  \n");
});

it('writes valid JSON when the first row or the first table is dropped', function () {
    $source = trimJson([
        'Input_Reference' => [['datetime' => 'x']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
    ]);
    $reader = app(JsonTableReader::class);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A1',
        keepTable: static fn (string $table): bool => $table !== 'Input_Reference');

    expect(json_decode((string) file_get_contents($this->target), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['dsScheduleData' => ['Activity' => [['id' => 'A2'], ['id' => 'A3']]]]);
});

it('keeps a one-row table an object, writes [] when its row goes, and keeps a list a list', function () {
    $source = trimJson([
        'Input_Reference' => [['datetime' => 'x']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2']],
    ], singleObjects: ['Input_Reference']);
    $reader = app(JsonTableReader::class);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A2');
    $kept = json_decode((string) file_get_contents($this->target), true);

    expect($kept['dsScheduleData']['Input_Reference'])->toBe(['datetime' => 'x'])
        ->and($kept['dsScheduleData']['Activity'])->toBe([['id' => 'A1']]);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => $segment->table !== 'Input_Reference');

    expect((string) file_get_contents($this->target))->toContain('"Input_Reference": []')
        ->and(json_decode((string) file_get_contents($this->target), true)['dsScheduleData']['Input_Reference'])->toBe([]);
});

it('leaves no file behind until the copy is complete, and none after an abort', function () {
    $writer = new XmlTableWriter;
    $writer->open($this->target);
    $writer->write(new Segment(Segment::HEAD, body: str_repeat('x', 300000)), true);

    expect(file_exists($this->target))->toBeFalse()
        ->and(file_exists($this->target.'.part'))->toBeTrue();

    $writer->abort();

    expect(file_exists($this->target))->toBeFalse()
        ->and(file_exists($this->target.'.part'))->toBeFalse();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/TableWritersTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\Writers\XmlTableWriter" not found`.

- [ ] **Step 4: Write the buffered output file and the contract**

Create `app/Support/TrimExport/Writers/OutputFile.php`:

```php
<?php

namespace App\Support\TrimExport\Writers;

use RuntimeException;

/** A buffered file written as `<path>.part` and renamed when complete, so a half-written export is never mistaken for one. */
class OutputFile
{
    private const int BUFFER_BYTES = 262144;

    /** @var resource|null */
    private $handle = null;

    private string $path = '';

    private string $pending = '';

    private int $bytesWritten = 0;

    public function open(string $path): void
    {
        $handle = @fopen($path.'.part', 'wb');
        if ($handle === false) {
            throw new RuntimeException('The trimmed export could not be written.');
        }

        $this->handle = $handle;
        $this->path = $path;
        $this->pending = '';
        $this->bytesWritten = 0;
    }

    public function write(string $bytes): void
    {
        $this->pending .= $bytes;
        if (strlen($this->pending) >= self::BUFFER_BYTES) {
            $this->flush();
        }
    }

    public function close(): int
    {
        $this->flush();
        fclose($this->handle);
        $this->handle = null;

        if (! @rename($this->path.'.part', $this->path)) {
            @unlink($this->path.'.part');

            throw new RuntimeException('The trimmed export could not be written.');
        }

        return $this->bytesWritten;
    }

    public function abort(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        @unlink($this->path.'.part');
        $this->pending = '';
    }

    private function flush(): void
    {
        if ($this->pending === '') {
            return;
        }

        $written = fwrite($this->handle, $this->pending);
        if ($written === false || $written < strlen($this->pending)) {
            throw new RuntimeException('The trimmed export could not be written.');
        }

        $this->bytesWritten += $written;
        $this->pending = '';
    }
}
```

Create `app/Support/TrimExport/Writers/TableWriter.php`:

```php
<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/** Writes the kept segments of a file. Every segment is passed, kept or not, so the writer can repair what sits between kept ones. */
interface TableWriter
{
    public function open(string $path): void;

    /** $body, when given, replaces the segment's body (the Input_Reference override). */
    public function write(Segment $segment, bool $keep, ?string $body = null): void;

    /** @return int bytes written */
    public function close(): int;

    public function abort(): void;
}
```

- [ ] **Step 5: Write the two writers**

Create `app/Support/TrimExport/Writers/XmlTableWriter.php`:

```php
<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/** Copies the head, every kept row with the bytes before it, and the tail, verbatim: same declaration, namespace and root. */
class XmlTableWriter implements TableWriter
{
    private OutputFile $output;

    public function __construct()
    {
        $this->output = new OutputFile;
    }

    public function open(string $path): void
    {
        $this->output->open($path);
    }

    public function write(Segment $segment, bool $keep, ?string $body = null): void
    {
        if (! $keep || $segment->type === Segment::TABLE_OPEN || $segment->type === Segment::TABLE_CLOSE) {
            return;
        }

        $this->output->write($segment->prefix.($body ?? $segment->body));
    }

    public function close(): int
    {
        return $this->output->close();
    }

    public function abort(): void
    {
        $this->output->abort();
    }
}
```

Create `app/Support/TrimExport/Writers/JsonTableWriter.php`:

```php
<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/**
 * Writes the kept tables and rows in the input's own structure and bytes. The first kept table (and the first
 * kept row of a table) takes the separator of the first one in the input, which has no comma, so dropping the
 * first of anything never leaves a leading comma. A table that was one row object stays an object; when that
 * row goes it becomes [] (the rule of the #26 fix).
 */
class JsonTableWriter implements TableWriter
{
    private OutputFile $output;

    private ?string $firstTablePrefix = null;

    private int $tablesWritten = 0;

    private bool $tableKept = false;

    private bool $singleObject = false;

    private ?string $firstRowPrefix = null;

    private int $rowsWritten = 0;

    public function __construct()
    {
        $this->output = new OutputFile;
    }

    public function open(string $path): void
    {
        $this->output->open($path);
        $this->firstTablePrefix = null;
        $this->tablesWritten = 0;
        $this->tableKept = false;
    }

    public function write(Segment $segment, bool $keep, ?string $body = null): void
    {
        $body ??= $segment->body;

        if ($segment->type === Segment::HEAD || $segment->type === Segment::TAIL) {
            $this->output->write($segment->prefix.$body);

            return;
        }

        if ($segment->type === Segment::TABLE_OPEN) {
            $this->firstTablePrefix ??= $segment->prefix;
            $this->tableKept = $keep;
            $this->singleObject = $segment->singleObject;
            $this->firstRowPrefix = null;
            $this->rowsWritten = 0;

            if ($keep) {
                $this->output->write(($this->tablesWritten === 0 ? $this->firstTablePrefix : $segment->prefix).$body);
                $this->tablesWritten++;
            }

            return;
        }

        if ($segment->type === Segment::ROW) {
            $this->firstRowPrefix ??= $segment->prefix;

            if ($this->tableKept && $keep) {
                $this->output->write(($this->rowsWritten === 0 ? $this->firstRowPrefix : $segment->prefix).$body);
                $this->rowsWritten++;
            }

            return;
        }

        // TABLE_CLOSE
        if ($this->tableKept) {
            $this->output->write($this->singleObject && $this->rowsWritten === 0 ? '[]' : $segment->prefix.$body);
        }
    }

    public function close(): int
    {
        return $this->output->close();
    }

    public function abort(): void
    {
        $this->output->abort();
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/TrimExport/Writers tests/Support/TrimExportFixtures.php tests/Unit/TrimExport/TableWritersTest.php
git commit -m "$(cat <<'EOF'
Trim export: XML and JSON writers that copy kept rows byte for byte, repair JSON separators and keep one-row tables as objects (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 5: Pass one, the keep sets

**Files:**
- Create: `app/Support/TrimExport/KeyCollector.php`, `app/Support/TrimExport/KeepSets.php`
- Modify: `tests/Support/TrimExportFixtures.php` (add `trimDataset()`)
- Create: `tests/Unit/TrimExport/KeyCollectorTest.php`

**Interfaces:**
- Consumes: `TableReader::segments()`, `TableReader::fieldsOf()`, `Segment`, `TrimPlan` (`regionIds`, `resourceIds`, `activityTypeIds`, `activityIds`, `windowStart`, `windowEnd`, `dropsTable()`).
- Produces:
  - `KeyCollector::collect(TableReader $reader, string $path, TrimPlan $plan, ?Closure $progress = null): KeepSets` (`$progress` receives a percent of the file read, 0 to 100).
  - `KeepSets` with public `array $resources`, `array $shifts`, `array $activities`, `array $locations`, `array $availability` (each id => true), `array $notes` (list<string>, plain English, no ids); `KeepSets::keepsRow(string $table, array $fields): bool`; `KeepSets::ids(string $set): array` (list<string>); constants `KeepSets::FIELDS` (the fields `keepsRow()` reads), `KeepSets::CASCADE`, `KeepSets::CASCADE_ONLY`.
  - Test helper `trimDataset(): array` (the synthetic export every later engine and tool test uses).

Rules (from the spec, Filter Load File and #26/#42):
- Resources: every resource; with regions, only those a Resource_Region row puts in a chosen region; with resources chosen, intersected with them.
- Locations in a chosen region: `Location.region_id` or a `Location_Region` row (#42).
- Activities: at a location in a chosen region (when regions are set), of a chosen type, a chosen id, and with an APPOINTMENT Activity_SLA overlapping the window (when it is set). Other SLA types never count.
- Shifts: of kept resources, overlapping the window when it is set.
- Availability: referenced by a kept resource's Resource_Region_Availability or Resource_Skill_Availability row, or by a kept Activity's `availability_id`; never by date. A removed table's rows reference nothing.
- Locations kept: used by a kept Activity (`location_id`), a kept Resources or Shift row, or any Resource_Type row (`location_id_start`, `location_id_end`), unless that table is removed.
- Selection reads every table, removed or not (the filters choose rows; removing a table only decides what is written).

- [ ] **Step 1: Add the synthetic export**

Append to `tests/Support/TrimExportFixtures.php`:

```php
/**
 * A small export that exercises every rule. With region V1 and the window 2026-10-05 to 2026-10-09 (UTC) the
 * kept ids are: resources R1; shifts S1; activities A1 (L1 is in V1) and A3 (L3 only through Location_Region);
 * locations L1, L3, LS1 (R1 and S1 start there), LT (Resource_Type); availability AV1 (R1's region
 * availability) and AV3 (R1's skill availability, dated 2025: never filtered by date).
 *
 * @return array<string, list<array<string, string>>>
 */
function trimDataset(): array
{
    return [
        'Input_Reference' => [['id' => 'IR1', 'datetime' => '2026-10-04T08:00:00', 'dataset_id' => 'NORTH']],
        'Region' => [['id' => 'V1', 'description' => 'Regina'], ['id' => 'V2', 'description' => 'Saskatoon']],
        'Location' => [
            ['id' => 'L1', 'region_id' => 'V1'], ['id' => 'L2', 'region_id' => 'V2'], ['id' => 'L3'],
            ['id' => 'L4', 'region_id' => 'V1'], ['id' => 'LS1'], ['id' => 'LT'],
        ],
        'Location_Region' => [['location_id' => 'L3', 'region_id' => 'V1'], ['location_id' => 'L2', 'region_id' => 'V2']],
        'Resource_Type' => [['id' => 'RT1', 'location_id_start' => 'LT', 'location_id_end' => 'LT']],
        'Resources' => [
            ['id' => 'R1', 'first_name' => 'Ada', 'surname' => 'Lovelace', 'location_id_start' => 'LS1'],
            ['id' => 'R2', 'first_name' => 'Alan', 'surname' => 'Turing'],
        ],
        'Resource_Region' => [['resource_id' => 'R1', 'region_id' => 'V1'], ['resource_id' => 'R2', 'region_id' => 'V2']],
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'R1', 'start_datetime' => '2026-10-06T08:00:00', 'end_datetime' => '2026-10-06T16:00:00', 'location_id_start' => 'LS1'],
            ['id' => 'S2', 'resource_id' => 'R2', 'start_datetime' => '2026-10-06T08:00:00', 'end_datetime' => '2026-10-06T16:00:00'],
            ['id' => 'S3', 'resource_id' => 'R1', 'start_datetime' => '2026-10-20T08:00:00', 'end_datetime' => '2026-10-20T16:00:00'],
        ],
        'Shift_Break' => [['shift_id' => 'S1', 'duration' => '30'], ['shift_id' => 'S2', 'duration' => '30']],
        'Availability' => [
            ['id' => 'AV1', 'resource_id' => 'R1', 'datetime_start' => '2026-10-06T08:00:00'],
            ['id' => 'AV2', 'resource_id' => 'R2', 'datetime_start' => '2026-10-06T08:00:00'],
            ['id' => 'AV3', 'resource_id' => 'R1', 'datetime_start' => '2025-01-01T08:00:00'],
            ['id' => 'AV4', 'datetime_start' => '2026-10-06T08:00:00'],
        ],
        'Resource_Region_Availability' => [['resource_id' => 'R1', 'availability_id' => 'AV1'], ['resource_id' => 'R2', 'availability_id' => 'AV2']],
        'Resource_Skill_Availability' => [['resource_id' => 'R1', 'availability_id' => 'AV3']],
        'Activity_Type' => [['id' => 'T1', 'description' => 'Install'], ['id' => 'T2', 'description' => 'Repair']],
        'Activity' => [
            ['id' => 'A1', 'location_id' => 'L1', 'activity_type_id' => 'T1'],
            ['id' => 'A2', 'location_id' => 'L2', 'activity_type_id' => 'T1'],
            ['id' => 'A3', 'location_id' => 'L3', 'activity_type_id' => 'T2'],
            ['id' => 'A4', 'location_id' => 'L1', 'activity_type_id' => 'T1'],
        ],
        'Activity_SLA' => [
            ['activity_id' => 'A1', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
            ['activity_id' => 'A2', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
            ['activity_id' => 'A3', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-07T08:00:00', 'datetime_end' => '2026-10-07T12:00:00'],
            ['activity_id' => 'A4', 'sla_type_id' => 'APPOINTMENT', 'datetime_start' => '2026-10-20T08:00:00', 'datetime_end' => '2026-10-20T12:00:00'],
            ['activity_id' => 'A4', 'sla_type_id' => 'COMMIT', 'datetime_start' => '2026-10-06T08:00:00', 'datetime_end' => '2026-10-06T12:00:00'],
        ],
        'Activity_Status' => [['activity_id' => 'A1', 'status_id' => '30', 'resource_id' => 'R2'], ['activity_id' => 'A2', 'status_id' => '0']],
        'Activity_Group' => [['activity_id1' => 'A1', 'activity_id2' => 'A3'], ['activity_id1' => 'A1', 'activity_id2' => 'A2']],
        'Activity_Skill' => [['activity_id' => 'A1', 'skill_id' => 'K1'], ['activity_id' => 'A2', 'skill_id' => 'K1']],
        'Plan' => [['id' => 'P1', 'dataset_id' => 'NORTH']],
        'Plan_Travel' => [['plan_id' => 'P1', 'resource_id' => 'R1'], ['plan_id' => 'P1', 'resource_id' => 'R2']],
        'Allocation' => [['activity_id' => 'A1', 'resource_id' => 'R1'], ['activity_id' => 'A2', 'resource_id' => 'R2']],
    ];
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/TrimExport/KeyCollectorTest.php`:

```php
<?php

use App\Support\TrimExport\KeepSets;
use App\Support\TrimExport\KeyCollector;
use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\TrimPlan;

/** @param array<string, mixed> $options */
function collectKeys(array $options, string $format = 'xml', ?array $tables = null, string $wrapper = 'dsScheduleData'): KeepSets
{
    $tables ??= trimDataset();
    $path = $format === 'xml' ? trimXml($tables) : trimJson($tables, wrapper: $wrapper);
    $reader = $format === 'xml' ? app(XmlTableReader::class) : app(JsonTableReader::class);

    return app(KeyCollector::class)->collect($reader, $path, TrimPlan::fromOptions($options, 'UTC'));
}

it('keeps resources, shifts, activities, locations and availability for a region and a window', function (string $format) {
    $keep = collectKeys(['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], $format);
    $locations = $keep->ids('locations');
    sort($locations);

    expect($keep->ids('resources'))->toBe(['R1'])
        ->and($keep->ids('shifts'))->toBe(['S1'])
        ->and($keep->ids('activities'))->toBe(['A1', 'A3'])
        ->and($locations)->toBe(['L1', 'L3', 'LS1', 'LT'])
        ->and($keep->ids('availability'))->toBe(['AV1', 'AV3'])
        ->and($keep->notes)->toBe([]);
})->with(['xml', 'json']);

it('keeps shifts and activities outside any window when none is set', function () {
    $keep = collectKeys(['region_ids' => ['V1']]);

    expect($keep->ids('shifts'))->toBe(['S1', 'S3'])
        ->and($keep->ids('activities'))->toBe(['A1', 'A3', 'A4']);
});

it('narrows resources to the chosen ones, within the chosen regions', function () {
    $alone = collectKeys(['resource_ids' => ['R2']]);
    $outside = collectKeys(['region_ids' => ['V1'], 'resource_ids' => ['R2']]);

    expect($alone->ids('resources'))->toBe(['R2'])
        ->and($alone->ids('shifts'))->toBe(['S2'])
        ->and($alone->ids('activities'))->toBe(['A1', 'A2', 'A3', 'A4'])
        ->and($outside->ids('resources'))->toBe([])
        ->and($outside->notes)->toContain('No resources match the row filters, so the file keeps none.');
});

it('filters activities by type and by id, and says so when nothing matches', function () {
    expect(collectKeys(['activity_type_ids' => ['T2']])->ids('activities'))->toBe(['A3'])
        ->and(collectKeys(['activity_ids' => ['A2', 'A4']])->ids('activities'))->toBe(['A2', 'A4'])
        ->and(collectKeys(['activity_type_ids' => ['T1'], 'activity_ids' => ['A3']])->notes)
        ->toBe(['No activities match the row filters, so the file keeps none.']);
});

it('matches number ids in a JSON export to the string ids the filters carry', function () {
    $tables = [
        'Location' => [['id' => 'L1', 'region_id' => 'V1']],
        'Activity' => [['id' => 14420, 'location_id' => 'L1', 'activity_type_id' => 'T1'], ['id' => 14421, 'location_id' => 'L1', 'activity_type_id' => 'T1']],
    ];

    expect(collectKeys(['activity_ids' => ['14420']], 'json', $tables, 'ScheduleData')->ids('activities'))->toBe(['14420'])
        ->and(collectKeys(['activity_ids' => ['14420']], 'xml', $tables)->ids('activities'))->toBe(['14420']);
});

it('counts no availability from a table the run removes', function () {
    $keep = collectKeys(['region_ids' => ['V1'], 'table_mode' => 'remove', 'tables' => ['Resource_Region_Availability']]);

    expect($keep->ids('availability'))->toBe(['AV3']);
});

it('reports progress as a percent of the file', function () {
    $percents = [];
    $rows = [];
    for ($index = 0; $index < 12000; $index++) {
        $rows[] = ['id' => "A{$index}", 'location_id' => 'L1', 'activity_type_id' => 'T1'];
    }
    $path = trimXml(['Activity' => $rows]);

    app(KeyCollector::class)->collect(app(XmlTableReader::class), $path, TrimPlan::fromOptions(['activity_type_ids' => ['T1']], 'UTC'),
        static function (int $percent) use (&$percents): void {
            $percents[] = $percent;
        });

    expect($percents)->not->toBe([])
        ->and(max($percents))->toBeLessThanOrEqual(100)
        ->and($percents)->toBe(collect($percents)->sort()->values()->all());
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/KeyCollectorTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\KeyCollector" not found`.

- [ ] **Step 4: Write the keep sets**

Create `app/Support/TrimExport/KeepSets.php`:

```php
<?php

namespace App\Support\TrimExport;

/**
 * The ids pass one decided to keep, and the rule pass two applies to every row of a kept table: the table's own
 * id for Resources, Shift, Activity and Location; for Availability, the cascade plus being referenced or owned
 * by a kept activity (never a date); for every other table, the generic cascade of #26.
 */
class KeepSets
{
    /** A row whose field points at a removed row of these sets goes with it. */
    public const array CASCADE = [
        'activity_id' => 'activities',
        'activity_id1' => 'activities',
        'activity_id2' => 'activities',
        'resource_id' => 'resources',
        'location_id' => 'locations',
        'shift_id' => 'shifts',
    ];

    /**
     * Tables that only follow their activity, as Filter Load File rebuilt them: a status fixed to a resource
     * outside the filter stays with its kept activity and is reported as dangling, not dropped.
     */
    public const array CASCADE_ONLY = [
        'Activity_Status' => ['activity_id'],
        'Activity_SLA' => ['activity_id'],
    ];

    /** Every field keepsRow() reads. */
    public const array FIELDS = ['id', 'activity_id', 'activity_id1', 'activity_id2', 'resource_id', 'location_id', 'shift_id'];

    /**
     * @param  array<string|int, true>  $resources
     * @param  array<string|int, true>  $shifts
     * @param  array<string|int, true>  $activities
     * @param  array<string|int, true>  $locations
     * @param  array<string|int, true>  $availability
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $resources,
        public array $shifts,
        public array $activities,
        public array $locations,
        public array $availability,
        public array $notes = [],
    ) {}

    /** @param array<string, string> $fields */
    public function keepsRow(string $table, array $fields): bool
    {
        $id = $fields['id'] ?? null;

        return match ($table) {
            'Resources' => $id !== null && isset($this->resources[$id]),
            'Shift' => $id !== null && isset($this->shifts[$id]),
            'Activity' => $id !== null && isset($this->activities[$id]),
            'Location' => $id !== null && isset($this->locations[$id]),
            'Availability' => $this->cascades($table, $fields)
                && (($id !== null && isset($this->availability[$id])) || (isset($fields['activity_id']) && isset($this->activities[$fields['activity_id']]))),
            default => $this->cascades($table, $fields),
        };
    }

    /** @return list<string> */
    public function ids(string $set): array
    {
        return array_map('strval', array_keys($this->{$set}));
    }

    /** @param array<string, string> $fields */
    private function cascades(string $table, array $fields): bool
    {
        foreach (self::CASCADE_ONLY[$table] ?? array_keys(self::CASCADE) as $field) {
            $value = $fields[$field] ?? null;
            if ($value !== null && ! isset($this->{self::CASCADE[$field]}[$value])) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 5: Write pass one**

Create `app/Support/TrimExport/KeyCollector.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\TableReader;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pass one: reads the key fields of the tables the row filters need, once, and resolves which resources,
 * shifts, activities, locations and availability rows stay. Memory holds ids only.
 */
class KeyCollector
{
    /** The fields read per table; every other table is skipped unread. */
    private const array FIELDS = [
        'Resources' => ['id', 'location_id_start', 'location_id_end'],
        'Resource_Region' => ['resource_id', 'region_id'],
        'Location' => ['id', 'region_id'],
        'Location_Region' => ['location_id', 'region_id'],
        'Activity' => ['id', 'location_id', 'activity_type_id', 'availability_id'],
        'Activity_SLA' => ['activity_id', 'sla_type_id', 'datetime_start', 'datetime_end'],
        'Shift' => ['id', 'resource_id', 'start_datetime', 'end_datetime', 'location_id_start', 'location_id_end'],
        'Resource_Type' => ['location_id_start', 'location_id_end'],
        'Resource_Region_Availability' => ['resource_id', 'availability_id'],
        'Resource_Skill_Availability' => ['resource_id', 'availability_id'],
    ];

    /** @param Closure(int): void|null $progress percent of the file read */
    public function collect(TableReader $reader, string $path, TrimPlan $plan, ?Closure $progress = null): KeepSets
    {
        $regions = array_fill_keys($plan->regionIds, true);
        $byRegion = $regions !== [];
        $windowed = $plan->windowStart !== null && $plan->windowEnd !== null;

        $resources = []; // id => [start and end location ids]
        $regionResources = [];
        $regionLocations = [];
        $activities = []; // id => [location id, type id, availability id]
        $windowActivities = [];
        $shifts = []; // id => [resource id, [start and end location ids]]
        $typeLocations = [];
        $availabilityPairs = []; // [resource id, availability id]

        $size = max(1, (int) @filesize($path));
        $rows = 0;
        $lastPercent = -1;

        foreach ($reader->segments($path) as $segment) {
            if ($segment->type !== Segment::ROW || ! isset(self::FIELDS[$segment->table])) {
                continue;
            }

            if ($progress !== null && ++$rows % 5000 === 0) {
                $percent = min(100, intdiv($segment->offset * 100, $size));
                if ($percent !== $lastPercent) {
                    $progress($percent);
                    $lastPercent = $percent;
                }
            }

            $table = $segment->table;
            $fields = $reader->fieldsOf($segment->body, self::FIELDS[$table]);
            $id = $fields['id'] ?? null;

            switch ($table) {
                case 'Resources':
                    if ($id !== null) {
                        $resources[$id] = self::locationsOf($fields);
                    }
                    break;
                case 'Resource_Region':
                    if ($byRegion && isset($fields['resource_id'], $fields['region_id'], $regions[$fields['region_id']])) {
                        $regionResources[$fields['resource_id']] = true;
                    }
                    break;
                case 'Location':
                    if ($byRegion && $id !== null && isset($fields['region_id'], $regions[$fields['region_id']])) {
                        $regionLocations[$id] = true;
                    }
                    break;
                case 'Location_Region':
                    if ($byRegion && isset($fields['location_id'], $fields['region_id'], $regions[$fields['region_id']])) {
                        $regionLocations[$fields['location_id']] = true;
                    }
                    break;
                case 'Activity':
                    if ($id !== null) {
                        $activities[$id] = [$fields['location_id'] ?? null, $fields['activity_type_id'] ?? '', $fields['availability_id'] ?? null];
                    }
                    break;
                case 'Activity_SLA':
                    if ($windowed && ($fields['sla_type_id'] ?? null) === 'APPOINTMENT' && isset($fields['activity_id'])
                        && self::overlaps($fields['datetime_start'] ?? null, $fields['datetime_end'] ?? null, $plan)) {
                        $windowActivities[$fields['activity_id']] = true;
                    }
                    break;
                case 'Shift':
                    if ($id !== null && isset($fields['resource_id'])
                        && (! $windowed || self::overlaps($fields['start_datetime'] ?? null, $fields['end_datetime'] ?? null, $plan))) {
                        $shifts[$id] = [$fields['resource_id'], self::locationsOf($fields)];
                    }
                    break;
                case 'Resource_Type':
                    if (! $plan->dropsTable($table)) {
                        foreach (self::locationsOf($fields) as $location) {
                            $typeLocations[$location] = true;
                        }
                    }
                    break;
                default: // Resource_Region_Availability, Resource_Skill_Availability
                    if (! $plan->dropsTable($table) && isset($fields['resource_id'], $fields['availability_id'])) {
                        $availabilityPairs[] = [$fields['resource_id'], $fields['availability_id']];
                    }
            }
        }

        $keptResources = array_fill_keys(array_keys($resources), true);
        if ($byRegion) {
            $keptResources = array_intersect_key($keptResources, $regionResources);
        }
        if ($plan->resourceIds !== []) {
            $keptResources = array_intersect_key($keptResources, array_fill_keys($plan->resourceIds, true));
        }

        $keptShifts = [];
        foreach ($shifts as $shiftId => [$resourceId]) {
            if (isset($keptResources[$resourceId])) {
                $keptShifts[$shiftId] = true;
            }
        }

        $types = array_fill_keys($plan->activityTypeIds, true);
        $chosen = array_fill_keys($plan->activityIds, true);
        $keptActivities = [];
        foreach ($activities as $activityId => [$locationId, $typeId]) {
            if (($byRegion && ($locationId === null || ! isset($regionLocations[$locationId])))
                || ($types !== [] && ! isset($types[$typeId]))
                || ($chosen !== [] && ! isset($chosen[$activityId]))
                || ($windowed && ! isset($windowActivities[$activityId]))) {
                continue;
            }
            $keptActivities[$activityId] = true;
        }

        $keptAvailability = [];
        foreach ($availabilityPairs as [$resourceId, $availabilityId]) {
            if (isset($keptResources[$resourceId])) {
                $keptAvailability[$availabilityId] = true;
            }
        }

        $keptLocations = $typeLocations;
        if (! $plan->dropsTable('Activity')) {
            foreach (array_keys($keptActivities) as $activityId) {
                [$locationId, , $availabilityId] = $activities[$activityId];
                if ($locationId !== null) {
                    $keptLocations[$locationId] = true;
                }
                if ($availabilityId !== null) {
                    $keptAvailability[$availabilityId] = true;
                }
            }
        }
        if (! $plan->dropsTable('Resources')) {
            foreach (array_keys($keptResources) as $resourceId) {
                foreach ($resources[$resourceId] as $location) {
                    $keptLocations[$location] = true;
                }
            }
        }
        if (! $plan->dropsTable('Shift')) {
            foreach (array_keys($keptShifts) as $shiftId) {
                foreach ($shifts[$shiftId][1] as $location) {
                    $keptLocations[$location] = true;
                }
            }
        }

        $notes = [];
        if ($resources !== [] && $keptResources === []) {
            $notes[] = 'No resources match the row filters, so the file keeps none.';
        }
        if ($activities !== [] && $keptActivities === []) {
            $notes[] = 'No activities match the row filters, so the file keeps none.';
        }

        return new KeepSets($keptResources, $keptShifts, $keptActivities, $keptLocations, $keptAvailability, $notes);
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private static function locationsOf(array $fields): array
    {
        return array_values(array_filter([$fields['location_id_start'] ?? null, $fields['location_id_end'] ?? null], static fn (?string $location): bool => $location !== null));
    }

    private static function overlaps(?string $start, ?string $end, TrimPlan $plan): bool
    {
        $from = self::timestamp($start);
        $to = self::timestamp($end);

        return $from !== null && $to !== null && $from <= $plan->windowEnd && $to >= $plan->windowStart;
    }

    /** PSO writes exported times in UTC; a time without an offset is UTC. */
    private static function timestamp(?string $value): ?int
    {
        static $utc = null;
        $utc ??= new DateTimeZone('UTC');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, $utc))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport/KeyCollectorTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/TrimExport/KeyCollector.php app/Support/TrimExport/KeepSets.php tests/Support/TrimExportFixtures.php tests/Unit/TrimExport/KeyCollectorTest.php
git commit -m "$(cat <<'EOF'
Trim export: pass one builds the keep sets (region via Resource_Region and Location_Region, types, ids, appointment window, shifts, availability, locations) (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 6: Pass two, the cascade and the referential check

**Files:**
- Create: `app/Support/TrimExport/ReferenceTally.php`, `app/Support/TrimExport/TrimReport.php`, `app/Support/TrimExport/TrimEngine.php`
- Create: `tests/Unit/TrimExport/TrimEngineTest.php`

**Interfaces:**
- Consumes: `XmlTableReader`, `JsonTableReader` (Task 3), `XmlTableWriter`, `JsonTableWriter` (Task 4), `KeyCollector`, `KeepSets` (Task 5), `TrimPlan`, `TableDependencies::INPUT_REFERENCE` (Task 1).
- Produces:
  - `TrimEngine::run(string $path, string $format, TrimPlan $plan, ?string $outputPath, ?Closure $progress = null): TrimReport`. `$outputPath` null writes nothing (Count only). `$progress` receives `(string $label, int $percent)`; labels `TrimEngine::PASS_ONE = 'Reading the keys (pass one)'` and `TrimEngine::PASS_TWO = 'Writing the trimmed file (pass two)'`. No row filter: one pass, rows untouched, tables only.
  - `TrimReport` with public `array $tables` (table => `['before' => int, 'kept' => int]`, file order), `array $removedTables` (list<string>), `array $dangling` (list of `['table' => string, 'field' => string, 'count' => int, 'examples' => list<string>]`, at most three examples), `array $notes` (list<string>, no ids), `int $bytesWritten`; methods `keptTables(): array`, `rowsKept(): int`, `rowsRemoved(): int`, `summaryLine(bool $countOnly): string`, static `plural(int $count, string $word): string`.
  - `ReferenceTally::record(string $table, array $fields): void`, `ReferenceTally::dangling(array $presentTables): array`, `ReferenceTally::FIELDS`, `ReferenceTally::TARGETS`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TrimExport/TrimEngineTest.php`:

```php
<?php

use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;
use App\Support\TrimExport\TrimReport;

/**
 * @param  array<string, mixed>  $options
 * @return array{0: TrimReport, 1: ?string}
 */
function runTrim(array $options, string $format = 'xml', ?array $tables = null, bool $write = true, array $singleObjects = []): array
{
    $tables ??= trimDataset();
    $source = $format === 'xml' ? trimXml($tables) : trimJson($tables, $singleObjects);
    $output = $write ? sys_get_temp_dir().'/trim-engine-'.uniqid().'.'.$format : null;

    return [app(TrimEngine::class)->run($source, $format, TrimPlan::fromOptions($options, 'UTC'), $output), $output];
}

/** @return array<string, int> */
function keptCounts(TrimReport $report): array
{
    return array_map(static fn (array $counts): int => $counts['kept'], $report->tables);
}

const REGION_AND_WINDOW = ['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'];

it('removes whole tables and copies every other row untouched', function () {
    [$report, $output] = runTrim(['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES]);
    $rows = trimmedRows($output, 'xml');

    expect($report->removedTables)->toBe(['Plan', 'Plan_Travel', 'Allocation'])
        ->and(array_keys($rows))->not->toContain('Plan', 'Plan_Travel', 'Allocation')
        ->and($rows['Activity'])->toBe(trimDataset()['Activity'])
        ->and($report->tables['Plan_Travel'])->toBe(['before' => 2, 'kept' => 0])
        ->and($report->dangling)->toBe([])
        ->and($report->summaryLine(false))->toBe('Removed 3 tables and 5 rows. Kept 18 tables and 45 rows.')
        ->and($report->bytesWritten)->toBe(filesize($output));
});

it('never cascades from a table the run removes', function () {
    [$report] = runTrim(['tables' => ['Plan']]);

    expect($report->tables['Plan_Travel']['kept'])->toBe(2)
        ->and($report->tables['Allocation']['kept'])->toBe(2);
});

it('keeps the rows a region and a window choose, and every row that depends on them', function (string $format) {
    [$report, $output] = runTrim(REGION_AND_WINDOW, $format);

    expect(keptCounts($report))->toBe([
        'Input_Reference' => 1, 'Region' => 2, 'Location' => 4, 'Location_Region' => 1, 'Resource_Type' => 1,
        'Resources' => 1, 'Resource_Region' => 1, 'Shift' => 1, 'Shift_Break' => 1, 'Availability' => 2,
        'Resource_Region_Availability' => 1, 'Resource_Skill_Availability' => 1, 'Activity_Type' => 2, 'Activity' => 2,
        'Activity_SLA' => 2, 'Activity_Status' => 1, 'Activity_Group' => 1, 'Activity_Skill' => 1, 'Plan' => 1,
        'Plan_Travel' => 1, 'Allocation' => 1,
    ])->and(array_column(trimmedRows($output, $format)['Activity_Group'], 'activity_id2'))->toBe(['A3'])
        ->and($report->summaryLine(false))->toBe('Removed 0 tables and 21 rows. Kept 21 tables and 29 rows.');
})->with(['xml', 'json']);

it('keeps every Availability row a kept row points at, whatever its date', function () {
    [, $output] = runTrim(REGION_AND_WINDOW);

    expect(array_column(trimmedRows($output, 'xml')['Availability'], 'id'))->toBe(['AV1', 'AV3']);
});

it('keeps a Location only when a kept row uses it', function () {
    [, $output] = runTrim(REGION_AND_WINDOW);

    expect(array_column(trimmedRows($output, 'xml')['Location'], 'id'))->toBe(['L1', 'L3', 'LS1', 'LT'])
        ->and(array_column(trimmedRows($output, 'xml')['Location_Region'], 'location_id'))->toBe(['L3']);
});

it('keeps the status of a kept activity fixed to a removed resource and lists it as dangling', function () {
    [$report, $output] = runTrim(REGION_AND_WINDOW);

    expect(trimmedRows($output, 'xml')['Activity_Status'])->toBe([['activity_id' => 'A1', 'status_id' => '30', 'resource_id' => 'R2']])
        ->and($report->dangling)->toBe([['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']]]);
});

it('lists the references a removed table leaves pointing at nothing, with three examples at most', function () {
    [$report] = runTrim(['tables' => ['Activity']]);

    expect($report->tables['Activity_Status']['kept'])->toBe(2)
        ->and($report->dangling)->toBe([
            ['table' => 'Activity_SLA', 'field' => 'activity_id', 'count' => 5, 'examples' => ['A1', 'A2', 'A3']],
            ['table' => 'Activity_Skill', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
            ['table' => 'Activity_Status', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
            ['table' => 'Allocation', 'field' => 'activity_id', 'count' => 2, 'examples' => ['A1', 'A2']],
        ]);
});

it('sets the Input_Reference datetime when asked, in xml, json and a one-row json object', function () {
    [, $xml] = runTrim(['input_reference_datetime' => '2026-10-05 06:00']);
    [, $json] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], 'json');
    [, $single] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], 'json', singleObjects: ['Input_Reference']);

    expect(trimmedRows($xml, 'xml')['Input_Reference'][0]['datetime'])->toBe('2026-10-05T06:00:00')
        ->and(trimmedRows($json, 'json')['Input_Reference'][0]['datetime'])->toBe('2026-10-05T06:00:00')
        ->and(json_decode((string) file_get_contents($single), true)['dsScheduleData']['Input_Reference'])
        ->toBe(['id' => 'IR1', 'datetime' => '2026-10-05T06:00:00', 'dataset_id' => 'NORTH']);
});

it('says so when there is no Input_Reference datetime to override', function () {
    [$report] = runTrim(['input_reference_datetime' => '2026-10-05 06:00'], tables: ['Activity' => [['id' => 'A1']]]);

    expect($report->notes)->toBe(['The Input_Reference datetime was not changed: the file has no Input_Reference row with a datetime, or it is removed.']);
});

it('keeps Input_Reference in Keep mode unless its removal is allowed', function () {
    [$locked] = runTrim(['table_mode' => 'keep', 'tables' => ['Activity']]);
    [$allowed] = runTrim(['table_mode' => 'keep', 'tables' => ['Activity'], 'allow_input_reference_removal' => true]);

    expect(array_filter(keptCounts($locked)))->toBe(['Input_Reference' => 1, 'Activity' => 4])
        ->and(array_filter(keptCounts($allowed)))->toBe(['Activity' => 4])
        ->and($allowed->removedTables)->toContain('Input_Reference');
});

it('counts the same rows on Count only and writes nothing', function () {
    $options = ['tables' => TableDependencies::CLEANUP_TABLES, ...REGION_AND_WINDOW];
    [$written] = runTrim($options);
    [$counted, $output] = runTrim($options, write: false);

    expect($output)->toBeNull()
        ->and($counted->tables)->toBe($written->tables)
        ->and($counted->dangling)->toBe($written->dangling)
        ->and($counted->bytesWritten)->toBe(0)
        ->and($counted->summaryLine(true))->toBe('Would remove 3 tables and 24 rows, and keep 18 tables and 26 rows. Nothing was written (Count only).');
});

it('gives the same kept rows and counts for the same data as xml and as json', function () {
    [$xmlReport, $xml] = runTrim(REGION_AND_WINDOW);
    [$jsonReport, $json] = runTrim(REGION_AND_WINDOW, 'json');

    expect(trimmedRows($json, 'json'))->toBe(trimmedRows($xml, 'xml'))
        ->and($jsonReport->tables)->toBe($xmlReport->tables)
        ->and($jsonReport->dangling)->toBe($xmlReport->dangling);
});

it('notes filters that match nothing and still writes a loadable file', function () {
    [$report, $output] = runTrim(['region_ids' => ['NOPE']]);

    expect($report->notes)->toBe([
        'No resources match the row filters, so the file keeps none.',
        'No activities match the row filters, so the file keeps none.',
    ])->and($report->tables['Activity']['kept'])->toBe(0)
        ->and(trimmedRows($output, 'xml')['Input_Reference'])->toHaveCount(1);
});

it('reports progress for each pass', function () {
    $labels = [];
    app(TrimEngine::class)->run(trimXml(trimDataset()), 'xml', TrimPlan::fromOptions(REGION_AND_WINDOW, 'UTC'), null,
        static function (string $label, int $percent) use (&$labels): void {
            $labels[$label] = $percent;
        });

    expect(array_keys($labels))->toBe([TrimEngine::PASS_ONE, TrimEngine::PASS_TWO])
        ->and($labels[TrimEngine::PASS_TWO])->toBe(50);
});
```

The Count only numbers: the Cleanup preset removes Plan, Plan_Travel and Allocation whole; with the region and window 26 of the 50 rows of `trimDataset()` stay (the 29 of the region and window test less the one kept row each of Plan, Plan_Travel and Allocation), so 24 go.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/TrimEngineTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\TrimEngine" not found`.

- [ ] **Step 3: Write the referential check**

Create `app/Support/TrimExport/ReferenceTally.php`:

```php
<?php

namespace App\Support\TrimExport;

/**
 * The writer's own tally of what it kept: the ids of the tables rows point at, and every reference field of
 * every kept row. Anything still pointing at an id that was not kept is dangling. A target table the input
 * never had is not checked (there was nothing to keep). Memory: distinct ids only.
 */
class ReferenceTally
{
    /** Reference field => the table holding the ids it points at (load-audit REFS in pso-tools). */
    public const array TARGETS = [
        'activity_id' => 'Activity',
        'resource_id' => 'Resources',
        'availability_id' => 'Availability',
        'location_id' => 'Location',
        'location_id_start' => 'Location',
        'location_id_end' => 'Location',
        'shift_id' => 'Shift',
    ];

    public const array FIELDS = ['id', 'activity_id', 'resource_id', 'availability_id', 'location_id', 'location_id_start', 'location_id_end', 'shift_id'];

    /** @var array<string, array<string|int, true>> target table => kept ids */
    private array $ids = [];

    /** @var array<string, array<string, array<string|int, int>>> table => field => value => rows */
    private array $references = [];

    /** @param array<string, string> $fields */
    public function record(string $table, array $fields): void
    {
        if (isset($fields['id']) && in_array($table, self::TARGETS, true)) {
            $this->ids[$table][$fields['id']] = true;
        }

        foreach (self::TARGETS as $field => $target) {
            if (isset($fields[$field])) {
                $value = $fields[$field];
                $this->references[$table][$field][$value] = ($this->references[$table][$field][$value] ?? 0) + 1;
            }
        }
    }

    /**
     * @param  list<string>  $presentTables  tables the input had
     * @return list<array{table: string, field: string, count: int, examples: list<string>}>
     */
    public function dangling(array $presentTables): array
    {
        $present = array_flip($presentTables);
        $found = [];

        foreach ($this->references as $table => $fields) {
            foreach ($fields as $field => $values) {
                $target = self::TARGETS[$field];
                if (! isset($present[$target])) {
                    continue;
                }

                $count = 0;
                $examples = [];
                foreach ($values as $value => $rows) {
                    if (isset($this->ids[$target][$value])) {
                        continue;
                    }
                    $count += $rows;
                    if (count($examples) < 3) {
                        $examples[] = (string) $value;
                    }
                }

                if ($count > 0) {
                    $found[] = ['table' => (string) $table, 'field' => $field, 'count' => $count, 'examples' => $examples];
                }
            }
        }

        usort($found, static fn (array $first, array $second): int => [$first['table'], $first['field']] <=> [$second['table'], $second['field']]);

        return $found;
    }
}
```

- [ ] **Step 4: Write the report**

Create `app/Support/TrimExport/TrimReport.php`:

```php
<?php

namespace App\Support\TrimExport;

/** What one trim did: rows before and kept per table, the tables removed whole, what is left dangling, and notes. */
class TrimReport
{
    /**
     * @param  array<string, array{before: int, kept: int}>  $tables  in file order
     * @param  list<string>  $removedTables
     * @param  list<array{table: string, field: string, count: int, examples: list<string>}>  $dangling
     * @param  list<string>  $notes  plain English, never an id
     */
    public function __construct(
        public array $tables,
        public array $removedTables,
        public array $dangling,
        public array $notes,
        public int $bytesWritten,
    ) {}

    /** @return list<string> */
    public function keptTables(): array
    {
        return array_values(array_diff(array_keys($this->tables), $this->removedTables));
    }

    public function rowsKept(): int
    {
        return array_sum(array_column($this->tables, 'kept'));
    }

    public function rowsRemoved(): int
    {
        return array_sum(array_column($this->tables, 'before')) - $this->rowsKept();
    }

    /** Counts only, never ids or names: this line is stored in ToolRun.summary. */
    public function summaryLine(bool $countOnly): string
    {
        $removedTables = self::plural(count($this->removedTables), 'table');
        $removedRows = self::plural($this->rowsRemoved(), 'row');
        $keptTables = self::plural(count($this->keptTables()), 'table');
        $keptRows = self::plural($this->rowsKept(), 'row');

        return $countOnly
            ? "Would remove {$removedTables} and {$removedRows}, and keep {$keptTables} and {$keptRows}. Nothing was written (Count only)."
            : "Removed {$removedTables} and {$removedRows}. Kept {$keptTables} and {$keptRows}.";
    }

    public static function plural(int $count, string $word): string
    {
        return number_format($count).' '.($count === 1 ? $word : $word.'s');
    }
}
```

- [ ] **Step 5: Write pass two**

Create `app/Support/TrimExport/TrimEngine.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\Writers\JsonTableWriter;
use App\Support\TrimExport\Writers\XmlTableWriter;
use Closure;
use Throwable;

/**
 * Trims one schedule data file. Tables first: a row of a removed table is skipped and never cascades. Then,
 * when a row filter is set, pass one (KeyCollector) decides the keep sets and pass two skips every row of a
 * kept table that points at a removed activity, resource, location or shift. Everything else is copied byte
 * for byte, and the Input_Reference row takes the datetime override. The writer's tally gives the dangling list.
 */
class TrimEngine
{
    public const string PASS_ONE = 'Reading the keys (pass one)';

    public const string PASS_TWO = 'Writing the trimmed file (pass two)';

    public function __construct(
        private XmlTableReader $xmlReader,
        private JsonTableReader $jsonReader,
        private KeyCollector $keys,
    ) {}

    /** @param Closure(string, int): void|null $progress label and percent */
    public function run(string $path, string $format, TrimPlan $plan, ?string $outputPath, ?Closure $progress = null): TrimReport
    {
        $reader = $format === 'json' ? $this->jsonReader : $this->xmlReader;
        $keep = null;
        $start = 0;

        if ($plan->filtersRows()) {
            if ($progress !== null) {
                $progress(self::PASS_ONE, 0);
            }
            $keep = $this->keys->collect($reader, $path, $plan, $progress === null ? null : static fn (int $percent) => $progress(self::PASS_ONE, intdiv($percent, 2)));
            $start = 50;
        }

        if ($progress !== null) {
            $progress(self::PASS_TWO, $start);
        }

        $writer = $outputPath === null ? null : ($format === 'json' ? new JsonTableWriter : new XmlTableWriter);
        $writer?->open($outputPath);

        $fieldNames = array_values(array_unique([...KeepSets::FIELDS, ...ReferenceTally::FIELDS]));
        $tally = new ReferenceTally;
        $tables = [];
        $dropped = [];
        $overrideApplied = false;
        $size = max(1, (int) @filesize($path));
        $rows = 0;

        try {
            foreach ($reader->segments($path) as $segment) {
                if ($segment->type === Segment::HEAD || $segment->type === Segment::TAIL) {
                    $writer?->write($segment, true);

                    continue;
                }

                $table = $segment->table;
                $dropped[$table] ??= $plan->dropsTable($table);
                $tables[$table] ??= ['before' => 0, 'kept' => 0];

                if ($segment->type !== Segment::ROW) {
                    $writer?->write($segment, ! $dropped[$table]);

                    continue;
                }

                $tables[$table]['before']++;
                $keepRow = false;
                $body = null;

                if (! $dropped[$table]) {
                    $fields = $reader->fieldsOf($segment->body, $fieldNames);
                    $keepRow = $keep === null || $keep->keepsRow($table, $fields);

                    if ($keepRow) {
                        $tables[$table]['kept']++;
                        $tally->record($table, $fields);

                        if ($table === TableDependencies::INPUT_REFERENCE && $plan->inputReferenceDatetime !== null) {
                            $body = $reader->replaceField($segment->body, 'datetime', $plan->inputReferenceDatetime);
                            $overrideApplied = $overrideApplied || $body !== $segment->body || $reader->fieldsOf($segment->body, ['datetime']) !== [];
                        }
                    }
                }

                $writer?->write($segment, $keepRow, $body);

                if ($progress !== null && ++$rows % 5000 === 0) {
                    $progress(self::PASS_TWO, min(100, $start + intdiv($segment->offset * (100 - $start), $size)));
                }
            }

            $bytesWritten = $writer?->close() ?? 0;
        } catch (Throwable $exception) {
            $writer?->abort();

            throw $exception;
        }

        $notes = $keep?->notes ?? [];
        if ($plan->inputReferenceDatetime !== null && ! $overrideApplied) {
            $notes[] = 'The Input_Reference datetime was not changed: the file has no Input_Reference row with a datetime, or it is removed.';
        }

        $present = array_map('strval', array_keys($tables));

        return new TrimReport($tables, $plan->droppedTables($present), $tally->dangling($present), $notes, $bytesWritten);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/TrimExport/ReferenceTally.php app/Support/TrimExport/TrimReport.php app/Support/TrimExport/TrimEngine.php tests/Unit/TrimExport/TrimEngineTest.php
git commit -m "$(cat <<'EOF'
Trim export: pass two with the cascade, Availability never by date, Location kept when used, the Input_Reference override, Count only and the dangling list (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 7: The tool: run both passes, file the output as a new export, one input only

**Files:**
- Create: `app/Tools/Contracts/TakesOneInput.php`
- Modify: `app/Support/Tools/StartToolRun.php` (refuse none or more than one of the one-of kinds)
- Modify: `app/Support/Tools/UnusableInputException.php` (add `oneOf()`)
- Modify: `app/Support/Exports/ExportStorage.php` (add `putFromLocal()`)
- Create: `app/Support/TrimExport/TrimmedExportStore.php`
- Modify: `app/Tools/TrimExport/TrimExportTool.php` (implements `TakesOneInput`; real `run()`)
- Create: `tests/Feature/TrimExport/TrimExportToolTest.php`
- Modify: `tests/Feature/ExportStorageTest.php`

**Interfaces:**
- Consumes: `TrimEngine::run()`, `TrimReport` (Task 6), `ExportSource::open()` (Task 3), `TrimPlan::fromOptions()`, `TableDependencies::warnings()` (Task 1), `ToolContext::reportProgress()`, `SummariseExportFileJob`.
- Produces:
  - `TakesOneInput::oneOfKinds(): array` (list<ExportKind>).
  - `StartToolRun::start()` throws `UnusableInputException` ("Choose one LOAD, Plan or End of day export.") for none and `InvalidArgumentException` ("Trim export takes one export at a time.") for more than one.
  - `UnusableInputException::oneOf(array $kinds): self`.
  - `ExportStorage::putFromLocal(ExportFile $file, string $localPath): void`.
  - `TrimmedExportStore::store(ToolContext $context, ExportFile $input, string $localPath, string $contentType): ExportFile`, `TrimmedExportStore::trimmedName(string $originalName, string $extension): string`.
  - The run's `result`: `tables`, `removed_tables`, `dangling`, `warnings`, `count_only`, `format`, `zipped`, `export_file_id` (?string). The run's `summary`: `line`, `tables_removed`, `rows_kept`, `rows_removed`, `count_only` (counts only). The run's `warnings`: dependency warnings, engine notes, and one plain sentence when anything is dangling (never ids).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ExportStorageTest.php`:

```php
it('stores a finished local file under the export object key', function () {
    $file = ExportFile::factory()->create(['content_type' => 'xml']);
    $local = tempnam(sys_get_temp_dir(), 'put_');
    file_put_contents($local, '<dsScheduleData/>');

    app(ExportStorage::class)->putFromLocal($file, $local);

    expect(Storage::disk('r2')->get(app(ExportStorage::class)->objectKey($file)))->toBe('<dsScheduleData/>');
});
```

Create `tests/Feature/TrimExport/TrimExportToolTest.php`:

```php
<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Support\TrimExport\TableDependencies;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

/** An export of $kind on $scope holding the bytes of $path. */
function storedTrimInput(Model $scope, ExportKind $kind, string $path, string $name, string $contentType): ExportFile
{
    $file = ExportFile::factory()->for($scope, 'scope')->create(['kind' => $kind, 'original_filename' => $name, 'content_type' => $contentType]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents($path));

    return $file;
}

/** @param array<string, mixed> $options */
function runTrimJob(Model $scope, ExportFile $input, array $options, User $user): ToolRun
{
    $run = ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::TrimExport, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null,
        'started_by_user_id' => $user->id,
        'inputs' => ['files' => [$input->kind->value => $input->id], 'options' => $options],
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    return $run->refresh();
}

/** The bytes of a stored export, in a local file. */
function storedTrimOutput(ExportFile $file): string
{
    $local = tempnam(sys_get_temp_dir(), 'trimmed_');
    file_put_contents($local, Storage::disk('r2')->get(app(ExportStorage::class)->objectKey($file)));

    return $local;
}

it('trims a dataset export into a new export beside it, named .trimmed, and queues its summary', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'north-load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES], $this->user);

    $trimmed = ExportFile::query()->whereKeyNot($input->id)->sole();
    $local = storedTrimOutput($trimmed);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($trimmed->scope_type)->toBe($this->dataset->getMorphClass())
        ->and((string) $trimmed->scope_id)->toBe((string) $this->dataset->getKey())
        ->and($trimmed->kind)->toBe(ExportKind::Load)
        ->and($trimmed->original_filename)->toBe('north-load.trimmed.xml')
        ->and($trimmed->content_type)->toBe('xml')
        ->and($trimmed->uploaded_by_user_id)->toBe($this->user->id)
        ->and($trimmed->status)->toBe(ExportFileStatus::Summarising)
        ->and($trimmed->file_size_bytes)->toBe(filesize($local))
        ->and($trimmed->expires_at->greaterThan(now()->addHours(11)))->toBeTrue()
        ->and(array_keys(trimmedRows($local, 'xml')))->not->toContain('Plan', 'Plan_Travel', 'Allocation')
        ->and($run->result['export_file_id'])->toBe($trimmed->id)
        ->and($run->result['removed_tables'])->toBe(['Plan', 'Plan_Travel', 'Allocation'])
        ->and($run->summary)->toMatchArray(['line' => 'Removed 3 tables and 5 rows. Kept 18 tables and 45 rows.', 'tables_removed' => 3, 'rows_kept' => 45, 'rows_removed' => 5, 'count_only' => false])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
    Queue::assertPushed(SummariseExportFileJob::class, static fn (SummariseExportFileJob $job): bool => $job->exportFileId === $trimmed->id);
});

it('keeps customer ids and file names out of the summary and warnings, and in the result', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'north-load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], $this->user);
    $plain = json_encode([$run->summary, $run->warnings]);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($plain)->not->toContain('R2')->not->toContain('north-load')->not->toContain('V1')->not->toContain('A1')
        ->and($run->warnings)->toBe(['Some kept rows point at rows that are not in the file. The list below names them.'])
        ->and($run->result['dangling'])->toBe([['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']]]);
});

it('files the output of an unfiled run under the user', function () {
    $input = storedTrimInput($this->user, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');

    $run = runTrimJob($this->user, $input, ['tables' => ['Plan']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);

    expect($trimmed->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $trimmed->scope_id)->toBe((string) $this->user->id)
        ->and($trimmed->original_filename)->toBe('load.trimmed.xml');
});

it('writes nothing on Count only', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['tables' => TableDependencies::CLEANUP_TABLES, 'count_only' => true], $this->user);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and(ExportFile::query()->count())->toBe(1)
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(1)
        ->and($run->result['export_file_id'])->toBeNull()
        ->and($run->result['count_only'])->toBeTrue()
        ->and($run->summary['line'])->toBe('Would remove 3 tables and 5 rows, and keep 18 tables and 45 rows. Nothing was written (Count only).');
    Queue::assertNotPushed(SummariseExportFileJob::class);
});

it('gives a zip back for a zip, reading a zip named .xml as a zip', function () {
    $zip = zipOf(trimXml(trimDataset()), 'File_2026_10_04.xml');
    $input = storedTrimInput($this->dataset, ExportKind::Load, $zip, 'load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['tables' => ['Plan', 'Plan_Travel', 'Allocation']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);
    $archive = new ZipArchive;
    $archive->open(storedTrimOutput($trimmed));
    $member = tempnam(sys_get_temp_dir(), 'member_');
    file_put_contents($member, $archive->getFromIndex(0));

    expect($trimmed->original_filename)->toBe('load.trimmed.zip')
        ->and($trimmed->content_type)->toBe('zip')
        ->and($archive->numFiles)->toBe(1)
        ->and($archive->getNameIndex(0))->toBe('File_2026_10_04.trimmed.xml')
        ->and(array_keys(trimmedRows($member, 'xml')))->not->toContain('Plan')
        ->and($run->result['zipped'])->toBeTrue();
});

it('trims an End of day json export and keeps its kind', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Eod, trimJson(trimDataset()), 'eod.json', 'json');

    $run = runTrimJob($this->dataset, $input, ['region_ids' => ['V1']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);

    expect($trimmed->kind)->toBe(ExportKind::Eod)
        ->and($trimmed->original_filename)->toBe('eod.trimmed.json')
        ->and(array_column(trimmedRows(storedTrimOutput($trimmed), 'json')['Activity'], 'id'))->toBe(['A1', 'A3', 'A4']);
});

it('removes the new export row when storing it fails', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');
    $this->partialMock(ExportStorage::class, static function ($mock): void {
        $mock->shouldReceive('putFromLocal')->andThrow(new RuntimeException('The file could not be stored.'));
    });

    $run = runTrimJob($this->dataset, $input, ['tables' => ['Plan']], $this->user);

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('The file could not be stored.')
        ->and(ExportFile::query()->count())->toBe(1);
    Queue::assertNotPushed(SummariseExportFileJob::class);
});

it('starts a run on exactly one of LOAD, plan and End of day', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan]);
    $start = app(StartToolRun::class);

    expect(fn () => $start->start($this->dataset, ToolId::TrimExport, [], [], $this->user))
        ->toThrow(UnusableInputException::class, 'Choose one LOAD, Plan or End of day export.');
    expect(fn () => $start->start($this->dataset, ToolId::TrimExport, ['load' => $load->id, 'plan' => $plan->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class, 'Trim export takes one export at a time.');
    expect(ToolRun::query()->count())->toBe(0);

    $run = $start->start($this->dataset, ToolId::TrimExport, ['load' => null, 'plan' => $plan->id], [], $this->user);

    expect($run->inputs['files'])->toBe(['plan' => $plan->id]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/TrimExport/TrimExportToolTest.php tests/Feature/ExportStorageTest.php`
Expected: FAIL with `Call to undefined method App\Support\Exports\ExportStorage::putFromLocal()` and the stub's "Trim export is not ready yet."

- [ ] **Step 3: Store a local file**

In `app/Support/Exports/ExportStorage.php` add after `readToScratch()`:

```php
    /** Uploads a finished local file (a tool's output in scratch) to the export's object key. */
    public function putFromLocal(ExportFile $file, string $localPath): void
    {
        $stream = @fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The file to store could not be opened.');
        }

        try {
            if ($this->disk()->writeStream($this->objectKey($file), $stream) === false) {
                throw new RuntimeException('The file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
```

- [ ] **Step 4: One input of several kinds**

Create `app/Tools/Contracts/TakesOneInput.php`:

```php
<?php

namespace App\Tools\Contracts;

use App\Enums\ExportKind;

/** A tool that takes exactly one export of any one of these kinds. It declares them optional, so no kind is required on its own. */
interface TakesOneInput
{
    /** @return list<ExportKind> */
    public function oneOfKinds(): array;
}
```

In `app/Support/Tools/UnusableInputException.php` add:

```php
    /** @param list<ExportKind> $kinds */
    public static function oneOf(array $kinds): self
    {
        $labels = array_map(static fn (ExportKind $kind): string => $kind->getLabel(), $kinds);
        $last = array_pop($labels);

        return new self($kinds[0], 'Choose one '.($labels === [] ? $last : implode(', ', $labels).' or '.$last).' export.');
    }
```

In `app/Support/Tools/StartToolRun.php` add `use App\Enums\ExportKind;` and `use App\Tools\Contracts\TakesOneInput;`, and directly before `$this->assertUsable($scope, $toolId, $chosen);` add:

```php
        if ($tool instanceof TakesOneInput) {
            $picked = array_filter($tool->oneOfKinds(), static fn (ExportKind $kind): bool => isset($chosen[$kind->value]));
            if ($picked === []) {
                throw UnusableInputException::oneOf($tool->oneOfKinds());
            }
            if (count($picked) > 1) {
                throw new InvalidArgumentException("{$toolId->getLabel()} takes one export at a time.");
            }
        }
```

- [ ] **Step 5: File the output as a new export**

Create `app/Support/TrimExport/TrimmedExportStore.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Enums\ExportFileStatus;
use App\Jobs\SummariseExportFileJob;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolContext;
use Throwable;

/**
 * Files a trimmed export where its input came from (the dataset, or the user for Unfiled), of the input's kind,
 * uploaded by the user who started the run, then queues its summary so it gets table counts and can feed
 * another tool. It expires like any upload. A failed store leaves no row behind.
 */
class TrimmedExportStore
{
    public function __construct(private ExportStorage $storage) {}

    public function store(ToolContext $context, ExportFile $input, string $localPath, string $contentType): ExportFile
    {
        $file = ExportFile::create([
            'scope_type' => $context->scope->getMorphClass(),
            'scope_id' => (string) $context->scope->getKey(),
            'kind' => $input->kind,
            'uploaded_by_user_id' => $context->run->started_by_user_id,
            'original_filename' => self::trimmedName((string) $input->original_filename, $contentType),
            'stored_path' => '',
            'file_size_bytes' => (int) filesize($localPath),
            'content_type' => $contentType,
            'status' => ExportFileStatus::Uploading,
        ]);

        try {
            $file->update(['stored_path' => $this->storage->objectKey($file)]);
            $this->storage->putFromLocal($file, $localPath);
        } catch (Throwable $exception) {
            $file->delete();

            throw $exception;
        }

        $file->forceFill(['status' => ExportFileStatus::Summarising])->save();
        SummariseExportFileJob::dispatch($file->id);

        return $file;
    }

    /** "north-load.xml" gives "north-load.trimmed.xml"; the extension is the stored format's, so a zip named .xml becomes .trimmed.zip. */
    public static function trimmedName(string $originalName, string $extension): string
    {
        $base = pathinfo(basename($originalName), PATHINFO_FILENAME);

        return ($base === '' ? 'export' : $base).'.trimmed.'.$extension;
    }
}
```

- [ ] **Step 6: Write the tool**

Replace `app/Tools/TrimExport/TrimExportTool.php` with:

```php
<?php

namespace App\Tools\TrimExport;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\ExportFile;
use App\Support\TrimExport\ExportSource;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimmedExportStore;
use App\Support\TrimExport\TrimPlan;
use App\Tools\Contracts\TakesOneInput;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\ToolContext;
use RuntimeException;
use ZipArchive;

/**
 * Trim export: one LOAD, plan or End of day export in (XML or JSON, zipped or not), a trimmed copy in the same
 * format filed next to it out, or only the counts on Count only. Its options sit on two wizard steps (Tables,
 * Rows) built by TrimExportEntry, because they depend on the chosen file.
 */
class TrimExportTool implements TakesOneInput, Tool
{
    public function __construct(
        private ExportSource $source,
        private TrimEngine $engine,
        private TrimmedExportStore $store,
    ) {}

    public static function id(): ToolId
    {
        return ToolId::TrimExport;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];
    }

    public function oneOfKinds(): array
    {
        return $this->optionalKinds();
    }

    public function optionFields(): array
    {
        return [];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        if (count($inputPaths) !== 1) {
            throw new RuntimeException('Trim export takes exactly one LOAD, plan or End of day export.');
        }

        $kindValue = (string) array_key_first($inputPaths);
        $localPath = $inputPaths[$kindValue];
        $input = ExportFile::withoutGlobalScopes()->find($context->run->inputs['files'][$kindValue] ?? null)
            ?? throw new RuntimeException('The export used by this run is no longer available.');
        $scratch = dirname($localPath);

        $context->reportProgress('Opening the export', 0, null, 'open');
        $source = $this->source->open($localPath);
        $plan = TrimPlan::fromOptions($options, $context->timeZone);
        $output = $plan->countOnly ? null : $scratch.'/trimmed.'.$source->format;

        $report = $this->engine->run($source->path, $source->format, $plan, $output,
            static fn (string $label, int $percent) => $context->reportProgress($label, $percent, null, $label));

        $newFile = null;
        if ($output !== null) {
            $context->reportProgress('Storing the trimmed export', 100, null, 'store');
            $newFile = $source->zipped()
                ? $this->store->store($context, $input, $this->zip($output, (string) $source->memberName, $scratch.'/trimmed.zip'), 'zip')
                : $this->store->store($context, $input, $output, $source->format);
        }

        $warnings = [...TableDependencies::warnings(array_map('strval', array_keys($report->tables)), $report->removedTables), ...$report->notes];
        if ($report->dangling !== []) {
            $warnings[] = 'Some kept rows point at rows that are not in the file. The list below names them.';
        }

        return new ToolResult(
            result: [
                'tables' => $report->tables,
                'removed_tables' => $report->removedTables,
                'dangling' => $report->dangling,
                'warnings' => $warnings,
                'count_only' => $plan->countOnly,
                'format' => $source->format,
                'zipped' => $source->zipped(),
                'export_file_id' => $newFile?->id,
            ],
            warnings: $warnings,
            summary: [
                'line' => $report->summaryLine($plan->countOnly),
                'tables_removed' => count($report->removedTables),
                'rows_kept' => $report->rowsKept(),
                'rows_removed' => $report->rowsRemoved(),
                'count_only' => $plan->countOnly,
            ],
        );
    }

    /** A zipped input gives a zip back, holding the one trimmed member. */
    private function zip(string $memberPath, string $memberName, string $zipPath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The trimmed export could not be zipped.');
        }

        $zip->addFile($memberPath, TrimmedExportStore::trimmedName($memberName, pathinfo($memberPath, PATHINFO_EXTENSION)));
        if (! $zip->close()) {
            throw new RuntimeException('The trimmed export could not be zipped.');
        }

        return $zipPath;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/TrimExport tests/Feature/ExportStorageTest.php tests/Feature/StartToolRunTest.php tests/Feature/RunToolJobTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Tools/Contracts/TakesOneInput.php app/Support/Tools/StartToolRun.php app/Support/Tools/UnusableInputException.php app/Support/Exports/ExportStorage.php app/Support/TrimExport/TrimmedExportStore.php app/Tools/TrimExport/TrimExportTool.php tests/Feature/TrimExport/TrimExportToolTest.php tests/Feature/ExportStorageTest.php
git commit -m "$(cat <<'EOF'
Trim export: the tool runs both passes in the queue and files the output as a new export beside its input; one LOAD, plan or EOD per run (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 8: The wizard, part one: which export, and the Tables step

**Files:**
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php` (hooks `inputKinds()`, `requiredInputKinds()`, `inputLead()`, `chosenFiles()`; `timeZoneField()` becomes protected)
- Create: `app/Filament/Pages/Tools/TrimExportEntry.php`
- Create: `app/Filament/Pages/Tools/TrimExport/TrimExportFields.php` (`tables()` now; `rows()` in Task 9)
- Create: `app/Support/TrimExport/TableCounter.php`, `app/Support/TrimExport/TableChoices.php`, `app/Support/TrimExport/ExportFileScanner.php`
- Create: `tests/Unit/TrimExport/TableChoicesTest.php`, `tests/Feature/TrimExport/TrimExportTablesStepTest.php`
- Modify: `tests/Feature/NavigationGroupsTest.php` (add the page)

**Interfaces:**
- Consumes: `TableDependencies::cleanupPresent()`, `TableDependencies::warnings()`, `TableDependencies::INPUT_REFERENCE`, `TrimPlan::fromOptions()`, `TrimPlan::droppedTables()`, `TrimPlan::MODE_REMOVE`, `TrimPlan::MODE_KEEP` (Task 1), `ExportSource::open()`, readers, `Segment` (Task 3), `TrimReport::plural()` (Task 6), `ExportStorage::scratchDirectoryFor()`, `readToScratch()`, `removeScratch()`.
- Produces:
  - `ToolWizardPage` hooks: `protected function inputKinds(): array` (list<ExportKind>, default every kind the tool takes), `protected function requiredInputKinds(): array` (default the tool's required kinds), `protected function inputLead(): array` (list<Component> above the file pickers, default `[]`), `protected function chosenFiles(array $files): array` (default unchanged), `protected function timeZoneField(): Select`.
  - `TrimExportEntry` (slug `tools/trim-export`): public `array $tableCounts`, `?string $tablesForFileId`; `chosenKind(): ExportKind`, `chosenExportFile(): ?ExportFile`, `prepareTables(): bool`, `resetTables(): void`. Form state `input_kind` and `options.table_mode`, `options.preset`, `options.tables`, `options.allow_input_reference_removal`.
  - `TrimExportFields::PRESET_CLEANUP = 'cleanup'`, `TrimExportFields::PRESET_CUSTOM = 'custom'`, `TrimExportFields::tables(): array`.
  - `TableCounter::count(string $path, string $format): array<string, int>`.
  - `TableChoices::GROUPS`, `TableChoices::groupOf(string $table): string`, `TableChoices::options(array $counts): array<string, string>`, `TableChoices::footer(array $counts, array $dropped): string`.
  - `ExportFileScanner::tableCounts(ExportFile $file): array<string, int>` (stored `table_counts`, else a scan cached encrypted under `trim-export:tables:<file id>` until the file expires).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TrimExport/TableChoicesTest.php`:

```php
<?php

use App\Support\TrimExport\TableChoices;

it('groups the tables in the spec order, by name within a group, with their row counts', function () {
    $options = TableChoices::options([
        'Resources' => 85, 'Plan_Travel' => 4812, 'Activity' => 7620, 'Input_Reference' => 1, 'Plan' => 1,
        'Appointment_Request' => 3, 'Location_Region' => 2566, 'Custom_Thing' => 1, 'Shift' => 2,
    ]);

    expect($options)->toBe([
        'Plan' => 'Plan output: Plan, 1 row',
        'Plan_Travel' => 'Plan output: Plan_Travel, 4,812 rows',
        'Appointment_Request' => 'Appointments and events: Appointment_Request, 3 rows',
        'Activity' => 'Activities: Activity, 7,620 rows',
        'Resources' => 'Resources and shifts: Resources, 85 rows',
        'Shift' => 'Resources and shifts: Shift, 2 rows',
        'Location_Region' => 'Locations and regions: Location_Region, 2,566 rows',
        'Custom_Thing' => 'Other: Custom_Thing, 1 row',
        'Input_Reference' => 'Other: Input_Reference, 1 row (always kept unless allowed below)',
    ]);
});

it('sums the rows on each side of the selection for the footer', function () {
    expect(TableChoices::footer(['Plan' => 1, 'Plan_Travel' => 4812, 'Activity' => 7620, 'Input_Reference' => 1], ['Plan', 'Plan_Travel']))
        ->toBe('Removing 2 tables, 4,813 rows. Keeping 2 tables, 7,621 rows.')
        ->and(TableChoices::footer([], []))->toBe('The tables are read from the file when you leave the Inputs step.');
});
```

Create `tests/Feature/TrimExport/TrimExportTablesStepTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Filament\Pages\Tools\TrimExportEntry;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->counts = ['Input_Reference' => 1, 'Activity' => 7620, 'Activity_Status' => 7620, 'Plan' => 1, 'Plan_Travel' => 4812, 'Resources' => 85];
});

/** @param array<string, mixed> $attributes */
function trimUnfiledExport(User $user, ExportKind $kind, array $attributes = []): ExportFile
{
    return ExportFile::factory()->for($user, 'scope')->create(['kind' => $kind, ...$attributes]);
}

it('asks which export to trim and shows one picker for that kind', function () {
    trimUnfiledExport($this->user, ExportKind::Load);

    Livewire::test(TrimExportEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'input_kind' => 'load'])
        ->assertSee('Export to trim')
        ->assertFormFieldExists('files.load')
        ->assertFormFieldDoesNotExist('files.plan')
        ->fillForm(['input_kind' => 'plan'])
        ->assertFormFieldExists('files.plan')
        ->assertFormFieldDoesNotExist('files.load');
});

it('opens on the kind of export the user has', function () {
    trimUnfiledExport($this->user, ExportKind::Plan);

    Livewire::test(TrimExportEntry::class)->assertFormSet(['input_kind' => 'plan']);
});

it('sends only the chosen kind even when a LOAD, plan and End of day are all preselected', function () {
    Queue::fake();
    $summary = ['export_date' => '2026-10-04', 'table_counts' => $this->counts];
    $load = trimUnfiledExport($this->user, ExportKind::Load, ['summary' => $summary]);
    $plan = trimUnfiledExport($this->user, ExportKind::Plan, ['summary' => $summary]);
    $eod = trimUnfiledExport($this->user, ExportKind::Eod, ['summary' => $summary]);

    Livewire::test(TrimExportEntry::class)
        ->assertFormSet(['files.load' => $load->id, 'files.plan' => $plan->id, 'files.eod' => $eod->id])
        ->fillForm(['input_kind' => 'plan'])
        ->call('prepareTables')
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['files'])->toBe(['plan' => $plan->id]);
});

it('lists the tables in the file with their row counts and ticks the Cleanup preset', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertFormSet(['options.tables' => ['Plan', 'Plan_Travel'], 'options.preset' => 'cleanup', 'options.table_mode' => 'remove'])
        ->assertSee('Plan output: Plan_Travel, 4,812 rows')
        ->assertSee('Removing 2 tables, 4,813 rows. Keeping 4 tables, 15,326 rows.');
});

it('switches the preset to Custom when a tick changes, and back to the Cleanup list when chosen', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->fillForm(['options.tables' => ['Plan']])
        ->assertFormSet(['options.preset' => 'custom'])
        ->fillForm(['options.preset' => 'cleanup'])
        ->assertFormSet(['options.tables' => ['Plan', 'Plan_Travel']]);
});

it('warns as soon as a kept table loses its parent', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertDontSee('is kept but')
        ->fillForm(['options.tables' => ['Activity']])
        ->assertSee('Activity_Status is kept but Activity is removed, so its rows will point at nothing.');
});

it('keeps Input_Reference locked until its removal is allowed', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertSee('(always kept unless allowed below)')
        ->fillForm(['options.tables' => ['Input_Reference', 'Plan']])
        ->assertSee('Removing 1 table, 1 row.')
        ->fillForm(['options.allow_input_reference_removal' => true])
        ->assertSee('Removing 2 tables, 2 rows.');
});

it('reads the tables from the file when its summary has none, once, and keeps the scan encrypted', function () {
    $file = trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['export_date' => '2026-10-04'], 'content_type' => 'xml']);
    $key = app(ExportStorage::class)->objectKey($file);
    Storage::disk('r2')->put($key, file_get_contents(trimXml(trimDataset())));

    Livewire::test(TrimExportEntry::class)->call('prepareTables')->assertSee('Activities: Activity, 4 rows');

    expect(Cache::get("trim-export:tables:{$file->id}"))->toBeString()->not->toContain('Activity');

    Storage::disk('r2')->delete($key);
    Livewire::test(TrimExportEntry::class)->call('prepareTables')->assertSee('Activities: Activity, 4 rows');
});

it('needs at least one tick in Keep mode', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->fillForm(['options.table_mode' => 'keep', 'options.tables' => []])
        ->call('start')
        ->assertHasFormErrors(['options.tables' => 'required']);

    expect(ToolRun::query()->count())->toBe(0);
});

it('does not leave Inputs without a file to read', function () {
    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertNotified('Choose an export first');
});
```

In `tests/Feature/NavigationGroupsTest.php` add to the dataset, after the `LoadAuditEntry` row:

```php
    [Pages\Tools\TrimExportEntry::class, Groups::ANALYSE],
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/TableChoicesTest.php tests/Feature/TrimExport/TrimExportTablesStepTest.php tests/Feature/NavigationGroupsTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\TableChoices" not found` and `Class "App\Filament\Pages\Tools\TrimExportEntry" not found`.

- [ ] **Step 3: Give the wizard four small hooks**

In `app/Filament/Pages/Tools/ToolWizardPage.php`:

Change `private function timeZoneField(): Select` to `protected function timeZoneField(): Select`.

In `inputFields()`, delete the line `$tool = app(ToolRegistry::class)->for(static::tool());`, and replace

```php
        foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
            $required = in_array($kind, $tool->requiredKinds(), true);
```

with

```php
        $requiredKinds = $this->requiredInputKinds();
        foreach ($this->inputKinds() as $kind) {
            $required = in_array($kind, $requiredKinds, true);
```

and change its final `return [` to `return [...$this->inputLead(),` (the `Group::make($rows)` and the `Text::make('Files are kept for ...')` entries stay as they are).

In `pendingKinds()` replace `app(RunInputPicker::class)->kindsOf(static::tool())` with `$this->inputKinds()`.

In `start()` replace `app(StartToolRun::class)->assertUsable($scope, static::tool(), $this->data['files'] ?? []);` with `app(StartToolRun::class)->assertUsable($scope, static::tool(), $this->chosenFiles($this->data['files'] ?? []));`, and `$state['files'] ?? []` in the `->start(...)` call with `$this->chosenFiles($state['files'] ?? [])`.

Add after `afterInputsChanged()`:

```php
    /** @return list<ExportKind> the kinds whose file pickers Inputs shows: every kind the tool takes, unless a page narrows them */
    protected function inputKinds(): array
    {
        return app(RunInputPicker::class)->kindsOf(static::tool());
    }

    /** @return list<ExportKind> */
    protected function requiredInputKinds(): array
    {
        return app(ToolRegistry::class)->for(static::tool())->requiredKinds();
    }

    /** @return list<Component> shown above the file pickers on Inputs */
    protected function inputLead(): array
    {
        return [];
    }

    /**
     * The files a run starts with; a page that shows only some pickers keeps only theirs.
     *
     * @param  array<string, string|null>  $files
     * @return array<string, string|null>
     */
    protected function chosenFiles(array $files): array
    {
        return $files;
    }
```

- [ ] **Step 4: Count tables and label them**

Create `app/Support/TrimExport/TableCounter.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;

/** Rows per table, streamed, for a file whose summary predates table_counts. */
class TableCounter
{
    public function __construct(private XmlTableReader $xmlReader, private JsonTableReader $jsonReader) {}

    /** @return array<string, int> table => rows, in file order */
    public function count(string $path, string $format): array
    {
        $counts = [];
        foreach (($format === 'json' ? $this->jsonReader : $this->xmlReader)->segments($path) as $segment) {
            if ($segment->type === Segment::TABLE_OPEN) {
                $counts[$segment->table] ??= 0;
            } elseif ($segment->type === Segment::ROW) {
                $counts[$segment->table] = ($counts[$segment->table] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
```

Create `app/Support/TrimExport/TableChoices.php`:

```php
<?php

namespace App\Support\TrimExport;

/**
 * The Tables step's list: tables grouped by what they hold (Filament's CheckboxList is flat, so the group is a
 * prefix and the order), each with its row count, and the footer that sums both sides of the selection.
 */
class TableChoices
{
    /** Group headings, in the order the list shows them. */
    public const array GROUPS = ['Plan output', 'Appointments and events', 'Activities', 'Resources and shifts', 'Locations and regions', 'Other'];

    public static function groupOf(string $table): string
    {
        return match (true) {
            str_starts_with($table, 'Plan') || str_starts_with($table, 'Allocation') => 'Plan output',
            str_starts_with($table, 'Appointment_') || str_starts_with($table, 'Schedule_')
                || str_starts_with($table, 'Explanation_') || str_starts_with($table, 'Broadcast') => 'Appointments and events',
            str_starts_with($table, 'Activity') => 'Activities',
            $table === 'Resources' || str_starts_with($table, 'Resource_') || str_starts_with($table, 'Shift') || $table === 'Availability' => 'Resources and shifts',
            str_starts_with($table, 'Location') || str_starts_with($table, 'Region') => 'Locations and regions',
            default => 'Other',
        };
    }

    /**
     * @param  array<string, int>  $counts  table => rows
     * @return array<string, string> table => "Plan output: Plan_Travel, 4,812 rows", in GROUPS order, by name within a group
     */
    public static function options(array $counts): array
    {
        $tables = array_map('strval', array_keys($counts));
        usort($tables, static fn (string $first, string $second): int => [array_search(self::groupOf($first), self::GROUPS, true), $first]
            <=> [array_search(self::groupOf($second), self::GROUPS, true), $second]);

        $options = [];
        foreach ($tables as $table) {
            $options[$table] = self::groupOf($table).': '.$table.', '.TrimReport::plural((int) $counts[$table], 'row')
                .($table === TableDependencies::INPUT_REFERENCE ? ' (always kept unless allowed below)' : '');
        }

        return $options;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $dropped
     */
    public static function footer(array $counts, array $dropped): string
    {
        if ($counts === []) {
            return 'The tables are read from the file when you leave the Inputs step.';
        }

        $droppedSet = array_flip($dropped);
        $removedTables = 0;
        $removedRows = 0;
        $keptTables = 0;
        $keptRows = 0;
        foreach ($counts as $table => $rows) {
            if (isset($droppedSet[$table])) {
                $removedTables++;
                $removedRows += (int) $rows;
            } else {
                $keptTables++;
                $keptRows += (int) $rows;
            }
        }

        return 'Removing '.TrimReport::plural($removedTables, 'table').', '.TrimReport::plural($removedRows, 'row').'. '
            .'Keeping '.TrimReport::plural($keptTables, 'table').', '.TrimReport::plural($keptRows, 'row').'.';
    }
}
```

- [ ] **Step 5: Read a file's tables for the wizard**

Create `app/Support/TrimExport/ExportFileScanner.php`:

```php
<?php

namespace App\Support\TrimExport;

use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * What the Trim export wizard shows about one export: its tables (from the stored summary, else a streamed
 * scan) and its filter choices for the Rows step. A scan is cached per export file until the file expires,
 * encrypted, because it can hold customer ids and names.
 */
class ExportFileScanner
{
    public function __construct(
        private ExportStorage $storage,
        private ExportSource $source,
        private TableCounter $counter,
    ) {}

    /** @return array<string, int> table => rows */
    public function tableCounts(ExportFile $file): array
    {
        $stored = $file->summary['table_counts'] ?? null;
        if (is_array($stored) && $stored !== []) {
            return array_map('intval', $stored);
        }

        return $this->remember($file, 'tables', fn (string $path, string $format): array => $this->counter->count($path, $format));
    }

    private function cacheKey(ExportFile $file, string $what): string
    {
        return "trim-export:{$what}:{$file->id}";
    }

    private function remember(ExportFile $file, string $what, Closure $scan): mixed
    {
        $key = $this->cacheKey($file, $what);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decrypt($cached);
            } catch (DecryptException) {
                Cache::forget($key);
            }
        }

        $value = $this->withLocalCopy($file, $scan);
        Cache::put($key, Crypt::encrypt($value), $file->expires_at ?? now()->addHours((int) config('exports.ttl_hours', 12)));

        return $value;
    }

    /** Streams the stored export to its own scratch folder, opens it (a zip's member) and scans it; the folder never outlives the call. */
    private function withLocalCopy(ExportFile $file, Closure $scan): mixed
    {
        $scratch = $this->storage->scratchDirectoryFor((string) Str::uuid());

        try {
            $source = $this->source->open($this->storage->readToScratch($file, $scratch));

            return $scan($source->path, $source->format);
        } finally {
            $this->storage->removeScratch($scratch);
        }
    }
}
```

- [ ] **Step 6: Write the Tables step fields**

Create `app/Filament/Pages/Tools/TrimExport/TrimExportFields.php`:

```php
<?php

namespace App\Filament\Pages\Tools\TrimExport;

use App\Filament\Pages\Tools\TrimExportEntry;
use App\Support\TrimExport\TableChoices;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimPlan;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The fields of Trim export's Tables and Rows steps. Option keys match TrimPlan::fromOptions(). */
class TrimExportFields
{
    public const string PRESET_CLEANUP = 'cleanup';

    public const string PRESET_CUSTOM = 'custom';

    /** @return list<Component> */
    public static function tables(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 3])->schema([
                Radio::make('options.table_mode')
                    ->label('Ticked tables are')
                    ->options([TrimPlan::MODE_REMOVE => 'Removed', TrimPlan::MODE_KEEP => 'Kept'])
                    ->default(TrimPlan::MODE_REMOVE)
                    ->inline()
                    ->required()
                    ->live(),
                Select::make('options.preset')
                    ->label('Preset')
                    ->options([self::PRESET_CLEANUP => 'Cleanup for input', self::PRESET_CUSTOM => 'Custom'])
                    ->helperText('Cleanup for input removes PSO output and other tables that do not belong in a file you load or compare as input.')
                    ->default(self::PRESET_CLEANUP)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(static function (?string $state, Set $set, TrimExportEntry $livewire): void {
                        if ($state === self::PRESET_CLEANUP) {
                            $set('options.tables', TableDependencies::cleanupPresent(array_keys($livewire->tableCounts)));
                        }
                    }),
                Toggle::make('options.allow_input_reference_removal')
                    ->label('Allow Input_Reference to be removed')
                    ->helperText('PSO will not load a file without it.')
                    ->default(false)
                    ->live(),
            ]),
            CheckboxList::make('options.tables')
                ->hiddenLabel()
                ->options(static fn (TrimExportEntry $livewire): array => TableChoices::options($livewire->tableCounts))
                ->disableOptionWhen(static fn (string $value, Get $get): bool => $value === TableDependencies::INPUT_REFERENCE && ! $get('options.allow_input_reference_removal'))
                ->searchable()
                ->bulkToggleable()
                ->columns(3)
                ->live()
                ->required(static fn (Get $get): bool => $get('options.table_mode') === TrimPlan::MODE_KEEP)
                ->validationMessages(['required' => 'Tick at least one table to keep.'])
                ->afterStateUpdated(static fn (Set $set) => $set('options.preset', self::PRESET_CUSTOM))
                ->columnSpanFull(),
            Text::make(static fn (Get $get, TrimExportEntry $livewire): string => TableChoices::footer($livewire->tableCounts, self::droppedTables($get, $livewire)))
                ->columnSpanFull(),
            Group::make(static fn (Get $get, TrimExportEntry $livewire): array => array_map(
                static fn (string $warning): Text => Text::make($warning)->color('warning'),
                TableDependencies::warnings(array_keys($livewire->tableCounts), self::droppedTables($get, $livewire)),
            ))->columnSpanFull(),
        ];
    }

    /** @return list<string> the tables the current choices remove, by the same rule the run uses */
    private static function droppedTables(Get $get, TrimExportEntry $livewire): array
    {
        return TrimPlan::fromOptions([
            'table_mode' => $get('options.table_mode'),
            'tables' => $get('options.tables') ?? [],
            'allow_input_reference_removal' => $get('options.allow_input_reference_removal'),
        ], 'UTC')->droppedTables(array_map('strval', array_keys($livewire->tableCounts)));
    }
}
```

- [ ] **Step 7: Write the page**

Create `app/Filament/Pages/Tools/TrimExportEntry.php`:

```php
<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\TrimExport\TrimExportFields;
use App\Models\ExportFile;
use App\Support\TrimExport\ExportFileScanner;
use App\Support\TrimExport\TableDependencies;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Locked;
use Override;
use RuntimeException;

/**
 * Trim export: Where, Inputs (one LOAD, plan or End of day export), Tables, Rows. Each option
 * step is filled from the chosen file when the step before it is done.
 */
class TrimExportEntry extends ToolWizardPage
{
    /** The kinds this page trims, in the order the choice lists them. */
    private const array KINDS = [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];

    /** @var array<string, int> table => rows in the chosen file (names and counts only) */
    public array $tableCounts = [];

    #[Locked]
    public ?string $tablesForFileId = null;

    public static function tool(): ToolId
    {
        return ToolId::TrimExport;
    }

    #[Override]
    public function mount(): void
    {
        parent::mount();

        // Open on the first kind the user has a file of.
        $files = $this->data['files'] ?? [];
        $this->data['input_kind'] = ExportKind::Load->value;
        foreach (self::KINDS as $kind) {
            if (filled($files[$kind->value] ?? null)) {
                $this->data['input_kind'] = $kind->value;

                break;
            }
        }
    }

    #[Override]
    protected function steps(): array
    {
        return [
            $this->whereStep(),
            $this->inputsStep()->afterValidation(function (): void {
                if (! $this->prepareTables()) {
                    throw new Halt;
                }
            }),
            Step::make('Tables')
                ->description('Which whole tables to remove or keep. Counts are rows in the chosen file.')
                ->schema(TrimExportFields::tables()),
        ];
    }

    #[Override]
    protected function inputLead(): array
    {
        return [
            Radio::make('input_kind')
                ->label('Export to trim')
                ->options(collect(self::KINDS)->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->getLabel()])->all())
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(static fn (self $livewire) => $livewire->resetTables()),
        ];
    }

    #[Override]
    protected function inputKinds(): array
    {
        return [$this->chosenKind()];
    }

    #[Override]
    protected function requiredInputKinds(): array
    {
        return [$this->chosenKind()];
    }

    #[Override]
    protected function chosenFiles(array $files): array
    {
        $kind = $this->chosenKind()->value;

        return [$kind => $files[$kind] ?? null];
    }

    /** A different file has different tables: forget the list read for the old one. */
    #[Override]
    protected function afterInputsChanged(): void
    {
        if ($this->tablesForFileId !== null && $this->tablesForFileId !== $this->chosenExportFile()?->id) {
            $this->resetTables();
        }
    }

    public function chosenKind(): ExportKind
    {
        $kind = ExportKind::tryFrom((string) ($this->data['input_kind'] ?? ''));

        return in_array($kind, self::KINDS, true) ? $kind : ExportKind::Load;
    }

    public function chosenExportFile(): ?ExportFile
    {
        $fileId = $this->data['files'][$this->chosenKind()->value] ?? null;

        return blank($fileId) ? null : ExportFile::query()->ready()->find($fileId);
    }

    /** Reads the chosen file's tables (stored counts, or a streamed scan) and ticks the Cleanup preset. False keeps the wizard on Inputs. */
    public function prepareTables(): bool
    {
        $file = $this->chosenExportFile();
        if ($file === null) {
            Notification::make()->title('Choose an export first')->body('Pick the '.$this->chosenKind()->getLabel().' export to trim.')->warning()->send();

            return false;
        }

        if ($file->id === $this->tablesForFileId) {
            return true;
        }

        try {
            $this->tableCounts = app(ExportFileScanner::class)->tableCounts($file);
        } catch (RuntimeException $exception) {
            Notification::make()->title('The tables could not be read')->body($exception->getMessage())->danger()->send();

            return false;
        }

        $this->tablesForFileId = $file->id;
        if (($this->data['options']['preset'] ?? TrimExportFields::PRESET_CLEANUP) === TrimExportFields::PRESET_CLEANUP) {
            $this->data['options']['tables'] = TableDependencies::cleanupPresent(array_keys($this->tableCounts));
            $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
        }

        return true;
    }

    public function resetTables(): void
    {
        $this->tableCounts = [];
        $this->tablesForFileId = null;
        $this->data['options']['tables'] = [];
        $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport/TableChoicesTest.php tests/Feature/TrimExport tests/Feature/NavigationGroupsTest.php tests/Feature/ToolWizardPageTest.php tests/Feature/ToolWizardUnfiledTest.php tests/Feature/PlanTravels/PlanTravelsWizardTest.php`
Expected: PASS (the existing wizard tests prove the hooks changed nothing for the other tools).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/Tools/ToolWizardPage.php app/Filament/Pages/Tools/TrimExportEntry.php app/Filament/Pages/Tools/TrimExport/TrimExportFields.php app/Support/TrimExport/TableCounter.php app/Support/TrimExport/TableChoices.php app/Support/TrimExport/ExportFileScanner.php tests/Unit/TrimExport/TableChoicesTest.php tests/Feature/TrimExport/TrimExportTablesStepTest.php tests/Feature/NavigationGroupsTest.php
git commit -m "$(cat <<'EOF'
Trim export wizard: choose one LOAD, plan or EOD; Tables step with grouped row counts, Remove or Keep, Cleanup preset, Input_Reference lock, live footer and dependency warnings (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 9: The wizard, part two: the Rows step

**Files:**
- Create: `app/Support/TrimExport/FilterChoices.php`
- Modify: `app/Support/TrimExport/ExportFileScanner.php` (add `choices()`, `cachedChoices()`, the readers)
- Modify: `app/Filament/Pages/Tools/TrimExport/TrimExportFields.php` (add `rows()`)
- Modify: `app/Filament/Pages/Tools/TrimExportEntry.php` (Rows step, `prepareRows()`, `filterChoices()`, `resetTables()` also clears the row choices)
- Create: `tests/Unit/TrimExport/FilterChoicesTest.php`, `tests/Feature/TrimExport/TrimExportRowsStepTest.php`

**Interfaces:**
- Consumes: `ExportFileScanner::remember()`, `cacheKey()`, `withLocalCopy()` (Task 8), `XmlTableReader`, `JsonTableReader`, `Segment` (Task 3), `ToolWizardPage::timeZoneField()` (Task 8).
- Produces:
  - `FilterChoices` with public `array $regions`, `array $resources`, `array $activityTypes`, `array $activities` (id => label), `array $resourceRegions` (resource id => list of region ids), `array $activityTypeOf` (activity id => type id); `resourcesIn(array $regionIds): array`, `activitiesOf(array $typeIds): array`.
  - `ExportFileScanner::choices(ExportFile $file): FilterChoices` (cached encrypted under `trim-export:choices:<file id>`), `ExportFileScanner::cachedChoices(ExportFile $file): ?FilterChoices`.
  - `TrimExportFields::rows(): array`. Option keys `region_ids`, `resource_ids`, `activity_type_ids`, `activity_ids`, `window_start`, `window_end` (`Y-m-d`), `input_reference_datetime`, `count_only`, and `time_zone` for an Unfiled run.
  - `TrimExportEntry::prepareRows(): bool`, `TrimExportEntry::filterChoices(): ?FilterChoices`, `#[Locked] ?string $choicesForFileId`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TrimExport/FilterChoicesTest.php`:

```php
<?php

use App\Support\TrimExport\FilterChoices;

beforeEach(function () {
    $this->choices = new FilterChoices(
        regions: ['V1' => 'V1 - Regina', 'V2' => 'V2 - Saskatoon'],
        resources: ['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing', 'R3' => 'R3'],
        activityTypes: ['T1' => 'T1 - Install (2)', 'T2' => 'T2 - Repair (1)'],
        activities: ['A1' => 'A1 - T1', 'A2' => 'A2 - T1', 'A3' => 'A3 - T2'],
        resourceRegions: ['R1' => ['V1'], 'R2' => ['V2', 'V1'], 'R3' => ['V2']],
        activityTypeOf: ['A1' => 'T1', 'A2' => 'T1', 'A3' => 'T2'],
    );
});

it('offers only the resources of the chosen regions, or every resource when none is chosen', function () {
    expect($this->choices->resourcesIn(['V1']))->toBe(['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing'])
        ->and($this->choices->resourcesIn([]))->toHaveCount(3);
});

it('offers only the activities of the chosen types, or every activity when none is chosen', function () {
    expect($this->choices->activitiesOf(['T2']))->toBe(['A3' => 'A3 - T2'])
        ->and($this->choices->activitiesOf([]))->toHaveCount(3);
});
```

Create `tests/Feature/TrimExport/TrimExportRowsStepTest.php`:

```php
<?php

use App\Enums\ExportKind;
use App\Filament\Pages\Tools\TrimExportEntry;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->file = ExportFile::factory()->for($this->user, 'scope')->create([
        'kind' => ExportKind::Load, 'content_type' => 'xml', 'summary' => ['table_counts' => ['Input_Reference' => 1, 'Activity' => 4]],
    ]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->file), file_get_contents(trimXml(trimDataset())));
});

function trimRowsReady(): Testable
{
    return Livewire::test(TrimExportEntry::class)->call('prepareTables')->call('prepareRows');
}

it('fills the filters from the chosen file once the Tables step is done', function () {
    $component = Livewire::test(TrimExportEntry::class)->call('prepareTables');

    expect($component->instance()->filterChoices())->toBeNull();

    $choices = $component->call('prepareRows')->instance()->filterChoices();

    expect($choices->regions)->toBe(['V1' => 'V1 - Regina', 'V2' => 'V2 - Saskatoon'])
        ->and($choices->resources)->toBe(['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing'])
        ->and($choices->activityTypes)->toBe(['T1' => 'T1 - Install (3)', 'T2' => 'T2 - Repair (1)'])
        ->and($choices->activities)->toBe(['A1' => 'A1 - T1', 'A2' => 'A2 - T1', 'A3' => 'A3 - T2', 'A4' => 'A4 - T1'])
        ->and($choices->resourceRegions)->toBe(['R1' => ['V1'], 'R2' => ['V2']]);
});

it('caches the choices per export file, encrypted', function () {
    trimRowsReady();

    expect(Cache::get("trim-export:choices:{$this->file->id}"))->toBeString()->not->toContain('Lovelace');

    Storage::disk('r2')->delete(app(ExportStorage::class)->objectKey($this->file));

    expect(trimRowsReady()->instance()->filterChoices()?->regions)->toHaveKey('V1');
});

it('narrows resources to the chosen regions and drops a chosen resource outside them', function () {
    trimRowsReady()
        ->fillForm(['options.resource_ids' => ['R2']])
        ->fillForm(['options.region_ids' => ['V1']])
        ->assertFormSet(['options.resource_ids' => []]);
});

it('needs both window days, the end not before the start', function () {
    trimRowsReady()
        ->fillForm(['options.window_start' => '2026-10-09'])
        ->call('start')
        ->assertHasFormErrors(['options.window_end' => 'required']);

    trimRowsReady()
        ->fillForm(['options.window_start' => '2026-10-09', 'options.window_end' => '2026-10-05'])
        ->call('start')
        ->assertHasFormErrors(['options.window_end']);

    expect(ToolRun::query()->count())->toBe(0);
});

it('starts a run with the row options and the time zone of an Unfiled run', function () {
    Queue::fake();

    trimRowsReady()
        ->fillForm([
            'options.region_ids' => ['V1'],
            'options.window_start' => '2026-10-05',
            'options.window_end' => '2026-10-09',
            'options.count_only' => true,
            'options.time_zone' => 'America/Regina',
        ])
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options'])->toMatchArray([
        'table_mode' => 'remove',
        'region_ids' => ['V1'],
        'window_start' => '2026-10-05',
        'window_end' => '2026-10-09',
        'count_only' => true,
        'time_zone' => 'America/Regina',
    ]);
});

it('clears the row choices when another file is chosen', function () {
    // Older, so the picker still preselects the file the beforeEach stored.
    $other = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['table_counts' => ['Activity' => 1]], 'created_at' => now()->subHour()]);

    $component = trimRowsReady()
        ->fillForm(['options.region_ids' => ['V1']])
        ->fillForm(['files.load' => $other->id])
        ->assertFormSet(['options.region_ids' => []]);

    expect($component->instance()->filterChoices())->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TrimExport/FilterChoicesTest.php tests/Feature/TrimExport/TrimExportRowsStepTest.php`
Expected: FAIL with `Class "App\Support\TrimExport\FilterChoices" not found` and `Method App\Filament\Pages\Tools\TrimExportEntry::prepareRows does not exist`.

- [ ] **Step 3: Write the choices**

Create `app/Support/TrimExport/FilterChoices.php`:

```php
<?php

namespace App\Support\TrimExport;

/** What the Rows step offers for one export, gathered by one streamed pass. Holds customer ids and names: cache it encrypted only. */
class FilterChoices
{
    /**
     * @param  array<string, string>  $regions  id => "V1 - Regina"
     * @param  array<string, string>  $resources  id => "R1 - Ada Lovelace"
     * @param  array<string, string>  $activityTypes  id => "T1 - Install (3)", only types some activity has
     * @param  array<string, string>  $activities  id => "A1 - T1"
     * @param  array<string, list<string>>  $resourceRegions  resource id => region ids (Resource_Region)
     * @param  array<string, string>  $activityTypeOf  activity id => type id
     */
    public function __construct(
        public array $regions = [],
        public array $resources = [],
        public array $activityTypes = [],
        public array $activities = [],
        public array $resourceRegions = [],
        public array $activityTypeOf = [],
    ) {}

    /**
     * @param  list<string>  $regionIds
     * @return array<string, string>
     */
    public function resourcesIn(array $regionIds): array
    {
        if ($regionIds === []) {
            return $this->resources;
        }

        $wanted = array_flip($regionIds);
        $kept = [];
        foreach ($this->resources as $resourceId => $label) {
            foreach ($this->resourceRegions[$resourceId] ?? [] as $regionId) {
                if (isset($wanted[$regionId])) {
                    $kept[$resourceId] = $label;

                    break;
                }
            }
        }

        return $kept;
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, string>
     */
    public function activitiesOf(array $typeIds): array
    {
        if ($typeIds === []) {
            return $this->activities;
        }

        $wanted = array_flip($typeIds);

        return array_filter($this->activities, fn (string|int $activityId): bool => isset($wanted[$this->activityTypeOf[$activityId] ?? '']), ARRAY_FILTER_USE_KEY);
    }
}
```

- [ ] **Step 4: Scan a file for the choices**

In `app/Support/TrimExport/ExportFileScanner.php`, add `use App\Support\TrimExport\Readers\JsonTableReader;` and `use App\Support\TrimExport\Readers\XmlTableReader;`, replace the constructor with:

```php
    public function __construct(
        private ExportStorage $storage,
        private ExportSource $source,
        private TableCounter $counter,
        private XmlTableReader $xmlReader,
        private JsonTableReader $jsonReader,
    ) {}
```

and add after `tableCounts()`:

```php
    public function choices(ExportFile $file): FilterChoices
    {
        return $this->remember($file, 'choices', fn (string $path, string $format): FilterChoices => $this->scanChoices($path, $format));
    }

    /** The choices a scan already cached for this file; null before the first scan. */
    public function cachedChoices(ExportFile $file): ?FilterChoices
    {
        $cached = Cache::get($this->cacheKey($file, 'choices'));
        if (! is_string($cached)) {
            return null;
        }

        try {
            $choices = Crypt::decrypt($cached);
        } catch (DecryptException) {
            return null;
        }

        return $choices instanceof FilterChoices ? $choices : null;
    }

    /** One streamed pass over the tables the filters offer, as Filter Load File listed them. */
    private function scanChoices(string $path, string $format): FilterChoices
    {
        $reader = $format === 'json' ? $this->jsonReader : $this->xmlReader;
        $fields = [
            'Region' => ['id', 'description'],
            'Resources' => ['id', 'first_name', 'surname'],
            'Activity_Type' => ['id', 'description'],
            'Activity' => ['id', 'activity_type_id'],
            'Resource_Region' => ['resource_id', 'region_id'],
        ];
        $regions = [];
        $resources = [];
        $typeNames = [];
        $activities = [];
        $activityTypeOf = [];
        $typeCounts = [];
        $resourceRegions = [];

        foreach ($reader->segments($path) as $segment) {
            if ($segment->type !== Segment::ROW || ! isset($fields[$segment->table])) {
                continue;
            }

            $row = $reader->fieldsOf($segment->body, $fields[$segment->table]);
            $id = $row['id'] ?? null;

            switch ($segment->table) {
                case 'Region':
                    if ($id !== null) {
                        $regions[$id] = isset($row['description']) ? "{$id} - {$row['description']}" : $id;
                    }
                    break;
                case 'Resources':
                    if ($id !== null) {
                        $name = trim(($row['first_name'] ?? '').' '.($row['surname'] ?? ''));
                        $resources[$id] = $name === '' ? $id : "{$id} - {$name}";
                    }
                    break;
                case 'Activity_Type':
                    if ($id !== null) {
                        $typeNames[$id] = isset($row['description']) ? "{$id} - {$row['description']}" : $id;
                    }
                    break;
                case 'Activity':
                    if ($id !== null) {
                        $type = $row['activity_type_id'] ?? '';
                        $activities[$id] = $type === '' ? $id : "{$id} - {$type}";
                        $activityTypeOf[$id] = $type;
                        $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
                    }
                    break;
                default:
                    if (isset($row['resource_id'], $row['region_id'])) {
                        $resourceRegions[$row['resource_id']][] = $row['region_id'];
                    }
            }
        }

        // Only types some activity has, with how many; a type the file uses but does not define still shows.
        $activityTypes = [];
        foreach ($typeCounts as $type => $count) {
            if ((string) $type !== '') {
                $activityTypes[$type] = ($typeNames[$type] ?? (string) $type).' ('.number_format($count).')';
            }
        }
        ksort($activityTypes, SORT_NATURAL);

        return new FilterChoices($regions, $resources, $activityTypes, $activities, $resourceRegions, $activityTypeOf);
    }
```

- [ ] **Step 5: Write the Rows step fields**

In `app/Filament/Pages/Tools/TrimExport/TrimExportFields.php` add the imports `use App\Support\TrimExport\FilterChoices;`, `use Closure;`, `use Filament\Forms\Components\DatePicker;`, `use Filament\Forms\Components\DateTimePicker;`, and add:

```php
    /** @return list<Component> */
    public static function rows(): array
    {
        $choices = static fn (TrimExportEntry $livewire): FilterChoices => $livewire->filterChoices() ?? new FilterChoices;

        return [
            Text::make('Leave every filter empty to keep all rows: the run then only removes the tables chosen on the Tables step.')
                ->color('gray')
                ->columnSpanFull(),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('options.region_ids')
                    ->label('Regions')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->options(static fn (TrimExportEntry $livewire): array => $choices($livewire)->regions)
                    ->helperText('Keeps resources in these regions (Resource_Region) and activities at their locations (Location.region_id or Location_Region).')
                    ->afterStateUpdated(static function (?array $state, Get $get, Set $set, TrimExportEntry $livewire) use ($choices): void {
                        $allowed = $choices($livewire)->resourcesIn($state ?? []);
                        $set('options.resource_ids', array_values(array_filter(
                            $get('options.resource_ids') ?? [],
                            static fn (mixed $resourceId): bool => array_key_exists((string) $resourceId, $allowed),
                        )));
                    }),
                Select::make('options.resource_ids')
                    ->label('Resources')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->options(static fn (Get $get, TrimExportEntry $livewire): array => $choices($livewire)->resourcesIn($get('options.region_ids') ?? []))
                    ->helperText('Optional. Narrows the kept resources to these, within the regions above.'),
                Select::make('options.activity_type_ids')
                    ->label('Activity types')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->options(static fn (TrimExportEntry $livewire): array => $choices($livewire)->activityTypes)
                    ->helperText('Only types that have activities in this file, with how many.'),
                Select::make('options.activity_ids')
                    ->label('Activities')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->options(static fn (Get $get, TrimExportEntry $livewire): array => $choices($livewire)->activitiesOf($get('options.activity_type_ids') ?? []))
                    ->helperText('Optional. Only these activities are kept.'),
                DatePicker::make('options.window_start')
                    ->label('Appointment window from')
                    ->native(false)
                    ->live()
                    ->required(static fn (Get $get): bool => filled($get('options.window_end')))
                    ->helperText('Keeps activities whose APPOINTMENT window overlaps these days, and shifts that overlap them.'),
                DatePicker::make('options.window_end')
                    ->label('Appointment window to')
                    ->native(false)
                    ->live()
                    ->required(static fn (Get $get): bool => filled($get('options.window_start')))
                    ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $start = $get('options.window_start');
                        if (filled($value) && filled($start) && substr((string) $value, 0, 10) < substr((string) $start, 0, 10)) {
                            $fail('The window must end on or after the day it starts.');
                        }
                    }),
            ]),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                DateTimePicker::make('options.input_reference_datetime')
                    ->label('Input_Reference datetime override (UTC)')
                    ->native(false)
                    ->seconds(false)
                    ->nullable()
                    ->helperText('Optional. Replaces the datetime in the Input_Reference row.'),
                Toggle::make('options.count_only')
                    ->label('Count only')
                    ->helperText('Show the counts and warnings, but write no file.')
                    ->default(false),
            ]),
        ];
    }
```

- [ ] **Step 6: Add the Rows step to the page**

In `app/Filament/Pages/Tools/TrimExportEntry.php` add `use App\Support\TrimExport\FilterChoices;`, the property

```php
    #[Locked]
    public ?string $choicesForFileId = null;
```

replace `steps()` with:

```php
    #[Override]
    protected function steps(): array
    {
        return [
            $this->whereStep(),
            $this->inputsStep()->afterValidation(function (): void {
                if (! $this->prepareTables()) {
                    throw new Halt;
                }
            }),
            Step::make('Tables')
                ->description('Which whole tables to remove or keep. Counts are rows in the chosen file.')
                ->schema(TrimExportFields::tables())
                ->afterValidation(function (): void {
                    if (! $this->prepareRows()) {
                        throw new Halt;
                    }
                }),
            Step::make('Rows')
                ->description('Which rows to keep. Leave every filter empty to keep all rows.')
                ->schema([
                    ...TrimExportFields::rows(),
                    // Shown for an Unfiled run only (the field hides itself otherwise); a dataset run uses the dataset's zone.
                    $this->timeZoneField()->helperText('The window days are whole days in this zone.'),
                ]),
        ];
    }
```

replace `resetTables()` with:

```php
    /** Another file: its tables, choices and chosen ids are not this one's. */
    public function resetTables(): void
    {
        $this->tableCounts = [];
        $this->tablesForFileId = null;
        $this->choicesForFileId = null;
        $this->data['options']['tables'] = [];
        $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
        foreach (['region_ids', 'resource_ids', 'activity_type_ids', 'activity_ids'] as $key) {
            $this->data['options'][$key] = [];
        }
    }
```

and add:

```php
    /** Reads the chosen file's filter choices (one streamed pass, cached per file). False keeps the wizard on Tables. */
    public function prepareRows(): bool
    {
        $file = $this->chosenExportFile();
        if ($file === null) {
            Notification::make()->title('Choose an export first')->body('Pick the '.$this->chosenKind()->getLabel().' export to trim.')->warning()->send();

            return false;
        }

        try {
            app(ExportFileScanner::class)->choices($file);
        } catch (RuntimeException $exception) {
            Notification::make()->title('The rows could not be read')->body($exception->getMessage())->danger()->send();

            return false;
        }

        $this->choicesForFileId = $file->id;

        return true;
    }

    /** The chosen file's filter choices once the Tables step has read them; null before, so no scan runs while the page renders. */
    public function filterChoices(): ?FilterChoices
    {
        $file = $this->chosenExportFile();

        return $file === null || $file->id !== $this->choicesForFileId ? null : app(ExportFileScanner::class)->cachedChoices($file);
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TrimExport tests/Feature/TrimExport tests/Feature/ToolWizardPageTest.php tests/Feature/ToolWizardUnfiledTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/TrimExport/FilterChoices.php app/Support/TrimExport/ExportFileScanner.php app/Filament/Pages/Tools/TrimExport/TrimExportFields.php app/Filament/Pages/Tools/TrimExportEntry.php tests/Unit/TrimExport/FilterChoicesTest.php tests/Feature/TrimExport/TrimExportRowsStepTest.php
git commit -m "$(cat <<'EOF'
Trim export wizard: Rows step with regions, resources, activity types, activities and appointment window from a cached, encrypted scan of the file; override and Count only (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 10: The run page, the export download and the catalogue entry

**Files:**
- Create: `resources/views/filament/tool-runs/trim_export.blade.php`
- Create: `app/Http/Controllers/Exports/ExportFileDownloadController.php`
- Modify: `routes/web.php` (add `exports.download`)
- Modify: `app/Filament/Tables/ExportFilesTable.php` (Download record action)
- Modify: `app/Filament/Widgets/ToolCatalogue.php` (Trim export tile; "Needs one ..." for a `TakesOneInput` tool)
- Create: `tests/Feature/TrimExport/TrimExportRunPageTest.php`, `tests/Feature/ExportFileDownloadTest.php`
- Modify: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: the run `result` and `summary` keys of Task 7, `TrimExportEntry` (Task 8), `TakesOneInput` (Task 7), `ExportStorage::disk()`, `objectKey()`, `mimeFor()`, `ToolRunPage` (renders `filament.tool-runs.<tool value>` through `_run-panel`, which already lists the run's warnings).
- Produces: route `exports.download` (`GET /exports/{exportFile}/download`, panel login, owner only through the `ExportFile` ownership scope, 404 for a SYS export, which holds live credentials, and for a purged, failed, still-uploading or expired file); the Download action on every Exports table except for SYS exports (`ExportFilesTable` already imports `ExportKind`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ExportFileDownloadTest.php`:

```php
<?php

use App\Enums\ExportFileStatus;
use App\Filament\Widgets\MyExportFiles;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->file = ExportFile::factory()->for($this->dataset, 'scope')->create(['original_filename' => 'north-load.trimmed.xml', 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->file), '<dsScheduleData/>');
    $this->actingAs($this->user);
});

it('streams an export to its owner under its own name', function () {
    $this->get(route('exports.download', ['exportFile' => $this->file->id]))
        ->assertOk()
        ->assertDownload('north-load.trimmed.xml')
        ->assertStreamedContent('<dsScheduleData/>');
});

it('never serves a SYS export, which holds live credentials', function () {
    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($sys), '<DsSystemData/>');

    $this->get(route('exports.download', ['exportFile' => $sys->id]))->assertNotFound();
});

it('refuses another user, a purged file and an expired one', function () {
    $url = route('exports.download', ['exportFile' => $this->file->id]);

    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

    $this->actingAs($this->user);
    $this->file->update(['status' => ExportFileStatus::Purged]);
    $this->get($url)->assertNotFound();

    $this->file->update(['status' => ExportFileStatus::Ready, 'expires_at' => now()->subMinute()]);
    $this->get($url)->assertNotFound();
});

it('sends a guest to the login page', function () {
    Auth::logout();

    $this->get(route('exports.download', ['exportFile' => $this->file->id]))->assertRedirect();
});

it('offers Download on the Exports table for a file that is still stored', function () {
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['original_filename' => 'load.xml']);
    $purged = ExportFile::factory()->for($this->user, 'scope')->purged()->create();

    Livewire::test(MyExportFiles::class)
        ->assertTableActionHasUrl('download', route('exports.download', ['exportFile' => $mine->id]), record: $mine)
        ->assertTableActionHidden('download', $purged);
});
```

Create `tests/Feature/TrimExport/TrimExportRunPageTest.php`:

```php
<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->export = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::Load, 'original_filename' => 'north-load.trimmed.xml', 'status' => ExportFileStatus::Summarising,
    ]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TrimExport,
        'started_by_user_id' => $this->user->id,
        'result' => [
            'tables' => ['Input_Reference' => ['before' => 1, 'kept' => 1], 'Plan_Travel' => ['before' => 4812, 'kept' => 0], 'Activity' => ['before' => 7620, 'kept' => 8]],
            'removed_tables' => ['Plan_Travel'],
            'dangling' => [['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 76, 'examples' => ['R9', 'R10', 'R11']]],
            'warnings' => ['Some kept rows point at rows that are not in the file. The list below names them.'],
            'count_only' => false, 'format' => 'xml', 'zipped' => false, 'export_file_id' => $this->export->id,
        ],
        'summary' => ['line' => 'Removed 1 table and 12,424 rows. Kept 2 tables and 9 rows.', 'tables_removed' => 1, 'rows_kept' => 9, 'rows_removed' => 12424, 'count_only' => false],
        'warnings' => ['Some kept rows point at rows that are not in the file. The list below names them.'],
    ]);
    $this->actingAs($this->user);
    $this->params = ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id];
});

it('shows the counts, the dangling list, the new export and its download', function () {
    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('Removed 1 table and 12,424 rows. Kept 2 tables and 9 rows.')
        ->assertSee('Table removed')
        ->assertSee('7,620')
        ->assertSee('References left pointing at nothing')
        ->assertSee('R9, R10, R11')
        ->assertSee('north-load.trimmed.xml')
        ->assertSee('It is being read; other tools can use it once it is ready.')
        ->assertSee('Download the trimmed export')
        ->assertSeeHtml(e(route('exports.download', ['exportFile' => $this->export->id])))
        ->assertSee('Open the dataset')
        ->assertSee('Some kept rows point at rows that are not in the file.');
});

it('says Count only and offers no download when nothing was written', function () {
    $this->run->update([
        'result' => [...$this->run->result, 'count_only' => true, 'export_file_id' => null],
        'summary' => ['line' => 'Would remove 1 table and 12,424 rows, and keep 2 tables and 9 rows. Nothing was written (Count only).'],
    ]);

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertSee('Count only: no file was written.')
        ->assertDontSee('Download the trimmed export')
        ->assertSee('References left pointing at nothing');
});

it('says so when the trimmed export has been deleted', function () {
    $this->export->delete();

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertSee('The trimmed export has been deleted.')
        ->assertDontSee('Download the trimmed export');
});

it('never shows the run to another user', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ToolRunPage::class, $this->params)->assertNotFound();
});
```

In `tests/Feature/DashboardTest.php`, in the test that checks the catalogue's "Needs a LOAD export; SYS optional" line, add:

```php
        ->assertSee('Trim export')
        ->assertSee('Needs one LOAD, Plan or End of day export')
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ExportFileDownloadTest.php tests/Feature/TrimExport/TrimExportRunPageTest.php tests/Feature/DashboardTest.php`
Expected: FAIL with `Route [exports.download] not defined.`

- [ ] **Step 3: Serve an export to its owner**

Create `app/Http/Controllers/Exports/ExportFileDownloadController.php`:

```php
<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a stored LOAD, plan, EOD, audits or event log export back to the user who owns it (the ownership scope finds nothing for anyone else). */
class ExportFileDownloadController extends Controller
{
    public function __invoke(string $exportFile, ExportStorage $storage): StreamedResponse
    {
        $file = ExportFile::query()->findOrFail($exportFile);
        // A SYS export holds live credentials (a TomTom key in plain text): it is never served back.
        abort_if($file->kind === ExportKind::Sys || $file->isExpired()
            || ! in_array($file->status, [ExportFileStatus::Ready, ExportFileStatus::Summarising], true), 404);

        $stream = $storage->disk()->readStream($storage->objectKey($file));
        abort_if($stream === null, 404);

        return response()->streamDownload(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, basename((string) $file->original_filename), ['Content-Type' => ExportStorage::mimeFor((string) $file->content_type)]);
    }
}
```

In `routes/web.php` add `use App\Http\Controllers\Exports\ExportFileDownloadController;` and, after the `download.filtered` route (removed in Task 12):

```php
Route::get('/exports/{exportFile}/download', ExportFileDownloadController::class)
    ->middleware(['web', Authenticate::class])
    ->whereUuid('exportFile')
    ->name('exports.download');
```

In `app/Filament/Tables/ExportFilesTable.php` add `use Filament\Actions\Action;` and put this first in `->recordActions([...])`:

```php
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(static fn (ExportFile $record): string => route('exports.download', ['exportFile' => $record->id]))
                    ->visible(static fn (ExportFile $record): bool => $record->kind !== ExportKind::Sys && ! $record->isExpired()
                        && in_array($record->status, [ExportFileStatus::Ready, ExportFileStatus::Summarising], true)),
```

- [ ] **Step 4: Write the run view**

Create `resources/views/filament/tool-runs/trim_export.blade.php`:

```blade
@php
    $result = $run->result ?? [];
    $tables = is_array($result['tables'] ?? null) ? $result['tables'] : [];
    $removed = is_array($result['removed_tables'] ?? null) ? $result['removed_tables'] : [];
    $dangling = is_array($result['dangling'] ?? null) ? $result['dangling'] : [];
    $countOnly = (bool) ($result['count_only'] ?? false);
    $exportId = $result['export_file_id'] ?? null;
    $export = filled($exportId) ? \App\Models\ExportFile::query()->find($exportId) : null;
    $downloadable = $export !== null && ! $export->isExpired()
        && in_array($export->status, [\App\Enums\ExportFileStatus::Ready, \App\Enums\ExportFileStatus::Summarising], true);
    $isDataset = $run->scope instanceof \App\Models\Dataset;
    $placeUrl = $isDataset
        ? \App\Filament\Resources\DatasetResource::getUrl('view', ['record' => $run->scope])
        : \App\Filament\Pages\MyFiles::getUrl();
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $run->summary['line'] ?? '' }}</p>
            @if ($countOnly)
                <p class="text-sm text-gray-500 dark:text-gray-400">Count only: no file was written.</p>
            @elseif ($export)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Saved as a new {{ $export->kind->getLabel() }} export, {{ $export->original_filename }}, {{ $isDataset ? 'on the dataset' : 'in My files' }}.
                    @if ($export->status === \App\Enums\ExportFileStatus::Summarising)
                        It is being read; other tools can use it once it is ready.
                    @endif
                </p>
            @elseif (filled($exportId))
                <p class="text-sm text-gray-500 dark:text-gray-400">The trimmed export has been deleted.</p>
            @endif
        </div>
        @if ($export && ! $countOnly)
            <div class="flex flex-wrap gap-2">
                @if ($downloadable)
                    <x-filament::button tag="a" href="{{ route('exports.download', ['exportFile' => $export->id]) }}" icon="heroicon-o-arrow-down-tray" size="sm">Download the trimmed export</x-filament::button>
                @endif
                <x-filament::button tag="a" href="{{ $placeUrl }}" icon="heroicon-o-folder-open" color="gray" size="sm">{{ $isDataset ? 'Open the dataset' : 'Open My files' }}</x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>

@if ($dangling !== [])
    <x-filament::section heading="References left pointing at nothing">
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">These kept rows point at rows that are not in the trimmed file.</p>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-1 pe-4 font-medium">Table</th>
                    <th class="py-1 pe-4 font-medium">Field</th>
                    <th class="py-1 pe-4 text-end font-medium">Rows</th>
                    <th class="py-1 font-medium">First ids</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dangling as $finding)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 pe-4">{{ $finding['table'] ?? '' }}</td>
                        <td class="py-1 pe-4">{{ $finding['field'] ?? '' }}</td>
                        <td class="py-1 pe-4 text-end">{{ number_format((int) ($finding['count'] ?? 0)) }}</td>
                        <td class="py-1">{{ implode(', ', $finding['examples'] ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
@endif

<x-filament::section heading="Tables">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
                <th class="py-1 pe-4 font-medium">Table</th>
                <th class="py-1 pe-4 text-end font-medium">Before</th>
                <th class="py-1 pe-4 text-end font-medium">Kept</th>
                <th class="py-1 text-end font-medium">Removed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tables as $name => $counts)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="py-1 pe-4">
                        {{ $name }}
                        @if (in_array($name, $removed, true))
                            <x-filament::badge color="gray" size="sm" class="ms-2 inline-flex">Table removed</x-filament::badge>
                        @endif
                    </td>
                    <td class="py-1 pe-4 text-end">{{ number_format((int) ($counts['before'] ?? 0)) }}</td>
                    <td class="py-1 pe-4 text-end">{{ number_format((int) ($counts['kept'] ?? 0)) }}</td>
                    <td class="py-1 text-end">{{ number_format((int) ($counts['before'] ?? 0) - (int) ($counts['kept'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-filament::section>
```

- [ ] **Step 5: Put Trim export in the catalogue**

In `app/Filament/Widgets/ToolCatalogue.php`:
- add `use App\Tools\Contracts\TakesOneInput;`
- in `wizardPageFor()` add `Pages\Tools\TrimExportEntry::class` to the list of pages
- in `sections()` replace `$this->needs($impl->requiredKinds(), $impl->optionalKinds())` with `$impl instanceof TakesOneInput ? $this->needsOne($impl->oneOfKinds()) : $this->needs($impl->requiredKinds(), $impl->optionalKinds())`
- add:

```php
    /** @param list<ExportKind> $kinds */
    private function needsOne(array $kinds): string
    {
        $labels = array_map(static fn (ExportKind $kind): string => $kind->getLabel(), $kinds);
        $last = array_pop($labels);

        return 'Needs one '.($labels === [] ? $last : implode(', ', $labels).' or '.$last).' export';
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ExportFileDownloadTest.php tests/Feature/TrimExport tests/Feature/DashboardTest.php tests/Feature/ExportsTabTest.php tests/Feature/MyFilesTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add resources/views/filament/tool-runs/trim_export.blade.php app/Http/Controllers/Exports/ExportFileDownloadController.php routes/web.php app/Filament/Tables/ExportFilesTable.php app/Filament/Widgets/ToolCatalogue.php tests/Feature/ExportFileDownloadTest.php tests/Feature/TrimExport/TrimExportRunPageTest.php tests/Feature/DashboardTest.php
git commit -m "$(cat <<'EOF'
Trim export: run page with per-table counts, dangling list and the new export; owner-only export download; catalogue tile (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 11: Golden tests on the SaskTel exports, and the performance check

**Files:**
- Create: `tests/Unit/TrimExport/TrimExportGoldenTest.php`
- Create: `tests/Unit/TrimExport/TrimEngineMemoryTest.php`

**Interfaces:**
- Consumes: `ExportSource::open()` (Task 3), `TrimEngine::run()`, `TrimReport` (Task 6), `TableCounter::count()` (Task 8), `TrimPlan::fromOptions()`, `TableDependencies::CLEANUP_TABLES` (Task 1).
- Produces: tests only. The golden tests skip themselves when `~/pso-data` (or `PSO_DATA_DIR`) lacks the files; they never copy customer data into the repo.

Facts checked on this machine before writing the plan: `~/pso-data/SATE/2026-10-04/load.zip` holds one 101 MB XML LOAD (`File_2026_10_04_08_07_12.xml`) with 7,620 Activity and 3,928 Location rows; `~/pso-data/SATE/1005/north_travel_1005.xml` is 135,325,000 bytes, has no XML declaration, one Input_Reference and 5,081 Plan_Travel rows.

- [ ] **Step 1: Write the golden tests**

Create `tests/Unit/TrimExport/TrimExportGoldenTest.php`:

```php
<?php

use App\Support\TrimExport\ExportSource;
use App\Support\TrimExport\TableCounter;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;
use Illuminate\Support\Facades\File;

/*
 * Real SaskTel exports (customer data): read in place, never copied into the repo, skipped when absent.
 * PSO_DATA_DIR overrides ~/pso-data.
 *   vendor/bin/pest tests/Unit/TrimExport/TrimExportGoldenTest.php
 */
function trimGoldenDataDir(): string
{
    $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

    return rtrim((string) (getenv('PSO_DATA_DIR') ?: $home.'/pso-data'), '/');
}

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/trim-golden-'.uniqid();
    mkdir($this->scratch);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('keeps 85 resources and 8 activities for region V1, 5 to 9 Oct, on the SaskTel LOAD (#42)', function () {
    $zip = trimGoldenDataDir().'/SATE/2026-10-04/load.zip';
    if (! is_file($zip)) {
        $this->markTestSkipped('The SaskTel LOAD of 2026-10-04 is not on this machine.');
    }

    copy($zip, $this->scratch.'/load.zip');
    $source = app(ExportSource::class)->open($this->scratch.'/load.zip');
    // Filter Load File took the days in the app zone (America/Toronto); the #42 figures were taken that way.
    $plan = TrimPlan::fromOptions(['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], 'America/Toronto');
    $output = $this->scratch.'/trimmed.'.$source->format;

    $report = app(TrimEngine::class)->run($source->path, $source->format, $plan, $output);

    // Resource_Type start and end location '1' is not in this LOAD at all (#26): a source finding, not ours.
    $locationDangling = array_values(array_filter($report->dangling,
        static fn (array $finding): bool => str_starts_with($finding['field'], 'location_id') && $finding['table'] !== 'Resource_Type'));

    expect($source->format)->toBe('xml')
        ->and($report->tables['Input_Reference']['kept'])->toBe(1)
        ->and($report->tables['Activity']['before'])->toBe(7620)
        ->and($report->tables['Resources']['kept'])->toBe(85)
        ->and($report->tables['Activity']['kept'])->toBe(8)
        ->and($report->tables['Location']['before'])->toBe(3928)
        ->and($report->tables['Location']['kept'])->toBeGreaterThan(0)->toBeLessThan(3928)
        ->and($locationDangling)->toBe([])
        ->and(app(TableCounter::class)->count($output, 'xml'))->toMatchArray(['Input_Reference' => 1, 'Resources' => 85, 'Activity' => 8]);
});

it('removes every Plan and Allocation table from the 135 MB SaskTel export with the Cleanup preset, in time and in flat memory', function () {
    $path = trimGoldenDataDir().'/SATE/1005/north_travel_1005.xml';
    if (! is_file($path)) {
        $this->markTestSkipped('The 135 MB SaskTel export is not on this machine.');
    }

    $plan = TrimPlan::fromOptions(['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES], 'UTC');
    $output = $this->scratch.'/trimmed.xml';
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    $report = app(TrimEngine::class)->run($path, 'xml', $plan, $output);

    $seconds = (hrtime(true) - $started) / 1e9;
    $growth = memory_get_peak_usage() - $before;
    $kept = app(TableCounter::class)->count($output, 'xml');
    $planTables = array_values(array_filter(array_keys($kept),
        static fn (string $table): bool => str_starts_with($table, 'Plan') || str_starts_with($table, 'Allocation')));

    fwrite(STDERR, sprintf("\n  135 MB Cleanup trim: %.1f s, peak memory growth %.1f MB\n", $seconds, $growth / 1048576));

    expect($planTables)->toBe([])
        ->and($report->tables['Plan_Travel'])->toBe(['before' => 5081, 'kept' => 0])
        ->and($kept['Input_Reference'] ?? 0)->toBe(1)
        ->and($kept['Activity'])->toBe($report->tables['Activity']['before'])
        ->and($seconds)->toBeLessThan(180.0)
        ->and($growth)->toBeLessThan(64 * 1024 * 1024);
});
```

- [ ] **Step 2: Write the always-on memory check**

Create `tests/Unit/TrimExport/TrimEngineMemoryTest.php`:

```php
<?php

use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;

it('keeps memory to ids on a large generated export, in xml and json', function (string $format) {
    $path = sys_get_temp_dir().'/trim-large-'.uniqid().'.'.$format;
    $handle = fopen($path, 'wb');
    fwrite($handle, $format === 'xml' ? '<dsScheduleData>' : '{"dsScheduleData": {"Activity": [');
    for ($index = 0; $index < 60000; $index++) {
        $row = ['id' => "A{$index}", 'location_id' => 'L'.($index % 500), 'activity_type_id' => 'T'.($index % 7), 'description' => str_repeat('x', 120)];
        fwrite($handle, $format === 'xml'
            ? "\n  <Activity><id>{$row['id']}</id><location_id>{$row['location_id']}</location_id><activity_type_id>{$row['activity_type_id']}</activity_type_id><description>{$row['description']}</description></Activity>"
            : ($index === 0 ? '' : ',')."\n    ".json_encode($row));
    }
    fwrite($handle, $format === 'xml' ? "\n</dsScheduleData>\n" : "\n  ]}}\n");
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(TrimEngine::class)->run($path, $format, TrimPlan::fromOptions(['activity_type_ids' => ['T3']], 'UTC'), $path.'.out');

        expect(filesize($path))->toBeGreaterThan(10 * 1024 * 1024)
            ->and($report->tables['Activity'])->toBe(['before' => 60000, 'kept' => 8571])
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(64 * 1024 * 1024);
    } finally {
        @unlink($path);
        @unlink($path.'.out');
    }
})->with(['xml', 'json']);
```

- [ ] **Step 3: Run them**

Run: `vendor/bin/pest tests/Unit/TrimExport/TrimEngineMemoryTest.php tests/Unit/TrimExport/TrimExportGoldenTest.php`
Expected: PASS on this Mac (the golden files are present), printing the 135 MB timing; SKIPPED for the two golden tests where the files are absent. If a golden number differs (85 resources, 8 activities), stop and report the numbers to Ish rather than changing the expectation: they are the figures #42 was closed on.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git add tests/Unit/TrimExport/TrimExportGoldenTest.php tests/Unit/TrimExport/TrimEngineMemoryTest.php
git commit -m "$(cat <<'EOF'
Trim export: golden tests on the SaskTel LOAD (V1, 5 to 9 Oct) and the 135 MB export (Cleanup preset, time and memory), plus a memory check (#28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

---

### Task 12: Retire Filter Load File and the public download (#19)

**Gate:** the spec says this goes "after this ships and Ish has used it on a real file". Do not start this task until Ish has confirmed that in the conversation. Tasks 1 to 11 ship without it.

**Files:**
- Delete: `app/Filament/Pages/FilterLoadFile.php`, `resources/views/filament/pages/filter-load-file.blade.php`, `app/Jobs/ProcessResourceFile.php`, `app/Jobs/DeleteTemporaryFiles.php`, `app/Services/ResourceActivityFilterService.php`, `app/Support/PreviewSummaryFormatter.php`, `app/Support/HasScopedCache.php`
- Delete tests: `tests/Feature/FilamentJobMonitoringTest.php`, `tests/Feature/FilteredDownloadTest.php`, `tests/Feature/FilterLoadFileDanglingWarningTest.php`, `tests/Feature/FilterLoadFileResourceScopingTest.php`, `tests/Feature/ProcessResourceFileCancellationTest.php`, `tests/Feature/ResourceActivityFilterCascadeTest.php`
- Modify: `routes/web.php` (remove `download.filtered`), `config/filesystems.php` (no `storage/app/public` link), `app/Filament/Widgets/ToolCatalogue.php` (remove the Filter Load File tile), `tests/Feature/NavigationGroupsTest.php` (remove its row), `README.md`
- Create: `tests/Feature/TrimExport/FilterLoadFileRetiredTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing new. Checked before writing this task: `PreviewSummaryFormatter`, `HasScopedCache` and `DeleteTemporaryFiles` are used only by `ProcessResourceFile` and `ResourceActivityFilterService`; nothing else uses the `public` disk or the `storage:link` target. The `public` disk definition itself stays (Laravel's default config; removing it is not part of #19).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TrimExport/FilterLoadFileRetiredTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('retires Filter Load File and its public download', function () {
    expect(Route::has('download.filtered'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Pages\\FilterLoadFile'))->toBeFalse()
        ->and(class_exists('App\\Services\\ResourceActivityFilterService'))->toBeFalse()
        ->and(config('filesystems.links'))->toBe([]);

    $this->actingAs(User::factory()->create())->get('/download/filtered_x.json')->assertNotFound();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/TrimExport/FilterLoadFileRetiredTest.php`
Expected: FAIL (`download.filtered` is still a route).

- [ ] **Step 3: Remove the code and its tests**

```bash
git rm app/Filament/Pages/FilterLoadFile.php resources/views/filament/pages/filter-load-file.blade.php app/Jobs/ProcessResourceFile.php app/Jobs/DeleteTemporaryFiles.php app/Services/ResourceActivityFilterService.php app/Support/PreviewSummaryFormatter.php app/Support/HasScopedCache.php tests/Feature/FilamentJobMonitoringTest.php tests/Feature/FilteredDownloadTest.php tests/Feature/FilterLoadFileDanglingWarningTest.php tests/Feature/FilterLoadFileResourceScopingTest.php tests/Feature/ProcessResourceFileCancellationTest.php tests/Feature/ResourceActivityFilterCascadeTest.php
```

In `routes/web.php` delete the whole `Route::get('/download/{filename}', ...)->name('download.filtered')->where(...)` statement.

In `config/filesystems.php` replace

```php
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
```

with

```php
    // Nothing under storage/ is served publicly (#19): exports download through exports.download, owner only.
    'links' => [],
```

In `app/Filament/Widgets/ToolCatalogue.php` delete the line `$analyse[] = $this->item(Pages\FilterLoadFile::class, 'Filter Load File', ...);`.

In `tests/Feature/NavigationGroupsTest.php` delete the row `[Pages\FilterLoadFile::class, Groups::ANALYSE],`.

In `README.md` replace the line `- Filter Load File: trim a LOAD to regions, resources, activities or dates, with referential cascade.` with:

```markdown
- Trim export: remove whole tables (the Cleanup for input preset, as Remove-PsoTables does) and keep rows by region, resource, activity type, activity or appointment window, with the referential cascade. Takes a LOAD, plan or End of day export (XML or JSON, zipped or not) and saves the result as a new export next to it.
```

Then refresh the autoloader so the deleted classes are gone from any class map: `composer dump-autoload`.

If `public/storage` exists as a symlink on this machine, leave it: it is not in git, and the box is deployed from git (Ish removes the link on the box himself; mention it in the report).

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/pest`
Expected: everything passes except the known failing `ExampleTest`. A failure that names one of the deleted classes means a reference was missed: remove it, never restore the class.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add routes/web.php config/filesystems.php app/Filament/Widgets/ToolCatalogue.php tests/Feature/NavigationGroupsTest.php README.md tests/Feature/TrimExport/FilterLoadFileRetiredTest.php
git status --short
git commit -m "$(cat <<'EOF'
Retire Filter Load File, its job, filter service and public download route; Trim export replaces them (closes #19, #28)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
EOF
)"
```

`git status --short` before the commit must show only the paths above and the `git rm` deletions; if anything under `docs/` is staged, unstage it.
