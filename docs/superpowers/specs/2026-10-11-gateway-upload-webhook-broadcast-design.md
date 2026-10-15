# Design: webhook broadcast, dataset ID and verbose run log for the Gateway Upload tool

Date: 2026-10-11. Status: design approved in conversation; spec for review.

Extends the Gateway Upload tab (`2026-09-10-pso-gateway-upload-tool-design.md`). Read that first: the R2
upload, `PsoGatewayUpload`, `SendPsoScheduleDataJob`, the gzip and POST mechanics and the status panel are
reused and are not re-described here. This spec brings the tab to parity with the command line script
`pso-tools/scripts/Send-PsoScheduleData.ps1`, including the `maximum_wait` default added in
`outofjam/pso-tools@b59322c`. It also moves session login and logoff behind new pso-services endpoints
(section 4.9), so it spans two repos.

## 1. Problem

The PowerShell script walks through a send step by step: it lets you keep or change
`Input_Reference.dataset_id`, creates a webhook endpoint, injects a Broadcast pointing at it into a copy
of the file, sends that copy, and ends with the webhook URL and PSO's response body, logging every step as
it goes. The web tab does none of this. It gzips the file as uploaded, POSTs it, and shows only the
`InternalId` on success or a mapped error message on failure.

Goal: the same walk-through on the web, as verbose as the script, so a tester can send a load, watch the
log, and open the webhook dashboard to see the plan come back without configuring a Broadcast in PSO by
hand.

Success: for the same input file and settings, the web run sends a payload equivalent to the script's
`_withBroadcast` copy, the run log carries the same steps and facts as the script's output, and the
summary shows the webhook URL and PSO's raw response.

## 2. Findings that shape the design

- **The existing code never holds a whole file in memory.** Uploads go up to 200 MB with a 512M
  `memory_limit`. XML is read with `XMLReader`; JSON is decoded whole and capped at 20 MB
  (`ScheduleDataSummaryExtractor::MAX_JSON_PARSE_BYTES`), so above that the summary fields are empty. The
  script's `ConvertFrom-Json` whole-file approach does not port. Injection must stream both formats.
- **The script replaces, it does not append.** Before injecting it strips every existing `Broadcast` and
  `Broadcast_Parameter` row so the only broadcast PSO sees is the injected one, and logs how many it
  removed.
- **Two JSON formats.** 6.15+ wraps in `ScheduleData` with native types and always-array entities. Older
  instances (`JsonFormatVersion=Version 1`) wrap in `dsScheduleData`, use string values throughout (even
  booleans and numbers) and only array-wrap an entity that has more than one row. The Broadcast row must
  match the format it lands in. XML uses the root element's namespace for every injected element.
- **The summary extractor only knows `dsScheduleData`.** For a `ScheduleData` file the dataset ID, counts
  and datetime come back empty today. Fixed here, since the dataset ID now matters more.
- **Self-hosted webhook.site cannot store gzipped bodies.** The script omits the `compression` broadcast
  parameter for the self-hosted service and only sets `compression=gzip` for the public one.
- **Auth is split and has no logoff.** The script logs on, sends `X-IFS-Session`, and logs off. The web
  job logs on via `PSOInteractionsTrait::authenticatePSO()`, which calls the PSO gateway's
  `/scheduling/session` directly (duplicating `PSOAuthService::getToken()` in pso-services), sends the
  token in `apiKey`, and never logs off. Two other callers use the same method: `EditEnvironment`
  (credential check on save) and `EnvironmentTools` (line 754). pso-services has no session endpoints and
  no logoff anywhere in `app/`, `routes/` or `docs/`; its own per-request sessions also run to timeout.
- **PSO accepts both header names.** The Interface Guide (RESTful Gateway, Authentication, p. 34) says the
  session token may be given in the `X-IFS-Session` or the `apikey` header. `DELETE /scheduling/session`
  takes no parameters (p. 44) and `GET /scheduling/session` checks that a session is valid (p. 45).

