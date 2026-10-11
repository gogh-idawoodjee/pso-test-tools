# PSO Test Tools

Eventually a suite of web based tools to simply sending test transactions to PSO.

Environment Variables Required
- GOOGLE_MAPS_GEOCODING_API_KEY - required for geocoding
- PSO_SERVICES_API - the PSO Services API base url; only the FQDN, exclude the https:// portion

## PSO Sys File Compare

Compares two or more PSO system data exports (`DsSystemData` XML) and produces an HTML report, a CSV bundle and an Excel workbook, with a banner when the environments are not all on the same PSO version. Find it under **Additional Tools**.

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
selects. Open a dataset (Base Data > Datasets, or from its environment) and press **Sync from PSO** to pull
them from the PSO gateway through pso-services (`GET /api/v2/dataset/reference-values`). The gateway returns
the whole dataset body, so a sync takes up to a few minutes on a large dataset and runs on the queue; a queue
worker must be running. Rows you edit keep your values on later syncs (badge "Edited"; "Reset to PSO" hands
them back). Rows PSO stops returning are flagged "Missing from PSO" and are never deleted automatically.
Customers and their tasks belong to a dataset too, and only see that dataset's regions and task types.

Task type rows carry priority, base value and base duration (minutes) as fallbacks for tasks that do not set
their own, following PSO's precedence: Activity_Status, then Activity, then Activity_Type. Slot usage choices
are `Slot_Usage_Rule_Set` ids, which is what an appointment request carries.

Tests run on an in-memory SQLite database (`phpunit.xml`), so they never touch the `.env` database.
`REFERENCE_VALUES_TIMEOUT` (default 600 s) bounds the call to pso-services.

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

### Load and configuration health audit

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

## Deploying

Pushes to `main` deploy themselves: a cron on the server runs `deploy/pull-deploy.sh` every 2 minutes, which
resets each checkout to `origin/main` and runs composer, migrate, optimize and a worker restart, with a
Pushover message per deploy. Install steps and the failure rules are in `deploy/README.md`.
