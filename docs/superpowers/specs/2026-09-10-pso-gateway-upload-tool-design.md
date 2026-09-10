# Design: PSO RESTful Gateway Upload Tool ("Load from File")

**Date:** 2026-09-10
**Status:** Approved design, pending implementation plan

## 1. Origin and problem

Replaces `Send-PsoScheduleData.ps1`, a PowerShell script that authenticates to the
IFS PSO RESTful Gateway, gzip-compresses a large `dsScheduleData` JSON/XML
payload, and POSTs it to `/scheduling/data`. Proven in production: a 27.34 MB
file compressed to 1.09 MB (96% reduction) and uploaded successfully with a
200 + `InternalId` response in ~16 seconds.

Payloads are 10–100+ MB. Sending them through API clients like Bruno/Postman
is slow or hangs (client-side rendering overhead, not gateway latency). The
PowerShell fix works but isn't usable by non-technical client-side schedulers
and ops staff — it requires editing script internals and reading raw console
output. This feature gives them the same capability through the existing
Filament web UI: drop a file in, get a plain pass/fail result.

## 2. Placement

This is a new tab, **"Load from File"**, on the existing `EnvironmentTools`
Filament page (`app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php`),
added to the existing `Tabs::make('activity_tabs')` array alongside
`load_rota_tab`, `system_usage_tab`, and `services_tab`.

This page is already scoped to one `Environment` record (`$this->record`) and
already has an editable "Environment Properties" section
(`base_url`/`account_id`/`username`/`password`) that other tabs read via
`$get(...)` — `fetchSystemUsage()` does exactly this today. The new tab reuses
that same data. **No environment picker is needed** — this is not a
standalone page with its own environment `Select`.

A useful side effect of this placement: since uploads are persisted rows
(§3), the tab can show a simple history of past gateway uploads for this
environment, for free.

## 3. Data model

New table `pso_gateway_uploads`, following the same conventions as the
`environments` migration (UUID primary key, `foreignIdFor` to `users`):

```php
Schema::create('pso_gateway_uploads', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->foreignUuid('pso_environment_id')
        ->constrained('environments')
        ->cascadeOnDelete();

    $table->foreignIdFor(User::class, 'initiated_by_user_id')
        ->constrained('users')
        ->cascadeOnDelete();

    $table->string('original_filename');
    $table->unsignedBigInteger('file_size_bytes');
    $table->unsignedBigInteger('compressed_size_bytes')->nullable();

    $table->string('status')->default('queued'); // queued, compressing, uploading, succeeded, failed
    $table->string('internal_id')->nullable();
    $table->text('error_message')->nullable();

    $table->timestamp('queued_at')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();

    $table->timestamps();
});
```

Notes:
- No `organization_id` — this app is not multi-tenant. `Environment` scopes
  by `user_id` via a `UserOwnedModel` global scope, not by organization.
  `initiated_by_user_id` is enough for the audit trail this table exists to
  provide.
- `status` as a plain string column with an `App\Enums\PsoGatewayUploadStatus`
  backing enum for casting, matching this codebase's existing enum-based
  status pattern (see `InputMode`, `ProcessType`, etc. in `app/Enums`).

`PsoGatewayUpload` model: `belongsTo(Environment::class, 'pso_environment_id')`,
`belongsTo(User::class, 'initiated_by_user_id')`.

## 4. Auth

Reuses `PSOInteractionsTrait::authenticatePSO($base_url, $account_id, $username, $password)`
(`app/Traits/PSOInteractionsTrait.php:23`) as-is. This already does exactly
what's needed: POSTs to `{base_url}/IFSSchedulingRESTfulGateway/api/v1/scheduling/session`
and returns a `SessionToken`. No new auth flow, no new service class.

**OIDC client-credentials auth is explicitly out of scope for v1.** The
original PowerShell script's testing surfaced a caveat that some
Cloud-hosted (`*.ifs.cloud`) gateways require OIDC unless the PSO user has
`GatewayOpenIdAllowStandardAuthentication`. Nothing in this app currently
supports OIDC, and no existing `Environment` record needs it — every
environment in this tool uses username/password. Building OIDC now would mean
adding an `auth_mode` field to `Environment` and a second token-acquisition
flow for a mode this app has zero current users of. If a Cloud-hosted
environment that requires OIDC shows up later, this is the first thing to
revisit.

## 5. Upload flow

