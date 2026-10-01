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

Server prerequisites for uploads of up to 25 MB per file: PHP `upload_max_filesize` and `post_max_size` of at least 25M, and nginx `client_max_body_size` of at least 25m. Limits are configurable in `config/sys-compare.php` (`SYS_COMPARE_*` env variables).

The golden tests use the four reference exports, which are not in the repo. Point `SYS_COMPARE_SAMPLES` at a folder holding `prod.xml`, `acc.xml`, `stg.xml` and `tst.xml`; the tests are skipped when they are not available.
