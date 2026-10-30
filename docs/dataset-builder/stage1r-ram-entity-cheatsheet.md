# DsModelling (RAM) — Stage 1R Entity Cheat Sheet

Derived from two real PSO 6.14 modelling exports (`.arp`) from the Technodrome; values rewritten. **Source of truth for RAM field names.** Where a field was not present in the sample but is documented in the schema, it is marked *(schema, unverified)* — use it, but it is the first suspect if the import complains.

Conventions: root `<DsModelling xmlns="http://360Scheduling.com/Schema/DsModelling.xsd">`. One element per row, one child per field. Datetimes with offset (the planner writes local time with offset; we write UTC `+00:00` — both are valid ISO-8601). Durations ISO-8601. Foreign keys are prefixed `ram_` (`ram_resource_type_id`, `ram_location_id_start`). Never mix this namespace with `dsScheduleData` in one document.

## Emit order

RAM_Update → RAM_Division → RAM_Location → RAM_Skill → RAM_Resource_Type → RAM_Rule → RAM_Rule_Collection → RAM_Rule_Collection_Rule → RAM_Rule_Parameter → RAM_Resource → RAM_Resource_Division → RAM_Time_Pattern → RAM_Resource_Division_Avail → RAM_Resource_Skill → RAM_Shift_Category → RAM_Shift_Template → RAM_Shift_Template_Item → RAM_Shift_Template_Item_Break

## NEVER emit

`RAM_Rota`, `RAM_Rota_Item`, `RAM_Rota_Item_Break`, `RAM_Rota_Membership_Period`, `RAM_Rota_Alteration`, `RAM_Rota_*` of any kind (planner-owned, internal ids), `RAM_Version_History` (PSO writes it). Not in 1R: `RAM_Activity_Type`, `RAM_SLA_Type`, `RAM_Appointment_Template*`, `RAM_Slot_Usage_*`, `RAM_Part`, `RAM_Capacity*`, `RAM_Unavailability` — later stages.

---

## RAM_Update (exactly 1 — plays the role of Input_Reference)
```xml
<RAM_Update>
  <organisation_id>2</organisation_id>
  <dataset_id>DC_US</dataset_id>
  <id>1793374200</id>
  <ram_update_type_id>LOAD</ram_update_type_id>
  <description>DC_US modelling data, generated 2026-10-30 15:30Z</description>
  <is_master_data>true</is_master_data>
</RAM_Update>
```
`id` = same unix timestamp as the scheduling `Input_Reference.id` from the same generate run.

## RAM_Division (= Region, and carries the time zone)
```xml
<RAM_Division>
  <id>CHI</id>
  <description>Chicago Metro</description>                 <!-- (schema, unverified) -->
  <send>true</send>                                        <!-- true = pushed to scheduling as a Region -->
  <time_zone_id>America/Chicago</time_zone_id>
</RAM_Division>
```
Optional `ram_division_id` = parent division. We keep divisions flat (no parents) — parent/child changes region eligibility rules.

## RAM_Location
```xml
<RAM_Location>
  <id>H-R-CHI-001</id>
  <latitude>41.92311</latitude>
  <longitude>-87.65402</longitude>
  <name>1138 Wayne Blvd, Chicago</name>                     <!-- (schema, unverified) -->
  <address_line1>1138 Wayne Blvd</address_line1>           <!-- (schema, unverified) -->
  <city>Chicago</city>                                     <!-- (schema, unverified) -->
  <post_code_zip>60614</post_code_zip>                     <!-- (schema, unverified) -->
</RAM_Location>
```
Same ids as the scheduling `Location` rows for homes and depots.

## RAM_Skill / RAM_Resource_Skill
```xml
<RAM_Skill><id>FIBRE</id><description>Fibre splicing</description><team_inheritable>true</team_inheritable></RAM_Skill>
<RAM_Resource_Skill><ram_resource_id>R-CHI-001</ram_resource_id><ram_skill_id>FIBRE</ram_skill_id></RAM_Resource_Skill>
```

## RAM_Resource_Type
```xml
<RAM_Resource_Type>
  <id>FIELD_TECH</id>
  <description>Field Technician</description>              <!-- (schema, unverified) -->
  <ram_resource_class_id>PERSON</ram_resource_class_id>    <!-- NOT "STANDARD" — RAM uses PERSON -->
  <cost_km>0.6</cost_km>
  <cost_ph>45</cost_ph>
  <cost_phot>68</cost_phot>
  <travel_to>PT1H</travel_to>
  <travel_from>PT1H</travel_from>
  <max_travel>PT3H</max_travel>                            <!-- (schema, unverified) -->
  <utilisation>100</utilisation>
  <hire_cost>4000</hire_cost>
  <release_cost>4000</release_cost>
  <relocation_cost>2000</relocation_cost>
  <out_of_region_multiplier>0</out_of_region_multiplier>
  <speedfactor>1</speedfactor>
</RAM_Resource_Type>
```
`hire_cost` / `release_cost` / `relocation_cost` are WISE/planning costs; keep the sample's values.

