# Gateway upload webhook broadcast: execution notes

Plan: `docs/superpowers/plans/2026-10-11-gateway-upload-webhook-broadcast.md`. Spec: `docs/superpowers/specs/2026-10-11-gateway-upload-webhook-broadcast-design.md`. Branch `feat/gateway-upload-webhook-broadcast`, from main 872ede0.

## What was built, per task

1. Session logon and logoff through pso-services instead of PSO's own session endpoint: `cf077dd`, tests added in `41ff996`.
2. Run log table and webhook/response columns on the upload record: `012e5e2`.
3. Broadcast settings and injection value objects with the script's defaults: `78c9ead`.
4. Streaming JSON rewrite (dataset ID, strip and inject Broadcast): `a933db1`, fixed in `c0ba0be`.
5. Streaming XML rewrite and the rewriter factory: `89b0b95`.
6. Per-environment webhook alias provisioning: `4de7bed`, fixed in `24ea063`.
7. Summary extractor reads `ScheduleData` and streams JSON of any size: `f7ebe96`.
8. Upload job: webhook injection, dataset ID override, verbose run log, logoff in `finally`: `1eb2cfc`.
9. Gateway Upload tab: dataset ID override, skip-webhook toggle, broadcast settings, live log, summary card: `30353e6`.
10. Environment variables, spec step order, README, these notes: the commit that adds this file.

## Rulings made during the work

- The briefs were requirements, not patches: the codebase had moved since the plan was written, so each task re-read the current files first.
- Old Broadcast rows were first stripped only when a new one was injected (as the PowerShell does). Since 2026-10-15 the "Remove existing Broadcast rows" toggle (column `strip_existing_broadcast`, on by default) strips them whether or not the webhook Broadcast is injected; it is forced on when injecting and can only be turned off with the webhook skipped. A file with no recognised wrapper is copied unchanged, and the rewriter refuses to overwrite its source.
- The XML rewriter is a byte-level scanner rather than XMLReader/XMLWriter. It keeps the bytes as they were and is faster (100 MB in about 1.4 s).
- The webhook alias is built per character with a Unicode-aware match, so an accented environment name gives the same alias as the PowerShell script.
- Order of the job: webhook, rewrite, summary, logon, gzip, POST, logoff. Logon comes before gzip, as in the script; the spec and README were corrected to match in the final fix wave.
- Queue `retry_after` raised to 960 on all connections, because an upload can run for several minutes.
- The Environment global scope made the alias "pso-" in the queue (no logged-in user); the job now resolves the name without the scope.
- The gateway form is filled with its own defaults only, so the username and password ciphertext are not copied into browser state.
- Dataset ID override and broadcast ID must be one line of valid XML 1.0 characters; required tables match `\A[A-Za-z0-9_]+\z`.
- The log panel keeps polling for 30 seconds after a run finishes so the logoff lines written after the final status are not lost.
- Verified by inspection rather than a second review: Task 1 test-only fix, Task 6 fix round.

## Behaviour changes

- Production-looking PSO URLs are refused (policy confirmed by Ish): every logon now goes through pso-services, whose `DisallowProdUrl` rejects any base URL containing `prd`, `prod` or `pd`, so the gateway upload, Test configuration and System Usage no longer work for such environments, and the run log shows pso-services' "Production URLs are not allowed" reason.

## ExportSummariser behaviour changed (Task 7)

This was not "unchanged". JSON LOAD and EOD exports over 20 MB are now accepted and streamed. The "larger than 20 MB" message is therefore misleading for them. Plan-kind JSON over 20 MB is still rejected, because `hasPlanBlock` calls `json_decode` on the whole file. The 20 MB constant moved into `ExportSummariser` as a private constant.

## Deferred minors

- Streaming `hasPlanBlock` with `JsonTokenReader`, then dropping the 20 MB cap and its message.
- Duplicate single-column index on the run log table.
- `BroadcastSettings::fromArray` casts non-numeric quality to 0 silently (form validation catches it); an empty required tables array falls back to the default.
- XML scanner: a comment or processing instruction inside a DOCTYPE internal subset breaks the scan (should refuse a DOCTYPE with a subset); injected values are escaped without `rejectInvalid`; chunk-boundary tests miss CDATA, self-closing `dataset_id`, quoted `>` and elements spanning chunks; UTF-32 is not detected.
- ExportSummariser: no test for valid JSON with neither wrapper; numeric `dataset_id` is ignored.
- Job: test a throw between logon and POST; `environmentName()` is queried twice; confirm pso-services never echoes headers in `details`.
- Tab: lock `gatewayLogLines`; cache the current-upload lookup per request (about 9 identical queries per 2 s poll); `viewGatewayUpload` reads only 500 lines; `is_string` guard on `dataset_id_override`; refusal tests should `assertNotified`; explicit `<script>` escaping test.
- Pre-existing, not from this branch: `context_data`, `load_rota_data`, `system_usage_data` and `services_data` fill from `record->toArray()` and put the password ciphertext in browser state; EditEnvironment's "test configuration" logged on but never logged off (fixed in the final fix wave, as was System Usage).

## Deploy notes

- Set `WEBHOOK_ALIAS_KEY` in the production `.env` (the webhook server's `ALIAS_API_KEY`). Without it every run gets a one-off webhook token instead of a stable per-environment alias. `WEBHOOK_BASE_URL` and `PSO_SESSION_TIMEOUT` have defaults.
- Queue `retry_after` now defaults to 960. If the server sets `DB_QUEUE_RETRY_AFTER`, it must be greater than 900. The job's own `$timeout` (900) wins over the supervisor worker's `--timeout`, so that needs no change.
- When the 15 minute timeout fires, the worker kills the job outright: `finally` does not run, so the session is not logged off and the work directory is not removed (best-effort cleanup is impossible). The run is marked failed with "The upload did not finish within 15 minutes; check PSO before resending.", because the POST may already have landed.
- The two migrations (run log table, webhook/response columns) run automatically through the deploy cron.
- After deploy: `php artisan optimize:clear` and restart the queue worker.

## Browser check list (nothing here was checked in a browser)

1. Dataset ID override: enter a value, upload, and see the old and new dataset ID in the run log and in the summary card.
2. Skip-webhook toggle: switching it on hides the Broadcast settings section; the run then logs that the webhook was skipped.
3. Live log: during a real upload to a test environment, lines appear every couple of seconds, auto-scroll follows and stops when you scroll up, and the last lines (logoff) still arrive after the run ends.
4. Summary card: HTTP status, wire size, duration, rate, raw response, Internal ID, webhook endpoint with Copy, dashboard link, dataset in file versus sent.
5. "Test configuration" on an environment with bad settings: the notification shows the pso-services reason.
6. View page source and the Livewire state: no password, token or alias key anywhere on the page.
7. A history entry opens that run's log.