1. **UI**: On the "Load from File" tab, a `FileUpload` component (disk `r2`,
   private — matches the existing convention in `FilterLoadFile.php`) plus a
   submit action.
2. **On submit**: file is already on `r2` (Filament uploads it during form
   interaction). Create a `PsoGatewayUpload` row (`status = queued`,
   `pso_environment_id = $this->record->id`, `initiated_by_user_id = auth()->id()`),
   dispatch `SendPsoScheduleDataJob::dispatch($upload->id)`.
3. **Job** (`app/Jobs/SendPsoScheduleDataJob.php`):
   - `status → compressing`. Stream the file off `r2` in chunks, gzip via
     `zlib` streaming (`deflate_init`/`deflate_add` with `ZLIB_ENCODING_GZIP`)
     to a local temp file. Never hold the full raw file and its compressed
     copy in memory at once — this is the whole reason the PowerShell script
     needed a rewrite in the first place (Bruno/Postman choke on this at
     10–100+ MB).
   - Record `compressed_size_bytes`.
   - `status → uploading`. Get a token via `authenticatePSO()` using the
     environment properties passed into the job (captured from the tab's
     live form state at submit time, same fields `fetchSystemUsage` reads).
   - POST the compressed body to `{base_url}/scheduling/data` with
     `Content-Encoding: gzip` and the auth header, streaming the request body
     from the temp file handle rather than loading it into a string.
   - On 200: parse `InternalId`, `status → succeeded`, store `internal_id`.
   - On failure: `status → failed`, store a human-readable `error_message`
     (map known error responses — e.g. `AUTHENTICATION_FAILED`,
     `Invalid Parameters` — to plain language; fall back to a generic
     message for anything unmapped, never a raw stack trace).
   - `finally`: delete the local temp compressed file and the original file
     on `r2`. No delayed cleanup job needed (unlike `ProcessResourceFile`'s
     pattern) — nothing else references these files after the job completes.
4. **UI status**: the tab polls the `PsoGatewayUpload` row directly via
   `wire:poll` while a job is in flight. This table is already the
   persisted source of truth (unlike `ProcessResourceFile`, which has no DB
   row and relies on `HasScopedCache` for progress) — a separate Cache/jobId
   layer on top would just be two things to keep in sync for no benefit.
   States shown: Queued → Compressing… / Uploading… → Success (Internal ID)
   or Failed (plain-language reason, with a "view details" expansion for the
   full log entry).

## 6. Logging

Never log full credentials or full payload contents — metadata only (file
size, compressed size, environment, status, response code/status, `InternalId`
or mapped error message). This matches the existing
`PSOInteractionsTrait::redactSensitivePayload()` pattern already used for
`environment.password`/`environment.token`.

## 7. Server config (this build's responsibility, per your answer)

- `upload_max_filesize` / `post_max_size`: raised to 200M on this site's Herd
  php.ini, to comfortably clear the largest expected payload.
- `memory_limit`: raised only enough to cover the largest single chunk
  processed at once (not the full file size — streaming means this doesn't
  need to scale with payload size).
- Document the equivalent nginx `client_max_body_size` /
  Apache `LimitRequestBody` values for whatever serves this app outside of
  local Herd, since that's out of this session's reach to change directly.

## 8. Testing

Per this app's TDD convention (single-pass, no subagents for routine CRUD):

- Feature test: submitting the "Load from File" tab with a small fixture file
  dispatches the job and creates a `pso_gateway_uploads` row with
  `status = queued`.
- Job unit test: `Http::fake()` the Gateway endpoints (`/scheduling/session`
  POST, `/scheduling/data` POST); assert correct headers, correct field names
  (`accountId`/`userName`/`password`), correct status transitions on success
  and on each failure mode (auth failure, network error, non-200 data
  response).
- Streaming-gzip step tested in isolation with a moderately large fixture (a
  few MB is enough to prove no full-file memory load) — assert the
  implementation uses chunked reads/`deflate_add`, not `file_get_contents` +
  `gzencode`.
- No `auth_mode` branch test — dropped along with OIDC scope (§4).

## 9. Explicitly deferred (not this build)

- OIDC client-credentials auth (§4).
- Websocket/broadcast-pushed status updates — this app has no Echo set up;
  `wire:poll` matches its existing convention for long-running job status.
- Permission-gating by environment (e.g. "who can trigger a production PSO
  upload") — moot, `app/Rules/NoProdURL.php` already blocks creating any
  `Environment` whose name/base_url looks like production. This tool
  structurally cannot point at prod.
