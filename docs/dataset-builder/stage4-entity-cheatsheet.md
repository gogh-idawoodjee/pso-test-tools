# Stage 4 — Entity Cheat Sheet

From issue #60.

Only new rows are shown. Everything in the stage 2 and 3 cheat sheets still applies.

### Activity (splittable project)
```xml
<Activity>
  <id>A-TOR-000225</id>
  <activity_class_id>CALL</activity_class_id>
  <activity_type_id>PROJECT</activity_type_id>
  <location_id>L-TOR-00225</location_id>
  <description>PROJECT : 77 Olsen Blvd</description>
  <duration>PT13H30M</duration>
  <base_value>13500</base_value>
  <split_allowed>true</split_allowed>
  <split_minimum_priority>1</split_minimum_priority>
  <appointed>false</appointed>
  <do_on_site>false</do_on_site>
  <auto_duration_enabled>false</auto_duration_enabled>
  <remote_fulfillment>false</remote_fulfillment>
  <date_time_created>2026-10-28T15:20:00+00:00</date_time_created>
  <date_time_open>2026-10-28T15:20:00+00:00</date_time_open>
  <memo>.</memo>
</Activity>
```
`split_minimum_priority` is documented on Activity in the schema (KB `06-tasks.md`), but it wasn't present in our reference export. If PSO rejects it, report the event-log line verbatim. Don't move it to the type without asking.

### Activity_Skill (two rows)
```xml
<Activity_Skill><skill_id>COMMERCIAL</skill_id><activity_id>A-TOR-000225</activity_id><share_with_corequisites>false</share_with_corequisites></Activity_Skill>
<Activity_Skill><skill_id>SAFETY_CERT</skill_id><activity_id>A-TOR-000225</activity_id><share_with_corequisites>false</share_with_corequisites></Activity_Skill>
```

### Activity_SLA (end-based pair)
```xml
<Activity_SLA>
  <sla_type_id>PROJECT</sla_type_id>
  <activity_id>A-TOR-000225</activity_id>
  <priority>1</priority>
  <start_based>false</start_based>
  <datetime_start>2026-11-06T13:00:00+00:00</datetime_start>   <!-- Fri 08:00 EST -->
  <datetime_end>2026-11-16T22:00:00+00:00</datetime_end>       <!-- Mon 17:00 EST -->
</Activity_SLA>
<Activity_SLA>
  <sla_type_id>OUT_SLA</sla_type_id>
  <activity_id>A-TOR-000225</activity_id>
  <priority>2</priority>
  <start_based>false</start_based>
  <datetime_start>2026-11-16T22:00:00+00:00</datetime_start>
  <datetime_end>2026-11-23T22:00:00+00:00</datetime_end>
</Activity_SLA>
```
Activity_Status is unchanged from stage 2: status 0, no `duration_override`, no `resource_id`.