## RAM_Rule set (COMMIT)
```xml
<RAM_Rule><ram_rule_type_id>COMMIT</ram_rule_type_id><id>STD</id><global>false</global></RAM_Rule>
<RAM_Rule_Collection><id>STD</id></RAM_Rule_Collection>
<RAM_Rule_Collection_Rule><ram_rule_collection_id>STD</ram_rule_collection_id><ram_rule_type_id>COMMIT</ram_rule_type_id><ram_rule_id>STD</ram_rule_id></RAM_Rule_Collection_Rule>
<RAM_Rule_Parameter><ram_rule_type_id>COMMIT</ram_rule_type_id><ram_rule_id>STD</ram_rule_id><parameter_id>MaximumCommittedActivities</parameter_id><parameter_value>2</parameter_value></RAM_Rule_Parameter>
<RAM_Rule_Parameter><ram_rule_type_id>COMMIT</ram_rule_type_id><ram_rule_id>STD</ram_rule_id><parameter_id>CommitBreaks</parameter_id><parameter_value>false</parameter_value></RAM_Rule_Parameter>
<RAM_Rule_Parameter><ram_rule_type_id>COMMIT</ram_rule_type_id><ram_rule_id>STD</ram_rule_id><parameter_id>CountBreaks</parameter_id><parameter_value>false</parameter_value></RAM_Rule_Parameter>
<RAM_Rule_Parameter><ram_rule_type_id>COMMIT</ram_rule_type_id><ram_rule_id>STD</ram_rule_id><parameter_id>LogonRequired</parameter_id><parameter_value>false</parameter_value></RAM_Rule_Parameter>
```

## RAM_Resource / RAM_Resource_Division
```xml
<RAM_Resource>
  <id>R-CHI-001</id>
  <first_name>Bruce</first_name>                           <!-- (schema, unverified) -->
  <surname>Wayne</surname>                                 <!-- (schema, unverified) -->
  <ram_resource_type_id>SENIOR_TECH</ram_resource_type_id>
  <ram_location_id_start>H-R-CHI-001</ram_location_id_start>
  <ram_location_id_end>H-R-CHI-001</ram_location_id_end>
  <ram_rule_collection_id>STD</ram_rule_collection_id>
</RAM_Resource>
<RAM_Resource_Division>
  <ram_division_id>CHI</ram_division_id>
  <ram_resource_id>R-CHI-001</ram_resource_id>
  <override_priority>1</override_priority>
</RAM_Resource_Division>
```
Same inheritance as scheduling: nothing on the resource that the type already carries, except seed `overrides`.

## Time-bounded division membership (= Resource_Region_Availability) — for `extra_regions`

The planner writes **both** a plain `RAM_Resource_Division` row and a dated `RAM_Resource_Division_Avail` row pointing at a `RAM_Time_Pattern`. Emit both for every `extra_regions` entry.

One-off window (what the generator uses — covers the whole horizon):
```xml
<RAM_Resource_Division>
  <ram_division_id>MTN</ram_division_id>
  <ram_resource_id>R-DEN-001</ram_resource_id>
  <override_priority>1</override_priority>
</RAM_Resource_Division>
<RAM_Time_Pattern>
  <id>TP-R-DEN-001-MTN</id>
  <base_time>2026-10-23T06:00:00+00:00</base_time>     <!-- day −7, 00:00 in the division's zone, written in UTC -->
  <duration>P107D</duration>
</RAM_Time_Pattern>
<RAM_Resource_Division_Avail>
  <id>RDA-R-DEN-001-MTN</id>
  <ram_resource_id>R-DEN-001</ram_resource_id>
  <ram_division_id>MTN</ram_division_id>
  <ram_time_pattern_id>TP-R-DEN-001-MTN</ram_time_pattern_id>
  <override_priority>1</override_priority>
</RAM_Resource_Division_Avail>
```

Recurring window (documented for later stages, e.g. "covers the rural region on Saturdays" — **not emitted in 1R**):
```xml
<RAM_Time_Pattern_Template><id>TPT-SAT</id><recurrence_days>7</recurrence_days></RAM_Time_Pattern_Template>
<RAM_Time_Pattern_Template_Item>
  <ram_time_pattern_template_id>TPT-SAT</ram_time_pattern_template_id>
  <sequence>0</sequence>
  <time_increment>PT0S</time_increment>
  <day_pattern>NNNNNYN</day_pattern>                   <!-- Mon..Sun -->
  <move_rule>0</move_rule>
  <duration>P1D</duration>
</RAM_Time_Pattern_Template_Item>
<RAM_Time_Pattern>
  <id>TP-R-X-SAT</id>
  <ram_time_pattern_template_id>TPT-SAT</ram_time_pattern_template_id>
  <base_time>1899-12-31T00:00:00+00:00</base_time>     <!-- planner's "no anchor" sentinel -->
  <time_zone>America/Denver</time_zone>
</RAM_Time_Pattern>
```

