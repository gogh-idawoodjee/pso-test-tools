# DC Demo Dataset — Stage 2: SLA work (unallocated)

From issue #60.

**For:** Claudia (pso-test-tools)
**Scope:** the first activities. REPAIR, MAINT and EMERGENCY jobs only: SLA windows, one skill each, all at status 0 (unallocated), spread over the next five working days in each region. The DSE does the scheduling. No appointments (stage 3), no PROJECT/splits (stage 4), no in-flight statuses (stage 5), no backlog curve (stage 6).
**Companion:** `stage2-entity-cheatsheet.md` (field names; same rule as always).
**Builds on:** 1R v2 + 1R2 as accepted. The ARP is unchanged in this stage.

---

## What we're building

After this stage, Generate puts a few hundred real-looking service calls into the LOAD: a repair at a synthetic address in Scarborough needing HVAC, due within three days; a maintenance visit in Huntsville with ten days to get to it; a same-day emergency in Etobicoke. Load it, and the Gantt fills with allocated work: travel between jobs, breaks placed between them, and a short unallocated list for anything the DSE couldn't fit. It's the first stage where the schedule should look like a schedule.

The test is whether PSO accepts activities whose region, skills and types exist only in RAM, and whether the DSE places them sensibly in each region's own time zone.

---

## 1. Spec additions

**Building:** a top-level `stage2` block in both seeds (moves into `backlog` in stage 6):

```json
"stage2": {
  "jobs_per_resource_per_day": 2,
  "working_days": 5,
  "mix": { "REPAIR": 0.55, "MAINT": 0.30, "EMERGENCY": 0.15 },
  "durations": {
    "REPAIR":    { "min": "PT45M", "max": "PT1H30M" },
    "MAINT":     { "min": "PT1H",  "max": "PT2H" },
    "EMERGENCY": { "min": "PT45M", "max": "PT1H15M" }
  },
  "sla_windows": {
    "REPAIR":    { "in": "P3D",  "out": "P7D" },
    "MAINT":     { "in": "P10D", "out": "P7D" },
    "EMERGENCY": { "in": "same_day", "out": "P1D" }
  },
  "skills": {
    "REPAIR":    { "pool": ["ELEC", "NET", "HVAC", "FIBRE"], "p": 1.0 },
    "MAINT":     { "pool": ["ELEC", "HVAC"], "p": 0.6 },
    "EMERGENCY": { "pool": ["ELEC", "NET", "HVAC"], "p": 1.0 }
  }
}
```

With the defaults that's about 120 activities for CA and 360 for US. That's a light day on purpose. Raise `jobs_per_resource_per_day` to see it fill up.

---

## 2. Target days

**Building:** per region, in the region's own zone. Working days are the next `working_days` weekdays (Mon–Fri) whose FIELD_TECH shift end (16:30 local) is still after `now`. A 17:00 Toronto run on a Friday starts Toronto at Monday but still gives LA Friday afternoon. EMERGENCY jobs only land on the first working day. Jobs per region per day = `round(jobs_per_resource_per_day × resources in region)`. Count a resource whose home region it is, not `extra_regions` members. Split by `mix` with largest-remainder rounding so the totals are exact.

---

## 3. Activities

**Building:** `ActivityFactory`, deterministic from the seed like everything else.

- **Location.** One new `Location` per activity, sampled exactly like homes (weighted centroid, σ = spread/2, rural segment + jitter, `exclude_polygons` respected), id `L-{REGION}-{nnnnn}`. Synthetic address, same street-name list. Plus one `Location_Region` row → the activity's region.
- **Activity.** id `A-{REGION}-{nnnnnn}`, sequence per region. `activity_class_id=CALL`, `activity_type_id`, `location_id`. `duration` uniform in the type's range, rounded to 15 min. `base_value = 1000 × duration hours` (EMERGENCY × 4), rounded to an integer. `split_allowed=false`, `appointed=false`, `do_on_site=false`, `auto_duration_enabled=false`, `remote_fulfillment=false`. `description = "{TYPE} : {address_line1}"`. `date_time_created` = `date_time_open` = `now − U(0, 72h)`, but never before day −1 00:00 local. `memo="."`.
- **Activity_Skill.** With probability `p`, one skill drawn from the type's pool, **restricted to skills held by at least two resources whose home region is this one** (so no job is impossible by construction). `share_with_corequisites=false`.
- **Activity_SLA.** Two rows, both `start_based=true`:
  - priority 1, `IN_SLA` (EMERGENCY: `EMERGENCY`): start = max(`now`, 08:00 local on the target day); end = start + `in`, snapped to 17:00 local on that day. `same_day` means 17:00 local on the target day itself.
  - priority 2, `OUT_SLA`: from the priority-1 end, for `out`.
  The two windows touch and never overlap.
- **Activity_Status.** One row: `status_id=0`, `visit_id=1`, `fixed=false`, `date_time_status` = `date_time_stamp` = `date_time_created`. No `resource_id`, no `date_time_earliest` (that's an appointment field, stage 3).

All datetimes are built in the region zone and output in UTC, same as shifts.

---

## 4. Writers

**Building:** the scheduling writer in modelling mode now emits, in this order: `Input_Reference`, `Source_Data`, `Source_Data_Parameter`, `Location`, `Location_Region`, `Shift`, `Shift_Break`, `Activity`, `Activity_Skill`, `Activity_SLA`, `Activity_Status`. Activity locations are scheduling data. They are not in the ARP, and resource home/depot locations stay ARP-only. The "no entity type in both files" test still holds (`Location` ≠ `RAM_Location`). Non-modelling mode gets the same activity entities on top of its stage 1 + 1R2 content.

The summary panel adds: activities by region × type × target day (local date), and the IN_SLA window range per type.

---

## 5. Acceptance (do these, report, stop)

1. Both datasets generate. Print activities per region × type × day; totals match §2 exactly (CA 120 ± rounding, US 360 ± rounding).
2. Every activity has exactly one `Location`, one `Location_Region`, one status row, two SLA rows that don't overlap, at most one skill, and that skill is held by ≥2 home-region resources. No activity location falls inside an `exclude_polygon`.
3. Spot check printed: one EMERGENCY per region with its IN_SLA in local time and UTC; one MAINT whose 10-day window crosses the Nov 1 DST change, showing the 17:00 local end is right on both sides.
4. ARP byte-identical to 1R2 apart from `RAM_Update.id` / description.
5. **Ish, Technodrome:** send DC_CA (ARP, then LOAD). Expect a clean event log: watch for anything about unknown regions or skills on `Location_Region` / `Activity_Skill`, since those reference RAM-only rows for the first time. The Gantt shows allocated work with travel and breaks. The unallocated list is short, and each entry has a reason. Pull the schedule export and run it through the Load Health Audit.
6. Then DC_US, and check LA activities sit inside LA working hours, not Toronto's.

Report: counts, pass/fail per line, any event-log messages verbatim, and the unallocated count + reason codes from the export. Then stop.
