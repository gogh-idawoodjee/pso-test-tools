# FIX — 1R: shifts move into the model

From issue #60, applied on top of stage 3 (250131c) as the stage 3 REVIEW sequenced it.

**Finding (Technodrome, 2026-10-30):** the model file only hands a resource to scheduling when that resource has rota content. With a rota header and a membership period alone, no resource reached scheduling. After a planner push that added a `RAM_Rota_Alteration` and `RAM_Rota_Item` rows for R-TOR-001, that resource, and only that one, appeared with its `Resource_Region`, `Resource_Skill` and home `Location`. Loaded `Shift` rows can't stand in for this, and shifts can't be split across the two files.

**Decision (Ish):** the `.arp` carries the rota (header, periods and shifts). The scheduling LOAD stops carrying `Shift` / `Shift_Break`.

## Changes

**`.arp` gains, in this order after `RAM_Shift_Template_Item_Break`:** `RAM_Rota` → `RAM_Rota_Membership_Period` → `RAM_Rota_Alteration` → `RAM_Rota_Item` → `RAM_Rota_Item_Break`.

- **`RAM_Rota`**: one row. `id` = dataset id, `ram_rota_type_id=MASTER`, `modified=false`.
- **`RAM_Rota_Membership_Period`**: one per resource, `id = RMP-{resource_id}`, from day −7 00:00 local to the end of `horizon_days` (same span as the extra-region time pattern).
- **`RAM_Rota_Alteration`**: one per resource, `id = {resource_id}` (that's the planner's own convention), same span.
- **`RAM_Rota_Item`**: one per shift that `ShiftFactory` already produces, with the same start and end instants. Build them in the region zone and write them in UTC. Never use the client's zone: the planner generates in the PSO client's time zone, which is why its own push came out in Regina time. `id` = the existing shift id. `ram_shift_category_id` and `ram_shift_template_id` = the resource type. `split_allowed=true`. **`split_priority` = 0 on local Mondays, 1 otherwise**, set per item, so the Monday rule survives (the template's single value can't do that). `description` = the template description. `modified=false`.
- **`RAM_Rota_Item_Break`**: one per rota item, `id` = item id + `1`, `ram_rota_item_id`, `duration`, `earliest_start`, `latest_start` from the type's break.

**Slim LOAD:** remove `Shift` and `Shift_Break`. It is now `Input_Reference`, `Source_Data`, `Source_Data_Parameter`, `Location`, `Location_Region`, `Activity`, `Activity_Skill`, `Activity_SLA`, `Activity_Status`, plus `Availability` once Stage 3 lands. **Stage 3's emit order loses `Shift` and `Shift_Break` too.**

**Non-modelling mode:** unchanged. `Shift` and `Shift_Break` stay in the LOAD.

**Rule 3 in the issue description changes to:** "In modelling mode, shifts live in the `.arp` as rota items. The LOAD never carries `Shift`." `RAM_Version_History` stays never-emit.

## Cheat sheet (from the planner export, values rewritten)

```xml
<RAM_Rota>
  <id>DC_CA</id>
  <ram_rota_type_id>MASTER</ram_rota_type_id>
  <modified>false</modified>
</RAM_Rota>
<RAM_Rota_Membership_Period>
  <ram_rota_id>DC_CA</ram_rota_id>
  <id>RMP-R-TOR-001</id>
  <ram_resource_id>R-TOR-001</ram_resource_id>
  <start_datetime>2026-10-23T04:00:00+00:00</start_datetime>
  <end_datetime>2027-02-08T05:00:00+00:00</end_datetime>
</RAM_Rota_Membership_Period>
<RAM_Rota_Alteration>
  <ram_rota_id>DC_CA</ram_rota_id>
  <id>R-TOR-001</id>
  <ram_resource_id>R-TOR-001</ram_resource_id>
  <start_datetime>2026-10-23T04:00:00+00:00</start_datetime>
  <end_datetime>2027-02-08T05:00:00+00:00</end_datetime>
</RAM_Rota_Alteration>
<RAM_Rota_Item>
  <ram_rota_id>DC_CA</ram_rota_id>
  <id>00120261102</id>
  <ram_resource_id>R-TOR-001</ram_resource_id>
  <start_datetime>2026-11-02T12:30:00+00:00</start_datetime>   <!-- Mon 07:30 EST, SENIOR_TECH -->
  <end_datetime>2026-11-02T21:00:00+00:00</end_datetime>
  <ram_shift_category_id>SENIOR_TECH</ram_shift_category_id>
  <ram_shift_template_id>SENIOR_TECH</ram_shift_template_id>
  <split_allowed>true</split_allowed>
  <split_priority>0</split_priority>                           <!-- Monday -->
  <description>Senior Technician Mon-Fri 07:30-16:00</description>
  <modified>false</modified>
</RAM_Rota_Item>
<RAM_Rota_Item_Break>
  <ram_rota_id>DC_CA</ram_rota_id>
  <id>001202611021</id>
  <ram_rota_item_id>00120261102</ram_rota_item_id>
  <duration>PT30M</duration>
  <earliest_start>PT3H</earliest_start>
  <latest_start>PT5H</latest_start>
</RAM_Rota_Item_Break>
```

The planner itself uses 32-character GUIDs for rota item and break ids. Ours are deterministic shift ids. If the import rejects them, report it verbatim and fall back to a deterministic 32-hex hash of the same id. Don't use random GUIDs, or byte-identical output is lost.

## Known side effect, no action

A rota shift reaches scheduling with `shift_type_id` = its category, and PSO creates that `Shift_Type` with `split_allowed=false`. The shift's own `split_allowed=true` overrides it (Shift → Shift_Pattern → Shift_Type), so splitting should still work. Stage 4 verifies it.

## Acceptance (report, stop)

1. Shift counts per resource are unchanged from stage 1 (US 2,628, CA 881), now as rota items. Every item matches its old `Shift` start and end to the second. Monday items have `split_priority 0`.
2. One membership and one alteration per resource; one break per item.
3. The slim LOAD has no `Shift` or `Shift_Break`. The cross-file test passes. Stage 2 activity rows are byte-identical.
4. **Ish, Technodrome:** send DC_CA (`.arp`, then LOAD). The merged load archive has all 12 `Resources` with skills, regions and home locations, plus shifts at the right local hours (Clark Mon 2 Nov 07:30 EST = 12:30Z). Then the stage 2 activities get allocated.