## RAM_Shift_Category
```xml
<RAM_Shift_Category><id>STANDARD</id><colour>#FF0087C1</colour><productive>true</productive></RAM_Shift_Category>
```
Colour is ARGB. One category per resource type gives distinct Gantt colours in the planner: FIELD_TECH `#FF0087C1`, SENIOR_TECH `#FF6A1B9A`, INSTALLER `#FF2E7D32`, CONTRACTOR `#FFEF6C00`.

## RAM_Shift_Template + Item + Item_Break (= shift pattern)
```xml
<RAM_Shift_Template>
  <id>FIELD_TECH</id>
  <description>Field Tech Mon-Fri 08:00-16:30</description>   <!-- (schema, unverified) -->
  <ram_shift_category_id>FIELD_TECH</ram_shift_category_id>
  <recurrence_days>7</recurrence_days>
  <start_day>1</start_day>                                 <!-- 1 = Monday -->
  <duration>PT8H30M</duration>                             <!-- one duration for every item in the template -->
  <split_allowed>true</split_allowed>
  <split_priority>1</split_priority>
  <precedence>1</precedence>
  <repeat_preference>1</repeat_preference>
</RAM_Shift_Template>
<RAM_Shift_Template_Item>
  <ram_shift_template_id>FIELD_TECH</ram_shift_template_id>
  <sequence>1</sequence>
  <day_increment>0</day_increment>                         <!-- 0 = Monday … 6 = Sunday -->
  <time_increment>PT8H</time_increment>                    <!-- shift start, local to the resource's division zone -->
</RAM_Shift_Template_Item>
<RAM_Shift_Template_Item_Break>
  <id>FIELD_TECH-1</id>
  <ram_shift_template_id>FIELD_TECH</ram_shift_template_id>
  <sequence>1</sequence>                                   <!-- matches the item's sequence -->
  <duration>PT30M</duration>
  <earliest_start>PT3H</earliest_start>
  <latest_start>PT5H</latest_start>
</RAM_Shift_Template_Item_Break>
```
One item + one break per working day. The sample used GUIDs for break ids; any unique string works.

The four templates from the spec:

| Template | `duration` | `day_increment`s | `time_increment` | break |
|---|---|---|---|---|
| FIELD_TECH | PT8H30M | 0,1,2,3,4 | PT8H | PT30M, PT3H–PT5H |
| SENIOR_TECH | PT8H30M | 0,1,2,3,4 | PT7H30M | PT30M, PT3H–PT5H |
| INSTALLER | PT10H30M | 0,1,2,3 | PT7H | PT45M, PT4H–PT5H30M |
| CONTRACTOR | PT8H | 1,2,3,4,5 | PT9H | PT30M, PT3H–PT5H |

`split_priority` on the template is the Monday-rule question: the template has one value for all its days. Planner-generated Monday shifts will therefore come out at 1, not 0, unless edited. Loaded shifts (from the scheduling LOAD) carry 0 on Mondays as before. Accept the discrepancy for planner-added shifts, or set the template to 0 and lose Tue–Fri splitting on those — brief says accept.

## Scheduling LOAD when `modelling: true` — slim mode

**Nothing the `.arp` carries goes in the LOAD.** The LOAD contains exactly: `Input_Reference`, `Source_Data`, `Source_Data_Parameter`, `Shift`, `Shift_Break` (stage 2 adds the activities and their locations; no `Object_Group`, FIX on #60). Everything else from the stage 1 cheat sheet (`Region`, `Location`, `Skill`, `Resource_Type`, `Rule*`, `Resources`, `Resource_Region`, `Resource_Skill`, `Availability`, `Resource_Region_Availability`) is RAM's and is **not** emitted. When `modelling: false`, the stage 1 LOAD is unchanged. A test must assert no entity type appears in both files.

Add to the slim LOAD:
```xml
<Source_Data><source_data_type_id>RAM</source_data_type_id><sequence>1</sequence></Source_Data>
<Source_Data_Parameter><source_data_type_id>RAM</source_data_type_id><sequence>1</sequence><parameter_name>rota_id</parameter_name><parameter_value>DC_US</parameter_value></Source_Data_Parameter>
```
`rota_id` = dataset id, matching the DCU convention where the master rota is named after the dataset. **Verify on first load**: if the planner names its master rota differently, this value changes.
