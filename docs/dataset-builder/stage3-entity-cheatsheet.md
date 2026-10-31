# Stage 3 — Entity Cheat Sheet

From issue #60.

Same conventions as stages 1–2. Only new or changed rows are shown. Everything in the stage 2 cheat sheet still applies.

### Slim LOAD emit order (stage 3)

Input_Reference → Source_Data → Source_Data_Parameter → Location → Location_Region → Shift → Shift_Break → Activity → Activity_Skill → Activity_SLA → Availability → Activity_Status

### Activity (appointment)
```xml
<Activity>
  <id>A-TOR-000121</id>
  <activity_class_id>CALL</activity_class_id>
  <activity_type_id>INSTALL</activity_type_id>
  <location_id>L-TOR-00121</location_id>
  <description>INSTALL : 1938 Lane St</description>
  <duration>PT2H15M</duration>
  <base_value>2250</base_value>
  <split_allowed>false</split_allowed>
  <appointed>true</appointed>
  <do_on_site>false</do_on_site>
  <auto_duration_enabled>false</auto_duration_enabled>
  <remote_fulfillment>false</remote_fulfillment>
  <date_time_created>2026-10-19T14:07:00+00:00</date_time_created>
  <date_time_open>2026-10-19T14:07:00+00:00</date_time_open>
  <memo>.</memo>
</Activity>
```

### Activity_SLA (one row, the booked slot)
```xml
<Activity_SLA>
  <sla_type_id>APPOINTMENT</sla_type_id>
  <activity_id>A-TOR-000121</activity_id>
  <priority>1</priority>
  <start_based>true</start_based>
  <datetime_start>2026-11-02T13:00:00+00:00</datetime_start>   <!-- Mon 08:00 EST -->
  <datetime_end>2026-11-02T17:00:00+00:00</datetime_end>       <!-- Mon 12:00 EST -->
</Activity_SLA>
```

### Availability (accepted appointment, activity-level)
```xml
<Availability>
  <id>AP-A-TOR-000121-1</id>
  <datetime_start>2026-11-02T13:00:00+00:00</datetime_start>
  <datetime_end>2026-11-02T17:00:00+00:00</datetime_end>
  <activity_id>A-TOR-000121</activity_id>
  <start_based>true</start_based>
  <availability_type>ACCEPTED_APPOINTMENT</availability_type>
</Availability>
```
Field order and names are exactly as in the reference export. Rebooking later (stage 5) means deleting this row and sending a fresh one with a new id (`-2`) and a fresh status row. These rows do not upsert.

### Activity_Status (unallocated, with earliest)
```xml
<Activity_Status>
  <activity_id>A-TOR-000121</activity_id>
  <status_id>0</status_id>
  <visit_id>1</visit_id>
  <fixed>false</fixed>
  <date_time_status>2026-10-19T14:07:00+00:00</date_time_status>
  <date_time_stamp>2026-10-19T14:07:00+00:00</date_time_stamp>
  <date_time_earliest>2026-11-02T13:00:00+00:00</date_time_earliest>
</Activity_Status>
```
`date_time_earliest` = slot start, on appointments only. No `resource_id`, `commit_sort_value`, `date_time_fixed` or `duration_override` in this stage.
