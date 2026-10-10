# Design: dataset-scoped reference values, synced from PSO

**Date:** 2026-10-10
**Status:** Approved design, pending implementation plan
**Repos:** pso-test-tools (data model, UI, sync job), pso-services (one new endpoint)

## 1. Problem

The "Base Data" entities (Region, Skill, TaskType, SlotUsageRule, AppointmentTemplate) are
owned by the logged-in user, not by a PSO dataset. In PSO those identifiers live in a dataset,
and one environment holds several datasets (for example account SATE has dataset NORTH). A
user with two environments cannot keep the right identifiers with the right environment, and a
Customer can point at a Region that does not exist in the dataset a test is being sent to.

Every one of those rows is also typed by hand, although the PSO RESTful Gateway already returns
all of them in the dataset's dsScheduleData.

The tools only need the identifiers that PSO will accept in a request (appointment request,
activity, and so on), not the full definition of each entity. The one exception is the task
type, whose priority, base value and base duration are the fallback when the activity and the
activity status do not set them (PSO precedence: Activity_Status, then Activity, then
Activity_Type).

## 2. Decisions already made

- Reference values are scoped to the **Dataset**, not the Environment.
- PSO is the source of truth, but every row stays **fully editable**; a local edit survives the
  next sync.
- The six entity types are stored in **one generic table** with an enum for the type and a JSON
  column for the few type-specific fields. Adding an entity type later is an enum case and a
  mapping, not a migration.
- Existing rows in the old tables are test data with no value. They are dropped, not migrated.
- Customer and Task become dataset-scoped too, with the same drop-and-recreate treatment.
- The gateway call lives in **pso-services**, behind one new endpoint. pso-test-tools never talks
  to the gateway for this.
- SLA_Type is added as the sixth entity type. Child and link tables (Appointment_Template_Item,
  Appt_Template_Slot_Usage, Activity_Type_Skill, SLA_Template, Resource_Type, Activity_Class)
  are **not** stored: requests reference them by id only, or hardcode them.

## 3. Gateway facts this design relies on

From the IFS PSO Interface Guide (RESTful Gateway), Data endpoint, GET:

- Parameters are `datasetId`, `includeInput`, `includeOutput`, `broadcastId`. There is no way to
  request a subset of tables. Every sync is a full dataset body.
- The response is compressed when the request sends `Accept-Encoding: gzip`.
- Bodies are the same size as a LOAD export: tens to hundreds of MB. The fetch must run off the
  web request with its own timeout and be parsed as a stream.

The guide is the 2018 edition. If a newer gateway adds a table filter, the endpoint in section 5
can use it without changing its contract.

## 4. Data model (pso-test-tools)

### 4.1 `dataset_reference_values`

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | primary key |
| dataset_id | uuid | foreign key to `datasets`, cascade on delete |
| entity_type | string | enum `ReferenceEntityType`, see 4.2 |
| pso_id | string(64) | the identifier PSO accepts; PSO ids are String(32) |
| label | string | display name; defaults to `pso_id` when PSO has no name |
| attributes | json, nullable | type-specific fields, see 4.2 |
| origin | string | enum `ReferenceValueOrigin`: `pso`, `manual` |
| last_seen_at | datetime, nullable | set by every sync that saw the row; null for manual rows |
| is_locally_modified | boolean, default false | set when a user edits a `pso` row |
| timestamps | | |

Unique index on (`dataset_id`, `entity_type`, `pso_id`). Index on (`dataset_id`, `entity_type`).

Model `DatasetReferenceValue`:

- `belongsTo(Dataset)`.
- Global scope `DatasetOwnedModel`: `whereHas('dataset.environment', user_id = auth()->id())`.
  Replaces `UserOwnedModel` for everything scoped by dataset.
- Casts: `entity_type` and `origin` to their enums, `attributes` to array, `last_seen_at` to
  datetime.
- Accessor `isMissingFromPso()`: `origin === pso && last_seen_at < dataset->last_synced_at`.
- Activity log options as the old models had.

### 4.2 `ReferenceEntityType` enum

| Case | PSO table | Label source | Attributes |
| --- | --- | --- | --- |
| `region` | Region | `description` | none |
| `skill` | Skill | `description` | none |
| `task_type` | Activity_Type | `description` | `priority` (int, from `priority`), `base_value` (decimal, from `base_value`, a Double in PSO), `base_duration` (whole seconds, from `duration`, a Time Span in PSO) |
| `slot_usage_rule` | Slot_Usage_Rule | `description` | none |
| `appointment_template` | Appointment_Template | `description` | none |
| `sla_type` | SLA_Type | `description` | none |

