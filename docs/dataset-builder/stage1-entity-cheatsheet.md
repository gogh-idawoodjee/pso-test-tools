# dsScheduleData LOAD — Stage 1 Entity Cheat Sheet

Derived from a real PSO 6.18 export; every value below is rewritten. **This is the source of truth for field names in stage 1.** Nothing outside this file goes on the wire without asking.

Conventions: root `<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">`; one element per row, one child element per field; datetimes UTC `2026-11-02T14:00:00+00:00`; durations ISO-8601 `PT1H30M` / `P7D`; booleans `true`/`false`; ids are strings ≤ 32 chars. Omit a field rather than send it empty.

## Emit order

Input_Reference → Region → Location → Skill → Resource_Type → Rule → Rule_Collection → Rule_Collection_Rule → Rule_Parameter → Resources → Resource_Region → Resource_Skill → Availability → Resource_Region_Availability → Shift → Shift_Break → Object_Group

## NEVER emit

`Plan`, `Plan_Resource`, `Plan_Route`, `Allocation`, `Allocation_Data`, `Schedule_Exception`, `Schedule_Exception_Data`, `Schedule_Event` (PSO output). Not in stage 1: anything Activity-related, `Appointment_*`, `Slot_Usage_*`, `SLA_Type`, `Activity_Type`, `Shift_Type`, `Broadcast*`, `Custom_URL`, `Additional_Attribute`, `Source_Data*`.

---

## Input_Reference (exactly 1)
```xml
<Input_Reference>
  <datetime>2026-10-30T15:30:00+00:00</datetime>
  <id>1793374200</id>
  <input_type>LOAD</input_type>
  <organisation_id>2</organisation_id>
  <dataset_id>DC_US</dataset_id>
  <schedule_data>CONTINUOUS</schedule_data>
  <load_status>0</load_status>
  <duration>P7D</duration>
  <appointment_window_duration>P90D</appointment_window_duration>
  <process_type>APPOINTMENT</process_type>
</Input_Reference>
```
Don't send `schedule_end_datetime`, `input_datetime`, `enacting_app_*`, `row_count`, `object_deletion_count`.

## Region
```xml
<Region><id>CHI</id><description>Chicago Metro</description></Region>
```

## Location
```xml
<Location>
  <id>H-R-CHI-001</id>
  <name>1138 Wayne Blvd, Chicago</name>
  <post_code_zip>60614</post_code_zip>
  <address_line1>1138 Wayne Blvd</address_line1>
  <city>Chicago</city>
  <state>IL</state>
  <latitude>41.92311</latitude>
  <longitude>-87.65402</longitude>
</Location>
```
Five decimal places on lat/long is plenty.

## Skill
```xml
<Skill><id>FIBRE</id><description>Fibre splicing</description></Skill>
```

## Resource_Type
```xml
<Resource_Type>
  <id>FIELD_TECH</id>
  <description>Field Technician</description>
  <travel_to>PT1H</travel_to>
  <travel_from>PT1H</travel_from>
  <max_travel>PT3H</max_travel>
  <cost_ph>45</cost_ph>
  <cost_phot>68</cost_phot>
  <cost_km>0.6</cost_km>
  <speedfactor>1</speedfactor>
  <utilisation>100</utilisation>
  <out_of_region_multiplier>0</out_of_region_multiplier>
  <resource_class_id>STANDARD</resource_class_id>
</Resource_Type>
```
No `location_id_start`/`location_id_end` on the type.

