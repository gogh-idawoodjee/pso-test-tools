# System day hand-off

Reference material for `docs/superpowers/specs/2026-10-20-load-audit-system-day-design.md`: the
"System day" leg of the load audit (PSO system events, Appointment History and the audit archive on one
zoomable clock).

| Path | What it is |
| --- | --- |
| `reference/index_audit.py` | Indexes an audit archive folder into one CSV row per file (type, time, bytes, element counts, `Object_Deletion` by type, `Input_Reference` fields). Streaming, standard library. `python3 -I index_audit.py <folder> <out.csv>` |
| `reference/timeline_renderer.js` | The SVG lane renderer with per-chart pan / zoom and delegated tooltips. No lanes, no data. |
| `reference/lanes.js` | The lane definitions from the 2026-10-20 page (broadcast, call duration, appointments, 2879, 2544, inputs with binning, per-minute bars, other events) with the data shapes they read, scrubbed of client names. Starting set for spec section 4.7, not a fixed one. |

No customer data in this folder. The sample exports used to build the first page are not in the repo.