## 3. Decisions made

- **Webhook source: self-hosted service with a per-environment alias, one-off token as fallback.** The
  alias is `pso-<environment>` (lowercased, non-alphanumerics to `-`), giving each environment one stable
  URL and dashboard. If no alias key is configured, a fresh one-off token is created on the same service
  with a warning in the log. The public webhook.site service and `-WebhookUuid` reuse are out of scope.
- **Stream both formats.** XML with `XMLReader` to `XMLWriter`; JSON with a depth-aware raw token copy
  (section 4.2, in-house tokenizer, no new dependency). Memory use does not scale with file size.
- **Naming.** Section 4.3's value object is split in two for the form's sake: `BroadcastSettings` (the
  editable defaults) and `BroadcastInjection` (settings plus the provisioned webhook URL).
- **The original upload is never modified.** A sibling `<name>_withBroadcast.<ext>` copy is written into
  the job's work directory and sent instead, as in the script. The R2 object is deleted afterwards, as
  today.
- **Dataset ID is a form field, not a prompt.** A queued job cannot pause for input and parsing the file
  in the web request is what the job was built to avoid. The field is an override: blank keeps the file's
  value. The run log reports the current value and the change.
- **Webhook injection is on by default** with a "Skip webhook broadcast" toggle, matching the script.
- **Sessions go through pso-services, not straight to PSO.** pso-tools is built on pso-services, so
  login and logoff become pso-services endpoints (`POST /session`, `DELETE /session`) and
  `authenticatePSO()` delegates to them for all three of its callers, so there is one login path.
  This adds a second repo and a deploy order (section 4.9).
- **The 200 MB data POST stays direct to the PSO gateway**, using the token pso-services issued.
  pso-services' `/load` is a JSON array API with a 10 second timeout; streaming a gzipped payload through
  it would double the transfer and need new body-limit handling. An upload endpoint there is a later spec.
- **Provisioning or injection failure fails the run and sends nothing to PSO**, as the script throws.
- **The log is a child table, not a JSON column**, so the status panel can poll for lines after the last
  id and a 200 MB run's log never rewrites a growing blob.

## 4. Design

### 4.1 `WebhookEndpointProvisioner` (`app/Support/Webhook/`)

Given the environment name and content type (`application/json` or `application/xml`), returns
`{url, viewUrl, aliasName, aliasUuid, created, usedAlias}`.

- Token config body: `default_status 200`, `default_content` `{}` for JSON or `<response/>` for XML,
  `default_content_type` the payload's content type. Without it PSO reports an "invalid response object".
- **Alias path:** `POST {base}/_alias/{alias}` with header `X-Alias-Key`, body the token config. The
  response carries `uuid` and `created`. When the alias already existed, `PUT {base}/token/{uuid}` with
  the token config to keep the canned response in step with this run's format; a failure there is a
  logged warning, not a failure.
- **One-off path** (no alias key configured): `POST {base}/token` with the token config.
- `url` is `{base}/{alias}` for an alias, `{base}/{uuid}` for a one-off. `viewUrl` is `{base}/#!/{uuid}`.
- Config: `config/services.php` key `webhook` with `base_url` (default `https://webhook.thetechnodro.me`)
  and `alias_key`, from `WEBHOOK_BASE_URL` and `WEBHOOK_ALIAS_KEY`. The alias key is never logged,
  stored on the row, or sent to the browser.
- Uses the `Http` facade with explicit timeouts so tests use `Http::fake()`.

### 4.2 `ScheduleDataRewriter` (`app/Support/ScheduleData/`)

One interface, two implementations (`XmlScheduleDataRewriter`, `JsonScheduleDataRewriter`), chosen by the
extension of the extracted file. Input: source path, destination path, `?string $datasetIdOverride`,
`?BroadcastInjection $injection`. Output: a `RewriteReport` with the distinct dataset IDs found, whether
they changed, the Broadcast and Broadcast_Parameter rows stripped, the format detected (`xml`, JSON
`ScheduleData`, JSON `dsScheduleData`) and the bytes written. Single pass, constant memory.

