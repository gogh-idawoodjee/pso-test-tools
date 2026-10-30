# Stage 1R2 — Activity Reference Data Cheat Sheet

RAM shapes from the DCU modelling exports (PSO 6.14), scheduling shapes from the 6.18 export; values rewritten. *(schema, unverified)* = documented but not seen populated in a sample — use, and report if rejected. Same conventions as the 1R sheet.

## The values (both seeds, identical)

### SLA types

| id | start_prop | end_prop | curve | ageing | jeopardy | mechanism | purpose |
|---|---|---|---|---|---|---|---|
| APPOINTMENT | 1 | 0.9 | 0.9 | 1 | true | POWER_BASED | booked window |
| IN_SLA | 1 | 1 | 1 | 1 | true | POWER_BASED | flat full value inside agreed window |
| OUT_SLA | 0.95 | 0.9 | 1 | 1 | false | POWER_BASED | gentle slope after the window |
| PROJECT | 1 | 0.9 | 1 | 1 | true | POWER_BASED | long splittable work, 10-day window |
| EMERGENCY | 1 | 0.8 | 1 | 1.1 | true | POWER_BASED | same-day; ageing so it never sinks |

### Activity types

| id | description | duration | base_value | split | min_split | cost_of_split | priority |
|---|---|---|---|---|---|---|---|
| REPAIR | Repair | PT1H | 1000 | false | PT1H | 0 | 1 |
| MAINT | Preventive maintenance | PT1H30M | 1000 | false | PT1H | 0 | 1 |
| INSTALL | Installation | PT2H15M | 1000 | false | PT1H | 0 | 1 |
| SURVEY | Site survey | PT45M | 1000 | false | PT1H | 0 | 1 |
| PROJECT | Project work | PT12H | 1000 | **true** | **PT2H** | **200** | 1 |
| EMERGENCY | Emergency callout | PT1H | 1000 | false | PT1H | 0 | 1 |

`interrupt=false`, `on_location_duration_factor=1`, `supply_locations=false` (RAM) / `remote_fulfillment=false` (scheduling) for all. Per-activity `base_value` scaling by duration happens in stage 2 on the activity, not here.

### Appointment templates — one per (region × kind), id `{REGION}-{KIND}`

| kind | day_pattern | items (slot start–end, local) | number_of_slots | window length |
|---|---|---|---|---|
| AMPM | YYYYYNN | PT8H–PT12H, PT12H–PT17H | 10 | PT4H |
| ALLDAY | YYYYYNN | PT8H–PT17H | 5 | PT9H |

Metros get both kinds; rurals get ALLDAY only (from `regions[].appointment_templates`). `start_based=true`, `offer_expiry_window=PT3M`, `blocking=false`, `default_template=false`, `exclude_to_template_start=false`. No slot usage rule set.

---

## RAM entities (`.arp`)

### RAM_SLA_Type
```xml
<RAM_SLA_Type>
  <id>IN_SLA</id>
  <description>Inside agreed window</description>              <!-- (schema, unverified) -->
  <start_proportion>1</start_proportion>
  <end_proportion>1</end_proportion>
  <curve_shape>1</curve_shape>
  <activity_ageing_factor>1</activity_ageing_factor>
  <generate_jeopardy_exceptions>true</generate_jeopardy_exceptions>
  <ram_sla_mechanism_type_id>POWER_BASED</ram_sla_mechanism_type_id>   <!-- note: ram_…_id, not mechanism_type -->
</RAM_SLA_Type>
```
`revenue`/`penalty`/`cost`/`early_sla_benefit` were not in the RAM sample — leave them out on the RAM side (schema defaults apply).

### RAM_Activity_Type
```xml
<RAM_Activity_Type>
  <id>PROJECT</id>
  <description>Project work</description>                      <!-- (schema, unverified) -->
  <duration>PT12H</duration>
  <base_value>1000</base_value>
  <supply_locations>false</supply_locations>
  <split_allowed>true</split_allowed>
  <interrupt>false</interrupt>
  <minimum_split>PT2H</minimum_split>                          <!-- RAM says minimum_split; scheduling says min_split -->
  <cost_of_split>200</cost_of_split>
  <priority>1</priority>
  <on_location_duration_factor>1</on_location_duration_factor>
</RAM_Activity_Type>
```

