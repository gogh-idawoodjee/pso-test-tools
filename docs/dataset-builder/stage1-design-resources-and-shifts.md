# DC Demo Dataset — Stage 1: Resources and Shifts

**For:** Claudia (pso-test-tools)
**Scope of this stage:** generate a `dsScheduleData` LOAD that contains regions, locations, skills, resource types, resources, shifts and breaks — and **no activities**. Load it into the Technodrome, confirm 48 resources appear on the Gantt with the right shift shapes across four time zones, then stop. Later stages add activities on top of the same spec.
**Lives in:** `pso-test-tools/docs/dataset-builder/` (brief + cheat sheet); seeds in `resources/datasets/`.
**Companion files:** `stage1-entity-cheatsheet.md` (the only source of truth for XML field names), `seed-DC_US.json`, `seed-DC_CA.json`.

---

## What we're building (whole project, for context)

A **Dataset Builder** page in pso-test-tools. Ish picks a seed spec (`DC_US` or `DC_CA`), leaves "now" at the current time, clicks Generate, and gets a complete LOAD XML for the Technodrome: ~36 (US) or ~12 (CA) field resources named after DC characters across real North American metros and rural corridors, eventually with ~1,000 activities between the two datasets piled onto today/tomorrow and tapering over six weeks, appointment and SLA work mixed, split jobs, and live in-flight statuses as of the moment he clicked. Re-running tomorrow with the same seed gives the same world slid forward a day — that's the "make it current" tool.

Everything is synthetic. The XML field names come from the cheat sheet, not from memory — PSO silently drops unknown fields and we won't know until something looks wrong. If the cheat sheet doesn't cover something, ask.

We build it in stages, each loaded and checked before the next:

1. **Resources and shifts** ← this document
2. SLA work, unallocated (activity types, SLA types, a few jobs, status 0)
3. Appointments (templates + the three-row booked-appointment pattern)
4. Splittables (long PROJECT jobs across shifts, never onto a Monday)
5. Status derivation — the "now" simulation (travelling / on-site / complete / committed)
6. Backlog curve + activity cap; then the "shift an existing file" mode

The seed spec already carries the fields later stages need (`activity_types`, `sla_types`, `backlog`, region `appointment_templates`). Stage 1 reads only what it needs and ignores the rest. Don't delete them, don't implement them.

---

## 1. Seed spec

**Building:** read a JSON spec from `resources/datasets/{id}.json`. Validate it into a plain PHP DTO (`DatasetSpec`) so the generator never touches raw arrays. Stage 1 fields:

