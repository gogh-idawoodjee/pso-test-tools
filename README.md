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

The golden tests use the four reference exports, which are not in the repo. Point `SYS_COMPARE_SAMPLES` at a folder holding `prod.xml`, `acc.xml`, `stg.xml` and `tst.xml`; the tests are skipped when they are not available.