- **Dataset ID:** every `dataset_id` inside every `Input_Reference` row is replaced when an override is
  given (the script changes every row). When the override equals the single existing value it is a no-op
  and the report says "kept".
- **Strip:** every top-level `Broadcast` and `Broadcast_Parameter` entity is dropped (counted first).
- **Inject:** one `Broadcast` and the `Broadcast_Parameter` rows for `url`, `mediatype`, `httpmethod`
  (`PATCH`), `etag` (`*`), `required_tables` (comma-joined) and, for a non-public webhook service, no
  `compression` row. Fields and types are exactly the script's:
  - XML: elements in the root namespace, values as strings.
  - `ScheduleData` JSON: native types (`active` and `once_only` booleans, quality, interval and
    allocation as numbers), `Broadcast` in an array.
  - `dsScheduleData` JSON: every value a string, `Broadcast` a single object.
- **JSON mechanics:** the file is walked token by token, tracking depth and string state, so bytes of
  untouched entities are copied verbatim (no decode and re-encode, no numeric or key-order drift). Within
  the wrapper object: skip `Broadcast`/`Broadcast_Parameter` subtrees, rewrite the string value of
  `dataset_id` under `Input_Reference`, and write the new entities before the wrapper's closing brace.
  The implementation is a small in-house tokenizer (`JsonTokenReader`) that yields raw tokens (structural
  characters, strings, literals, whitespace runs) from a file handle in 64 KB chunks. Decided at plan
  time: `json-machine` has no docs available to confirm a raw-token API, and a verbatim copy is the
  contract, so there is no new composer dependency.
- A file with neither a `ScheduleData` nor a `dsScheduleData` wrapper (JSON) or a non-ScheduleData root
  (XML) is a run failure with the script's message.

### 4.3 `BroadcastInjection` and settings

A value object holding `broadcastId` (`WEBHOOK_COMPLETE`), `planType` (`COMPLETE`), `minimumPlanQuality`
(85), `maximumWait` (`PT5M`), `allocationType` (1), `requiredTables`, plus the fixed REST/PATCH/once-only
values. `requiredTables` defaults to `Allocation, Plan, Plan_Deletion, Appointment_Offer,
Appointment_Summary, Suggested_Dispatch, Schedule_Exception, Schedule_Exception_Data`. Defaults live in one
place (`config/psott.php` under `gateway_upload.broadcast`) so changing them is a config edit, as editing
the array is in the script. `maximumWait` is validated as an ISO 8601 duration.

### 4.4 `UploadRunLog`

`record(level, text)` writes a row to `pso_gateway_upload_log_lines`
(`id`, `pso_gateway_upload_id`, `level` of `step|sub|warn|error|ok`, `text`, `created_at` with
milliseconds). `step` and `sub` mirror `Write-Step` and `Write-SubStep`. Nothing sensitive is passed to
it: the password, the session token and the alias key are never formatted into a line, and a test scans a
full run's log for them.

### 4.5 Job flow (`SendPsoScheduleDataJob`)

Existing steps keep their order; new ones are marked. Each logs what the script logs.

