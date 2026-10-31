# DC Demo Dataset — Stage 4: Splittable PROJECT work

From issue #60. Builds on the 1R rota FIX (e0ba87a).

### What we're building

Each metro gets a couple of PROJECT jobs a week: 10 to 16 hours of commercial work, too long for any one shift. The DSE has to split them across days. Each split visit is at least 2 hours, and each split costs 200, so it doesn't split for fun. Several start late in the week on purpose, which makes the Monday rule visible: a Thursday or Friday project that runs over must skip Monday and continue on Tuesday. It's also the first stage with two-skill jobs (COMMERCIAL + SAFETY_CERT).

Three things this stage puts in front of PSO:

1. **The Monday rule.** Rota item `split_priority` is 0 on Mondays and 1 otherwise, and these activities carry `split_minimum_priority = 1`. A shift's `split_priority` must be ≥ the activity's minimum, so Monday can't be a continuation day.
2. **Rota-derived shift types.** Rota shifts arrive with a `Shift_Type` that PSO generated from the category with `split_allowed=false`. The shift's own `split_allowed=true` should win; this stage proves it.
3. **Utilisation caps against multi-day splits.** SENIOR_TECH runs at 90% utilisation at resource level. KB `claude/31` says a multi-day split can't be placed on a resource with any utilisation cap below 100%. We expect projects to land on 100% resources (INSTALLERs, mostly). We are **not** changing SENIOR_TECH to dodge it: this is a known PSO behaviour, and it should show up in the demo.

### 1. Spec additions

**Building:** a new top-level `stage4` block (it moves into `backlog` in stage 6):

```json
"stage4": {
  "projects_per_region_per_week": { "metro": 2, "rural": 1 },
  "weeks": 2,
  "duration": { "min": "PT10H", "max": "PT16H" },
  "skills": {
    "required": ["COMMERCIAL"],
    "optional": { "SAFETY_CERT": 0.5 }
  },
  "start_day_weights": { "1": 1, "2": 1, "3": 1, "4": 2, "5": 2 },
  "split_minimum_priority": 1,
  "sla": { "in": "P10D", "out": "P7D", "start_based": false }
}
```

`start_day_weights` is keyed by ISO weekday, so Thursday (4) and Friday (5) are twice as likely.

### 2. Which regions get projects

**Building:** a region gets projects only if **at least 2 serving resources** hold the job's full skill set. Serving resources are home resources plus `extra_regions` members at `extra_region_weight`, with each counted as a holder. Draw the optional SAFETY_CERT first. If adding it would leave fewer than 2 holders, drop it for that job. If even COMMERCIAL alone has fewer than 2 holders, the region gets no projects. On the current seeds that means:

- **MTN:** no projects. Only Carter Hall holds COMMERCIAL.
- **DFW and IOWA:** COMMERCIAL only. Each has one SAFETY_CERT holder.
- Everywhere else, both skills are possible.

Print the result per region.

### 3. Project activities

**Building:** extend `ActivityFactory`. Draws happen **after** all stage 3 draws, and ids continue the region's sequence.

- **Target start day:** among the region's next `weeks × 5` working days, excluding today, weighted by `start_day_weights`.
- **Location, Location_Region:** as stage 2. Projects are commercial premises, so if the spec gives a region a `commercial_centroid` use it; otherwise use the normal draw.
- **Activity:** `activity_type_id=PROJECT`; `duration` uniform in range, rounded to 15 min; `base_value = 1000 × hours`; **`split_allowed=true`**; **`split_minimum_priority=1`**; `appointed=false`; the rest as stage 2. `min_split` and `cost_of_split` come from the type (PT2H / 200), so don't repeat them on the activity.
- **Activity_Skill:** COMMERCIAL, plus SAFETY_CERT per §2.
- **Activity_SLA:** two rows, **both `start_based=false`** (end-based). KB `06`: for split work, "end" means the end of the final visit, and end-based is the better fit.
  - priority 1, `PROJECT`: from 08:00 local on the start day to `in` later, snapped to 17:00 local.
  - priority 2, `OUT_SLA`: from there for `out`.

  Both rows are in the same end-based model, so they must not overlap.
- **Activity_Status:** status 0, as stage 2. No `duration_override`; the in-flight split is stage 5.

### 4. Writers

**Building:** no new entities. The slim LOAD gains project rows in the existing `Activity*` / `Location*` entities. The ARP is unchanged. `split_minimum_priority` is a new Activity field (see Part 2). Summary panel: projects per region × start weekday × skill set, with min/max duration.

### 5. Acceptance (do these, report, stop)

1. Both generate. Print projects per region, start weekday and skill set. Expected on the current seeds: US 4 metros × 2 × 2 + IOWA 1 × 2 = 18 (MTN 0); CA TOR 4 + ONT_RURAL 2 = 6. Every stage 1–3 row stays byte-identical.
2. Every project has `split_allowed=true` and `split_minimum_priority=1`, a skill set held by ≥2 serving resources, a duration between 10 h and 16 h, and two end-based SLA rows that don't overlap.
3. Spot check printed: one Friday-start TOR project with its SLA in local time and UTC, and the names of its skill holders and their resource types.
4. ARP byte-identical to the rota FIX's.
5. **Ish, Technodrome, DC_CA (ARP then LOAD).** In the plan or export:
   - every project allocated and split into ≥2 visits, each ≥2 h
   - **no continuation visit (visit 2+) on a Monday**
   - splits happen even though the rota-derived `Shift_Type` says `split_allowed=false` (if no project splits at all, that's the first suspect)
   - multi-day projects not on SENIOR_TECH resources; note any that are, because that would contradict KB `claude/31` §3.
6. **Ish, DC_US:** the same, plus DFW and IOWA projects carry COMMERCIAL only.

Report: counts, pass/fail, any event-log lines verbatim. Then stop.
