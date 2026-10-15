# Activity trace inventory

Behaviour inventory of the two PowerShell scripts the "Trace an activity" tool replaces, read on 2026-10-15
from pso-tools `scripts/Build-AppointmentTimeline.ps1` (ATL, 1,500 lines), `scripts/Get-PsoTaskTimeline.ps1`
(PTT, 937 lines) and `scripts/task_timeline_template.html` (457 lines). Reference for the spec and plan; not a design.

## Biggest surprises

1. ATL embeds its HTML as a here-string inside the .ps1 (no template file). PTT uses task_timeline_template.html with only __TITLE__ and __DATA__ placeholders (plus a literal __TZ__ replaced client-side). The template shares localStorage keys with the load audit: theme "pso-audit-theme", zone "pso-trace-tz"; ATL theme key is "pso-appt-theme".
2. The two scripts define "file time" differently. ATL: Input_Reference datetime, else Plan output_datetime, else zip entry or file last-write time. PTT: Plan output_datetime first, else Input_Reference datetime, else the file name's UTC stamp, else now.
3. "Pin source", "declined", and "slot rule continues until next rule" are labelled inference or calibrated on one sample. The PSW/SIM/SBM classification of who sent an input rests on a single Workbench sample.
4. ATL ignores LOAD-timed rows for response-time stats but still places them on the timeline and CSV at the LOAD's Input_Reference time. LOAD lookup ends in a Read-Host prompt (blocks non-interactive runs).
5. PTT's pairing of IFS events to PSO inputs is time-only (-2 s to +MatchToleranceSeconds), and the IFS-assignment-to-activity link is inferred only from a Status 30 (Committed) input.

## 1. Inputs and discovery