1. Header: environment, gateway base URL, account, user, file, content type, gzip (script's banner).
2. Download from R2, unzip if needed; log file size.
3. **Webhook (new, unless skipped):** provision the endpoint (alias created or found, endpoint, dashboard,
   canned response). It comes first because the rewrite needs its URL.
4. **Rewrite (new):** one streaming pass; log the dataset ID (current value and any change), rows stripped
   (`No existing Broadcast ... nothing to strip` when none), the injected broadcast and its required tables,
   and the modified copy's name and size.
5. Summary extraction (now run on the rewritten file) and gzip; log sizes and ratio.
6. Authenticate through pso-services (4.9); log success (token length only) or the failure with HTTP
   status and body.
7. POST; log HTTP status, wire bytes, duration, effective MB/s and the raw response body. On a non-2xx
   response, log the status and raw body before the mapped error, as the script does.
8. **Logoff (new):** through pso-services `DELETE /session` (4.9) with the session token; failure is a
   warning. It runs in a `finally` so a failed POST still logs off, as the script does.
9. Finish: total duration; the webhook view URL again (script's closing line).

### 4.6 Form and status panel

- **Form** (`gatewayUploadForm`): `Dataset ID override` (text, optional, helper "Leave blank to keep the
  file's value"), `Skip webhook broadcast` (toggle, off), and a collapsed `Broadcast settings` section with
  broadcast ID, plan type (`BroadcastPlanType`), minimum plan quality (0 to 100), maximum wait (ISO 8601,
  `PT5M`), allocation type (`BroadcastAllocationType`) and required tables, pre-filled from the defaults.
  The section is hidden while the toggle is on.
- **Settings are stored on the row** (`dataset_id_override`, `skip_webhook`, `broadcast_settings` JSON)
  so the job is dispatched with only the row id, as today, and a run can be reconstructed afterwards.
- **Status panel** (`gateway-upload-status.blade.php`): a log pane that polls every 2 seconds for lines
  after the last seen id, coloured by level and auto-scrolling, stopping at a terminal status. Under it a
  summary card: HTTP status, wire size, duration, rate, raw response body in a code block, Internal ID,
  webhook endpoint URL (copyable), dashboard link, and the dataset ID change. The history list keeps its
  current rows and links each to its stored log.

### 4.7 Data model

Migration adds to `pso_gateway_uploads`: `dataset_id_override` string nullable, `skip_webhook` boolean
default false, `broadcast_settings` json nullable, `response_status` unsigned small integer nullable,
`response_body` text nullable (truncated at 64 KB), `webhook_url` and `webhook_view_url` string nullable,
`wire_bytes` unsigned big integer nullable, `post_duration_ms` unsigned integer nullable. New table
`pso_gateway_upload_log_lines` as in 4.4, cascading on delete, indexed on
(`pso_gateway_upload_id`, `id`). Log lines are pruned with the upload row.

### 4.8 `ScheduleDataSummaryExtractor` fix

Recognise the `ScheduleData` wrapper as well as `dsScheduleData`, and replace the whole-file
`json_decode` and 20 MB cap with the same streaming reader, so JSON of any size yields the dataset ID,
datetime and counts. It still never blocks or alters an upload.

### 4.9 Sessions through pso-services

**pso-services** (`/Users/idawoodjee/Herd/pso-services`, its own spec and branch):
- `POST /session` returns a session token. Credentials arrive as the headers
  `/dataset/reference-values` already takes (`baseUrl`, `accountId`, `username`, `password`), and the
  controller wraps `PSOAuthService::getToken()`.
- `DELETE /session` takes the same `baseUrl` header plus the session token and calls the gateway's
  `DELETE /scheduling/session` with `X-IFS-Session` (the header the Interface Guide's own examples use).
  It returns the gateway's raw result, and maps failures with the existing `HttpErrorMapper`.
- Both are routed in `routes/api_v2.php`, documented through the existing Scramble `#[Group]` attributes
  and `api.json`, and covered by feature tests. Optional, same change if cheap: `GET /session` backing
  `PSOAuthService::validateToken()`, which is a placeholder returning `true` today. Not required here.

**pso-test-tools:**
- `PsoServicesSessionClient` (`app/Support/PsoServices/`), same shape as `ReferenceValuesClient`:
  `logon(...)` and `logoff(...)`, URL built from `psott.pso-services-api` and its version, explicit
  timeouts, and a typed exception carrying the status and the services error text.
- `PSOInteractionsTrait::authenticatePSO()` keeps its signature and `$error_value` contract (401 for bad
  credentials, 500 for anything else including an unreachable services API) and now delegates to the
  client, so the job, `EditEnvironment` and `EnvironmentTools` all move together. A new `logoffPSO()` sits
  beside it.
- The token is then used against the gateway for the data POST with the existing `apiKey` header, which
  PSO accepts in place of `X-IFS-Session`.

**Order:** pso-services ships first. Until it does, the tab cannot log on; there is deliberately no
fallback to calling PSO directly, because two login paths is what this removes.

## 5. Failure handling

| Failure | Behaviour |
|---|---|
| Webhook service unreachable, alias key rejected, bad token response | Run fails, nothing sent to PSO, log shows URL, HTTP status and message |
| No alias key configured | Warning, one-off token created, run continues |
| Unrecognised payload wrapper or root | Run fails with the script's message; suggests "Skip webhook broadcast" |
| Rewriter I/O error | Run fails, work directory cleaned, R2 object still deleted |
| Gateway non-2xx | Raw status and body logged, then mapped message (existing `mapGatewayError`) |
| pso-services unreachable or rejects logon | Run fails before anything is sent; log shows the services status and error text; bad credentials stay distinguishable (401) |
| Logoff fails | Warning only |
| Worker killed or timed out | Existing `failed()` hook, log keeps the lines written so far |

## 6. Testing (Pest)

- **Rewriter, XML and both JSON formats**, from fixtures: injects the exact rows and types; strips
  existing broadcast rows and reports counts; dataset ID override across multiple `Input_Reference` rows;
  no-op when unchanged; untouched entities byte-identical; unknown wrapper fails.
- **Memory:** a generated multi-megabyte fixture for each format, asserting peak memory growth is bounded
  and independent of the file size (two sizes compared).
- **Provisioner** with `Http::fake`: alias created, alias existing (PUT refresh, PUT failure tolerated),
  one-off fallback, service error.
- **Job** with `Http::fake` for webhook and gateway and the fake R2 disk: log lines in order, summary
  columns filled, skip-webhook path sends the original bytes, webhook failure sends nothing, no secret in
  any log line, logoff failure is non-fatal.
- **Extractor:** `ScheduleData` wrapper and a file larger than the old 20 MB cap.
- **Sessions:** `PsoServicesSessionClient` with `Http::fake` (success, 401, 5xx, unreachable);
  `authenticatePSO()` keeps its `$error_value` mapping for all three callers; existing tests that fake the
  gateway's session URL are repointed at the services URL. In pso-services: feature tests for
  `POST /session` and `DELETE /session` against a faked gateway.
- **Livewire:** form defaults and validation (bad ISO duration, quality out of range), settings stored on
  the row, status panel renders log lines, summary and the webhook link.
- The existing Gateway Upload tests keep passing; any that assumed the payload is sent unchanged are
  updated to use the skip toggle.

## 7. Operations

- Add `WEBHOOK_BASE_URL` and `WEBHOOK_ALIAS_KEY` to the production `.env` (the key is the webhook
  server's `ALIAS_API_KEY`) and to `.env.example` without a value. Without the key the tool still works
  using one-off tokens.
- No new composer dependency and no new infrastructure; the self-hosted webhook service already exists.
- Deploy pso-services (session endpoints) before pso-test-tools. `PSO_SERVICES_API` must already be
  reachable from the queue worker; the reference-values job relies on the same.

## 8. Out of scope

- Public webhook.site support, reusing an arbitrary existing token, `-OpenWebhook`.
- Showing the plan the webhook receives inside the app; the dashboard link is the hand-off.
- Per-dataset or per-user saved broadcast defaults beyond the config file.
- Changing the CLI script.
- A pso-services endpoint for the bulk gzipped data upload (section 3).
- OpenID / IFS Cloud bearer authentication (Interface Guide p. 34). The session endpoints are where it
  would be added later, in one place.
- Making pso-services itself log off the per-request sessions it opens.