The enum carries, per case: the PSO table name, the PSO id column (all `id`), the label column,
the list of attribute columns with their casts, the navigation label and the Filament resource
class. Column names are confirmed against the Scheduling Schema document: all six tables have
`id` String(32) and `description` String(2000); Activity_Type has `priority` Integer,
`base_value` Double and `duration` Time Span. When `description` is empty the label falls back
to `id`. The time span is parsed by the same rules the existing formatters use for PSO durations
and stored as whole seconds.

Attribute values are validated per type in the form and in the sync (numeric, nullable).

### 4.3 Dataset

`datasets` gains:

| Column | Type | Notes |
| --- | --- | --- |
| last_synced_at | datetime, nullable | end of the last successful sync |
| last_sync_summary | json, nullable | counts per entity type: created, updated, kept (locally modified), missing |

`Dataset` gets `hasMany(DatasetReferenceValue)`, `hasMany(Customer)`, `hasMany(Task)`, and a
`referenceValuesOf(ReferenceEntityType $type)` helper. `name` is the PSO dataset id, as the
Environment Tools page already treats it.

### 4.4 Customer and Task

- `customers`: drop `user_id`, add `dataset_id` (uuid, foreign key, cascade). `region_id`
  becomes a uuid foreign key to `dataset_reference_values` (nullable, null on delete).
- `tasks`: drop `user_id`, add `dataset_id` the same way. `task_type_id` becomes a uuid foreign
  key to `dataset_reference_values` (null on delete).
- Both use `DatasetOwnedModel` instead of `UserOwnedModel`. `Customer::region()` and
  `Task::taskType()` are `belongsTo(DatasetReferenceValue)`.
- Form rule: a Customer's region and a Task's task type must belong to the same dataset as the
  row, enforced by a validation rule on save, not only by the select filter.
- `friendly_id` on tasks stays unique per dataset (unique index on `dataset_id`, `friendly_id`)
  instead of globally unique.

### 4.5 Migration

One migration, `2026_10_10_000001_create_dataset_reference_values`:

1. Create `dataset_reference_values`.
2. Add the two sync columns to `datasets`.
3. Drop `tasks`, `customers`, `task_types`, `skills`, `regions`, `slot_usage_rules`,
   `appointment_templates` (children first).
4. Recreate `customers` and `tasks` with the new columns.

`down()` recreates the old tables empty and drops the new ones. No data is carried either way.
The old models, resources, factories and their migrations are deleted from the codebase in the
same change.

## 5. Sync

### 5.1 pso-services endpoint

`GET /api/v2/dataset/reference-values`, controller `DatasetReferenceValuesController@show`,
request class `DatasetReferenceValuesRequest extends BaseGetFormRequest` (the common fields:
`baseUrl`, `accountId`, `username`, `password`, `datasetId`; no extra rules).

Service `ReferenceValuesService`:

1. Authenticate with `PSOAuthService` as the other endpoints do.
2. `GET {base}/IFSSchedulingRESTfulGateway/api/v1/scheduling/data?datasetId=...&includeInput=true`
   with `Accept: application/xml` and `Accept-Encoding: gzip`, streamed to a temporary file
   (`sink`), with a timeout from `config('pso-services.reference_values.timeout')`, default 300
   seconds. The default client timeout of 10 seconds is not used here.
3. Parse the file with `XMLReader`. For each element whose local name is one of the six PSO
   tables, read its child elements into an associative array and keep only the id, the label
   column and the attribute columns. Skip everything else without materialising it. Strip
   namespaces.
4. Delete the temporary file in a `finally`.
5. Respond through the existing `ApiResponses` envelope:

```json
{
  "data": {
    "dataset_id": "NORTH",
    "fetched_at": "2026-10-10T14:03:11Z",
    "tables": {
      "Region":               [{"id": "R1", "description": "Regina"}],
      "Skill":                [{"id": "FIBRE", "description": "Fibre install"}],
      "Activity_Type":        [{"id": "INSTALL", "description": "Install", "priority": 5, "base_value": 1000, "base_duration": 3600}],
      "Slot_Usage_Rule":      [{"id": "AM"}],
      "Appointment_Template": [{"id": "STANDARD", "description": "Standard"}],
      "SLA_Type":             [{"id": "IN", "description": "In window"}]
    }
  }
}
```

Errors: gateway auth failure and non-200 responses map to the existing error envelope with the
gateway status. A body that is not a `dsScheduleData` document is a 502 with a clear message.
The usual `DisallowProdUrl` rule applies.

Only the six tables leave pso-services. The raw body is never stored past the request.

### 5.2 pso-test-tools job

`SyncDatasetReferenceValuesJob` (queued, `timeout` 600, no retries on gateway errors):