ATL
- Params and defaults: -Path (alias -ZipPath), -ActivityId (required), -OutputHtml (default appointment-timeline-<id>.html next to the input), -OutputCsv (same path with .csv), -TimeZone (default 'America/Regina'; '' means viewer's zone first), -ActivityLinkField ('activity_id'), -LoadFile (alias -LoadPath), -SkipSlotUsage, -ShowAllFiles, -OpenReport.
- Constants: FullInputMaxMb = 5; PlanOutputTables = Allocation*, Plan*, Schedule_Exception*, Appointment_Offer*.
- Exit code 2 when no Appointment_Request for the activity (lists up to 10 activities that do have requests).
- Input: a .zip (XML entries; nested .zip entries kept), a folder (recursive *.xml), or one XML file. Sources sorted by last-write time, then ordinal name.
- Streams each file once with XmlReader. Keeps only depth-1 elements: Input_Reference, Plan, Appointment_Request, Appointment_Offer, Appointment_Offer_Response, Appointment_Summary. Unreadable files counted as skipped with a warning.
- No file-name parsing. No event log.
- LOAD lookup for slot usage, in order: (1) newest file already read whose InputType is LOAD; (2) first nested .zip in the input zip, biggest .xml inside; (3) -LoadFile; (4) load.xml then load.zip next to the input; (5) Read-Host prompt. Skipped with -SkipSlotUsage.
- Time zone: report only. Data is UTC. -TimeZone is the first zone offered; the page also offers the browser zone and UTC. Invalid IANA name dropped with a banner.

PTT
- Params and defaults: -AuditPath ('' = current folder), -WorkTaskId, -ActivityId (comma/semicolon/space separated list), -EventLogPath, -OutputFolder (trace_wt<id> or trace_act<id>), -LocalTimeZoneId 'Canada Central Standard Time', -LocalLabel 'Regina', -IfsTimeZoneId ('' = local zone), -BrowserTimeZone 'America/Regina', -TemplatePath, -MatchToleranceSeconds 10, -IncludeRelated $true, -NoHtml, -NoOpen. Prompts if neither id given.
- Audit input: folder (recursive *.xml) or .zip (extracted to a temp folder).
- File-name parsing: `file<seq>-<TYPE>-yyyy_MM_dd_HH_mm_ss.xml` gives Seq, Type (CHANGE, PLAN_CHANGE, COMPLETE, LOAD, ...) and NameUtc; `File_yyyy_MM_dd_HH_mm_ss.xml` (Workbench download) gives NameUtc only (Type DATA, Seq 0); names starting load/plan/eod set Type LOAD/PLAN/EOD. Sorted by Seq then Name.
- Event log: .xlsx (hand-parsed: xl/sharedStrings.xml, first worksheet; inline, shared and numeric cells; first row = headers) or .csv. Expected columns: Created Date, Key Ref, Event, Event Description, Source, Created By, Event Text, Transaction Note, Previous Value, Event Log Sequence, Generated. Created Date is an OADate number (sub-second noise truncated) or parseable text; rows with no date dropped. Key Ref regexes: EXECUTION_INSTANCE_SEQ=(\d+) (assignment instance), TASK_SEQ=(\d+) (work task).
- Time zones: IFS times interpreted in -IfsTimeZoneId (else local) and converted to UTC. Audit times are UTC. Fallback to a fixed UTC-06:00 zone with a warning.
- Work task resolution: -ActivityId given means primary ids are that list. Otherwise scans files for Activity_Custom_URL.custom_parameter_value1 == id, or Additional_Attribute label 'Work Task ID' with label_value == id, giving activity_id.

## 2. Extraction per XML file

ATL
- Input_Reference (first wins): datetime, input_type, id.
- Plan (first wins): output_datetime, plan_type, input_reference_id, id, time_taken (ISO duration to seconds).
- Appointment_Request: id, activity_id (or -ActivityLinkField), request_datetime, appointment_template_id, appointment_template_datetime, appointment_window_length, offer_expiry_datetime, appointment_base_datetime, slot_usage_rule_set_id.
- Appointment_Offer: id, appointment_request_id, available ('true'), offer_start_datetime, offer_end_datetime, window_start_datetime, window_end_datetime, offer_value, reason_type_id, prospective_resource_id, prospective_allocation_start, plan_id.
- Appointment_Offer_Response: appointment_request_id, appointment_offer_id, input_updated.
- Appointment_Summary: appointment_request_id, appointed, appointment_start_datetime, appointment_end_datetime, appointment_value, request_outstanding.
- From the LOAD only: Appointment_Template (id, slot_usage_rule_set_id, time_zone, description), Appointment_Template_Item (appointment_template_id, slot_start_time, slot_end_time, slot_usage_window_start, slot_usage_window_end), Appt_Template_Slot_Usage (appointment_template_id, slot_usage_rule_set_id), Slot_Usage_Rule_Set (id, description, all filter_* fields), Slot_Usage_Rule (slot_usage_rule_set_id, minimum_start_offset, maximum_utilisation, minimum_free_time).
- Per-file metadata: No, Name, Time, TimeSource (Input_Reference | Plan output | file time), InputType (Input_Reference.input_type, else 'PLAN_'+plan_type), InputRefId, PlanId, Engine seconds, SizeMb, counts, Target, Error.
- "Full input" documents for the JSON/XML buttons: modes full / activity / ref. Activity mode keeps Input_Reference, the Activity row, its Location, the Appointment_Request, and any other table whose activity_id equals the activity, excluding PlanOutputTables and Availability rows of type ACCEPTED_APPOINTMENT. LOAD input_type is turned into CHANGE and row_count, object_deletion_count, load_status removed.

PTT (streams every depth-1 element, ReadOuterXml each)
- Input_Reference or Plan becomes the file header. Plan_Route, Schedule_Exception(+_Data) and Plan_Deletion rows are kept as context for the whole file. A row is a hit if the raw XML matches the id regex `>(optional prefix-)A-id(-n)<` and the mention fields test.
- Mention fields (exact equal to id, or `^(?:[A-Za-z0-9_]+-)?A-<id>(?:-\d+)?$`): activity_id, activity_id1, activity_id2, previous_activity_id, id, object_pk1..4, value, label_value, custom_parameter_value1.
- Header (Input_Reference): id, input_type, process_type, enacting_app_type_id, enacting_app_instance_id, row_count, object_deletion_count, user_id, application_type_id, description, datetime. Header (Plan): id, plan_type, application_instance_id, software_version, quality, total_allocations, time_taken, input_reference_id, output_datetime.
- Activity: id, activity_class_id, activity_type_id, location_id, duration, priority, base_value, date_time_created.
- Activity_Status: status_id, fixed, resource_id, date_time_stamp, date_time_status, date_time_fixed, date_time_earliest, commit_sort_date_time.
- Activity_SLA: sla_type_id, datetime_start, datetime_end, start_based.
- Availability: availability_type, datetime_start, datetime_end.
- Activity_Group: activity_id1, activity_id2, pre_co_req, min_delay, same_resource, all_or_nothing.
- Activity_Custom_URL: custom_url_id, custom_parameter_value1 (work task id). Additional_Attribute: label, label_value, sequence (label 'Work Task ID' is the work task id).
- Object_Deletion: object_type_id.
- Allocation: activity_id, resource_id, shift_id, activity_start, activity_end, duration, visit_status, date_time_fixed, fixed_resource, rank, plan_id, shift_start_datetime.
- Plan_Route (matched on resource_id and shift_id): shift_end_datetime, shift_start_datetime, total_allocations, utilisation, total_on_site_time, total_travel_time, total_break_time, total_unutilised_time.
- Allocation_Data: expected_start, sla_start_time, sla_jeopardy_time, commit_datetime.
- Plan_Travel: activity_id, previous_activity_id, start_location_id, end_location_id, distance, expected_travel_time, start_time, end_time.
- Schedule_Exception: id, activity_id, schedule_exception_type_id. Schedule_Exception_Data: schedule_exception_id, label ('Reason', 'Previous activity ID', others), value, activity_id.
- Plan_Deletion: object_type_id (Allocation, Allocation_Data, Schedule_Exception), object_pk1.
- Schedule_Exception_Response (whole-file DOM parse): schedule_exception_id, acknowledgement_datetime, acknowledgement_user_id, acknowledged, acknowledgement_comment, plan_id.
- File-level time: Plan/Input_Reference output_datetime, else datetime, else file-name UTC, else now. isInput = Type CHANGE or LOAD, overridden by header: input_type means input; plan_type means plan. Step source: 'PSO input' / 'PSO plan' / 'PSO baseline' (Type COMPLETE).
- Event log fields: Utc, Event, Desc, Source, User, Text, Note, Prev, Instance, Task, Seq, Generated.

## 3. Linking and de-duplication

ATL
- Request: Appointment_Request.<ActivityLinkField> == ActivityId. Offer: appointment_request_id in the request set. Response: appointment_request_id in the request set.
- Dedupe keys (first seen kept in file-time order, later ones increment Seen): request by id; offer by requestId|offerId (offer ids restart at 0 per request); response by requestId|offer_id|input_updated.
- Each row's time comes from the first file it was seen in. Request time = request_datetime, else file time.
- Timeline ordering: Timestamp, then rank (Request 0, Offer 1, Response 2), then insertion order.
- Offers per request sorted by start, then id. A response carrying an offer id is matched by requestId|offerId and copies the offer's slot details.
- Timing maps: earliest time per key, preferring measured (non-LOAD, Input_Reference or Plan output time) over unmeasured. Maps: tReq (by request id), tOff (by request id, with engine secs), tRespFirst (by request id, taken/declined), tResp (by full response key). Stats cover every request in the files, not only the target activity.
- Appointment_Summary: per request, earliest file with appointed == 'true' gives booked (time, start, end, value, file); earliest with request_outstanding == 'false' gives closed.
- Slot usage rule set per request: request.slot_usage_rule_set_id, else Appointment_Template.slot_usage_rule_set_id, else first Appt_Template_Slot_Usage row (warns if more than one). Without a LOAD, only the request's own value.

PTT
- Primary ids = ActivityId list (or resolved from work task). Related ids = other end (activity_id1/2) of any Activity_Group row in a file mentioning a primary id (if -IncludeRelated). Roles: Primary / Related.
- One step per (file, activity) that has hits, in file order. Link-only mentions (only Activity_Group / Activity_Custom_URL rows and no title) are dropped; a step with no title bits is titled 'referenced'.
- state[act] carries across files: SlaStart/SlaEnd/StartBased, Alloc (last placement), Duration, ShiftStart/End, Fixed/FixedResource.
- Previous allocation compared on Res/Start/End: "Changed since plan X" or "Same placement as plan X"; "First allocation for this activity seen in the audit" when none yet. Cleared when allocation has no resource or a plan deletion removes it.
- Exception text de-duped by exception id; knock-ons keyed by exception id. Cleared exceptions matched via Plan_Deletion of type Schedule_Exception with object_pk1 = id.
- Neighbour activities named by knock-ons are checked in later CHANGE files until a file is more than 30 minutes after the step; looks for Object_Deletion rows for the neighbour.
- Work task id: -WorkTaskId, else Activity_Custom_URL/Work Task ID attribute (last write wins per activity; primary first, then related "inferred"), else first TASK_SEQ in the event log ("not verified"). Warns if the log has events with a different TASK_SEQ.
- Event pairing: for each IFS event, the best PSO-input step whose Utc (or Meta.StatusStamp) has gap = candidate - event in [-2 s, +MatchToleranceSeconds]; minimum |gap| wins. Only ASSIGNED, APPOINTMENT, UNASSIGNED, CANCELLED, REASSIGNED are marked Matched. assignMap[instance] = activity only for ASSIGNED events whose matched step has status 30 and the instance is not yet mapped.
- Timeline sort: Utc, then IFS before others, then file Seq. Final placement per activity = last bar by plan output time.

## 4. Checks and inferences (thresholds)

ATL
- Offer available only if available == 'true'; otherwise "Offer (not available)".
- Response with offer id = "Response: offer taken"; no offer id = "Response: declined" (inference).
- Warnings per request: no offers found; every offer not available; response took an offer not in files; taken offer PSO had marked not available; more than one response (outcome = last); taken but Summary never shows appointed; response file (non-LOAD) written after expiry.
- Expired = offer_expiry_datetime < latest file time in the whole input.
- Outcome (no responses): Booked if Summary booked; "No slot available" if offers exist but none available; "Not booked, offers expired" if expired or Summary closed; else "No response yet". With responses: last taken = "Booked (offer N)", declined = "Declined". Kinds: good / bad / warn.
- Stats: n, avg, median, p90, min, max. Request-to-offers only when both measured; engine from Plan time_taken; offers-to-taken and offers-to-declined from first response time. Counts: booked, no_slot, avail_not_booked, snapshot_only (requests whose offers are only in LOAD snapshots).
- Slot usage: base = appointment_base_datetime. Each Slot_Usage_Rule placed at base + minimum_start_offset (ISO duration; non-ISO offsets dropped with warning). Sorted by offset; until = next rule's from (inference). Warnings: template not in LOAD; request and template name different rule sets (request wins); template rule set vs Appt_Template_Slot_Usage mismatch; more than one Appt_Template_Slot_Usage rule set; rule set not in LOAD; rule set has no rows; no base datetime. Console shows allowed utilisation at each offer's start; HTML uses rules overlapping the offer span and shows "no rule yet" when none.
- Files without Input_Reference or Plan time: warned, "not timed".

PTT
- Appointment window check (allocation with resource and times, SlaStart and SlaEnd known): inside = start between SlaStart and SlaEnd inclusive; if not start_based, end must also be <= SlaEnd. "CHECK start ... is inside/OUTSIDE the appointment window".
- Shift check (needs Plan_Route match on resource and shift): end <= ShiftEnd ok; else overshoot and "Latest start that fits the shift" = ShiftEnd - duration. Shift start also from Allocation.shift_start_datetime if the route lacks it.
- Fixed-start fact: fixed inside/OUTSIDE the SLA window (inclusive). Commit-sort time vs fixed start difference noted.
- Overlaps are not computed; they appear only via Schedule_Exception type 210 and knock-on exceptions.
- Moves: changed resource/start/end versus previous plan.
- Findings severity (HTML): warn line starting "CHECK" = RED; warn "Changed since" = INFO; other warn = AMBER; ok line starting "CHECK" = GREEN. De-duplicated by sev|activity|text.
- Missing IFS counterpart warning: input steps whose title contains status|appointment|window|record with no match line, when an event log is present.
- Hero: from the first matched IFS event (else first PSO input) to the last PSO plan step at or after it.
- Facts: "Pinned by" (first step with a PSW user and lines matching FIXED|Status Committed; else users of matched ASSIGNED IFS events; else "Not visible"), "Fixed start", "Past shift end" / "Shift fit" for the primary's final plan, "Actually worked" from IFS (work started to completed).
- Planned vs actual per assignment instance, from Source == 'Work Assignment' events: last ONROUTE, first WORKSTARTED, last COMPLETED; technician from Text 'by (\S+)$' or 'Work Assignment <inst> sent to (\S+)'.
- Tolerances: MatchToleranceSeconds 10 (gap >= -2 s); neighbour removal window 30 minutes; acknowledgement scan skips files earlier than first exception time minus 1 minute and files of type PLAN_CHANGE/COMPLETE/PLAN/EOD.
- Input source classification: application_type_id 'PSW' or (user_id present and app != 'SIM') = PSO Workbench; enacting_app_type_id 'SIM' with no user = integration via SIM; user_id with other app = USER; else UNKNOWN.

## 5. Outputs

ATL
- Console: header, progress, per-file lines, response-time block, per-request block (template, window, expiry, source file, offers with resource and "taken", responses with gap, booked slot, rule set and allowed utilisation per offer, outcome, warnings), final summary per request, UTC note, "Done in N s".
- CSV (events, in order): Timestamp, TimestampSource, Event, RequestId, OfferId, Available, OfferStart, OfferEnd, WindowStart, WindowEnd, OfferValue, ReasonTypeId, ProspectiveResource, ProspectiveStart, PlanId, TemplateId, TemplateDatetime, WindowLength, OfferExpiry, InputUpdated, AuditFile, InputType, InputReferenceId, SeenInFiles. Event values: Request, Offer, Offer (not available), Response: offer taken, Response: declined.
- HTML (single file): header with zone buttons (configured zone, This computer, UTC) and Theme; banners (bad zone, slot-usage note); timing cards (request to offers with engine average; offers to slot taken/declined; bookings %; outcome of the latest request; offers available; booked slot); per request: outcome pill, JSON buttons (Show JSON / Copy JSON / Copy XML), meta line (template, template date, window length, base, slot usage rules, first-seen file), rule-set note (filters, template slots, template zone), duration chips, "Check this" warnings, flow (Requested, Offers returned, Response), responses table, calendar of offered slots (available / not available / taken; per-day Allowed utilisation row; click to jump), offers table (Offer, Slot, Length, Status, Allowed utilisation, Offer value, Prospective resource, Plan, Files; "Available and taken only" toggle); Activity log (Time, Since first, Event, Detail, File); Files read table (#, File, Input, File time, MB, Requests, Offers, Responses); footer with method notes.
- Embedded data model: meta, timing, requests[], files[].

PTT
- Output folder trace_wt<id> or trace_act<id> with timeline_<tag>.csv, .md, .html.
- Console: coloured steps (IFS, PSO input, PSO plan, PSO baseline) with line levels (warn, ok, key, match, ctx); "PLANNED vs ACTUAL" lines; "Wrote:" list.
- CSV columns: UtcTime, LocalTime, Source, Activity, Role, Step, Level, Detail, File (one row per step line).
- Markdown: title "Task timeline - work task X (activity Y)"; local zone and generated time; related activities; summary table (Time (UTC), Time (<Label>), Source, Activity, Step); per-step sections with level prefixes; "Planned vs actual" table (Assignment, User, Activity, Planned, On route, Started, Completed).
- HTML (template): H1 + subtitle, zone select (configured zone, America/Toronto, UTC; stored in localStorage "pso-trace-tz"), Theme button, hero (elapsed time and a proportional pipe of steps with axis), facts (Pinned by, Fixed start, Past shift end / Shift fit, Actually worked), KPI tiles (steps, IFS events, PSO inputs, PSO plans, red findings, amber findings; click to filter), Findings table (Severity, Time, Activity, Finding; filter Needs attention / Information / All; click jumps to step), Day strip (SVG of the focus allocation's local day: appointment window row, shift row, one row per plan allocation with a red past-shift-end segment, actual on route / on site rows; tooltips; legend), Planned vs actual table, Timeline (filters All/IFS/PSO inputs/PSO plans, Context lines toggle, status pills coloured by status code, gaps shown as "+ N later"), Notes, footer. Time tokens `{t:yyyyMMddTHHmmssZ:mode}` (modes hms, hm, dthm, dthms, md, dow, full, both) rendered in the chosen zone.
- Embedded model: meta {title, sub, tz, files, ifs, primary, related, generated, ifsTz}, steps, findings, bars, actuals, hero, facts, notes.

## 6. Lookup tables (PTT; ATL has none)

StatusNames: -1 Do Not Schedule; 0 Unallocated; 10 Allocated; 20 Follow On; 30 Committed; 32 Sent; 35 Downloaded; 40 Accepted; 50 Travelling; 55 Waiting at location; 60 On Site; 65 Pending Completion; 68 Visit Complete; 70 Complete; 80 Incomplete.

ExceptionNames: 50 Planned Activity in Jeopardy; 60 Unplanned Activity in Jeopardy; 70 Unplanned Activities; 120 Travel in Jeopardy; 150 Activity Requiring Attention; 160 Externally Violated Constraints; 210 Overlapping Activities; 290 Fixed Activity Interrupts Commits; 320 Committed Linked Activity; 420 Invalid Committed Route; 430 Broken Activity Link.

Unknown status prints the bare code; unknown exception prints "type N". The template has its own status colour map for the same codes.

## 7. Inference, unconfirmed, TODO markers

- ATL: a response with no appointment_offer_id is declined, with an offer id is taken.
- ATL: a rule applies from its offset until the next rule's offset.
- ATL: link rules checked against SaskTel NORTH 2026-10-05; slot rules against NORTH LOAD 2026-10-04 (requests carry no slot_usage_rule_set_id there; 346 of 885 templates name one; one rule per day PT0S..P364D; maximum_utilisation 0 to 100).
- ATL: JSON documents built from a LOAD or a large file are "Not the original message: review it before sending".
- PTT: input source classification calibrated on one Workbench sample (PSW, logon user, enacting app SBM) versus integration (SIM, no user); SBM meaning unconfirmed.
- PTT: IFS event to PSO input pairing is by time only; assignment-to-activity link inferred by timing; work task from a related activity inferred; from the event log not verified.
- PTT: the audit holds changes only; earlier history needs earlier LOAD or audit files.

## 8. Overlap and differences

Shared: both read ARC audit XML (CHANGE, PLAN_CHANGE, COMPLETE, LOAD) streaming depth-1 children of dsScheduleData; zip or folder input; Input_Reference/Plan headers; UTC times with an in-page zone switcher and a theme toggle; HTML + CSV; an activity id. Both treat a LOAD as a snapshot whose rows carry the export time. Both tag a file as client input versus PSO output by header type.

Differences: file time rule (see surprise 2); dedupe (ATL first-seen with Seen count; PTT one step per file per activity, no cross-file dedupe); zone handling (ATL one zone plus browser plus UTC; PTT local id and label for console/MD/CSV, browser zone dropdown, IFS zone); ATL has no event log, no PSW/SIM detection, no shift or window checks; PTT has no appointment handling or slot usage rules; ATL single-file HTML, PTT external template plus Markdown; theme keys differ; file ordering (ATL last-write time, PTT file-name sequence).
