# PSO Test Tools

A web app for IFS Cloud PSO (Planning and Scheduling Optimization) consultants. It has two families of tools.

**Analysis tools** read exported PSO data files (LOAD, plan and SYS exports). You upload the files per environment or dataset. The run goes on a queue, and the app keeps each run's page, downloads and warnings.

- Load Health Audit: what is wrong with the data and configuration in a LOAD.
- Visualize Resource Regions (region availability): who works in which region, and when.
- Trace an activity: how one activity was booked and how PSO scheduled it.
- Plan travels: every planned travel leg, optionally checked against Google Routes.
- Sys compare: parameter differences between PSO environments.
- Trim export: remove whole tables (the Cleanup for input preset, as Remove-PsoTables does) and keep rows by region, resource, activity type, activity or appointment window, with the referential cascade. Takes a LOAD, plan or End of day export (XML or JSON, zipped or not) and saves the result as a new export next to it.

**Live PSO actions** send to a live PSO environment through the companion pso-services API: Load from File (gateway upload with webhook status), appointment booking, unavailability and event generation, and Travel Analyzer. Production PSO URLs are refused by policy.

Several analysis tools run Python scripts from a checkout of the sibling pso-tools repo, so the server needs that checkout and Python 3.

## Environment Variables Required

Laravel's standard variables (`APP_KEY`, `DB_*`, `MAIL_*` and so on) are not repeated here. See `.env.example` for the full list.

**pso-services connection and tokens**

- `PSO_SERVICES_API`: base URL of the pso-services API. Only the host name is used; the `https://` part is removed. Required.
- `PSO_SERVICES_API_VERSION`: API version to call, for example `2`.
- `PSO_SESSION_TIMEOUT`: seconds allowed for a session logon or logoff through pso-services. Default 20.
- `PSO_SERVICES_ROUTES_TIMEOUT`: seconds allowed for one chunk of 200 trips sent for Google Routes. Default 300.
- `PSO_ROUTES_TOKEN`: shared token sent to pso-services as `X-PSO-Routes-Token`. Leave empty if pso-services sets none.
- `WEBHOOK_BASE_URL`: the self-hosted webhook.site that receives PSO's broadcast after a Load from File upload.
- `WEBHOOK_ALIAS_KEY`: the webhook server's `ALIAS_API_KEY`. Without it, each Load from File run gets a one-off token.

**Google**

- `GOOGLE_MAPS_GEOCODING_API_KEY`: required for geocoding.

**Analysis tool scripts (pso-tools checkout)**

- `TOOLS_PSO_TOOLS_PATH`: path to the pso-tools checkout. Required for the analysis tools that run Python.
- `TOOLS_PYTHON`: the Python command to run them. Default `python3`.
- `TOOLS_LOAD_AUDIT_TIMEOUT_SECONDS`, `TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS`, `TOOLS_ACTIVITY_TRACE_TIMEOUT_SECONDS`: how long each script may run. Defaults 840, 600 and 840.
- `TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS`: how long the Plan travels script may run. Default 600.
- `TOOLS_PLAN_TRAVELS_GOOGLE_DEADLINE_SECONDS`: how long the Google Routes check may take before it stops. Default 720.

**Queue**

- `QUEUE_CONNECTION`: the analysis tools run on the queue, so a worker must be running. The app uses `database`.
- `DB_QUEUE_RETRY_AFTER`: seconds before a running job is retried. Keep it above the longest script timeout. Default 960.

**Storage**

- `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_REGION`, `R2_BUCKET`, `R2_URL`: the Cloudflare R2 disk.
- `LIVEWIRE_TEMPORARY_UPLOAD_DISK`: set to `r2` so large uploads go to R2.
- `EXPORTS_DISK`: where uploaded exports are kept. Default `r2`.
- `EXPORTS_TTL_HOURS`: how long kept exports last. Default 12.
- `EXPORTS_MAX_FILE_MEGABYTES`, `EXPORTS_MAX_MEMBER_MEGABYTES`: size limits for an upload and for a file inside a zip. Defaults 400 and 600.
- `SYS_COMPARE_DISK`: private local disk for SYS uploads. Default `sys-compare`. See the Sys compare section.

**Other**

- `MAIN_PASSWORD`: password for the main user created by the seeder.
- `SENTRY_LARAVEL_DSN`: Sentry error reporting. Optional.

## Navigation

