# DC Demo Dataset — Stage 3: Appointments (booked windows)

From issue #60. Builds on stage 2 as merged (afbb4bb) and the stage 2 REVIEW.

### What we're building

Customers have booked INSTALL and SURVEY visits into the region's appointment templates (AM 08–12, PM 12–17, or all day), from today through the next two working weeks. Each one arrives in the LOAD already accepted: PSO sees a promise it must keep, and the DSE fits it inside the slot alongside the stage 2 SLA work. The Gantt gets fuller, and the booking tool and first-available-date tool now have real competing load to probe against.

Also in this stage: the explicit `primary_sla_type` mapping from the stage 2 review, and a share of work for regions that are served only through `extra_regions` (MTN), so Carter Hall and Shayera Hol's mountain availability is exercised.

### 1. Spec changes

**Building:**

- `activity_types.{id}` gains `work_mode` (`sla` | `appointment`) and `primary_sla_type`. Both seeds:

  | type | work_mode | primary_sla_type |
  |---|---|---|
  | REPAIR | sla | IN_SLA |
  | MAINT | sla | IN_SLA |
  | EMERGENCY | sla | EMERGENCY |
  | INSTALL | appointment | APPOINTMENT |
  | SURVEY | appointment | APPOINTMENT |
  | PROJECT | sla | PROJECT |

  Stage 2 switches to reading `primary_sla_type` instead of the "type id, else IN_SLA" rule. The output must stay byte-identical.

- New top-level `stage3` block (moves into `backlog` in stage 6):

  ```json
  "stage3": {
    "appointments_per_resource_per_day": 1,
    "working_days": 10,
    "mix": { "INSTALL": 0.7, "SURVEY": 0.3 },
    "durations": {
      "INSTALL": { "min": "PT1H30M", "max": "PT3H" },
      "SURVEY":  { "min": "PT30M",   "max": "PT1H" }
    },
    "skills": {
      "INSTALL": { "pool": ["FIBRE", "ELEC"], "p": 1.0 },
      "SURVEY":  { "pool": [], "p": 0.0 }
    },
    "kind_weights": { "AMPM": 0.6, "ALLDAY": 0.4 },
    "booked_ago": { "min": "P1D", "max": "P21D" }
  }
  ```

- New key `extra_region_weight` (default `0.5`) in both seeds. See §4.

### 2. Target days and slots

**Building:** per region, in the region's zone. Working days are today plus the next `working_days − 1` weekdays. Today counts only for slots that start at least one hour after `now`. Appointments per region per day = `round(appointments_per_resource_per_day × serving resources)` (§4), split by `mix` with largest-remainder rounding.

For each appointment, pick a template kind from the region's `appointment_templates` using `kind_weights`. A rural region has only ALLDAY, so it always gets that. Then pick an item uniformly among those still valid on that day. That gives the slot as a local start and end, built in the region zone and output in UTC. Slots respect the template's `day_pattern` (weekdays only).

### 3. Appointment activities

**Building:** extend `ActivityFactory`. Ids carry on the region's stage 2 sequence (`A-{REGION}-{nnnnnn}`). The draws come **after** all stage 2 draws, so stage 2 output can't move.

- **Location, Location_Region:** same as stage 2.
- **Activity:** as stage 2, except `appointed=true`, `base_value = 1000 × duration hours`, `description = "{TYPE} : {address_line1}"`, and `date_time_created` = `date_time_open` = slot start − U(`booked_ago`), never after `now`.
- **Activity_Skill:** by `stage3.skills`, with the same "held by ≥2 serving resources" rule.
- **Activity_SLA:** **one** row: `primary_sla_type` (`APPOINTMENT`), priority 1, `start_based=true`, the slot start and end. No secondary row. A booked window is the promise.
- **Availability:** one row with `id = AP-{activity_id}-1`, `activity_id`, `start_based=true`, `availability_type=ACCEPTED_APPOINTMENT`, and the same start/end as the SLA. This is activity-level scheduling data, so it goes in the LOAD. It isn't the resource-region availability that moved to the ARP in 1R.
- **Activity_Status:** status 0, `visit_id=1`, `fixed=false`, `date_time_status` = `date_time_stamp` = `date_time_created`, **plus `date_time_earliest` = slot start**.

The three rows (SLA, Availability, `date_time_earliest`) must agree to the second. All three are required: this is the wire pattern PSO itself produces for an accepted appointment.

### 4. Regions served only through `extra_regions`

**Building:** a region's *serving resources* are its home resources plus `extra_region_weight` × each resource with that region in `extra_regions`. MTN = 0 + 0.5 × 2 = 1.0, so MTN gets `round(2 × 1.0)` = 2 SLA jobs a day (stage 2 knobs) and `round(1 × 1.0)` = 1 ALLDAY appointment a day. The ≥2-holders skill rule counts those `extra_regions` members as holders. Every other region's counts are unchanged, since none has incoming `extra_regions` except ONT_RURAL, which gains 0.5 from John Henry Irons.

**This changes stage 2 output for MTN and ONT_RURAL only.** That's expected. State the new stage 2 totals in the report.

### 5. Writers

**Building:** slim LOAD emit order becomes: `Input_Reference`, `Source_Data`, `Source_Data_Parameter`, `Location`, `Location_Region`, `Shift`, `Shift_Break`, `Activity`, `Activity_Skill`, `Activity_SLA`, `Availability`, `Activity_Status`. In the slim LOAD, `Availability` only ever carries `activity_id` rows. A test asserts there is no resource-type `Availability` row in modelling mode. Non-modelling mode gets the same activity rows on top of what it has. The ARP is unchanged.

Summary panel: appointments by region × type × day × template kind.

**Correction to 1R2 acceptance 1:** US has 4 metros (CHI, LAX, DFW, DEN) and 2 rurals (MTN, IOWA), so 4 × 2 + 2 × 1 = **10 templates / 14 items**, not 12 / 17. If the 1R2 build produced 10 / 14, it was right and the brief was wrong.

### 6. Acceptance (do these, report, stop)

1. Both generate. Print appointments per region × type × day × kind, plus the new stage 2 totals for MTN and ONT_RURAL. Every other stage 2 activity is byte-identical to afbb4bb's output for the same `now`.
2. Every appointment has one SLA row, one `ACCEPTED_APPOINTMENT` Availability and `date_time_earliest`, all agreeing to the second; its slot matches a template item of its region in local time on a pattern day; its Availability id is ≤32 chars; it starts ≥1 h after `now`.
3. Spot checks printed: one LAX PM slot (local and UTC); one TOR AM slot on Mon 2 Nov (after DST, so 13:00Z); one MTN ALLDAY with its skill and holders.
4. ARP is byte-identical to stage 2's.
5. **Ish, Technodrome:** DC_CA, ARP then LOAD. Event log clean. Appointments are allocated inside their slots (none at reason 320). The booking tool returns offers from TOR-AMPM / TOR-ALLDAY. Note how the offers look with this load on the schedule.
6. **Ish:** DC_US. LAX appointments in LA hours. MTN jobs go to Carter Hall or Shayera Hol only.

Report: counts, pass/fail per line, any event-log lines verbatim. Then stop.
