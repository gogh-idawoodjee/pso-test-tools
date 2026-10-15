# Trace an activity: execution notes

Date: 2026-10-15. Spec: `docs/superpowers/specs/2026-10-15-activity-trace-design.md`. Plan:
`docs/superpowers/plans/2026-10-15-activity-trace.md`.

## What was built, by task

pso-tools, branch `activity-trace` (from main 6bc37cf):

1. Reader, file time rule and CLI: 6ede34d, fix round 41edd38. Streams the audit zip once, byte pre-scans so only files holding the id are parsed, resolves work task and related activities. About 27 s on the 868 MB SaskTel zip.
2. Booking builder and booking.csv: 6ab3d7a.
3. Page template and Booking view: 84da7f1, fix round 16cc662. Page size on a real run went from 13 MB to about 1 MB.
4. History builder and history.csv: baf1ea9, fix round 46b1daa.
5. IFS event log reading and pairing: de9bebd, fix round e1bd257.
6. History view, files read table, summary line: 0a8f6a9.

pso-test-tools, branch `feat/activity-trace` (from main 6bd04fa):

7. Event log export kind, summariser and `ifs_time_zone` setting: f2b2466, fix round c86565e.
8. The tool itself (command, run page, CSV downloads, catalogue, wizard): 7f27c65.
9. This documentation commit.

## Rulings made during the work

- Timing cards stay dataset-wide, as in the PowerShell. The reader keeps a compact table of every request's times (no rows), and the cards are built from it.
- Byte pre-scans before parsing are accepted: two extra passes over the zip, 27 s in total on the real file.
- The History shift check uses Plan_Route rows from the plan files, as the PowerShell does, not the LOAD. The spec said otherwise and has been corrected.
- The LOAD is read as one more audit file at its own time. History files are processed in time order so carried state never contradicts a check.
- "Not booked, offers expired" keeps the amber "warn" colour, as the PowerShell does.
- trace.json lists only files that hold rows for the activity or failed, plus totals. This cut the JSON and page from 13 to 16 MB down to about 1 MB.
- Page wording follows the house rule: "allocation", never "placement" ("Same allocation as plan X").
- Event log dates are converted as wall-clock time in the IFS zone, so DST transition days are right (2026-03-08 and 2026-11-01 are tested in both repos).
- A foreign TASK_SEQ in the event log warns whenever a work task id was given.
- Exit 2 (activity not found) shows the script's own last line as the whole error, with no prefix.

## Deferred minors

pso-tools:
- Context rows are buffered per file unfiltered; the activity-mention regex is case-sensitive where PowerShell's is not (decide: deliberate or add case-insensitivity); two scans share the stage name "scanning".
- A file with no datetime in its Input_Reference falls back to the file name even if the Plan sets a time; a partly parsed file can seed `found` before erroring.
- booking.csv TimestampSource values should use the PowerShell labels (Input_Reference / Plan output / file time); iso_secs and engine_secs raise on a lone "."; is_load fallback and latest_file_time skipping errored files are accepted differences.
- Late acknowledgements keep every unmatched row for the whole run (cap or trim fields on very large days); acknowledgements are filtered against exception ids known at read time (one-minute edge); plan_id is None for plan files without a header; the combined summary drops "No appointment request" when history has steps.
- Booked-slot card loop readability; leftover hero and day-strip CSS from before Task 6; dark-mode pill contrast inherited from the template; a LOAD with no rows is now listed in Files read, but check it.
- Event log: whole sheet XML is loaded; midnight cap; "earlier" wording for a negative gap (parity); header-only log message slightly misleading; no strict-OOXML regression test.

pso-test-tools:
- Last "Created Date" header wins in the summariser's first pass (it now breaks on the first, check the CSV path); numeric text in a csv could still be read as a serial inside 30000 to 80000.
- Empty error on exit 2 with empty stderr (ScriptRun.php, line 75); no test that both ids blank is rejected in the wizard; the CSV file name uses `str_replace('.csv')`; the article helper is duplicated in ToolCatalogue; an exception class name can leak into exit-1 messages; the exit-1 message reads "stopped while starting: Stopped during: ..." when no PROGRESS line came first.
- Only the first worksheet of an event log is read; text dates with an explicit offset use that offset.

## After merge

1. Merge `activity-trace` into pso-tools main and `feat/activity-trace` into pso-test-tools main.
2. Both repos deploy themselves through the pull-deploy cron within two minutes of a push to main. pso-tools is a pull-only target on the box.
3. No migration: the new setting is JSON on the dataset and the new export kind is an enum case.
4. Nothing to restart for the Python: the trace runs the script fresh each run.
5. Push pso-tools first, so the script exists when the web tool appears.

## Browser check list

- Exports tab accepts an .xlsx event log and shows "N events, first to last".
- Datasets settings show IFS event log time zone.
- The wizard for Trace an activity shows three input rows (Audit messages, LOAD, IFS event log) and the two option fields (activity id, work task id).
- A run on the SaskTel dataset with activity 813775 (known: 35 requests, 87 steps, no allocations) shows Booking and History, the zone switch and the four downloads (page, JSON, booking CSV, history CSV).
- A wrong activity id fails the run with the plain "not found" message.
- Recent runs shows the summary line.