| Key | Used for |
|---|---|
| `dataset_id`, `organisation_id`, `description` | `Input_Reference` |
| `seed` | Seeds a `Random\Randomizer` instance. Same seed + same spec + same `now` ⇒ byte-identical output (`Input_Reference.datetime`/`id` come from `now`, so two runs a minute apart differ only there) |
| `base_time_zone` | Not used in stage 1 beyond being stored |
| `horizon_days` | How many days of shifts to generate from day 0 (100 — must cover `duration` + `appointment_window_duration`, i.e. 7 + 90; validate this and refuse to generate if it doesn't) |
| `input_reference` | `duration`, `appointment_window_duration`, `process_type` |
| `regions[]` | `id`, `description`, `time_zone`, `kind`, `centroids[]` (`lat`, `lon`, `weight`, `label`), `spread_km`, `depot` (lat/lon/address), `street_names[]` |
| `resource_types{}` | id → `description`, `shift` (`days[]` ISO weekday 1–7, `start`, `end` local `HH:MM`), `break` (`duration`, `earliest_offset`, `latest_offset`, ISO durations), `start_end` (`home` / `depot`), `travel_to`, `travel_from`, `max_travel`, `cost_ph`, `cost_phot`, `cost_km`, `utilisation` |
| `skills[]` | `id`, `description` |
| `resources[]` | `id`, `first_name`, `surname`, `region`, `resource_type`, `skills[]`, optional `extra_regions[]`, optional `utilisation` override |
| `rule_collection` | `MaximumCommittedActivities` etc. |

**"Now"** is an input to the generator, default `now()`, overridable from the page. Day 0 = the calendar date of `now` **in each region's own time zone** (an 11 pm Toronto run is still "today" in LA). Stage 1 uses `now` only for `Input_Reference.datetime` and for picking day 0.

---

## 2. Geography and locations

**Building:** `LocationFactory`.

- **Home locations.** One `Location` per resource whose type has `start_end = home`. Pick a centroid by weight, then offset by a normal distribution with σ = `spread_km / 2` in both axes (convert km → degrees: lat ÷ 111, lon ÷ (111 × cos lat)). Rural regions: pick a random point along the segment between two consecutive centroids, then jitter ± 8 km. Id `H-{resource_id}`.
- **Depot locations.** One `Location` per region that has a non-null `regions[].depot`. Id `D-{region_id}`. Used as start/end for `start_end = depot` types. Rural regions `MTN` and `ONT_RURAL` have `depot: null` — skip them; a depot-based resource in a region with no depot is a spec validation error.
- **Addresses** are synthetic but readable: `"{100–9999} {street_name} {Ave|St|Blvd|Rd}"`, city = nearest centroid's `label`, state/province from the region, a plausible postcode shape (`606xx` for Chicago, `M5V xxx` for Toronto — from the spec). HTM only needs lat/long; the Workbench shows the address, so keep it human.
- `Location.name` = `"{address_line1}, {city}"`.

Every resource's home region gets a `Resource_Region` row. Each `extra_regions` entry gets an `Availability` row spanning the **whole horizon** (day −7 to day `horizon_days`, so about 107 days) plus a `Resource_Region_Availability` row pointing at it. The spec has two of these in US (Carter Hall and Shayera Hol → `MTN`) and one in CA (John Henry Irons → `ONT_RURAL`) so the region-boundary case exists from day one.

Region ids are short and boring (`CHI`, `LAX`, `DFW`, `DEN`, `IOWA`, `MTN`, `TOR`, `ONT_RURAL`); they appear in every filter and must stay scannable. The DC flavour is in resource names and street names only.

**PSO entities:** `Region`, `Location`, `Resource_Region`, `Availability`, `Resource_Region_Availability`.

---

## 3. Resource types

**Building:** emit one `Resource_Type` per spec entry. The four shipped, and why each exists:

| Type | Shift (local) | Break | Start/End | Cost ph / phot / km | Purpose |
|---|---|---|---|---|---|
| `FIELD_TECH` | Mon–Fri 08:00–16:30 | 30 min, offset 3h–5h | home | 45 / 68 / 0.60 | bulk of the workforce |
| `SENIOR_TECH` | Mon–Fri 07:30–16:00 | same | home | 65 / 98 / 0.60 | holds the scarce skills (`SAFETY_CERT`, `COMMERCIAL`); utilisation 90 |
| `INSTALLER` | Mon–Thu 07:00–17:30 (4×10) | 45 min, offset 4h–5h30 | depot | 50 / 75 / 0.60 | the long-job people in later stages |
| `CONTRACTOR` | Tue–Sat 09:00–17:00 | 30 min | home | 120 / 180 / 1.10 | expensive on purpose; two skills only |

`travel_to`/`travel_from` are the max-travel-home limits; `max_travel` is the max journey between jobs. `out_of_region_multiplier=0`, `speedfactor=1`, `resource_class_id=STANDARD`. The type does **not** carry `location_id_start/end` — resources do.

**PSO entities:** `Resource_Type`, `Skill`, `Rule`, `Rule_Collection`, `Rule_Collection_Rule`, `Rule_Parameter`.

---

## 4. Resources

**Building:** one `Resources` row per spec entry (yes, the entity name is plural). Fields: `id`, `first_name`, `surname`, `resource_type_id`, `resource_class_id=STANDARD`, `location_id_start`/`location_id_end` (home or depot per type), `travel_to`/`travel_from`/`cost_*`/`utilisation` copied down from the type unless overridden, `rule_collection_id=STD`, `memo=*`. One `Resource_Skill` per skill.

Casting (all in the seed files): Chicago = Bat-family + Lucius Fox + Alfred as contractor; LA = Green Lanterns + Carol Ferris as contractor; DFW = Flash family; Denver = Hawks and misc Leaguers; Iowa = the Kents and Smallville; Toronto = Superman family + Lex Luthor as contractor; rural Ontario = the retired JSA. Nobody appears in both datasets. Counts: US 36, CA 12.

**PSO entities:** `Resources`, `Resource_Skill`. (No `Object_Group`: FIX on #60.)

---

## 5. Shifts and breaks — the part that has to be exactly right

**Building:** `ShiftFactory`. For each resource, for each day from day −7 to day 0 + `horizon_days` whose ISO weekday is in the type's `days[]`, emit one `Shift`.

**Time zone rule.** Build the shift start and end as `DateTimeImmutable` in the **region's** IANA zone from the local `HH:MM`, then `->setTimezone(new DateTimeZone('UTC'))` for output. Never add a fixed offset. Nov 1 2026 is DST end across North America and sits inside the first horizon we'll ever generate: Chicago 08:00 is `13:00Z` on Fri Oct 30 and `14:00Z` on Mon Nov 2. Toronto and rural Ontario share a zone; DFW and Iowa are both `America/Chicago`; Denver is `America/Denver`; LA is `America/Los_Angeles`. The spec drives this — no hard-coded zones in code.

**Fields.** `id` numeric and readable: `{resource_seq:03}{yyyymmdd}` (e.g. `00120261102`). `resource_id`, `start_datetime`, `end_datetime`, `actual=true`, `utilisation` from the resource, `split_allowed=true`, `split_priority` = **`0` on Mondays (local), `1` every other day**. The Monday rule is what stops cross-shift splits landing on a Monday in stage 4 — confirmed behaviour from Ish's Acme splitting POC (Monday shifts `split_priority=0`, all others `1`, blocks Monday as a split destination regardless of the activity's `split_minimum_priority`). Set it now so we don't have to touch shifts again.

**Breaks.** One `Shift_Break` per shift: `id = shift id + "1"`, `shift_id`, `duration`, `earliest_start_offset`, `latest_start_offset` from the type. All breaks are implicit (`Shift_Break`) — no BREAK activities in this project until a later stage decides otherwise.

**Shift_Type.** Reference `Default`; don't emit it, it exists in every install. There is no `shift_type_id` on `Shift` in this wire format — don't add one.

**PSO entities:** `Shift`, `Shift_Break`.

---

## 6. Serialisation

**Building:** `DsScheduleDataWriter` on `XMLWriter`, streaming. Root `<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">`. Entities grouped by type in the cheat sheet's emit order. Datetimes `Y-m-d\TH:i:sP` in UTC (`+00:00`), durations ISO-8601, booleans lowercase, ids ≤ 32 chars. Omit a field rather than send it empty.

`Input_Reference`: `datetime = now` (UTC), `id` = unix timestamp of `now` (e.g. `1793374200` for 2026-10-30 15:30Z), `input_type=LOAD`, `organisation_id`, `dataset_id`, `schedule_data=CONTINUOUS`, `load_status=0`, `duration`, `appointment_window_duration`, `process_type`. Nothing else — the rest of that entity is PSO's to fill.

Emit **only** the entities in the cheat sheet. Never `Plan*`, `Allocation*`, `Schedule_Exception*`, `Schedule_Event`.

Output to `storage/app/datasets/{dataset_id}/{yyyymmdd-HHmm}-stage1.xml`.

---

## 7. Page

**Building:** a Filament page `Dataset Builder` under Tools. Stage 1 controls: spec picker (lists `resources/datasets/*.json`), "now" datetime (default current, editable), seed override, Generate. After generate: download link, and a summary table — resources by region × type, shifts count, first and last shift datetime per region in local time *and* UTC (so a DST error is visible in the summary without opening the file), file size. Send-to-environment button reuses the existing Gateway upload with the existing environment picker, **Skip webhook broadcast ticked by default** for these loads. A "clean reset (Delete-all then LOAD)" option is new and can land in stage 2 — for stage 1 a plain LOAD is fine.

No screenshots in the summary. Counts and timestamps are enough.

---

## 8. Stage 1 acceptance (do these, report the results, then stop)

1. `DC_US` and `DC_CA` both generate with no exceptions; US has 36 resources, CA has 12.
2. Shift count per resource matches its type's day pattern over the horizon (FIELD_TECH ≈ 5/week, INSTALLER 4, CONTRACTOR 5 incl. Saturday).
3. Spot-check in the summary: Chicago FIELD_TECH shift on Fri Oct 30 starts `13:00Z`, on Mon Nov 2 starts `14:00Z`. LA on those days `15:00Z` → `16:00Z`. Toronto `12:00Z` → `13:00Z`.
4. Every Monday shift has `split_priority=0`; every other shift `1`.
5. Load into the Technodrome (datasets `DC_US`, `DC_CA`, HTM on). Event log: zero schema warnings, zero `Location not found`.
6. Workbench Gantt: all resources visible, shift bars at the right local hours, breaks shown, Denver's Carter Hall and Shayera Hol and Toronto's John Henry Irons also visible when filtering by `MTN` / `ONT_RURAL`.
7. Pull the schedule export and run it through the Load Health Audit: zero orphans, zero zero-duration shifts.

Report: counts from 1–2, the six timestamps from 3, pass/fail on 4–7 with any event-log lines. Then wait — stage 2 comes after we've looked at it.

---

## Environment notes for Ish

- Create datasets `DC_US` and `DC_CA` in the Technodrome with HTM enabled; straight-line will load but is pointless once activities arrive.
- `organisation_id` in both specs is `2`; change if the Technodrome differs.