1. Load the dataset and its environment; decrypt the password as the existing jobs do.
2. Call the endpoint through the existing pso-services client helper.
3. In one transaction, for each table in the response, for each row:
   - No existing row for (dataset, type, pso_id): create with `origin = pso`,
     `last_seen_at = now`, label and attributes from PSO.
   - Existing row, `is_locally_modified = false`: overwrite label and attributes, set
     `last_seen_at = now`. Origin stays as it is (a `manual` row that PSO now also has becomes
     `pso`).
   - Existing row, `is_locally_modified = true`: set `last_seen_at = now` only.
   - Rows for that type not in the response: untouched. They show as missing because their
     `last_seen_at` is older than the dataset's `last_synced_at`.
4. Set `datasets.last_synced_at = now` and `last_sync_summary` to the per-type counts.
5. Notify the user through the existing Filament database notification channel: success with
   the counts, or failure with the gateway message.

Sync never deletes a row. Users delete rows themselves.

### 5.3 Trigger

A `Sync from PSO` header action on the Dataset view page. It dispatches the job, shows a
"sync queued" notification, and the page shows `last_synced_at` and the summary counts. A
second click while a sync is running for the same dataset is refused (cache lock keyed by
dataset id, 10 minutes).

No scheduled sync in this release.

## 6. UI (pso-test-tools)

### 6.1 Reference value resources

Abstract `DatasetReferenceValueResource` with `protected static ReferenceEntityType $entityType`,
and six thin subclasses (`RegionResource`, `SkillResource`, `TaskTypeResource`,
`SlotUsageRuleResource`, `AppointmentTemplateResource`, `SlaTypeResource`) that only set the
type, navigation label and icon. They replace the existing resources of the same names.
Navigation group stays `Base Data`.

Shared table: dataset (filterable, default to the user's most recently synced dataset when
there is one), `pso_id`, `label`, origin badge (`PSO` or `Manual`), `Missing from PSO` badge,
`Edited` badge when `is_locally_modified`, `last_seen_at`. Task type adds its three attribute
columns.

Shared form: dataset select (user's datasets, disabled on edit), `pso_id` (required, max 32,
unique within dataset and type), `label`, and for task type the three attribute fields as
integer inputs bound to `attributes.*`. Saving an edit on a `pso` row sets
`is_locally_modified = true`. Creating a row sets `origin = manual`.

Row action `Reset to PSO`: visible on `pso` rows with `is_locally_modified = true`; clears the
flag so the next sync overwrites the row. It does not fetch anything itself.

Bulk action `Delete` as today.

### 6.2 Dataset

The Dataset view page gets the `Sync from PSO` action (5.3), a small summary section (last
synced, counts), and one relation manager per entity type, reusing the shared table and form.
Customers and Tasks get relation managers there too.

### 6.3 Customer and Task

Forms gain a dataset select; region and task type selects are filtered to the chosen dataset
and clear when the dataset changes. Lists gain a dataset column and filter.

### 6.4 Consumers

Pages that build PSO requests read identifiers with
`Dataset::referenceValuesOf(ReferenceEntityType::X)` for the dataset the user picked. Today no
page reads the old tables, so this release only wires the selects on Customer and Task; the
activity and appointment pages pick the new source up when they are next touched.

## 7. Testing

pso-services:

- Unit: `ReferenceValuesParser` against a synthetic dsScheduleData XML holding all six tables,
  other tables that must be ignored, a namespaced root, a missing `description`, and an
  Activity_Type with a time-span duration. Asserts the exact JSON shape above.
- Feature: the endpoint with `Http::fake()` for session and data calls: happy path, gateway 401,
  gateway 500, non-dsScheduleData body, prod URL refused.

pso-test-tools:

- Feature: migration leaves no old tables and the new foreign keys resolve.
- Feature: `SyncDatasetReferenceValuesJob` with the services call faked, one test per rule in
  5.2 (create, overwrite, keep locally modified, leave missing), plus the summary counts and
  the `manual` to `pso` origin change.
- Feature: each resource lists only its type and only datasets owned by the user; editing a
  `pso` row sets the flag; `Reset to PSO` clears it; a Customer cannot save a region from
  another dataset.
- Golden: when `REFERENCE_VALUES_SAMPLE_LOAD` points at a real LOAD export (kept outside the
  repo, as the sys compare samples are), the parser output is compared with a stored JSON
  snapshot; skipped otherwise.

## 8. Out of scope

- Account-style settings (display names, attribute labels, exclusions, coverage): a later spec
  on top of this table.
- Feeding the parser from an uploaded LOAD file: part of the shared upload subsystem spec.
- Scheduled syncs, and syncing Resources or any output table.
- Porting the remaining PSO tools.
