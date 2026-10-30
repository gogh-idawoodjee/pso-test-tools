# Trim export: one tool for tables and rows

Issue #28 (closes #19, replaces Filter Load File, ports pso-tools `scripts/Remove-PsoTables.ps1`). Agreed with Ish
2026-10-16.

## 1. Problem

Three things today do one job badly between them. Filter Load File (web) keeps rows by region, resource, activity
type, activity id and date, but only on JSON, by decoding the whole file (100 MB cap), and it serves the result from
a public download route (#19). Remove-PsoTables (PowerShell) strips whole tables so an export can be loaded or
compared as input, streaming, XML only. `Build-PlanTravelsReport.ps1 -BuildImportXml` wrote a trimmed import file;
Ish dropped that (2026-10-16), so no preset for it.

## 2. Decision

One analysis tool, **Trim export**, under Analysis tools and in the tool wizard like the others. Input: one export
(LOAD, plan or EOD; XML or JSON; zipped or not). Two steps of options: tables, then rows. Output: a new export file in
the same place the input came from (the dataset, or Unfiled), same format as the input, so it can go straight to Load
from File or another tool. Everything is streamed in two passes; the file is never decoded whole.

Rejected: keeping Filter Load File alongside (two tools, one of them JSON-only); rows for JSON first and XML later
(Ish: both from day one); a "targeted DSE run" preset (dropped with -BuildImportXml).

## 3. The wizard

Where and Inputs are the standard steps. The tool accepts Unfiled (`Tool::acceptsUnfiled()` true). Required kind:
one of LOAD, plan or EOD; the picker offers all three kinds and exactly one file is chosen (a new "any schedule data"
input: the tool declares `requiredKinds()` as LOAD with plan and EOD optional, and `start()` refuses a run with more
than one of them or none).

### 3.1 Options step 1: tables

A searchable, bulk-toggleable Filament `CheckboxList` (`searchable()`, `bulkToggleable()`, three columns) of the
tables that are **in the chosen file**, each labelled "Plan_Travel, 4,812 rows". The counts come from the export's
stored summary (`ExportSummary` gains `table_counts`: top-level element or key name to row count, gathered by the
summariser's existing streamed pass; a file summarised before this change has none and the list then shows names
without counts, taken from a quick streamed scan when the step opens).

- A mode toggle **Remove ticked / Keep ticked**. Default Remove.
- Preset **Cleanup for input** (default): ticks the Remove-PsoTables default list: Plan, Plan_Resource, Plan_Route,
  Plan_Travel, Plan_Break, Allocation, Allocation_Data, Schedule_Event, Schedule_Exception, Schedule_Exception_Data,
  Schedule_Exception_Response, Appointment_Request, Appointment_Offer, Appointment_Offer_Response,
  Appointment_Summary, Explanation_Request, Explanation_Request_Change, Broadcast, Broadcast_Parameter,
  Additional_Attribute, Activity_Custom_URL, Resource_Custom_URL, URL_String, URL_Parameter, Object_Group,
  Source_Data, Source_Data_Parameter. Only the ones present in the file are shown and ticked. Changing a tick
  switches the preset to **Custom**.
- Input_Reference is listed greyed and always kept; a separate **Allow Input_Reference to be removed** toggle unlocks
  it (Remove-PsoTables `-DropInputReference`).
- Groups, in this order, each a heading inside the list: Plan output (Plan*, Allocation*), Appointments and events
  (Appointment_*, Schedule_*, Explanation_*, Broadcast*), Activities (Activity*), Resources and shifts (Resources,
  Resource_*, Shift*, Availability), Locations and regions (Location*, Region*), Other (anything else). Filament's
  list is flat, so the grouping is done by ordering and a prefix label, not nested lists.
- Live footer under the list: "Removing 12 tables, 410,223 rows. Keeping 31 tables, 88,540 rows." computed from the
  counts, and amber dependency warnings as soon as a kept table loses its parent, from Remove-PsoTables' `$DependsOn`
  map copied into `app/Support/TrimExport/TableDependencies.php`: "Activity_Status is kept but Activity is removed,
  so its rows will point at nothing."

### 3.2 Options step 2: rows

The five filters Filter Load File has, each a searchable multi-select filled from the file (ids and labels gathered
by a streamed pass when the step opens, cached per export file for the export's lifetime):

- Regions (via Resource_Region for resources; Location.region_id or Location_Region for locations, the #42 rule).
- Resources (id and name).
- Activity types.
- Activities (ids).
- Date range on Activity_SLA APPOINTMENT windows (start and end pickers, same semantics as today).

Plus **Input_Reference datetime override** (kept from Filter Load File) and **Count only**: run the engine and show
the counts and warnings but write no file (Remove-PsoTables `-List`).

Leaving every filter empty means rows are untouched: the run is tables only.

## 4. The engine

`app/Support/TrimExport/` in PHP, format-agnostic core with two format drivers:

- `TrimPlan`: the resolved request (tables to drop, keep set, row filters, override, count only).
- `Readers`: `XmlTableReader` (XMLReader, depth-1 elements as rows) and `JsonTableReader` (the existing
  `JsonTokenReader` under `dsScheduleData`), both yielding `(table, row fields)` for pass one and raw row bytes for
  pass two.
- `Writers`: `XmlTableWriter` (XMLWriter, byte-verbatim copy of kept rows, same declaration and namespace, root
  `dsScheduleData`) and `JsonTableWriter` (same structure as the input: `{"dsScheduleData": {Table: [...]}}`, a
  single-row table written as an object when the input had an object, the rule the #26 fix uses).
- `Pass one (keys)`: reads every row once and builds the keep sets: resources (from Resource_Region when regions are
  set, intersected with the resource filter), locations (Location.region_id or Location_Region), activities
  (location in the keep set when regions are set, type filter, id filter, SLA date window), shifts (of kept
  resources), availability ids referenced by kept Resource_Region_Availability and Resource_Skill_Availability rows
  and kept Activity rows, plus the ids a kept Activity or Shift or Resources or Resource_Type row points at for
  locations. Memory holds ids only.
- `Pass two (write)`: streams the file again; a row of a dropped table is skipped; a row of a kept table is skipped
  when it points at a removed activity, resource, location or shift (the generic cascade of #26: any `activity_id`,
  `resource_id`, `location_id`, `shift_id` field that is not in the keep set); Availability, Resource_Region_Availability
  and Resource_Skill_Availability are never filtered by date; Location is kept when referenced; everything else is
  copied byte-verbatim. The Input_Reference row gets the datetime override when set.
- `Referential check`: after pass two, the writer's own tally of kept ids is compared with every reference field it
  wrote; anything still pointing at nothing is listed as `dangling` (table, field, count, first three ids).
- Order: tables first, then rows, so a dropped Plan table never cascades.
- The whole thing runs in the queue through the standard `RunToolJob`; the tool reports progress (pass one, pass two)
  through the existing progress hook; the output is written to the scratch directory, then stored.

Performance target: the 135 MB Acme plan export in under three minutes on the box; memory flat (ids only).

## 5. Output

- A new `ExportFile` on the same scope as the input (dataset or user), kind = the input's kind (but see section 9: a plan or End of day export with no Plan table left is filed as a LOAD), original name
  `<input name without extension>.trimmed.<ext>`, uploaded by the user who started the run, stored through
  `ExportStorage` from the scratch file, then `SummariseExportFileJob` so it gets its summary and table counts. Same
  12-hour expiry.
- The run's result (encrypted) holds the summary: per table before and after counts, removed tables, the dangling
  list, the warnings; and the new export's id. `ToolRun.summary` holds counts only (tables removed, rows kept and
  removed), never ids or names.
- The run page shows: the counts table (before, kept, removed per table), the dependency warnings, the dangling list,
  a link to the new export (its Runs tab or My files), a Download button for it (through the normal export download),
  and for Count only the same without the file.

## 6. What goes away

After this ships and Ish has used it on a real file: the Filter Load File page and its view, `ProcessResourceFile`,
`ResourceActivityFilterService`, `PreviewSummaryFormatter` if nothing else uses it, the `download.filtered` route and
`storage/app/public` serving (#19), and their tests. `scripts/Remove-PsoTables.ps1` stays as the Windows version.

## 7. Out of scope

- Row filters on tables other than the ones Filter Load File handles today (no new filter kinds).
- Editing rows (only drop or keep).
- A JSON to XML or XML to JSON conversion (output format = input format).
- Multi-file input or merging exports.

## 8. Testing

- Unit: readers and writers round-trip small XML and JSON files byte-exactly when nothing is dropped; table removal;
  each row filter; the cascade; the Availability-never-by-date rule; Location kept when referenced; dangling list;
  dependency warnings; Input_Reference kept, dropped only when unlocked; the override; single-row JSON table shape.
- Parity: the same synthetic data as XML and as JSON gives the same kept rows and counts.
- Feature: wizard steps (table list from the file's counts, preset and Custom switch, footer text, warnings), the run
  creating the new export on the right scope with the right name, the Unfiled case, Count only writing nothing,
  another user never seeing the result.
- Golden (skipped when the files are absent): the customer LOAD under `~/pso-data/ACME/2026-10-04/` with region V1,
  5 to 9 Oct: 85 resources, 8 activities, Location 3928 to 1362 (from #26 and #42); and the 135 MB plan export with
  the Cleanup preset: every Plan* and Allocation* table gone, Input_Reference kept, time under the target.

## 9. Rulings made while planning (2026-10-17)

- Input kinds: LOAD, plan and EOD are all optional on the tool; a `TakesOneInput` contract makes `start()` refuse none or more than one (the spec's "LOAD required" would refuse a plan alone).
- Two Filter Load File behaviours are kept: the appointment window also drops shifts outside it; Activity_Status and Activity_SLA follow only their activity, so a status fixed to a resource outside the filter stays and is listed as dangling (the #26 check relied on this).
- The golden test pins 85 resources and 8 activities for V1, 5 to 9 Oct, and that every kept location reference resolves; the 1,362 Location figure came from a different filter and is not pinned.
- A zipped input gives a zip back (`<name>.trimmed.zip` holding `<member>.trimmed.xml`). The new owner-only export download refuses SYS exports, which hold live credentials.
- A trimmed plan or End of day export with no Plan table left is filed as a LOAD (the summariser would refuse it as a plan export); with a Plan table kept it keeps its kind.
- With row filters set, the keep sets are built from every table in the file, removed or not; a removed table is never written but still decides which rows of kept tables go.
- Task 12 (removing Filter Load File and the public download route) runs only after Ish has used the tool on a real file.