The panel has four sidebar groups: **Analysis tools** (the tools that read LOAD, plan and SYS exports), **Live PSO actions** (pages that send to a live PSO environment, marked with a banner), **Setup** (environments, datasets and reference values) and **Admin**. Each analysis tool opens a short wizard (Where, Inputs, Options, Run) that remembers your last environment or dataset; the home page lists the tools and your recent runs.

**Unfiled and My files.** Trace an activity, Load Health Audit and Plan travels only read the file, so their Where step offers **Unfiled** (the default) next to **A dataset**. An Unfiled run needs no environment or dataset, even for a file from production: nothing is sent to PSO, you choose the time zone the pages show times in on the Options step, and the files and runs are yours alone. **My files**, under Analysis tools, takes uploads of any export kind and lists your unfiled exports (with the same purge countdown as a dataset's Exports tab) and your unfiled runs; their pages open at `unfiled/me/runs/<run>`. Region availability and Sys compare still need a dataset or an environment.

## PSO Sys File Compare

Compares two or more PSO system data exports (`DsSystemData` XML) and produces an HTML report, a CSV bundle and an Excel workbook, with a banner when the environments are not all on the same PSO version. Find it under **Analysis tools**.

Sys files contain customer data (user accounts and a live routing API key), so:

- Uploads go through `POST /sys-compare/uploads` to a private local disk (`storage/app/private/sys-compare`), **not** Livewire's R2-backed temporary uploads.
- Uploads are deleted when a comparison ends. Generated results (no users, keys masked) are kept for 1 hour; `sys-compare:purge` (scheduled every 10 minutes) removes expired results and abandoned uploads.
- The comparison runs on the queue, so a queue worker must be running.

The web process (PHP-FPM) and the queue worker may run as different users. The `sys-compare` disk is therefore group-shared (files `0660`, folders `0770`), which requires both users to be in the same group and the folder to have the setgid bit, so folders created by either user keep that group:

```
sudo mkdir -p storage/app/private/sys-compare
sudo chgrp -R www-data storage/app/private/sys-compare      # the group both users share
sudo chmod -R g+rwX storage/app/private/sys-compare
sudo find storage/app/private/sys-compare -type d -exec chmod g+s {} +
id deploy                                                    # must list www-data; if not: sudo usermod -aG www-data deploy
```

After a deploy that adds config, routes or jobs, run `php artisan optimize:clear` and **restart the queue worker** (a worker started before the deploy does not see new config). If the job reports that an upload "could not be found" straight after it was added, check the worker's user and the folder permissions; the worker logs which user it ran as.

Server prerequisites for uploads of up to 25 MB per file: PHP `upload_max_filesize` and `post_max_size` of at least 25M, and nginx `client_max_body_size` of at least 25m. Limits are configurable in `config/sys-compare.php` (`SYS_COMPARE_*` env variables).

Parameters are compared as effective values: the export lists only parameters that were set explicitly, so an unset parameter uses its default, taken from the parameter catalog (`app/Support/SysCompare/Data/pso_parameters_reference.csv`, 736 rows from IFS). Rows that differ only because of a default are marked `Same (default)` and are not counted. Defaults can change between PSO versions and the shipped catalog is for **PSO 6.14**: the report warns, by environment name, whenever an environment is on a different release, because a `Same (default)` row could then be hiding a real difference. If the catalog cannot be loaded the comparison falls back to raw values and the report says so.

The golden tests use the four reference exports, which are not in the repo. Point `SYS_COMPARE_SAMPLES` at a folder holding `prod.xml`, `acc.xml`, `stg.xml` and `tst.xml`; the tests are skipped when they are not available.

## Base Data and datasets

Regions, skills, task types, slot usage rule sets, appointment templates and SLA types are stored per **dataset**
(an environment can hold several), in one table, and are what the request-building pages offer in their
selects. Open a dataset (Setup > Datasets, or from its environment) and press **Sync from PSO** to pull
them from the PSO gateway through pso-services (`GET /api/v2/dataset/reference-values`). The gateway returns
the whole dataset body, so a sync takes up to a few minutes on a large dataset and runs on the queue; a queue
worker must be running. Rows you edit keep your values on later syncs (badge "Edited"; "Reset to PSO" hands
them back). Rows PSO stops returning are flagged "Missing from PSO" and are never deleted automatically.
On the dataset page they are all under one **Reference values** tab with a type switch above the table
(Regions first); each type also has its own page under Setup. Customers and their tasks belong to a
dataset too, and only see that dataset's regions and task types.

Task type rows carry priority, base value and base duration (minutes) as fallbacks for tasks that do not set
their own, following PSO's precedence: Activity_Status, then Activity, then Activity_Type. Slot usage choices
are `Slot_Usage_Rule_Set` ids, which is what an appointment request carries.

Tests run on an in-memory SQLite database (`phpunit.xml`), so they never touch the `.env` database.
`REFERENCE_VALUES_TIMEOUT` (default 600 s) bounds the call to pso-services.

## Gateway Upload and the webhook broadcast

An environment's **Gateway Upload** tab sends a schedule data file to the PSO gateway on the queue (a queue worker must be running). Before sending, the job can change the dataset ID in the file (an override field; blank leaves it alone) and inject a Broadcast that points PSO's plan output at a webhook endpoint, so the plan PSO sends back can be inspected. Turn on "Skip webhook broadcast" to send the file without the webhook. "Remove existing Broadcast rows" is on by default and removes any Broadcast and Broadcast_Parameter rows already in the file; it is always on while the webhook Broadcast is added, and can be turned off only when the webhook is skipped, which shows a warning to check that the kept rows do not point at production. Every logon goes through pso-services, which refuses any base URL containing `prd`, `prod` or `pd`, so uploads, Test configuration and System Usage do not work for production-looking environments (agreed policy).

Order of a run: header, download, webhook endpoint provisioned (needs its URL first), one streaming rewrite of the file, summary, logon through pso-services, gzip, POST to the gateway, logoff (always, even after a failed POST). Every step is written to a run log shown live on the tab and kept per run.

Configuration (`.env`):

- `WEBHOOK_BASE_URL`: the self-hosted webhook.site (default `https://webhook.thetechnodro.me`).
- `WEBHOOK_ALIAS_KEY`: the webhook server's `ALIAS_API_KEY`. With it each environment gets a stable alias; without it every run gets a fresh one-off token.
- `PSO_SESSION_TIMEOUT`: seconds allowed for a session logon or logoff through pso-services (default 20).

Queue note: the job can run for several minutes, so the queue `retry_after` defaults to 960 seconds. On a server, `DB_QUEUE_RETRY_AFTER` must be greater than 900. The job sets its own 900 second timeout, which wins over the worker's `--timeout`.

## Exports and tool runs

LOAD, plan, end-of-day and audit exports are uploaded on a dataset's **Exports** tab; SYS exports on an
environment's. Files go straight from the browser to the private R2 bucket with a short-lived signed URL
(no file passes through PHP), are summarised on the queue, and are **deleted 12 hours after upload**
(`EXPORTS_TTL_HOURS`). The **Runs** tab starts a tool against the newest set for a day; results are kept in
the database, encrypted with the app key, and rendered on the run page. A queue worker must be running.

R2 bucket setup: add a CORS rule allowing the app origin with methods `PUT` and `GET`, headers
`Content-Type`, and `MaxAgeSeconds` 3600, or uploads fail in the browser with a CORS error.
The scheduler must run (`php artisan schedule:run` every minute) for `exports:purge`.

Per-dataset settings (display names, coverage, exclusions, labels, time zone) live on the dataset's edit
form. Seed one from a pso-tools account file with
`php artisan datasets:seed-settings <dataset id> accounts/SATE.json --time-zone=America/Regina`.

### Load Health Audit

The audit is the Python script in the private `pso-tools` repo, run as a child process; nothing is ported.
The server needs Python 3 (3.13 on the server) and a checkout of that repo:

1. Raise the container to 1 GB of RAM before shipping. The Python peaks at about 146 MB on top of the PHP
   worker, and a memory kill shows only as a "stopped while ..." failure on the run.
2. As `deploy`: `git clone <read-only deploy URL for outofjam/pso-tools> /var/www/pso-tools` (using a
   read-only deploy key added to the repo in GitHub).
3. In `.env`: `TOOLS_PSO_TOOLS_PATH=/var/www/pso-tools` (and `TOOLS_PYTHON` if `python3` is not on the worker's PATH).
4. `php artisan migrate --force --path=database/migrations/2026_10_11_000004_add_progress_and_summary_to_tool_runs_table.php`
   (`--path` because of the production `.env` placeholder issue), then `php artisan optimize:clear`,
   `php artisan optimize`, and restart the queue worker.
5. To update the audit later: `git pull` in `/var/www/pso-tools`. The app needs no redeploy.

A run shows live progress (reading the LOAD, the plan, the SYS file, running the checks) and finishes with the
audit's own page in a sandboxed frame, plus downloads of that page and the findings JSON. Run options: the IFS
Cloud release (not in any PSO file) and how long finished work is kept (24 hours by default). The SYS file comes
from the dataset's environment. If the server is not set up, a run fails with a message naming what is missing.

### Region availability

Which regions each technician can work on each day of a week, from a LOAD, run as a child process from the
same pso-tools checkout (nothing is ported). Start it on a dataset's Runs tab with "Run Region availability";
"Week of" is any date in the week to show (blank: the LOAD's own week; a weekend LOAD shows the next week).
One run is one week. The page has three layers: the week grid (technicians down, days across, shift bar and
region change chips, filters for district, supervisor and resource type, search by id or name), the one-day
view (click a cell: the shift with breaks and every region window with multiplier and priority) and the
region pivot (regions by parent, technician counts per day with thresholds, click for names). The
per-technician view is the search box plus a click on a day.

The dataset's settings are handed to the script as its account file, so the district and supervisor labels,
excluded resource types, resource type names and pivot thresholds come from the dataset's edit form, not from
the checkout. Downloads: the page and the gathered JSON. Timeout: `TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS`
(600 by default).

This replaces the Technician Availability page (JSON upload, one technician's shifts), removed 2026-10-11.

### Trace an activity

What happened to one activity: how it was booked and how PSO scheduled it. Inputs: the **Audit messages** zip
(required), the LOAD (optional; adds the slot usage rule check to the Booking view) and the **IFS event log**
(optional; an .xlsx or .csv export, paired with the audit's scheduling events). Enter an activity id, or a work
task id if that is all you have. The report has two views: **Booking** (requests, offers, response, the calendar
of offered slots) and **History** (inputs, plan changes, window and shift checks, planned versus actual), with a
switch for the time zone. Downloads: the page, the JSON, `booking.csv` and `history.csv`. Like the audit, it is
Python from the pso-tools checkout (`activity-trace/trace_activity.py`, found through `TOOLS_PSO_TOOLS_PATH`),
run fresh each time. The dataset's settings add an **IFS event log time zone** (`ifs_time_zone`), the zone the event log
times are written in; blank means the dataset's own zone.

### Plan travels

How long are the planned travels in a plan export, and do they match real road times? Input: a **Plan** export
(required). Options: the first date (filled in from the export's own day) and how many days (1 to 31), and
**Check against Google Routes** (off by default), which shows the travel mode, routing preference and the review
thresholds (20 minutes, and a percent that is 0 for minutes only). The legs are built by Python from the pso-tools
checkout (`plan-travels/plan_travels.py`, found through `TOOLS_PSO_TOOLS_PATH`). With Google on, every distinct trip
goes to pso-services `POST /api/v2/routes/compute` in chunks of 200; pso-services holds the Google key and a shared
cache, so this app never sends a key and a trip already in the cache costs nothing. Trips pso-services reports as
"Not attempted" are retried once. If pso-services fails, the run fails with its message but still shows the legs
without Google columns. The page lists every leg with filters, a Google Maps link and **Check in PSO**, which opens
the Travel Analyzer in a new tab with the leg's coordinates filled in. Downloads: the CSV (the script's 30 columns,
plus 11 Google columns when Google ran; named `plan_travels_<account>_<dataset>_<first date>.csv`) and the JSON.
Timeouts: `TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS` (600, per script run) and `PSO_SERVICES_ROUTES_TIMEOUT` (300, per
chunk of 200 trips). The whole Google check also has an overall time limit, `TOOLS_PLAN_TRAVELS_GOOGLE_DEADLINE_SECONDS`
(720 by default, under the job's 900 s): when it runs out, the trips not yet sent are left without a Google answer and the run finishes with a warning saying how many were not checked. Running the tool again picks up the trips pso-services has cached since. If pso-services has `PSO_ROUTES_TOKEN` set,
set the same value here; it is optional and left blank when pso-services has none.

### Trim export

The trim export golden tests use real SaskTel exports, which are not in the repo. Point `PSO_DATA_DIR` at a folder holding `SATE/2026-10-04/load.zip` and `SATE/1005/north_travel_1005.xml`; the tests are skipped when they are not available.

## Deploying

Pushes to `main` deploy themselves: a cron on the server runs `deploy/pull-deploy.sh` every 2 minutes, which
resets each checkout to `origin/main` and runs composer, migrate, optimize and a worker restart, with a
Pushover message per deploy. Install steps and the failure rules are in `deploy/README.md`.
