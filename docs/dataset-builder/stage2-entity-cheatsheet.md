# Stage 2 — Entity Cheat Sheet

From issue #60.

From the PSO 6.18 export; values rewritten. Same conventions as stage 1: UTC `+00:00`, ISO durations, lowercase booleans, ids ≤ 32 chars, omit rather than send empty.

## LOAD emit order in modelling mode (stage 2)

Input_Reference → Source_Data → Source_Data_Parameter → Location → Location_Region → Activity → Activity_Skill → Activity_SLA → Activity_Status

**1R FIX on #60 (`stage1r-fix-rota.md`):** in modelling mode the shifts are `.arp` rota items; the LOAD never carries `Shift` or `Shift_Break`.

## Still NEVER emit

`Object_Group` (FIX on #60: not needed for this dataset, in either mode). Anything the ARP carries (Region, Resources, Resource_*, Skill, Resource_Type, Rule*, Activity_Type, SLA_Type, Appointment_Template*), any PSO output entity (Plan*, Allocation*, Schedule_Exception*, Schedule_Event), and in this stage `Availability`, `Additional_Attribute`, `Activity_Custom_URL`.

---

## Location (activity locations only)
```xml
<Location>
  <id>L-TOR-00042</id>
  <name>3381 Olsen Ave, Scarborough</name>
  <address_line1>3381 Olsen Ave</address_line1>
  <city>Scarborough</city>
  <state>ON</state>
  <post_code_zip>M5G 2K7</post_code_zip>
  <latitude>43.77412</latitude>
  <longitude>-79.25530</longitude>
</Location>
```

## Location_Region
```xml
<Location_Region><region_id>TOR</region_id><location_id>L-TOR-00042</location_id></Location_Region>
```
`region_id` is a RAM division with `send=true`. This is the first stage where a LOAD row references it, so check the event log.

## Activity
```xml
<Activity>
  <id>A-TOR-000042</id>
  <activity_class_id>CALL</activity_class_id>
  <activity_type_id>REPAIR</activity_type_id>
  <location_id>L-TOR-00042</location_id>
  <description>REPAIR : 3381 Olsen Ave</description>
  <duration>PT1H15M</duration>
  <base_value>1250</base_value>
  <split_allowed>false</split_allowed>
  <appointed>false</appointed>
  <do_on_site>false</do_on_site>
  <auto_duration_enabled>false</auto_duration_enabled>
  <remote_fulfillment>false</remote_fulfillment>
  <date_time_created>2026-10-29T18:12:00+00:00</date_time_created>
  <date_time_open>2026-10-29T18:12:00+00:00</date_time_open>
  <memo>.</memo>
</Activity>
```
There's no `priority` on Activity; priority lives on the SLA rows. Don't send `min_split` or `cost_of_split` here, since the type carries them.

## Activity_Skill
```xml
<Activity_Skill><skill_id>HVAC</skill_id><activity_id>A-TOR-000042</activity_id><share_with_corequisites>false</share_with_corequisites></Activity_Skill>
```

## Activity_SLA (primary + secondary, both start-based)
```xml
<Activity_SLA>
  <sla_type_id>IN_SLA</sla_type_id>
  <activity_id>A-TOR-000042</activity_id>
  <priority>1</priority>
  <start_based>true</start_based>
  <datetime_start>2026-11-02T13:00:00+00:00</datetime_start>   <!-- Mon 08:00 Toronto (EST) -->
  <datetime_end>2026-11-05T22:00:00+00:00</datetime_end>       <!-- Thu 17:00 Toronto -->
</Activity_SLA>
<Activity_SLA>
  <sla_type_id>OUT_SLA</sla_type_id>
  <activity_id>A-TOR-000042</activity_id>
  <priority>2</priority>
  <start_based>true</start_based>
  <datetime_start>2026-11-05T22:00:00+00:00</datetime_start>
  <datetime_end>2026-11-12T22:00:00+00:00</datetime_end>
</Activity_SLA>
```
Rows in one start-based model must not overlap; touching is fine. Every CALL needs at least one Activity_SLA or it won't schedule.

## Activity_Status (unallocated)
```xml
<Activity_Status>
  <activity_id>A-TOR-000042</activity_id>
  <status_id>0</status_id>
  <visit_id>1</visit_id>
  <fixed>false</fixed>
  <date_time_status>2026-10-29T18:12:00+00:00</date_time_status>
  <date_time_stamp>2026-10-29T18:12:00+00:00</date_time_stamp>
</Activity_Status>
```
No `resource_id`, `commit_sort_value`, `date_time_earliest`, `date_time_fixed` or `duration_override` in this stage.