### RAM_Appointment_Template / _Item
```xml
<RAM_Appointment_Template>
  <id>CHI-AMPM</id>
  <description>Chicago AM/PM weekdays</description>            <!-- (schema, unverified) -->
  <day_pattern>YYYYYNN</day_pattern>
  <number_of_slots>10</number_of_slots>
  <exclude_to_template_start>false</exclude_to_template_start>
  <start_based>true</start_based>
  <offer_expiry_window>PT3M</offer_expiry_window>
  <default_template>false</default_template>
  <blocking>false</blocking>
  <time_zone>America/Chicago</time_zone>                       <!-- (schema, unverified) — drop if rejected, see brief §3.4 -->
</RAM_Appointment_Template>
<RAM_Appointment_Template_Item>
  <ram_appointment_template_id>CHI-AMPM</ram_appointment_template_id>
  <slot_start_time>PT8H</slot_start_time>
  <slot_end_time>PT12H</slot_end_time>
</RAM_Appointment_Template_Item>
```
Not in the RAM sample and therefore not emitted: `appointment_window_length`, `template_start_day_of_week`, `template_start_time_of_day`, `slot_usage_rule_set_id`, per-item `slot_usage_window_*`.

---

## Scheduling entities — only when `modelling: false`

### SLA_Type
```xml
<SLA_Type>
  <id>IN_SLA</id>
  <description>Inside agreed window</description>
  <revenue>1000</revenue>
  <penalty>1000</penalty>
  <cost>0</cost>
  <early_sla_benefit>0</early_sla_benefit>
  <mechanism_type>POWER_BASED</mechanism_type>
  <start_proportion>1</start_proportion>
  <end_proportion>1</end_proportion>
  <curve_shape>1</curve_shape>
  <activity_ageing_factor>1</activity_ageing_factor>
  <generate_jeopardy_exceptions>true</generate_jeopardy_exceptions>
</SLA_Type>
```

### Activity_Type
```xml
<Activity_Type>
  <id>PROJECT</id>
  <description>Project work</description>
  <duration>PT12H</duration>
  <base_value>1000</base_value>
  <split_allowed>true</split_allowed>
  <min_split>PT2H</min_split>
  <interrupt>false</interrupt>
  <cost_of_split>200</cost_of_split>
  <priority>1</priority>
  <on_location_duration_factor>1</on_location_duration_factor>
  <remote_fulfillment>false</remote_fulfillment>
  <ignore_days_old>1000</ignore_days_old>
</Activity_Type>
```

### Appointment_Template / _Item
```xml
<Appointment_Template>
  <id>CHI-AMPM</id>
  <description>Chicago AM/PM weekdays</description>
  <day_pattern>YYYYYNN</day_pattern>
  <number_of_slots>10</number_of_slots>
  <appointment_window_length>PT4H</appointment_window_length>
  <template_start_day_of_week>1</template_start_day_of_week>
  <template_start_time_of_day>PT8H</template_start_time_of_day>
  <exclude_to_template_start>false</exclude_to_template_start>
  <time_zone>America/Chicago</time_zone>
  <start_based>true</start_based>
  <offer_expiry_window>PT3M</offer_expiry_window>
  <default_template>false</default_template>
  <blocking>false</blocking>
</Appointment_Template>
<Appointment_Template_Item>
  <appointment_template_id>CHI-AMPM</appointment_template_id>
  <slot_start_time>PT8H</slot_start_time>
  <slot_end_time>PT12H</slot_end_time>
  <slot_usage_window_start>PT8H</slot_usage_window_start>
  <slot_usage_window_end>PT17H</slot_usage_window_end>
</Appointment_Template_Item>
```