## Rule set (COMMIT) — emit once, verbatim
```xml
<Rule><rule_type_id>COMMIT</rule_type_id><id>STD</id><description>Standard commit</description><global>false</global></Rule>
<Rule_Collection><id>STD</id><description>Standard</description></Rule_Collection>
<Rule_Collection_Rule><rule_collection_id>STD</rule_collection_id><rule_type_id>COMMIT</rule_type_id><rule_id>STD</rule_id></Rule_Collection_Rule>
<Rule_Parameter><rule_type_id>COMMIT</rule_type_id><rule_id>STD</rule_id><parameter_id>MaximumCommittedActivities</parameter_id><parameter_value>2</parameter_value></Rule_Parameter>
<Rule_Parameter><rule_type_id>COMMIT</rule_type_id><rule_id>STD</rule_id><parameter_id>CommitBreaks</parameter_id><parameter_value>false</parameter_value></Rule_Parameter>
<Rule_Parameter><rule_type_id>COMMIT</rule_type_id><rule_id>STD</rule_id><parameter_id>CountBreaks</parameter_id><parameter_value>false</parameter_value></Rule_Parameter>
<Rule_Parameter><rule_type_id>COMMIT</rule_type_id><rule_id>STD</rule_id><parameter_id>LogonRequired</parameter_id><parameter_value>false</parameter_value></Rule_Parameter>
```

## Resources (entity name is plural)
```xml
<Resources>
  <id>R-CHI-001</id>
  <first_name>Bruce</first_name>
  <surname>Wayne</surname>
  <resource_type_id>SENIOR_TECH</resource_type_id>
  <resource_class_id>STANDARD</resource_class_id>
  <location_id_start>H-R-CHI-001</location_id_start>
  <location_id_end>H-R-CHI-001</location_id_end>
  <travel_to>PT1H</travel_to>
  <travel_from>PT1H</travel_from>
  <cost_ph>65</cost_ph>
  <cost_phot>98</cost_phot>
  <cost_km>0.6</cost_km>
  <utilisation>90</utilisation>
  <rule_collection_id>STD</rule_collection_id>
  <memo>*</memo>
</Resources>
```

## Resource_Region / Resource_Skill
```xml
<Resource_Region><region_id>CHI</region_id><resource_id>R-CHI-001</resource_id><override_priority>1</override_priority></Resource_Region>
<Resource_Skill><skill_id>FIBRE</skill_id><resource_id>R-CHI-001</resource_id></Resource_Skill>
```

## Availability + Resource_Region_Availability (extra region, time-bounded)
```xml
<Availability>
  <id>AV-R-DEN-001-MTN</id>
  <datetime_start>2026-10-23T06:00:00+00:00</datetime_start>
  <datetime_end>2027-02-14T06:00:00+00:00</datetime_end>
</Availability>
<Resource_Region_Availability>
  <id>RRA-R-DEN-001-MTN</id>
  <resource_id>R-DEN-001</resource_id>
  <region_id>MTN</region_id>
  <availability_id>AV-R-DEN-001-MTN</availability_id>
  <override_priority>1</override_priority>
</Resource_Region_Availability>
```
Cover the whole horizon (day −7 to day `horizon_days`; the example is Oct 23 → Feb 14 for a 100-day horizon). A bare `Availability` (no `activity_id`) is a resource one; the activity form of this entity arrives in stage 3.

## Shift / Shift_Break
```xml
<Shift>
  <id>00120261102</id>
  <resource_id>R-CHI-001</resource_id>
  <start_datetime>2026-11-02T13:30:00+00:00</start_datetime>
  <end_datetime>2026-11-02T22:00:00+00:00</end_datetime>
  <actual>true</actual>
  <utilisation>90</utilisation>
  <split_allowed>true</split_allowed>
  <split_priority>0</split_priority>
</Shift>
<Shift_Break>
  <id>001202611021</id>
  <shift_id>00120261102</shift_id>
  <duration>PT30M</duration>
  <earliest_start_offset>PT3H</earliest_start_offset>
  <latest_start_offset>PT5H</latest_start_offset>
</Shift_Break>
```
That example is Mon Nov 2, 07:30–16:00 America/Chicago after DST end, hence `split_priority=0`. No `shift_type_id` field on Shift.

## Object_Group
```xml
<Object_Group><group_id>DC_US</group_id><id>DC_US-R-CHI-001</id><resource_id>R-CHI-001</resource_id></Object_Group>
```
