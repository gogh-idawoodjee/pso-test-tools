# DC Demo Dataset — Stage 1R: Modelling Data (RAM)

**For:** Claudia (pso-test-tools)
**Scope:** alongside the scheduling LOAD from stage 1, generate a second file per dataset in the PSO modelling schema (`DsModelling`, the `.arp` format) carrying the same regions, locations, skills, resource types, resources and shift patterns. Load both. Shifts keep coming from the scheduling LOAD; the modelling data is what lets Ish edit the model and add or override shifts live in the PSO Resource Planner.
**Companion files:** `stage1r-ram-entity-cheatsheet.md` (the only source of truth for RAM field names), stage 1 brief and cheat sheet, seeds (two small additions below).
**Runs in parallel with stage 2** — activities don't care about any of this.

---

## Why

Everything stage 1 sends lives only in the scheduling dataset, which the DSE treats as disposable input: to change a shift you regenerate and reload. PSO's Resource Planner works from the modelling dataset instead — divisions, resources, shift templates, a rota — and lets a planner add, move or delete shifts by hand. For demos ("Bruce calls in sick, watch the DSE reallocate") that is exactly the tool we want, so the generator emits the model into RAM too. The rota itself we leave alone: PSO owns rota tables and their ids, and Ish's rule is **no shifts in RAM**. Loaded shifts and planner-made shifts coexist on a resource (different ids merge; same id, last writer wins), so the planner can layer on top of what the LOAD brought in.

Same rule as stage 1: field names come from the cheat sheet, not memory. If something isn't there, ask.

---

## 1. Spec additions

**Building:** two new keys, both optional with defaults.

| Key | Default | Purpose |
|---|---|---|
| `modelling` | `true` | Emit the `.arp` alongside the LOAD and add `Source_Data RAM` to the LOAD |
| `resource_types{}.shift_category_colour` | per cheat sheet | ARGB colour for the planner Gantt |

Nothing else changes in the spec. Divisions come from `regions[]` (id, description, `time_zone`), templates from `resource_types{}.shift` and `.break`, everything else from what stage 1 already reads.

---

## 2. Modelling writer

**Building:** `DsModellingWriter`, a sibling of `DsScheduleDataWriter` on the same `XMLWriter` pattern, root `<DsModelling xmlns="http://360Scheduling.com/Schema/DsModelling.xsd">`, cheat-sheet emit order. Output `storage/app/datasets/{dataset_id}/{yyyymmdd-HHmm}-stage1r.arp` next to the `.xml`.

Entity by entity, from the same in-memory model the scheduling writer uses:

- **`RAM_Update`** — `LOAD`, `is_master_data=true`, `id` = the same unix timestamp as the LOAD's `Input_Reference.id`.
- **`RAM_Division`** — one per region, `send=true`, `time_zone_id` = region `time_zone`. Flat, no parents.
- **`RAM_Location`** — every home and depot location stage 1 already emits, same ids, lat/long plus the address fields.
- **`RAM_Skill`**, **`RAM_Resource_Skill`** — from `skills[]` and each resource's `skills[]`.
- **`RAM_Resource_Type`** — one per type; `ram_resource_class_id=PERSON`; costs/travel/utilisation as in the type; `hire_cost 4000`, `release_cost 4000`, `relocation_cost 2000` fixed.
- **`RAM_Rule*`** — the STD commit rule, mirrored.
- **`RAM_Resource`** — id, names, type, start/end location, rule collection, plus any `overrides` keys. Inheritance from the type exactly as the stage 1 fix.
- **`RAM_Resource_Division`** — home region, plus one row per `extra_regions` entry (permanent membership in RAM; the date-bounded version stays in the LOAD).
- **`RAM_Shift_Category`** — one per resource type, colour from the spec.
- **`RAM_Shift_Template`** + **`_Item`** + **`_Item_Break`** — one template per resource type, built from `shift.days[]` (ISO weekday → `day_increment` = weekday − 1), `shift.start` (→ `time_increment`, e.g. `07:30` → `PT7H30M`), `shift.end − shift.start` (→ `duration`), and the break block. One item and one break per working day, `sequence` 1..n matching. Break ids `{template}-{sequence}`.

**Not emitted:** anything rota-related, `RAM_Version_History`, anything activity-related (later stages mirror `Activity_Type`, `SLA_Type`, appointment templates into RAM the same way).

**Scheduling writer change:** when `modelling` is true, append `Source_Data` + `Source_Data_Parameter rota_id={dataset_id}` to the LOAD (cheat sheet, last section). Nothing is removed from the LOAD — `Resources`, `Shift`, `Shift_Break` all stay.

---

## 3. Consistency

**Building:** a single `GenerateDataset` run produces both files from one model object; the writers never re-derive anything. Add a test that walks the two outputs and asserts: same set of resource ids, location ids, skill ids, region/division ids and type ids; every `RAM_Resource_Division` has a matching `Resource_Region`; the template for each type reproduces the first week of that type's loaded shifts (start time, duration, days) in the division's zone.

---

## 4. Page and send

**Building:** the summary gains a line for the `.arp` (entity counts, size) and a second download button. For Send: check whether the existing Gateway uploader can post modelling data (the RESTful Gateway has a modelling input alongside scheduling input, same session, different route). If it can, Send does both, **modelling first, then the LOAD** — the LOAD's `Source_Data` refers to RAM, so RAM must exist first. If it can't, say so in the report and leave the `.arp` as download-only for now; Ish can import it through the Resource Planner's Import Data. Don't build a new transport in this stage.

---

## 5. Acceptance (do these, report, stop)

1. Both datasets produce an `.arp` and an `.xml`; the consistency test passes.
2. `.arp` entity counts match expectation: US — 6 divisions, 36 resources, 46 locations (36 homes… minus depot-based installers, plus 5 depots — print the actual count and the formula), 4 types, 4 templates with 5/5/4/5 items, 4 categories; CA — 2 divisions, 12 resources, 2 templates in use but all 4 emitted.
3. Template spot check printed: INSTALLER has items with `day_increment` 0–3, `time_increment PT7H`, `duration PT10H30M`, breaks `PT45M PT4H–PT5H30M`; CONTRACTOR has `day_increment` 1–5.
4. LOAD now contains `Source_Data RAM` with `rota_id` = dataset id; the stage 1 acceptance (counts, DST table, Monday rule) still holds unchanged.
5. **Ish, in the Technodrome:** import the `.arp` into `DC_US` (planner or Gateway), then send the LOAD. Expect: planner shows 6 divisions with their zones, 36 resources under them, 4 templates; event log clean; scheduling Gantt identical to stage 1. Then add one shift to Dick Grayson from the FIELD_TECH template on a day he already has one, and confirm the Gantt shows both (merge, not overwrite), and that the new one sits at 08:00 Chicago.
6. If `rota_id` turns out to need a different value, or any *(schema, unverified)* field is rejected, report which — those are the two known soft spots.

Report: counts from 1–4, pass/fail per line, any import or event-log messages verbatim. Then stop.

---

## Known limits, deliberately accepted

- Planner-generated Monday shifts come out at the template's `split_priority` (1), not 0. Loaded shifts are unaffected. Revisit if stage 4 splitting tests ever hit a planner-added Monday.
- RAM region membership for `extra_regions` resources is permanent; the LOAD's `Resource_Region_Availability` still time-bounds it for the DSE.
- Template-to-resource assignment is done in the planner by hand — not modelled, by design.
