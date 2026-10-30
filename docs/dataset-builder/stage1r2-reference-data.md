# DC Demo Dataset — Stage 1R2: Activity Reference Data

**For:** Claudia (pso-test-tools)
**Scope:** add the reference data that activities will hang off — activity types, SLA types, appointment templates — to the modelling file (`.arp`). No activities yet, no slot usage rules. The scheduling LOAD does not change in modelling mode.
**Companion:** `stage1r2-ram-reference-cheatsheet.md` (field names; same rule as always — nothing on the wire that isn't in a cheat sheet, ask if missing). Builds on 1R v2 as accepted.

---

## Why

Stage 2 will emit `Activity` rows that reference an `activity_type_id`, `Activity_SLA` rows that reference an `sla_type_id`, and (stage 3) booked appointments that came from an `Appointment_Template`. All three are model data, so under the 1R rule they live in RAM and the planner/Workbench can edit them live — e.g. change a type's `cost_of_split` mid-demo. Load them now, on their own, so the first activity stage has only activities to debug.

Nothing in this stage shows on the Gantt. Acceptance is that both datasets load clean and the Workbench shows the new reference data.

---

## 1. Spec additions

**Building:** populate `_later_stages.activity_types`, `_later_stages.sla_types` and a new `_later_stages.appointment_templates` in both seeds, and promote them to top-level keys `activity_types`, `sla_types`, `appointment_templates` (drop the `_later_stages` wrapper for these three; leave `backlog` and `defects` where they are). Values are in the cheat sheet tables; identical in both seeds. Region entries already carry `appointment_templates: ["AMPM","ALLDAY"]` / `["ALLDAY"]` — that list now means "instantiate these template kinds for this region".

---

## 2. Modelling writer additions

**Building:** extend `DsModellingWriter`. New emit order tail: … `RAM_Shift_Template_Item_Break` → `RAM_SLA_Type` → `RAM_Activity_Type` → `RAM_Appointment_Template` → `RAM_Appointment_Template_Item`.

- **`RAM_SLA_Type`** — five rows (APPOINTMENT, IN_SLA, OUT_SLA, PROJECT, EMERGENCY), curve parameters from the cheat sheet.
- **`RAM_Activity_Type`** — six rows (REPAIR, MAINT, INSTALL, SURVEY, PROJECT, EMERGENCY). `duration` = the type's mean duration; PROJECT is the only splittable one.
- **`RAM_Appointment_Template`** + **`_Item`** — one template per (region × kind) named `{REGION}-{KIND}`: `AMPM` has two items (08–12, 12–17) on `YYYYYNN`, `ALLDAY` has one item (08–17) on `YYYYYNN`. `number_of_slots` = working days × items (10 and 5). No `slot_usage_rule_set_id`. `time_zone` = the region's zone — this field is *(schema, unverified)* on the RAM side; see acceptance 4.

**Scheduling writer:** in modelling mode, nothing changes. In non-modelling mode (`modelling: false`) emit the scheduling-side equivalents from the cheat sheet's last section (`SLA_Type`, `Activity_Type`, `Appointment_Template`, `Appointment_Template_Item`) so the LOAD-only path stays complete. The "no entity in both files" test still applies.

---

## 3. Acceptance (do these, report, stop)

1. Both datasets generate; `.arp` gains 5 SLA types, 6 activity types, and US: 5 metros × 2 + 2 rurals × 1 = 12 templates / 17 items; CA: 1 × 2 + 1 × 1 = 3 templates / 4 items. Print the counts.
2. Slim LOAD byte-identical to 1R v2 apart from `Input_Reference.datetime`/`id`. Non-modelling LOAD contains the four scheduling-side entities and the cross-file test passes.
3. **Ish, Technodrome:** send DC_CA (ARP then LOAD). Event log clean. Workbench → Scheduling Basic Data: 6 activity types with PROJECT showing `split_allowed=true`, `minimum_split PT2H`, `cost_of_split 200`; 5 SLA types with the right curves; appointment templates TOR-AMPM, TOR-ALLDAY, ONT_RURAL-ALLDAY with their items. The booking tool in pso-test-tools lists those three templates for `DC_CA`.
4. If `RAM_Appointment_Template.time_zone` (or `description`) is rejected on import, report it and drop the field; the template then follows the dataset/division zone, and we test what that means for DC_US (three zones) in stage 3 before relying on it.

Report: counts, pass/fail, any rejected field names verbatim. Then stop — stage 2 (first activities) follows.
