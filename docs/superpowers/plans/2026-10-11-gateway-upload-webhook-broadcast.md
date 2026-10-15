# Gateway Upload: Webhook Broadcast, Dataset ID and Verbose Run Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the Gateway Upload tab to parity with `Send-PsoScheduleData.ps1`: optional dataset ID override, an auto-injected webhook Broadcast (with `maximum_wait`), a step-by-step run log, and the webhook URL plus PSO's raw response at the end, with session login and logoff going through pso-services.

**Architecture:** The queued `SendPsoScheduleDataJob` gains steps: provision a webhook endpoint, stream-rewrite the file (dataset ID, strip old broadcast rows, inject the new Broadcast) into a modified copy, log on and off through pso-services, and write every step to a log-line table the status panel polls. All streaming is constant-memory: an in-house JSON tokenizer and `XMLReader`/`XMLWriter`.

**Tech Stack:** Laravel 13, Livewire 4, Filament v5, Pest, PHP 8.5, MySQL; pso-services (`POST/DELETE /api/v2/session`).

**Spec:** `docs/superpowers/specs/2026-10-11-gateway-upload-webhook-broadcast-design.md` (read it with this plan). The pso-services half is `~/Herd/pso-services/docs/superpowers/plans/2026-10-11-session-endpoints.md`; that plan ships first.

## Global Constraints

- Naming and style (CLAUDE.md): no abbreviated variable names (`$userRecord` not `$ur`); `app()` over `new` for Filament/Laravel classes; never `final` or `readonly` on classes that extend Filament; `static fn` for closures that do not reference `$this`; constructor property promotion always; explicit return types and type hints on all methods.
- Filament namespaces: form fields `Filament\Forms\Components\`, layout `Filament\Schemas\Components\`, `Get`/`Set` in `Filament\Schemas\Components\Utilities\`, actions `Filament\Actions\`.
- Broadcast defaults (verbatim from the script): id `WEBHOOK_COMPLETE`, plan type `COMPLETE`, minimum plan quality `85`, maximum wait `PT5M`, allocation type `1`, minimum step interval `1`, once only `true`, broadcast type `REST`, HTTP method `PATCH`, etag `*`, no `compression` parameter (self-hosted service).
- Required tables default: `Allocation, Plan, Plan_Deletion, Appointment_Offer, Appointment_Summary, Suggested_Dispatch, Schedule_Exception, Schedule_Exception_Data`.
- Webhook alias is `pso-` plus the lowercased environment name with non-`[a-z0-9-]` replaced by `-`; default base URL `https://webhook.thetechnodro.me`; alias key header `X-Alias-Key`; canned response `{}` for JSON and `<response/>` for XML, status 200.
- The original upload is never modified; a modified copy is sent. The password, session token and alias key never appear in a log line, a row column or the browser.
- Webhook provisioning or rewrite failure fails the run and sends nothing to PSO. Logoff failure is a warning only.
- JSON entities the rewriter does not touch are copied byte for byte (no decode and re-encode).
- Do not open the app in a browser or curl it; the user verifies UI changes themselves. Run Pint only on the files you touched (`vendor/bin/pint <file> ...`), never on `app/`, `config/` or `routes/` as a whole.
- Work on a branch: `git switch -c feat/gateway-upload-webhook-broadcast` before Task 1.

## Review Focus

- **A string value that contains `"Broadcast":` text, and a `Broadcast` key nested deeper than the wrapper's members:** must not be stripped. Pinned in Task 4.
- **Dataset ID override containing `"`, `\`, `&` or `<`:** the output must still parse and decode to the exact string. Pinned in Tasks 4 and 5.
- **A file with a UTF-8 BOM, CRLF line endings, or minified on one line:** must rewrite and still parse. Pinned in Task 4.
- **The POST fails or throws after logon:** the session must still be logged off, and a failing logoff must not fail an otherwise good run. Pinned in Task 8.
- **pso-services rejects the logon (422 for a production-looking base URL, 401, 504):** the run log must show the reason, not only "Could not authenticate". Pinned in Tasks 1 and 8.
- **A 100 MB file:** the rewrite plus gzip plus POST must fit the job timeout. Measured in Task 8.

## File Structure

| File | Responsibility |
|---|---|
| `app/Support/PsoServices/PsoServicesSessionClient.php` | Logon and logoff through pso-services |
| `app/Support/PsoServices/PsoServicesSessionException.php` | Carries the HTTP status and message |
| `app/Traits/PSOInteractionsTrait.php` (modify) | `authenticatePSO()` delegates; new `logoffPSO()` |
| `app/Enums/GatewayUploadLogLevel.php` | `step/sub/warn/error/ok` |
| `app/Models/PsoGatewayUploadLogLine.php` | One log row |
| `app/Support/GatewayUpload/UploadRunLog.php` | Writes log rows for one upload |
| `app/Support/ScheduleData/BroadcastSettings.php` | Editable broadcast defaults |
| `app/Support/ScheduleData/BroadcastInjection.php` | Settings plus webhook URL; builds rows |
| `app/Support/ScheduleData/JsonTokenReader.php` | Chunked raw JSON tokenizer |
| `app/Support/ScheduleData/ScheduleDataRewriter.php` | Interface |
| `app/Support/ScheduleData/JsonScheduleDataRewriter.php` | Streaming JSON rewrite |
| `app/Support/ScheduleData/XmlScheduleDataRewriter.php` | Streaming XML rewrite |
| `app/Support/ScheduleData/ScheduleDataRewriterFactory.php` | Picks by format |
| `app/Support/ScheduleData/RewriteReport.php` | What a rewrite did |
| `app/Support/Webhook/WebhookEndpointProvisioner.php` | Alias or one-off webhook |
| `app/Support/Webhook/WebhookEndpoint.php`, `WebhookProvisioningException.php` | Result and failure |
| `app/Support/ScheduleDataSummaryExtractor.php` (modify) | `ScheduleData` wrapper, streaming JSON |
| `app/Jobs/SendPsoScheduleDataJob.php` (modify) | The new flow |
| `app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php` (modify) | Form fields, submit, log polling |
| `resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php` (modify) | Log pane and summary |

---

### Task 1: Session login and logoff through pso-services

**Files:**
- Create: `app/Support/PsoServices/PsoServicesSessionClient.php`
- Create: `app/Support/PsoServices/PsoServicesSessionException.php`
- Create: `tests/Support/PsoServicesFake.php`
- Modify: `app/Traits/PSOInteractionsTrait.php` (`authenticatePSO()` lines 23-90, add `logoffPSO()`)
- Modify: `config/psott.php`
- Modify: `tests/Feature/SendPsoScheduleDataJobTest.php`
- Test: `tests/Feature/PsoServicesSessionClientTest.php`

**Interfaces:**
- Consumes: pso-services `POST {base}/session` (headers `baseUrl`, `accountId`, `username`, `password`; body reply `message.SessionToken`) and `DELETE {base}/session` (headers `baseUrl`, `token`), where `{base}` is `https://{psott.pso-services-api}/api/{version/}`.
- Produces: `PsoServicesSessionClient::logon(string $baseUrl, string $accountId, string $username, string $password): string` (throws `PsoServicesSessionException`); `::logoff(string $baseUrl, string $sessionToken): void` (throws the same); `PsoServicesSessionException::$statusCode` (int, 0 when unreachable); trait `authenticatePSO(...) : ?string` unchanged signature and `$error_value` contract (401 bad credentials, 500 anything else); new trait `logoffPSO(string $base_url, string $session_token): bool`; new trait property `protected ?string $session_error_message`; test helper `Tests\Support\PsoServicesFake::session(string $token = 'tok-abc', int $logonStatus = 200, int $logoffStatus = 200): array` for spreading into `Http::fake([...])`.

- [ ] **Step 1: Write the failing client tests**

Create `tests/Feature/PsoServicesSessionClientTest.php`:

```php
<?php

use App\Support\PsoServices\PsoServicesSessionClient;
use App\Support\PsoServices\PsoServicesSessionException;
use App\Traits\PSOInteractionsTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PsoServicesFake;

it('logs on through pso-services and returns the session token', function () {
    Http::fake(PsoServicesFake::session('fresh-token'));

    $token = app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'test-user', 'secret-password');

    expect($token)->toBe('fresh-token');

    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('baseUrl', 'https://example.test')
        && $request->hasHeader('accountId', 'acc-1')
        && $request->hasHeader('username', 'test-user')
        && $request->hasHeader('password', 'secret-password'));
});

it('logs off through pso-services with the token header', function () {
    Http::fake(PsoServicesFake::session());

    app(PsoServicesSessionClient::class)->logoff('https://example.test', 'tok-abc');

    Http::assertSent(static fn ($request) => $request->method() === 'DELETE'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('token', 'tok-abc')
        && $request->hasHeader('baseUrl', 'https://example.test'));
});

it('throws with the gateway error text and status on a 401 logon', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response(['error' => 'Unauthorized. Please check your session or login credentials.', 'details' => null], 401)]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'bad-user', 'bad-password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(401);
        expect($exception->getMessage())->toContain('Unauthorized');
    }
});

it('surfaces the first validation error when pso-services answers 422', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response([
        'message' => 'Production URLs are not allowed',
        'errors' => ['baseUrl' => ['Production URLs are not allowed']],
    ], 422)]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://prod.example.test', 'acc-1', 'user', 'password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(422);
        expect($exception->getMessage())->toContain('Production URLs are not allowed');
    }
});

it('throws a status-zero exception when pso-services cannot be reached', function () {
    Http::fake([PsoServicesFake::sessionUrl() => static function () {
        throw new ConnectionException('Connection timed out');
    }]);

    try {
        app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'user', 'password');
        $this->fail('Expected a PsoServicesSessionException');
    } catch (PsoServicesSessionException $exception) {
        expect($exception->statusCode)->toBe(0);
        expect($exception->getMessage())->toContain('could not be reached');
    }
});

it('throws when the logon reply carries no session token', function () {
    Http::fake([PsoServicesFake::sessionUrl() => Http::response(['data' => 'Auth was successful.', 'message' => [], 'status' => 200], 200)]);

    expect(static fn () => app(PsoServicesSessionClient::class)->logon('https://example.test', 'acc-1', 'user', 'password'))
        ->toThrow(PsoServicesSessionException::class, 'without a session token');
});

it('keeps authenticatePSO() returning null with error_value 401 on bad credentials', function () {
    Http::fake(PsoServicesFake::session(logonStatus: 401));

    $probe = new class
    {
        use PSOInteractionsTrait;

        public function authenticate(): ?string
        {
            return $this->authenticatePSO('https://example.test', 'acc-1', 'user', 'password');
        }

        public function errorValue(): ?int
        {
            return $this->error_value;
        }

        public function errorMessage(): ?string
        {
            return $this->session_error_message;
        }
    };

    expect($probe->authenticate())->toBeNull();
    expect($probe->errorValue())->toBe(401);
    expect($probe->errorMessage())->not->toBeNull();
});

it('reports error_value 500 when pso-services is unreachable', function () {
    Http::fake([PsoServicesFake::sessionUrl() => static function () {
        throw new ConnectionException('Connection timed out');
    }]);

    $probe = new class
    {
        use PSOInteractionsTrait;

        public function authenticate(): ?string
        {
            return $this->authenticatePSO('https://example.test', 'acc-1', 'user', 'password');
        }

        public function errorValue(): ?int
        {
            return $this->error_value;
        }
    };

    expect($probe->authenticate())->toBeNull();
    expect($probe->errorValue())->toBe(500);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/PsoServicesSessionClientTest.php`
Expected: FAIL (class `Tests\Support\PsoServicesFake` not found).

- [ ] **Step 3: Add the test helper**

Create `tests/Support/PsoServicesFake.php`:

```php
<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the pso-services session endpoints for Http::fake(). POST is the
 * logon, DELETE is the logoff, both on the same URL.
 */
class PsoServicesFake
{
    public static function sessionUrl(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'session';
    }

    /**
     * @return array<string, callable>
     */
    public static function session(string $token = 'tok-abc', int $logonStatus = 200, int $logoffStatus = 200): array
    {
        return [
            self::sessionUrl() => static function (Request $request) use ($token, $logonStatus, $logoffStatus) {
                if ($request->method() === 'DELETE') {
                    return $logoffStatus === 200
                        ? Http::response(['data' => 'Logged off.', 'message' => 'Logged off.', 'status' => 200], 200)
                        : Http::response(['error' => 'Internal server error. Try again later.', 'details' => null], $logoffStatus);
                }

                return $logonStatus === 200
                    ? Http::response(['data' => 'Auth was successful.', 'message' => ['SessionToken' => $token], 'status' => 200], 200)
                    : Http::response(['error' => 'Unauthorized. Please check your session or login credentials.', 'details' => null], $logonStatus);
            },
        ];
    }
}
```

- [ ] **Step 4: Add the config key**

In `config/psott.php`, after the `'defaults'` array, add:

```php
    'session' => [
        // Logon goes pso-test-tools -> pso-services -> PSO, so it needs more than the 5s default.
        'timeout' => (int) env('PSO_SESSION_TIMEOUT', 20),
    ],
```

- [ ] **Step 5: Create the exception and client**

`app/Support/PsoServices/PsoServicesSessionException.php`:

```php
<?php

namespace App\Support\PsoServices;

use RuntimeException;
use Throwable;

class PsoServicesSessionException extends RuntimeException
{
    /**
     * @param  int  $statusCode  HTTP status from pso-services, 0 when it could not be reached
     */
    public function __construct(string $message, public int $statusCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`app/Support/PsoServices/PsoServicesSessionClient.php`:

```php
<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Logs on and off the PSO gateway through pso-services (POST and DELETE
 * /session) so this app has one login path. Kept apart from
 * PSOInteractionsTrait::sendToPSONew() because that helper wraps replies in
 * the payload envelope and raises notifications.
 */
class PsoServicesSessionClient
{
    /**
     * @throws PsoServicesSessionException
     */
    public function logon(string $baseUrl, string $accountId, string $username, #[SensitiveParameter] string $password): string
    {
        $response = $this->send('post', [
            'baseUrl' => $baseUrl,
            'accountId' => $accountId,
            'username' => $username,
            'password' => $password,
        ]);

        $token = $response->json('message.SessionToken');

        if (! is_string($token) || $token === '') {
            throw new PsoServicesSessionException('The PSO services API answered the logon without a session token.', $response->status());
        }

        return $token;
    }

    /**
     * @throws PsoServicesSessionException
     */
    public function logoff(string $baseUrl, #[SensitiveParameter] string $sessionToken): void
    {
        $this->send('delete', [
            'baseUrl' => $baseUrl,
            'token' => $sessionToken,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws PsoServicesSessionException
     */
    private function send(string $method, array $headers): Response
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('psott.session.timeout', 20))
                ->connectTimeout(10)
                ->withHeaders($headers)
                ->{$method}($url);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services session: the API could not be reached', [
                'url' => $url,
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);

            throw new PsoServicesSessionException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PsoServicesSessionException($this->errorMessage($response), $response->status());
        }

        return $response;
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'session';
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        $candidates = [
            data_get($body, 'error'),
            data_get($body, 'message'),
            data_get($body, 'details'),
            data_get($body, 'details.Message'),
        ];

        $validationErrors = data_get($body, 'errors');

        if (is_array($validationErrors)) {
            $candidates[] = collect($validationErrors)->flatten()->first();
        }

        $parts = array_values(array_unique(array_filter(
            $candidates,
            static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '',
        )));

        return $parts === []
            ? "The PSO services API returned {$response->status()}."
            : implode(' ', $parts);
    }
}
```

- [ ] **Step 6: Delegate `authenticatePSO()` and add `logoffPSO()`**

In `app/Traits/PSOInteractionsTrait.php`: add `use App\Support\PsoServices\PsoServicesSessionClient;` and `use App\Support\PsoServices\PsoServicesSessionException;` to the imports; add a property under `$error_value`:

```php
    /**
     * Why the last authenticatePSO()/logoffPSO() call failed, as pso-services
     * reported it, for callers that want to show the reason.
     */
    protected ?string $session_error_message = null;
```

Replace the whole `authenticatePSO()` method (from its docblock/signature through its closing brace) with:

```php
    public function authenticatePSO(
        string $base_url,
        string $account_id,
        string $username,
        #[SensitiveParameter] string $password,
    ): ?string {
        $this->error_value = null;
        $this->session_error_message = null;

        if (! $base_url) {
            Log::warning('Base URL is missing for PSO authentication.');

            return null;
        }

        try {
            return app(PsoServicesSessionClient::class)->logon($base_url, $account_id, $username, $password);
        } catch (PsoServicesSessionException $exception) {
            $this->error_value = $exception->statusCode === 401 ? 401 : 500;
            $this->session_error_message = $exception->getMessage();

            Log::warning('PSO authentication through pso-services failed', [
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
                'username' => $username,
                'account_id' => $account_id,
            ]);

            return null;
        }
    }

    /**
     * Ends a session through pso-services. A failure is reported, not thrown:
     * callers have already finished their work by the time they log off.
     */
    public function logoffPSO(string $base_url, #[SensitiveParameter] string $session_token): bool
    {
        $this->session_error_message = null;

        try {
            app(PsoServicesSessionClient::class)->logoff($base_url, $session_token);

            return true;
        } catch (PsoServicesSessionException $exception) {
            $this->session_error_message = $exception->getMessage();

            Log::warning('PSO logoff through pso-services failed', [
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
```

Then remove imports that are no longer used in the trait only if a grep shows they are unused: `grep -n "RequestException\|ConnectionException\|Exception \$\|use Exception" app/Traits/PSOInteractionsTrait.php`. `ConnectionException` is still used by `sendToPSONew()`; leave anything still referenced.

- [ ] **Step 7: Repoint the existing job tests at the services session URL**

In `tests/Feature/SendPsoScheduleDataJobTest.php` add `use Tests\Support\PsoServicesFake;` and replace every
`'*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),` with `...PsoServicesFake::session(),`:

```bash
sed -i '' "s#'\*/scheduling/session' => Http::response(\['SessionToken' => 'tok-abc'\], 200),#...PsoServicesFake::session(),#" tests/Feature/SendPsoScheduleDataJobTest.php
```

The 401 test has `'*/scheduling/session' => Http::response([], 401),`; change it by hand to `...PsoServicesFake::session(logonStatus: 401),`. Check: `grep -n "scheduling/session" tests/Feature/SendPsoScheduleDataJobTest.php` prints nothing.

- [ ] **Step 8: Run to verify everything passes**

Run: `php artisan test --compact tests/Feature/PsoServicesSessionClientTest.php tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint app/Support/PsoServices app/Traits/PSOInteractionsTrait.php config/psott.php tests/Support/PsoServicesFake.php tests/Feature/PsoServicesSessionClientTest.php tests/Feature/SendPsoScheduleDataJobTest.php
git add app/Support/PsoServices app/Traits/PSOInteractionsTrait.php config/psott.php tests/Support/PsoServicesFake.php tests/Feature/PsoServicesSessionClientTest.php tests/Feature/SendPsoScheduleDataJobTest.php
git commit -m "feat: log on and off the PSO gateway through pso-services"
```

---

### Task 2: Upload columns, log-line table and `UploadRunLog`

**Files:**
- Create: `database/migrations/2026_10_11_000001_add_webhook_broadcast_fields_to_pso_gateway_uploads_table.php`
- Create: `database/migrations/2026_10_11_000002_create_pso_gateway_upload_log_lines_table.php`
- Create: `app/Enums/GatewayUploadLogLevel.php`
- Create: `app/Models/PsoGatewayUploadLogLine.php`
- Create: `app/Support/GatewayUpload/UploadRunLog.php`
- Modify: `app/Models/PsoGatewayUpload.php`
- Modify: `database/factories/PsoGatewayUploadFactory.php`
- Test: `tests/Feature/UploadRunLogTest.php`

**Interfaces:**
- Produces: columns `dataset_id_override` (string null), `skip_webhook` (bool), `broadcast_settings` (json null), `response_status` (uint null), `response_body` (text null), `webhook_url` and `webhook_view_url` (string null), `wire_bytes` (ubigint null), `post_duration_ms` (uint null) on `PsoGatewayUpload`; relation `PsoGatewayUpload::logLines(): HasMany`; `GatewayUploadLogLevel` cases `STEP`, `SUB`, `WARN`, `ERROR`, `OK`; `UploadRunLog::__construct(PsoGatewayUpload $upload)` with `step(string)`, `sub(string)`, `warn(string)`, `error(string)`, `ok(string)`, `record(GatewayUploadLogLevel, string)`, all `: void`. The factory default becomes `skip_webhook => true` so existing job tests send the file as-is.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/UploadRunLogTest.php`:

```php
<?php

use App\Enums\GatewayUploadLogLevel;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use App\Support\GatewayUpload\UploadRunLog;

it('records lines in order with their level', function () {
    $upload = PsoGatewayUpload::factory()->create();
    $log = app(UploadRunLog::class, ['upload' => $upload]);

    $log->step('=== Upload ===');
    $log->sub('Gateway base URL : https://example.test');
    $log->warn('No alias key');
    $log->ok('Logon successful');
    $log->error('Boom');

    $lines = $upload->logLines()->orderBy('id')->get();

    expect($lines->pluck('text')->all())->toBe(['=== Upload ===', 'Gateway base URL : https://example.test', 'No alias key', 'Logon successful', 'Boom']);
    expect($lines->pluck('level')->all())->toBe([
        GatewayUploadLogLevel::STEP,
        GatewayUploadLogLevel::SUB,
        GatewayUploadLogLevel::WARN,
        GatewayUploadLogLevel::OK,
        GatewayUploadLogLevel::ERROR,
    ]);
    expect($lines->first()->created_at)->not->toBeNull();
});

it('deletes a log with its upload', function () {
    $upload = PsoGatewayUpload::factory()->create();
    app(UploadRunLog::class, ['upload' => $upload])->step('hello');

    $upload->delete();

    expect(PsoGatewayUploadLogLine::query()->count())->toBe(0);
});

it('stores and casts the webhook and response columns', function () {
    $upload = PsoGatewayUpload::factory()->create([
        'dataset_id_override' => 'NEW',
        'skip_webhook' => false,
        'broadcast_settings' => ['id' => 'WEBHOOK_COMPLETE', 'maximum_wait' => 'PT5M'],
        'response_status' => 200,
        'wire_bytes' => 1234,
        'post_duration_ms' => 5678,
    ])->refresh();

    expect($upload->skip_webhook)->toBeFalse();
    expect($upload->broadcast_settings)->toBe(['id' => 'WEBHOOK_COMPLETE', 'maximum_wait' => 'PT5M']);
    expect($upload->response_status)->toBe(200);
    expect($upload->wire_bytes)->toBe(1234);
    expect($upload->post_duration_ms)->toBe(5678);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/UploadRunLogTest.php`
Expected: FAIL (class `App\Enums\GatewayUploadLogLevel` not found).

- [ ] **Step 3: Migrations**

`database/migrations/2026_10_11_000001_add_webhook_broadcast_fields_to_pso_gateway_uploads_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->string('dataset_id_override')->nullable()->after('original_filename');
            $table->boolean('skip_webhook')->default(false)->after('dataset_id_override');
            $table->json('broadcast_settings')->nullable()->after('skip_webhook');
            $table->unsignedSmallInteger('response_status')->nullable()->after('internal_id');
            $table->text('response_body')->nullable()->after('response_status');
            $table->string('webhook_url')->nullable()->after('response_body');
            $table->string('webhook_view_url')->nullable()->after('webhook_url');
            $table->unsignedBigInteger('wire_bytes')->nullable()->after('compressed_size_bytes');
            $table->unsignedInteger('post_duration_ms')->nullable()->after('wire_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->dropColumn([
                'dataset_id_override',
                'skip_webhook',
                'broadcast_settings',
                'response_status',
                'response_body',
                'webhook_url',
                'webhook_view_url',
                'wire_bytes',
                'post_duration_ms',
            ]);
        });
    }
};
```

Check the `after()` anchors exist: `grep -n "internal_id\|compressed_size_bytes\|original_filename" database/migrations/*gateway*`. If one of them lives elsewhere, drop that `->after(...)` call (column order is cosmetic).

`database/migrations/2026_10_11_000002_create_pso_gateway_upload_log_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pso_gateway_upload_log_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('pso_gateway_upload_id')->constrained('pso_gateway_uploads')->cascadeOnDelete();
            $table->string('level', 16);
            $table->text('text');
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['pso_gateway_upload_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pso_gateway_upload_log_lines');
    }
};
```

If `pso_gateway_uploads.id` is not a uuid column, change `foreignUuid` to match (`grep -n "uuid\|id()" database/migrations/2026_09_10_000001_create_pso_gateway_uploads_table.php`).

- [ ] **Step 4: Enum, model, relation, factory**

`app/Enums/GatewayUploadLogLevel.php`:

```php
<?php

namespace App\Enums;

enum GatewayUploadLogLevel: string
{
    case STEP = 'step';
    case SUB = 'sub';
    case WARN = 'warn';
    case ERROR = 'error';
    case OK = 'ok';
}
```

`app/Models/PsoGatewayUploadLogLine.php`:

```php
<?php

namespace App\Models;

use App\Enums\GatewayUploadLogLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsoGatewayUploadLogLine extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = [
        'level' => GatewayUploadLogLevel::class,
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'pso_gateway_upload_id',
        'level',
        'text',
        'created_at',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(PsoGatewayUpload::class, 'pso_gateway_upload_id');
    }
}
```

In `app/Models/PsoGatewayUpload.php` add to `$casts`: `'skip_webhook' => 'boolean', 'broadcast_settings' => 'array', 'response_status' => 'integer', 'wire_bytes' => 'integer', 'post_duration_ms' => 'integer',`; add to `$fillable`: `'dataset_id_override', 'skip_webhook', 'broadcast_settings', 'response_status', 'response_body', 'webhook_url', 'webhook_view_url', 'wire_bytes', 'post_duration_ms',`; add the relation (and `use Illuminate\Database\Eloquent\Relations\HasMany;`):

```php
    public function logLines(): HasMany
    {
        return $this->hasMany(PsoGatewayUploadLogLine::class, 'pso_gateway_upload_id');
    }
```

In `database/factories/PsoGatewayUploadFactory.php` add to `definition()`:

```php
            // Existing job tests send the file as-is; tests of the webhook
            // flow set this to false explicitly.
            'skip_webhook' => true,
```

- [ ] **Step 5: `UploadRunLog`**

`app/Support/GatewayUpload/UploadRunLog.php`:

```php
<?php

namespace App\Support\GatewayUpload;

use App\Enums\GatewayUploadLogLevel;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;

/**
 * The run log the status panel shows: one row per line, in the script's
 * Write-Step / Write-SubStep voice. Callers must never pass the password,
 * a session token or the webhook alias key into a line.
 */
class UploadRunLog
{
    public function __construct(private PsoGatewayUpload $upload) {}

    public function step(string $text): void
    {
        $this->record(GatewayUploadLogLevel::STEP, $text);
    }

    public function sub(string $text): void
    {
        $this->record(GatewayUploadLogLevel::SUB, $text);
    }

    public function warn(string $text): void
    {
        $this->record(GatewayUploadLogLevel::WARN, $text);
    }

    public function error(string $text): void
    {
        $this->record(GatewayUploadLogLevel::ERROR, $text);
    }

    public function ok(string $text): void
    {
        $this->record(GatewayUploadLogLevel::OK, $text);
    }

    public function record(GatewayUploadLogLevel $level, string $text): void
    {
        PsoGatewayUploadLogLine::query()->create([
            'pso_gateway_upload_id' => $this->upload->id,
            'level' => $level,
            'text' => $text,
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Run to verify it passes, then the existing upload tests**

Run: `php artisan test --compact tests/Feature/UploadRunLogTest.php tests/Feature/SendPsoScheduleDataJobTest.php tests/Feature/GatewayUploadTabTest.php`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Enums/GatewayUploadLogLevel.php app/Models/PsoGatewayUploadLogLine.php app/Models/PsoGatewayUpload.php app/Support/GatewayUpload database/migrations/2026_10_11_000001_add_webhook_broadcast_fields_to_pso_gateway_uploads_table.php database/migrations/2026_10_11_000002_create_pso_gateway_upload_log_lines_table.php database/factories/PsoGatewayUploadFactory.php tests/Feature/UploadRunLogTest.php
git add app/Enums app/Models app/Support/GatewayUpload database tests/Feature/UploadRunLogTest.php
git commit -m "feat: gateway upload run log table and webhook/response columns"
```

---

### Task 3: `BroadcastSettings` and `BroadcastInjection`

**Files:**
- Create: `app/Support/ScheduleData/BroadcastSettings.php`
- Create: `app/Support/ScheduleData/BroadcastInjection.php`
- Modify: `config/psott.php`
- Test: `tests/Unit/BroadcastInjectionTest.php`

**Interfaces:**
- Produces: `BroadcastSettings` (readonly) with public `string $broadcastId`, `string $planType`, `int $minimumPlanQuality`, `string $maximumWait`, `int $allocationType`, `array $requiredTables` (list of string); `BroadcastSettings::defaults(): self`; `::fromArray(array $values): self` (keys `id`, `plan_type`, `minimum_plan_quality`, `maximum_wait`, `allocation_type`, `required_tables`; blank or missing keys take the config default); `->toArray(): array` (same keys); `::isValidDuration(string $value): bool`. `BroadcastInjection` (readonly) with `BroadcastSettings $settings`, `string $webhookUrl`; `->broadcastFields(bool $typed): array<string, mixed>`; `->parameterRows(string $mediaType): list<array{broadcast_id: string, parameter_name: string, parameter_value: string}>`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/BroadcastInjectionTest.php`:

```php
<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;

it('defaults to the PowerShell script values', function () {
    $settings = BroadcastSettings::defaults();

    expect($settings->broadcastId)->toBe('WEBHOOK_COMPLETE');
    expect($settings->planType)->toBe('COMPLETE');
    expect($settings->minimumPlanQuality)->toBe(85);
    expect($settings->maximumWait)->toBe('PT5M');
    expect($settings->allocationType)->toBe(1);
    expect($settings->requiredTables)->toBe([
        'Allocation', 'Plan', 'Plan_Deletion', 'Appointment_Offer',
        'Appointment_Summary', 'Suggested_Dispatch', 'Schedule_Exception', 'Schedule_Exception_Data',
    ]);
});

it('overrides defaults per key and falls back for blanks', function () {
    $settings = BroadcastSettings::fromArray([
        'id' => 'MY_HOOK',
        'maximum_wait' => 'PT10M',
        'minimum_plan_quality' => '90',
        'plan_type' => '',
        'required_tables' => ['Plan', 'Allocation'],
    ]);

    expect($settings->broadcastId)->toBe('MY_HOOK');
    expect($settings->maximumWait)->toBe('PT10M');
    expect($settings->minimumPlanQuality)->toBe(90);
    expect($settings->planType)->toBe('COMPLETE');
    expect($settings->requiredTables)->toBe(['Plan', 'Allocation']);
    expect(BroadcastSettings::fromArray($settings->toArray()))->toEqual($settings);
});

it('builds typed broadcast fields for ScheduleData and string fields otherwise', function () {
    $injection = new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');

    expect($injection->broadcastFields(typed: true))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => true,
        'broadcast_type_id' => 'REST',
        'plan_type' => 'COMPLETE',
        'minimum_plan_quality' => 85,
        'maximum_wait' => 'PT5M',
        'minimum_step_interval' => 1,
        'once_only' => true,
        'allocation_type' => 1,
    ]);

    expect($injection->broadcastFields(typed: false))->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => 'true',
        'broadcast_type_id' => 'REST',
        'plan_type' => 'COMPLETE',
        'minimum_plan_quality' => '85',
        'maximum_wait' => 'PT5M',
        'minimum_step_interval' => '1',
        'once_only' => 'true',
        'allocation_type' => '1',
    ]);
});

it('builds the parameter rows in the script order without a compression row', function () {
    $injection = new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');

    $rows = $injection->parameterRows('application/json');

    expect(array_column($rows, 'parameter_name'))->toBe(['url', 'mediatype', 'httpmethod', 'etag', 'required_tables']);
    expect(array_unique(array_column($rows, 'broadcast_id')))->toBe(['WEBHOOK_COMPLETE']);
    expect($rows[0]['parameter_value'])->toBe('https://webhook.example.test/pso-env');
    expect($rows[1]['parameter_value'])->toBe('application/json');
    expect($rows[2]['parameter_value'])->toBe('PATCH');
    expect($rows[3]['parameter_value'])->toBe('*');
    expect($rows[4]['parameter_value'])->toBe('Allocation,Plan,Plan_Deletion,Appointment_Offer,Appointment_Summary,Suggested_Dispatch,Schedule_Exception,Schedule_Exception_Data');
});

it('validates ISO 8601 durations', function (string $value, bool $valid) {
    expect(BroadcastSettings::isValidDuration($value))->toBe($valid);
})->with([
    ['PT5M', true],
    ['PT1H30M', true],
    ['P1DT2H', true],
    ['P1W', true],
    ['PT0.5S', true],
    ['', false],
    ['P', false],
    ['PT', false],
    ['5 minutes', false],
    ["PT5M\n", false],
    ['5', false],
]);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Unit/BroadcastInjectionTest.php`
Expected: FAIL (class `BroadcastSettings` not found).

- [ ] **Step 3: Config defaults**

In `config/psott.php` add, after `'session'`:

```php
    'gateway_upload' => [
        // Defaults for the webhook Broadcast injected into an upload; these
        // mirror Send-PsoScheduleData.ps1 (edit here to change them, as the
        // script's param block is edited there).
        'broadcast' => [
            'id' => 'WEBHOOK_COMPLETE',
            'plan_type' => 'COMPLETE',
            'minimum_plan_quality' => 85,
            'maximum_wait' => 'PT5M',
            'allocation_type' => 1,
            'required_tables' => [
                'Allocation',
                'Plan',
                'Plan_Deletion',
                'Appointment_Offer',
                'Appointment_Summary',
                'Suggested_Dispatch',
                'Schedule_Exception',
                'Schedule_Exception_Data',
            ],
        ],
    ],
```

- [ ] **Step 4: Implement**

`app/Support/ScheduleData/BroadcastSettings.php`:

```php
<?php

namespace App\Support\ScheduleData;

/**
 * The editable parts of the injected webhook Broadcast. The fixed parts
 * (REST, PATCH, once only, step interval 1) live in BroadcastInjection.
 */
readonly class BroadcastSettings
{
    /**
     * @param  list<string>  $requiredTables
     */
    public function __construct(
        public string $broadcastId,
        public string $planType,
        public int $minimumPlanQuality,
        public string $maximumWait,
        public int $allocationType,
        public array $requiredTables,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = (array) config('psott.gateway_upload.broadcast');

        $pick = static fn (string $key): mixed => filled($values[$key] ?? null) ? $values[$key] : $defaults[$key];

        return new self(
            broadcastId: (string) $pick('id'),
            planType: (string) $pick('plan_type'),
            minimumPlanQuality: (int) $pick('minimum_plan_quality'),
            maximumWait: (string) $pick('maximum_wait'),
            allocationType: (int) $pick('allocation_type'),
            requiredTables: array_values(array_map('strval', (array) $pick('required_tables'))),
        );
    }

    /**
     * @return array{id: string, plan_type: string, minimum_plan_quality: int, maximum_wait: string, allocation_type: int, required_tables: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->broadcastId,
            'plan_type' => $this->planType,
            'minimum_plan_quality' => $this->minimumPlanQuality,
            'maximum_wait' => $this->maximumWait,
            'allocation_type' => $this->allocationType,
            'required_tables' => $this->requiredTables,
        ];
    }

    public static function isValidDuration(string $value): bool
    {
        return preg_match(
            '/\AP(?=\d|T\d)(?:\d+W|(?:\d+Y)?(?:\d+M)?(?:\d+D)?)(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?\z/',
            $value,
        ) === 1;
    }
}
```

`app/Support/ScheduleData/BroadcastInjection.php`:

```php
<?php

namespace App\Support\ScheduleData;

/**
 * Everything the rewriters need to write the Broadcast and its
 * Broadcast_Parameter rows: the settings plus the webhook URL PSO will call.
 *
 * No `compression` parameter is ever written: the self-hosted webhook
 * service cannot store gzipped bodies (the script omits it there too).
 */
readonly class BroadcastInjection
{
    public function __construct(
        public BroadcastSettings $settings,
        public string $webhookUrl,
    ) {}

    /**
     * @param  bool  $typed  true for 6.15+ ScheduleData JSON (native booleans and numbers);
     *                       false for XML and legacy dsScheduleData JSON (strings throughout)
     * @return array<string, bool|int|string>
     */
    public function broadcastFields(bool $typed): array
    {
        return [
            'id' => $this->settings->broadcastId,
            'active' => $typed ? true : 'true',
            'broadcast_type_id' => 'REST',
            'plan_type' => $this->settings->planType,
            'minimum_plan_quality' => $typed ? $this->settings->minimumPlanQuality : (string) $this->settings->minimumPlanQuality,
            'maximum_wait' => $this->settings->maximumWait,
            'minimum_step_interval' => $typed ? 1 : '1',
            'once_only' => $typed ? true : 'true',
            'allocation_type' => $typed ? $this->settings->allocationType : (string) $this->settings->allocationType,
        ];
    }

    /**
     * @return list<array{broadcast_id: string, parameter_name: string, parameter_value: string}>
     */
    public function parameterRows(string $mediaType): array
    {
        $values = [
            'url' => $this->webhookUrl,
            'mediatype' => $mediaType,
            'httpmethod' => 'PATCH',
            'etag' => '*',
            'required_tables' => implode(',', $this->settings->requiredTables),
        ];

        $rows = [];

        foreach ($values as $name => $value) {
            $rows[] = [
                'broadcast_id' => $this->settings->broadcastId,
                'parameter_name' => $name,
                'parameter_value' => $value,
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 5: Run to verify it passes, format, commit**

Run: `php artisan test --compact tests/Unit/BroadcastInjectionTest.php`
Expected: PASS.

```bash
vendor/bin/pint app/Support/ScheduleData/BroadcastSettings.php app/Support/ScheduleData/BroadcastInjection.php config/psott.php tests/Unit/BroadcastInjectionTest.php
git add app/Support/ScheduleData config/psott.php tests/Unit/BroadcastInjectionTest.php
git commit -m "feat: broadcast settings and injection value objects with script defaults"
```

---

### Task 4: `JsonTokenReader` and `JsonScheduleDataRewriter`

**Files:**
- Create: `app/Support/ScheduleData/JsonTokenReader.php`
- Create: `app/Support/ScheduleData/ScheduleDataRewriter.php`
- Create: `app/Support/ScheduleData/RewriteReport.php`
- Create: `app/Support/ScheduleData/JsonScheduleDataRewriter.php`
- Test: `tests/Unit/JsonTokenReaderTest.php`
- Test: `tests/Unit/JsonScheduleDataRewriterTest.php`

**Interfaces:**
- Consumes: `BroadcastInjection` from Task 3.
- Produces: `JsonTokenReader::tokens(resource $handle): Generator<int, string>` yielding raw tokens (`{ } [ ] : ,` singly, whole strings including quotes, literal runs, whitespace runs) whose concatenation equals the input; `ScheduleDataRewriter::rewrite(string $sourcePath, string $destinationPath, ?string $datasetIdOverride, ?BroadcastInjection $injection): RewriteReport`; `RewriteReport` (readonly) with `string $format` (`xml`, `ScheduleData`, `dsScheduleData`), `array $datasetIds` (distinct original values, list of string), `bool $datasetIdChanged`, `int $strippedBroadcasts`, `int $strippedBroadcastParameters`, `int $bytesWritten`.

- [ ] **Step 1: Write the failing tokenizer tests**

Create `tests/Unit/JsonTokenReaderTest.php`:

```php
<?php

use App\Support\ScheduleData\JsonTokenReader;

function jsonTokens(string $json): array
{
    $handle = fopen('php://memory', 'w+b');
    fwrite($handle, $json);
    rewind($handle);

    try {
        return iterator_to_array(app(JsonTokenReader::class)->tokens($handle), false);
    } finally {
        fclose($handle);
    }
}

it('splits a document into raw tokens', function () {
    expect(jsonTokens('{"a":"b"}'))->toBe(['{', '"a"', ':', '"b"', '}']);
    expect(jsonTokens('[1, 2.50,true ,null]'))->toBe(['[', '1', ',', ' ', '2.50', ',', 'true', ' ', ',', 'null', ']']);
});

it('reproduces the input exactly, including whitespace, escapes and a BOM', function () {
    $json = "\xEF\xBB\xBF{ \"a\" : [1, 2.50, true, null, \"x\\\"y\\\\\\u00e9\"],\r\n\t\"b\":{} }\n";

    expect(implode('', jsonTokens($json)))->toBe($json);
});

it('keeps a string that spans several chunks as one token', function () {
    $value = str_repeat('ab"c\\d', 30000);
    $json = json_encode(['k' => $value]);

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect(json_decode($tokens[3]))->toBe($value);
});

it('does not split an escape across the chunk boundary', function () {
    // Byte 65535 is the backslash, the escaped quote starts the next chunk.
    $json = '{"k":"'.str_repeat('a', 65529).'\\"'.'tail"}';

    expect(strlen('{"k":"'.str_repeat('a', 65529)))->toBe(65535);

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect(json_decode($tokens[3]))->toBe(str_repeat('a', 65529).'"tail');
});

it('keeps a number that spans the chunk boundary as one token', function () {
    $json = '{"k":'.str_repeat(' ', 65536 - 5 - 3).'12345678}';

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect($tokens)->toContain('12345678');
});

it('throws on an unterminated string', function () {
    expect(static fn () => jsonTokens('{"a":"never closed'))->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Unit/JsonTokenReaderTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the tokenizer**

`app/Support/ScheduleData/JsonTokenReader.php`:

```php
<?php

namespace App\Support\ScheduleData;

use Generator;
use RuntimeException;

/**
 * Yields the raw tokens of a JSON document from a file handle, 64 KB at a
 * time, so a 200 MB schedule data file is never held in memory. Tokens are
 * the six structural characters one at a time, whole strings (quotes and
 * escapes included), runs of literal characters (numbers, true, false, null)
 * and runs of whitespace. Concatenating every token reproduces the input.
 *
 * It does not validate: it only needs to find where strings end, so that
 * braces inside strings are never mistaken for structure.
 */
class JsonTokenReader
{
    private const int CHUNK_BYTES = 65536;

    private const string WHITESPACE = " \t\r\n";

    private const string STRUCTURAL = '{}[]:,';

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    public function tokens($handle): Generator
    {
        $buffer = '';
        $position = 0;
        $endOfFile = false;

        while (true) {
            if ($position >= strlen($buffer)) {
                if ($endOfFile) {
                    return;
                }

                $buffer = $this->readChunk($handle, $endOfFile);
                $position = 0;

                if ($buffer === '') {
                    return;
                }
            }

            $character = $buffer[$position];

            if (str_contains(self::WHITESPACE, $character)) {
                $length = strspn($buffer, self::WHITESPACE, $position);
                $token = substr($buffer, $position, $length);
                $position += $length;

                yield $token;

                continue;
            }

            if (str_contains(self::STRUCTURAL, $character)) {
                $position++;

                yield $character;

                continue;
            }

            if ($character === '"') {
                yield $this->readString($handle, $buffer, $position, $endOfFile);

                continue;
            }

            yield $this->readLiteral($handle, $buffer, $position, $endOfFile);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readChunk($handle, bool &$endOfFile): string
    {
        $chunk = fread($handle, self::CHUNK_BYTES);

        if ($chunk === false) {
            throw new RuntimeException('The JSON file could not be read.');
        }

        if ($chunk === '' || feof($handle)) {
            $endOfFile = true;
        }

        return $chunk;
    }

    /**
     * @param  resource  $handle
     */
    private function readString($handle, string &$buffer, int &$position, bool &$endOfFile): string
    {
        $token = '"';
        $position++;

        while (true) {
            if ($position >= strlen($buffer)) {
                $this->refill($handle, $buffer, $position, $endOfFile);
            }

            $length = strcspn($buffer, '"\\', $position);
            $token .= substr($buffer, $position, $length);
            $position += $length;

            if ($position >= strlen($buffer)) {
                continue;
            }

            $character = $buffer[$position];
            $token .= $character;
            $position++;

            if ($character === '"') {
                return $token;
            }

            // A backslash: the next byte belongs to the escape, in whichever chunk it is.
            if ($position >= strlen($buffer)) {
                $this->refill($handle, $buffer, $position, $endOfFile);
            }

            $token .= $buffer[$position];
            $position++;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readLiteral($handle, string &$buffer, int &$position, bool &$endOfFile): string
    {
        $token = '';

        while (true) {
            $length = strcspn($buffer, self::WHITESPACE.self::STRUCTURAL.'"', $position);
            $token .= substr($buffer, $position, $length);
            $position += $length;

            if ($position < strlen($buffer) || $endOfFile) {
                return $token;
            }

            $buffer = $this->readChunk($handle, $endOfFile);
            $position = 0;

            if ($buffer === '') {
                return $token;
            }
        }
    }

    /**
     * Loads the next chunk in the middle of a string; running out of input
     * here means the string never closed.
     *
     * @param  resource  $handle
     */
    private function refill($handle, string &$buffer, int &$position, bool &$endOfFile): void
    {
        if ($endOfFile) {
            throw new RuntimeException('Unterminated string in the JSON file.');
        }

        $buffer = $this->readChunk($handle, $endOfFile);
        $position = 0;

        if ($buffer === '') {
            throw new RuntimeException('Unterminated string in the JSON file.');
        }
    }
}
```

- [ ] **Step 4: Run to verify the tokenizer passes**

Run: `php artisan test --compact tests/Unit/JsonTokenReaderTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing rewriter tests**

Create `tests/Unit/JsonScheduleDataRewriterTest.php`:

```php
<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\JsonScheduleDataRewriter;
use App\Support\ScheduleData\RewriteReport;

/**
 * @return array{0: string, 1: RewriteReport}
 */
function rewriteJson(string $json, ?string $datasetIdOverride = null, ?BroadcastInjection $injection = null): array
{
    $source = tempnam(sys_get_temp_dir(), 'rwsrc');
    $destination = tempnam(sys_get_temp_dir(), 'rwdst');
    file_put_contents($source, $json);

    try {
        $report = app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, $datasetIdOverride, $injection);

        return [file_get_contents($destination), $report];
    } finally {
        @unlink($source);
        @unlink($destination);
    }
}

function webhookInjection(): BroadcastInjection
{
    return new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');
}

it('injects a typed Broadcast and parameters into a ScheduleData file', function () {
    [$output, $report] = rewriteJson('{"ScheduleData":{"Resources":[{"id":"R1"}]}}', null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $broadcast = $decoded['ScheduleData']['Broadcast'];

    expect($report->format)->toBe('ScheduleData');
    expect($decoded['ScheduleData']['Resources'])->toBe([['id' => 'R1']]);
    expect($broadcast)->toHaveCount(1);
    expect($broadcast[0])->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => true,
        'broadcast_type_id' => 'REST',
        'plan_type' => 'COMPLETE',
        'minimum_plan_quality' => 85,
        'maximum_wait' => 'PT5M',
        'minimum_step_interval' => 1,
        'once_only' => true,
        'allocation_type' => 1,
    ]);

    $parameters = $decoded['ScheduleData']['Broadcast_Parameter'];
    expect(array_column($parameters, 'parameter_name'))->toBe(['url', 'mediatype', 'httpmethod', 'etag', 'required_tables']);
    expect($parameters[0]['parameter_value'])->toBe('https://webhook.example.test/pso-env');
    expect($parameters[1]['parameter_value'])->toBe('application/json');
});

it('injects string-typed rows and a single Broadcast object into a legacy dsScheduleData file', function () {
    [$output, $report] = rewriteJson('{"dsScheduleData":{"Resources":{"id":"R1"}}}', null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $broadcast = $decoded['dsScheduleData']['Broadcast'];

    expect($report->format)->toBe('dsScheduleData');
    expect($broadcast['active'])->toBe('true');
    expect($broadcast['minimum_plan_quality'])->toBe('85');
    expect($broadcast['once_only'])->toBe('true');
    expect($broadcast['maximum_wait'])->toBe('PT5M');
    expect(array_is_list($broadcast))->toBeFalse();
    expect($decoded['dsScheduleData']['Broadcast_Parameter'])->toHaveCount(5);
});

it('strips existing Broadcast and Broadcast_Parameter rows and reports the counts', function () {
    $json = '{"ScheduleData":{"Broadcast":[{"id":"OLD1"},{"id":"OLD2"}],"Resources":[{"id":"R1"}],'
        .'"Broadcast_Parameter":[{"broadcast_id":"OLD1","parameter_name":"url","parameter_value":"x"}]}}';

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report->strippedBroadcasts)->toBe(2);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($decoded['ScheduleData']['Broadcast'])->toHaveCount(1);
    expect($decoded['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
    expect($output)->not->toContain('OLD1');
});

it('counts a legacy single-object Broadcast as one row', function () {
    [, $report] = rewriteJson('{"dsScheduleData":{"Broadcast":{"id":"OLD"},"Resources":{"id":"R1"}}}', null, webhookInjection());

    expect($report->strippedBroadcasts)->toBe(1);
});

it('copies untouched entities byte for byte', function () {
    $resources = '"Resources" : [ { "id":"Ré", "x":1.50, "y" : 1E3 } ]';
    $json = "{\r\n  \"ScheduleData\" : {\r\n    {$resources}\r\n  }\r\n}\r\n";

    [$output] = rewriteJson($json, null, webhookInjection());

    expect($output)->toContain($resources);
    expect($output)->toStartWith("{\r\n  \"ScheduleData\" : {\r\n    ");
    expect($output)->toEndWith("}\r\n}\r\n");
});

it('returns the input unchanged when there is nothing to do', function () {
    $json = "{ \"ScheduleData\" : { \"Resources\": [ {\"id\":\"R1\"} ] } }\n";

    [$output, $report] = rewriteJson($json);

    expect($output)->toBe($json);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('tolerates a UTF-8 BOM and CRLF line endings', function () {
    $json = "\xEF\xBB\xBF{\r\n\"ScheduleData\":{\r\n\"Resources\":[]\r\n}\r\n}";

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    expect($report->format)->toBe('ScheduleData');
    expect(str_starts_with($output, "\xEF\xBB\xBF"))->toBeTrue();
    expect(json_decode(substr($output, 3), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('ScheduleData.Broadcast');
});

it('rewrites a minified single-line file', function () {
    [$output] = rewriteJson('{"ScheduleData":{"Broadcast":[{"id":"OLD"}]}}', null, webhookInjection());

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
});

it('does not strip text that only looks like a Broadcast key', function () {
    $json = '{"ScheduleData":{"Activity":[{"id":"A1","description":"see \"Broadcast\": and \"Broadcast_Parameter\":{}"}],'
        .'"Resources":[{"id":"R1","Broadcast":{"nested":true}}]}}';

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report->strippedBroadcasts)->toBe(0);
    expect($report->strippedBroadcastParameters)->toBe(0);
    expect($decoded['ScheduleData']['Activity'][0]['description'])->toBe('see "Broadcast": and "Broadcast_Parameter":{}');
    expect($decoded['ScheduleData']['Resources'][0]['Broadcast'])->toBe(['nested' => true]);
});

it('replaces dataset_id in every Input_Reference row and reports the originals', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"datetime":"2026-10-11T00:00:00Z","dataset_id":"OLD1"},{"dataset_id":"OLD2"}],"Resources":[{"dataset_id":"KEEP"}]}}';

    [$output, $report] = rewriteJson($json, 'NEW');

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($decoded['ScheduleData']['Input_Reference'][1]['dataset_id'])->toBe('NEW');
    expect($decoded['ScheduleData']['Input_Reference'][0]['datetime'])->toBe('2026-10-11T00:00:00Z');
    expect($decoded['ScheduleData']['Resources'][0]['dataset_id'])->toBe('KEEP');
    expect($report->datasetIds)->toBe(['OLD1', 'OLD2']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('handles a legacy single-object Input_Reference', function () {
    [$output, $report] = rewriteJson('{"dsScheduleData":{"Input_Reference":{"dataset_id":"OLD"}}}', 'NEW');

    expect(json_decode($output, true)['dsScheduleData']['Input_Reference']['dataset_id'])->toBe('NEW');
    expect($report->datasetIds)->toBe(['OLD']);
});

it('reports no change when the override equals the existing value', function () {
    [, $report] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"SAME"}]}}', 'SAME');

    expect($report->datasetIdChanged)->toBeFalse();
    expect($report->datasetIds)->toBe(['SAME']);
});

it('reports the existing dataset ids even without an override', function () {
    [, $report] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"ONLY"}]}}', null, webhookInjection());

    expect($report->datasetIds)->toBe(['ONLY']);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('escapes an override containing JSON special characters', function () {
    $override = 'A"B\\C/D & <E>';

    [$output] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}', $override);

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe($override);
});

it('does nothing to a file with no Input_Reference when only an override is given', function () {
    [$output, $report] = rewriteJson('{"ScheduleData":{"Resources":[]}}', 'NEW');

    expect($output)->toBe('{"ScheduleData":{"Resources":[]}}');
    expect($report->datasetIds)->toBe([]);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('injects into an empty wrapper', function () {
    [$output] = rewriteJson('{"ScheduleData":{}}', null, webhookInjection());

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData'])->toHaveKeys(['Broadcast', 'Broadcast_Parameter']);
});

it('fails with the script message when there is no ScheduleData wrapper', function () {
    expect(static fn () => rewriteJson('{"Other":{"Resources":[]}}', null, webhookInjection()))
        ->toThrow(RuntimeException::class, "Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
});

it('rewrites a large file in constant memory', function () {
    $source = tempnam(sys_get_temp_dir(), 'bigsrc');
    $destination = tempnam(sys_get_temp_dir(), 'bigdst');

    $handle = fopen($source, 'wb');
    fwrite($handle, '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Activity":[');
    $row = '{"id":"ACT-000000","description":"'.str_repeat('x', 200).'","priority":5},';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '{"id":"LAST"}]}}');
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', webhookInjection());
        $growth = memory_get_peak_usage() - $before;

        expect(filesize($source))->toBeGreaterThan(20 * 1024 * 1024);
        expect($report->bytesWritten)->toBe(filesize($destination));
        expect($growth)->toBeLessThan(16 * 1024 * 1024);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});
```

- [ ] **Step 6: Run to verify it fails**

Run: `php artisan test --compact tests/Unit/JsonScheduleDataRewriterTest.php`
Expected: FAIL (class `JsonScheduleDataRewriter` not found).

- [ ] **Step 7: Implement the interface, report and JSON rewriter**

`app/Support/ScheduleData/ScheduleDataRewriter.php`:

```php
<?php

namespace App\Support\ScheduleData;

interface ScheduleDataRewriter
{
    /**
     * Writes a modified copy of the schedule data file: optionally with every
     * Input_Reference dataset_id replaced, and optionally with every existing
     * Broadcast and Broadcast_Parameter row replaced by the injected one.
     * The source file is never modified. Streams; memory does not grow with
     * the file.
     *
     * @throws \RuntimeException when the file is not schedule data or cannot be written
     */
    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport;
}
```

`app/Support/ScheduleData/RewriteReport.php`:

```php
<?php

namespace App\Support\ScheduleData;

readonly class RewriteReport
{
    /**
     * @param  string  $format  `xml`, `ScheduleData` or `dsScheduleData`
     * @param  list<string>  $datasetIds  distinct original Input_Reference dataset_id values
     */
    public function __construct(
        public string $format,
        public array $datasetIds,
        public bool $datasetIdChanged,
        public int $strippedBroadcasts,
        public int $strippedBroadcastParameters,
        public int $bytesWritten,
    ) {}
}
```

`app/Support/ScheduleData/JsonScheduleDataRewriter.php`:

```php
<?php

namespace App\Support\ScheduleData;

use RuntimeException;

/**
 * Copies a schedule data JSON file token by token. Everything outside the
 * Input_Reference dataset_id values and the Broadcast/Broadcast_Parameter
 * members of the top-level wrapper is written exactly as read.
 *
 * 6.15+ files wrap in "ScheduleData" with native types and always-array
 * entities; older files wrap in "dsScheduleData", use string values and only
 * array-wrap an entity with more than one row. The injected rows match.
 */
class JsonScheduleDataRewriter implements ScheduleDataRewriter
{
    private const array WRAPPER_KEYS = ['ScheduleData', 'dsScheduleData'];

    private const array STRIPPED_ENTITIES = ['Broadcast', 'Broadcast_Parameter'];

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __construct(private JsonTokenReader $tokenReader) {}

    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport {
        $source = @fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException("The schedule data file could not be opened: {$sourcePath}");
        }

        $destination = @fopen($destinationPath, 'wb');

        if ($destination === false) {
            fclose($source);

            throw new RuntimeException("The rewritten schedule data could not be opened for writing: {$destinationPath}");
        }

        try {
            return $this->copy($source, $destination, $datasetIdOverride, $injection);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * @param  resource  $source
     * @param  resource  $destination
     */
    private function copy($source, $destination, ?string $datasetIdOverride, ?BroadcastInjection $injection): RewriteReport
    {
        $bytesWritten = 0;

        $write = static function (string $text) use ($destination, &$bytesWritten): void {
            if ($text === '') {
                return;
            }

            $written = fwrite($destination, $text);

            if ($written === false || $written < strlen($text)) {
                throw new RuntimeException('The rewritten schedule data could not be written.');
            }

            $bytesWritten += strlen($text);
        };

        $depth = 0;
        $previousSignificant = '';
        $pendingWrapperKey = null;
        $format = null;

        // Inside the wrapper object; null while outside it.
        $memberState = null; // 'key' | 'colon' | 'valueStart' | 'value'
        $heldWhitespace = '';
        $wroteMember = false;
        $strippingMember = false;
        $memberName = '';
        $memberDepth = 0;
        $memberRows = 0;
        $memberIsArray = false;
        $inInputReference = false;
        $datasetStage = 0; // 0 idle, 1 saw the dataset_id key, 2 saw its colon
        $datasetIds = [];
        $datasetIdChanged = false;
        $strippedBroadcasts = 0;
        $strippedParameters = 0;

        foreach ($this->tokenReader->tokens($source) as $token) {
            $isWhitespace = $token[0] === ' ' || $token[0] === "\t" || $token[0] === "\r" || $token[0] === "\n";

            if ($memberState === null) {
                $write($token);

                if ($isWhitespace) {
                    continue;
                }

                $before = $previousSignificant;
                $previousSignificant = $token;

                if ($pendingWrapperKey !== null && $token !== ':' && $token !== '{') {
                    $pendingWrapperKey = null;
                }

                if ($token === '{' && $pendingWrapperKey !== null && $depth === 1) {
                    $format = $pendingWrapperKey;
                    $pendingWrapperKey = null;
                    $depth = 2;
                    $memberState = 'key';

                    continue;
                }

                if ($token === '{' || $token === '[') {
                    $depth++;
                } elseif ($token === '}' || $token === ']') {
                    $depth--;
                } elseif ($token[0] === '"' && $depth === 1 && $format === null && ($before === '{' || $before === ',')) {
                    $key = json_decode($token);
                    $pendingWrapperKey = is_string($key) && in_array($key, self::WRAPPER_KEYS, true) ? $key : null;
                }

                continue;
            }

            if ($isWhitespace) {
                if ($memberState === 'key') {
                    $heldWhitespace .= $token;
                } elseif (! $strippingMember) {
                    $write($token);
                }

                continue;
            }

            $before = $previousSignificant;
            $previousSignificant = $token;

            if ($memberState === 'key') {
                if ($token === ',') {
                    continue;
                }

                if ($token === '}') {
                    if ($injection !== null) {
                        foreach ($this->injectedEntities($injection, $format) as $entityName => $entityJson) {
                            $write(($wroteMember ? ',' : '').json_encode($entityName).':'.$entityJson);
                            $wroteMember = true;
                        }
                    }

                    $write($heldWhitespace.'}');
                    $heldWhitespace = '';
                    $memberState = null;
                    $depth = 1;

                    continue;
                }

                $decodedKey = json_decode($token);
                $memberName = is_string($decodedKey) ? $decodedKey : '';
                $strippingMember = in_array($memberName, self::STRIPPED_ENTITIES, true);
                $memberRows = 0;
                $memberIsArray = false;

                if (! $strippingMember) {
                    $write(($wroteMember ? ',' : '').$heldWhitespace.$token);
                    $wroteMember = true;
                }

                $heldWhitespace = '';
                $memberState = 'colon';

                continue;
            }

            if ($memberState === 'colon') {
                if (! $strippingMember) {
                    $write($token);
                }

                if ($token === ':') {
                    $memberState = 'valueStart';
                }

                continue;
            }

            if ($memberState === 'valueStart') {
                if ($token === '{' || $token === '[') {
                    $memberDepth = 1;
                    $memberIsArray = $token === '[';
                    $memberState = 'value';
                    $inInputReference = $memberName === 'Input_Reference';
                    $datasetStage = 0;

                    if ($strippingMember && ! $memberIsArray) {
                        $memberRows = 1;
                    }

                    if (! $strippingMember) {
                        $write($token);
                    }

                    continue;
                }

                // A scalar member value (null, a number, a string).
                if (! $strippingMember) {
                    $write($token);
                }

                $memberState = 'key';
                $strippingMember = false;

                continue;
            }

            // $memberState === 'value'
            $output = $token;

            if ($token === '{' || $token === '[') {
                if ($strippingMember && $memberIsArray && $token === '{' && $memberDepth === 1) {
                    $memberRows++;
                }

                $memberDepth++;
            } elseif ($token === '}' || $token === ']') {
                $memberDepth--;
            }

            if ($inInputReference && ! $strippingMember) {
                if ($datasetStage === 0) {
                    if ($token[0] === '"' && ($before === '{' || $before === ',') && json_decode($token) === 'dataset_id') {
                        $datasetStage = 1;
                    }
                } elseif ($datasetStage === 1) {
                    $datasetStage = $token === ':' ? 2 : 0;
                } else {
                    $datasetStage = 0;

                    if ($token !== '{' && $token !== '[') {
                        $original = json_decode($token);
                        $originalText = is_scalar($original) ? (string) $original : '';
                        $datasetIds[] = $originalText;

                        if ($datasetIdOverride !== null) {
                            $output = json_encode($datasetIdOverride, self::JSON_FLAGS);

                            if ($originalText !== $datasetIdOverride) {
                                $datasetIdChanged = true;
                            }
                        }
                    }
                }
            }

            if (! $strippingMember) {
                $write($output);
            }

            if ($memberDepth === 0) {
                if ($strippingMember) {
                    if ($memberName === 'Broadcast') {
                        $strippedBroadcasts += $memberRows;
                    } else {
                        $strippedParameters += $memberRows;
                    }
                }

                $memberState = 'key';
                $strippingMember = false;
                $inInputReference = false;
            }
        }

        if ($format === null || $memberState !== null) {
            throw new RuntimeException("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - can't inject a Broadcast.");
        }

        return new RewriteReport(
            format: $format,
            datasetIds: array_values(array_unique($datasetIds)),
            datasetIdChanged: $datasetIdChanged,
            strippedBroadcasts: $strippedBroadcasts,
            strippedBroadcastParameters: $strippedParameters,
            bytesWritten: $bytesWritten,
        );
    }

    /**
     * @return array<string, string> entity name => JSON text of its value
     */
    private function injectedEntities(BroadcastInjection $injection, string $format): array
    {
        $typed = $format === 'ScheduleData';
        $broadcast = $injection->broadcastFields($typed);

        return [
            'Broadcast' => json_encode($typed ? [$broadcast] : $broadcast, self::JSON_FLAGS),
            'Broadcast_Parameter' => json_encode($injection->parameterRows('application/json'), self::JSON_FLAGS),
        ];
    }
}
```

- [ ] **Step 8: Run to verify it passes**

Run: `php artisan test --compact tests/Unit/JsonTokenReaderTest.php tests/Unit/JsonScheduleDataRewriterTest.php`
Expected: PASS. If the "byte for byte" test fails on the leading whitespace assertion, the held whitespace before the first kept member was dropped or duplicated; the first kept member must write `$heldWhitespace` exactly once.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint app/Support/ScheduleData tests/Unit/JsonTokenReaderTest.php tests/Unit/JsonScheduleDataRewriterTest.php
git add app/Support/ScheduleData tests/Unit/JsonTokenReaderTest.php tests/Unit/JsonScheduleDataRewriterTest.php
git commit -m "feat: streaming JSON rewrite for dataset id and webhook Broadcast injection"
```

---

### Task 5: `XmlScheduleDataRewriter` and the rewriter factory

**Files:**
- Create: `app/Support/ScheduleData/XmlScheduleDataRewriter.php`
- Create: `app/Support/ScheduleData/ScheduleDataRewriterFactory.php`
- Test: `tests/Unit/XmlScheduleDataRewriterTest.php`

**Interfaces:**
- Consumes: `ScheduleDataRewriter`, `RewriteReport`, `BroadcastInjection` from Tasks 3-4.
- Produces: `XmlScheduleDataRewriter implements ScheduleDataRewriter` (report format `xml`); `ScheduleDataRewriterFactory::forFormat(string $format): ScheduleDataRewriter` where `$format` is `'xml'` or `'json'` (the extension-derived value the job already computes).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/XmlScheduleDataRewriterTest.php`:

```php
<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\RewriteReport;
use App\Support\ScheduleData\ScheduleDataRewriterFactory;
use App\Support\ScheduleData\XmlScheduleDataRewriter;

const XML_NS = 'http://360Scheduling.com/Schema/dsScheduleData.xsd';

/**
 * @return array{0: string, 1: RewriteReport}
 */
function rewriteXml(string $xml, ?string $datasetIdOverride = null, ?BroadcastInjection $injection = null): array
{
    $source = tempnam(sys_get_temp_dir(), 'xmlsrc');
    $destination = tempnam(sys_get_temp_dir(), 'xmldst');
    file_put_contents($source, $xml);

    try {
        $report = app(XmlScheduleDataRewriter::class)->rewrite($source, $destination, $datasetIdOverride, $injection);

        return [file_get_contents($destination), $report];
    } finally {
        @unlink($source);
        @unlink($destination);
    }
}

function xmlWebhookInjection(): BroadcastInjection
{
    return new BroadcastInjection(BroadcastSettings::defaults(), 'https://webhook.example.test/pso-env');
}

function xpathFor(string $xml): DOMXPath
{
    $document = new DOMDocument;
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', XML_NS);

    return $xpath;
}

it('injects a Broadcast and its parameters in the root namespace', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Resources><id>R1</id></Resources></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection());

    $xpath = xpathFor($output);

    expect($report->format)->toBe('xml');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Resources/s:id)'))->toBe('R1');
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:id)'))->toBe('WEBHOOK_COMPLETE');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:maximum_wait)'))->toBe('PT5M');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:once_only)'))->toBe('true');
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Broadcast_Parameter)'))->toBe(5.0);
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast_Parameter[s:parameter_name="url"]/s:parameter_value)'))
        ->toBe('https://webhook.example.test/pso-env');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast_Parameter[s:parameter_name="mediatype"]/s:parameter_value)'))
        ->toBe('application/xml');
});

it('strips existing broadcast rows and reports the counts', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'">'
        .'<Broadcast><id>OLD1</id></Broadcast><Broadcast><id>OLD2</id></Broadcast>'
        .'<Resources><id>R1</id></Resources>'
        .'<Broadcast_Parameter><broadcast_id>OLD1</broadcast_id></Broadcast_Parameter>'
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection());

    expect($report->strippedBroadcasts)->toBe(2);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($output)->not->toContain('OLD1');
    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('replaces dataset_id inside Input_Reference only', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'">'
        .'<Input_Reference><datetime>2026-10-11T00:00:00Z</datetime><dataset_id>OLD</dataset_id></Input_Reference>'
        .'<Resources><dataset_id>KEEP</dataset_id></Resources>'
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW');

    $xpath = xpathFor($output);

    expect($xpath->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('NEW');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:datetime)'))->toBe('2026-10-11T00:00:00Z');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Resources/s:dataset_id)'))->toBe('KEEP');
    expect($report->datasetIds)->toBe(['OLD']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('escapes an override containing XML special characters', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';

    [$output] = rewriteXml($xml, 'A&B <C> "D"');

    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('A&B <C> "D"');
});

it('replaces an empty dataset_id element', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id/></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW');

    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('NEW');
    expect($report->datasetIds)->toBe(['']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('keeps other content, attributes, comments, cdata and escaped text', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<dsScheduleData xmlns="'.XML_NS.'" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        .'<!-- kept --><Activity id="A&amp;1" xsi:nil="false"><description><![CDATA[x < y]]></description>'
        .'<notes>fish &amp; chips</notes></Activity></dsScheduleData>';

    [$output] = rewriteXml($xml, null, xmlWebhookInjection());

    $xpath = xpathFor($output);

    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/@id)'))->toBe('A&1');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/s:description)'))->toBe('x < y');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/s:notes)'))->toBe('fish & chips');
    expect($output)->toContain('<!-- kept -->');
});

it('injects into an empty root element', function () {
    [$output] = rewriteXml('<dsScheduleData xmlns="'.XML_NS.'"/>', null, xmlWebhookInjection());

    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('writes injected elements with the root prefix when the root is prefixed', function () {
    $xml = '<ds:dsScheduleData xmlns:ds="'.XML_NS.'"><ds:Resources><ds:id>R1</ds:id></ds:Resources></ds:dsScheduleData>';

    [$output] = rewriteXml($xml, null, xmlWebhookInjection());

    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('fails when the root element is not schedule data', function () {
    expect(static fn () => rewriteXml('<other xmlns="x"><a/></other>', null, xmlWebhookInjection()))
        ->toThrow(RuntimeException::class, "Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
});

it('rewrites a large xml file in constant memory', function () {
    $source = tempnam(sys_get_temp_dir(), 'bigxmlsrc');
    $destination = tempnam(sys_get_temp_dir(), 'bigxmldst');

    $handle = fopen($source, 'wb');
    fwrite($handle, '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference>');
    $row = '<Activity><id>ACT-000000</id><description>'.str_repeat('x', 200).'</description><priority>5</priority></Activity>';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '</dsScheduleData>');
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(XmlScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', xmlWebhookInjection());
        $growth = memory_get_peak_usage() - $before;

        expect(filesize($source))->toBeGreaterThan(20 * 1024 * 1024);
        expect($report->bytesWritten)->toBe(filesize($destination));
        expect($growth)->toBeLessThan(16 * 1024 * 1024);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});

it('picks the rewriter by format', function () {
    $factory = app(ScheduleDataRewriterFactory::class);

    expect($factory->forFormat('xml'))->toBeInstanceOf(XmlScheduleDataRewriter::class);
    expect($factory->forFormat('json'))->toBeInstanceOf(App\Support\ScheduleData\JsonScheduleDataRewriter::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Unit/XmlScheduleDataRewriterTest.php`
Expected: FAIL (class `XmlScheduleDataRewriter` not found).

- [ ] **Step 3: Implement the XML rewriter**

`app/Support/ScheduleData/XmlScheduleDataRewriter.php`:

```php
<?php

namespace App\Support\ScheduleData;

use RuntimeException;
use XMLReader;
use XMLWriter;

/**
 * Copies a dsScheduleData XML file node by node with XMLReader and
 * XMLWriter. Output is semantically equal to the input (prefixes, namespace
 * declarations, attributes, comments and CDATA are kept); only the
 * Input_Reference dataset_id values and the Broadcast/Broadcast_Parameter
 * children of the root change.
 */
class XmlScheduleDataRewriter implements ScheduleDataRewriter
{
    private const array ROOT_NAMES = ['dsScheduleData', 'ScheduleData'];

    private const array STRIPPED_ENTITIES = ['Broadcast', 'Broadcast_Parameter'];

    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport {
        $reader = new XMLReader;

        if (! $reader->open($sourcePath, null, LIBXML_NONET)) {
            throw new RuntimeException("The schedule data file could not be opened: {$sourcePath}");
        }

        $writer = new XMLWriter;

        if (! $writer->openUri($destinationPath)) {
            $reader->close();

            throw new RuntimeException("The rewritten schedule data could not be opened for writing: {$destinationPath}");
        }

        $writer->setIndent(false);
        $writer->startDocument('1.0', 'UTF-8');

        $datasetIds = [];
        $datasetIdChanged = false;
        $strippedBroadcasts = 0;
        $strippedParameters = 0;
        $rootPrefix = '';
        $rootSeen = false;
        $inInputReference = false;

        try {
            $hasNode = $reader->read();

            while ($hasNode) {
                switch ($reader->nodeType) {
                    case XMLReader::ELEMENT:
                        $depth = $reader->depth;
                        $localName = $reader->localName;
                        $isEmpty = $reader->isEmptyElement;

                        if ($depth === 0) {
                            if (! in_array($localName, self::ROOT_NAMES, true)) {
                                throw new RuntimeException("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - can't inject a Broadcast.");
                            }

                            $rootSeen = true;
                            $rootPrefix = (string) $reader->prefix;
                        }

                        if ($depth === 1 && in_array($localName, self::STRIPPED_ENTITIES, true)) {
                            if ($localName === 'Broadcast') {
                                $strippedBroadcasts++;
                            } else {
                                $strippedParameters++;
                            }

                            $hasNode = $reader->next();

                            continue 2;
                        }

                        $this->startElementWithAttributes($reader, $writer);

                        if ($depth === 0 && $isEmpty) {
                            if ($injection !== null) {
                                $this->writeInjection($writer, $injection, $rootPrefix);
                            }

                            $writer->endElement();
                        } elseif ($depth === 1 && $localName === 'Input_Reference') {
                            if ($isEmpty) {
                                $writer->endElement();
                            } else {
                                $inInputReference = true;
                            }
                        } elseif ($inInputReference && $depth === 2 && $localName === 'dataset_id') {
                            $original = $isEmpty ? '' : $reader->readString();
                            $datasetIds[] = $original;

                            $writer->text($datasetIdOverride ?? $original);
                            $writer->endElement();

                            if ($datasetIdOverride !== null && $datasetIdOverride !== $original) {
                                $datasetIdChanged = true;
                            }

                            $hasNode = $reader->next();

                            continue 2;
                        } elseif ($isEmpty) {
                            $writer->endElement();
                        }

                        break;

                    case XMLReader::END_ELEMENT:
                        if ($reader->depth === 1 && $reader->localName === 'Input_Reference') {
                            $inInputReference = false;
                        }

                        if ($reader->depth === 0 && $injection !== null) {
                            $this->writeInjection($writer, $injection, $rootPrefix);
                        }

                        $writer->endElement();

                        break;

                    case XMLReader::TEXT:
                    case XMLReader::WHITESPACE:
                    case XMLReader::SIGNIFICANT_WHITESPACE:
                        $writer->text($reader->value);

                        break;

                    case XMLReader::CDATA:
                        $writer->writeCdata($reader->value);

                        break;

                    case XMLReader::COMMENT:
                        $writer->writeComment($reader->value);

                        break;

                    case XMLReader::PI:
                        $writer->writePi($reader->name, $reader->value);

                        break;
                }

                $hasNode = $reader->read();
            }

            if (! $rootSeen) {
                throw new RuntimeException("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - can't inject a Broadcast.");
            }

            $writer->endDocument();
            $writer->flush();
        } finally {
            $reader->close();
        }

        clearstatcache(true, $destinationPath);

        return new RewriteReport(
            format: 'xml',
            datasetIds: array_values(array_unique($datasetIds)),
            datasetIdChanged: $datasetIdChanged,
            strippedBroadcasts: $strippedBroadcasts,
            strippedBroadcastParameters: $strippedParameters,
            bytesWritten: (int) filesize($destinationPath),
        );
    }

    private function startElementWithAttributes(XMLReader $reader, XMLWriter $writer): void
    {
        $writer->startElement($reader->name);

        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $writer->writeAttribute($reader->name, $reader->value);
            }

            $reader->moveToElement();
        }
    }

    private function writeInjection(XMLWriter $writer, BroadcastInjection $injection, string $prefix): void
    {
        $qualified = static fn (string $name): string => $prefix !== '' ? "{$prefix}:{$name}" : $name;

        $writer->startElement($qualified('Broadcast'));

        foreach ($injection->broadcastFields(typed: false) as $field => $value) {
            $writer->writeElement($qualified($field), (string) $value);
        }

        $writer->endElement();

        foreach ($injection->parameterRows('application/xml') as $row) {
            $writer->startElement($qualified('Broadcast_Parameter'));

            foreach ($row as $field => $value) {
                $writer->writeElement($qualified($field), $value);
            }

            $writer->endElement();
        }
    }
}
```

`app/Support/ScheduleData/ScheduleDataRewriterFactory.php`:

```php
<?php

namespace App\Support\ScheduleData;

class ScheduleDataRewriterFactory
{
    /**
     * @param  string  $format  `xml` or `json`, as derived from the file extension
     */
    public function forFormat(string $format): ScheduleDataRewriter
    {
        return $format === 'xml'
            ? app(XmlScheduleDataRewriter::class)
            : app(JsonScheduleDataRewriter::class);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact tests/Unit/XmlScheduleDataRewriterTest.php`
Expected: PASS. Two likely adjustments: if the prefixed-root test fails because an `xmlns:` attribute is written twice, skip attributes whose name starts with `xmlns` only when the writer already declared them (it does not for plain `writeAttribute`, so this should not occur); if `Input_Reference` handling leaves `$inInputReference` true after an empty element, the `isEmpty` branch above already ends it.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Support/ScheduleData/XmlScheduleDataRewriter.php app/Support/ScheduleData/ScheduleDataRewriterFactory.php tests/Unit/XmlScheduleDataRewriterTest.php
git add app/Support/ScheduleData tests/Unit/XmlScheduleDataRewriterTest.php
git commit -m "feat: streaming XML rewrite and rewriter factory"
```

---

### Task 6: `WebhookEndpointProvisioner`

**Files:**
- Create: `app/Support/Webhook/WebhookEndpointProvisioner.php`
- Create: `app/Support/Webhook/WebhookEndpoint.php`
- Create: `app/Support/Webhook/WebhookProvisioningException.php`
- Modify: `config/services.php`
- Test: `tests/Feature/WebhookEndpointProvisionerTest.php`

**Interfaces:**
- Consumes: `UploadRunLog` from Task 2.
- Produces: `WebhookEndpointProvisioner::provision(string $environmentName, string $contentType, UploadRunLog $log): WebhookEndpoint`; `WebhookEndpoint` (readonly) with `string $url`, `string $viewUrl`, `string $uuid`, `?string $alias`, `bool $created`; `WebhookProvisioningException`. Config `services.webhook.base_url` and `services.webhook.alias_key`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/WebhookEndpointProvisionerTest.php`:

```php
<?php

use App\Models\PsoGatewayUpload;
use App\Support\GatewayUpload\UploadRunLog;
use App\Support\Webhook\WebhookEndpointProvisioner;
use App\Support\Webhook\WebhookProvisioningException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.webhook.base_url' => 'https://webhook.example.test',
        'services.webhook.alias_key' => 'alias-key-123',
    ]);
});

function provisionWebhook(string $environmentName = 'SaskTel TST', string $contentType = 'application/json'): array
{
    $upload = PsoGatewayUpload::factory()->create();
    $endpoint = app(WebhookEndpointProvisioner::class)->provision(
        $environmentName,
        $contentType,
        app(UploadRunLog::class, ['upload' => $upload]),
    );

    return [$endpoint, $upload->logLines()->orderBy('id')->get()];
}

it('creates the per-environment alias and returns its stable url', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->url)->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($endpoint->viewUrl)->toBe('https://webhook.example.test/#!/uuid-1');
    expect($endpoint->uuid)->toBe('uuid-1');
    expect($endpoint->alias)->toBe('pso-sasktel-tst');
    expect($endpoint->created)->toBeTrue();
    expect($lines->pluck('text')->implode("\n"))->toContain('Created new alias /pso-sasktel-tst')
        ->not->toContain('alias-key-123');

    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://webhook.example.test/_alias/pso-sasktel-tst'
        && $request->hasHeader('X-Alias-Key', 'alias-key-123')
        && $request['default_status'] === 200
        && $request['default_content'] === '{}'
        && $request['default_content_type'] === 'application/json');
});

it('uses an xml canned response for xml payloads', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201)]);

    provisionWebhook('env', 'application/xml');

    Http::assertSent(static fn ($request) => $request['default_content'] === '<response/>'
        && $request['default_content_type'] === 'application/xml');
});

it('refreshes the canned response of an existing alias', function () {
    Http::fake([
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-9', 'created' => false], 200),
        'https://webhook.example.test/token/uuid-9' => Http::response([], 200),
    ]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->created)->toBeFalse();
    expect($lines->pluck('text')->implode("\n"))->toContain('already exists');

    Http::assertSent(static fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://webhook.example.test/token/uuid-9'
        && $request['default_content'] === '{}');
});

it('tolerates a failure refreshing the canned response', function () {
    Http::fake([
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-9', 'created' => false], 200),
        'https://webhook.example.test/token/uuid-9' => Http::response('boom', 500),
    ]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->uuid)->toBe('uuid-9');
    expect($lines->pluck('text')->implode("\n"))->toContain('Could not update');
});

it('falls back to a one-off token with a warning when no alias key is configured', function () {
    config(['services.webhook.alias_key' => null]);

    Http::fake(['https://webhook.example.test/token' => Http::response(['uuid' => 'uuid-77'], 201)]);

    [$endpoint, $lines] = provisionWebhook();

    expect($endpoint->url)->toBe('https://webhook.example.test/uuid-77');
    expect($endpoint->viewUrl)->toBe('https://webhook.example.test/#!/uuid-77');
    expect($endpoint->alias)->toBeNull();
    expect($lines->pluck('text')->implode("\n"))->toContain('falling back to a fresh one-off token');

    Http::assertNotSent(static fn ($request) => str_contains($request->url(), '/_alias/'));
});

it('throws a provisioning exception when the alias call fails', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response('forbidden', 403)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, '403');
});

it('throws when the service is unreachable', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => static function () {
        throw new Illuminate\Http\Client\ConnectionException('timed out');
    }]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, 'could not be reached');
});

it('throws when the reply carries no uuid', function () {
    Http::fake(['https://webhook.example.test/_alias/*' => Http::response(['created' => true], 200)]);

    expect(static fn () => provisionWebhook())->toThrow(WebhookProvisioningException::class, 'uuid');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/WebhookEndpointProvisionerTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Config**

In `config/services.php`, before the final `];`, add:

```php
    'webhook' => [
        // Self-hosted webhook.site that receives the plan PSO broadcasts back.
        'base_url' => env('WEBHOOK_BASE_URL', 'https://webhook.thetechnodro.me'),
        // The webhook server's ALIAS_API_KEY. Without it, one-off tokens are used.
        'alias_key' => env('WEBHOOK_ALIAS_KEY'),
    ],
```

- [ ] **Step 4: Implement**

`app/Support/Webhook/WebhookEndpoint.php`:

```php
<?php

namespace App\Support\Webhook;

readonly class WebhookEndpoint
{
    public function __construct(
        public string $url,
        public string $viewUrl,
        public string $uuid,
        public ?string $alias,
        public bool $created,
    ) {}
}
```

`app/Support/Webhook/WebhookProvisioningException.php`:

```php
<?php

namespace App\Support\Webhook;

use RuntimeException;

class WebhookProvisioningException extends RuntimeException {}
```

`app/Support/Webhook/WebhookEndpointProvisioner.php`:

```php
<?php

namespace App\Support\Webhook;

use App\Support\GatewayUpload\UploadRunLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Creates (or finds) the webhook endpoint PSO will broadcast the plan to,
 * the way Send-PsoScheduleData.ps1 does: a stable per-environment alias on
 * the self-hosted service, or a fresh one-off token when no alias key is set.
 * The alias key is read from config and never written to the run log.
 */
class WebhookEndpointProvisioner
{
    public function provision(string $environmentName, string $contentType, UploadRunLog $log): WebhookEndpoint
    {
        $baseUrl = rtrim((string) config('services.webhook.base_url'), '/');
        $aliasKey = config('services.webhook.alias_key');

        $tokenConfig = [
            'default_status' => 200,
            'default_content' => $contentType === 'application/json' ? '{}' : '<response/>',
            'default_content_type' => $contentType,
        ];

        if (blank($aliasKey)) {
            $log->warn('No alias API key configured (WEBHOOK_ALIAS_KEY) - falling back to a fresh one-off token.');

            $endpoint = $this->createOneOff($baseUrl, $tokenConfig, $log);
        } else {
            $endpoint = $this->ensureAlias($baseUrl, (string) $aliasKey, $this->aliasFor($environmentName), $tokenConfig, $log);
        }

        $log->sub("Configured default response: status 200, content-type {$contentType}, body '{$tokenConfig['default_content']}'");

        return $endpoint;
    }

    private function aliasFor(string $environmentName): string
    {
        return 'pso-'.preg_replace('/[^a-z0-9-]/', '-', Str::lower($environmentName));
    }

    /**
     * @param  array<string, mixed>  $tokenConfig
     */
    private function ensureAlias(string $baseUrl, string $aliasKey, string $alias, array $tokenConfig, UploadRunLog $log): WebhookEndpoint
    {
        $log->step("Ensuring webhook alias '/{$alias}' exists and injecting Broadcast...");

        $response = $this->send(
            fn (): Response => $this->client()->withHeaders(['X-Alias-Key' => $aliasKey])->post("{$baseUrl}/_alias/{$alias}", $tokenConfig),
            "Failed to create/look up webhook alias '{$alias}' at {$baseUrl}/_alias",
        );

        $uuid = $response->json('uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new WebhookProvisioningException("The webhook service answered the alias request for '{$alias}' without a uuid.");
        }

        $created = (bool) $response->json('created');

        if ($created) {
            $log->sub("Created new alias /{$alias} -> token {$uuid}");
        } else {
            $log->sub("Alias /{$alias} already exists -> token {$uuid}");

            // Keep the canned response in step with this run's format. Non-fatal.
            try {
                $refresh = $this->client()->put("{$baseUrl}/token/{$uuid}", $tokenConfig);

                if (! $refresh->successful()) {
                    $log->warn("Could not update the alias token's default response (non-fatal): HTTP {$refresh->status()}");
                }
            } catch (ConnectionException $exception) {
                $log->warn("Could not update the alias token's default response (non-fatal): {$exception->getMessage()}");
            }
        }

        $endpoint = new WebhookEndpoint(
            url: "{$baseUrl}/{$alias}",
            viewUrl: "{$baseUrl}/#!/{$uuid}",
            uuid: $uuid,
            alias: $alias,
            created: $created,
        );

        $log->sub("Webhook endpoint : {$endpoint->url}");
        $log->sub("Dashboard        : {$endpoint->viewUrl}");

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $tokenConfig
     */
    private function createOneOff(string $baseUrl, array $tokenConfig, UploadRunLog $log): WebhookEndpoint
    {
        $log->step('Creating one-off webhook endpoint and injecting Broadcast...');

        $response = $this->send(
            fn (): Response => $this->client()->post("{$baseUrl}/token", $tokenConfig),
            "Failed to create webhook token at {$baseUrl}/token",
        );

        $uuid = $response->json('uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new WebhookProvisioningException('The webhook service answered the token request without a uuid.');
        }

        $endpoint = new WebhookEndpoint(
            url: "{$baseUrl}/{$uuid}",
            viewUrl: "{$baseUrl}/#!/{$uuid}",
            uuid: $uuid,
            alias: null,
            created: true,
        );

        $log->sub("New webhook endpoint: {$endpoint->viewUrl}");

        return $endpoint;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $failureMessage): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new WebhookProvisioningException("{$failureMessage}: the webhook service could not be reached (timed out or unreachable).", previous: $exception);
        }

        if (! $response->successful()) {
            throw new WebhookProvisioningException("{$failureMessage}: HTTP {$response->status()}.");
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::asJson()->acceptJson()->timeout(15)->connectTimeout(10);
    }
}
```

- [ ] **Step 5: Run to verify it passes, format, commit**

Run: `php artisan test --compact tests/Feature/WebhookEndpointProvisionerTest.php`
Expected: PASS.

```bash
vendor/bin/pint app/Support/Webhook config/services.php tests/Feature/WebhookEndpointProvisionerTest.php
git add app/Support/Webhook config/services.php tests/Feature/WebhookEndpointProvisionerTest.php
git commit -m "feat: provision the per-environment webhook alias for broadcast injection"
```

---

### Task 7: Summary extractor reads `ScheduleData` and streams JSON

**Files:**
- Modify: `app/Support/ScheduleDataSummaryExtractor.php`
- Modify: `tests/Unit/ScheduleDataSummaryExtractorTest.php`

**Interfaces:**
- Consumes: `JsonTokenReader` from Task 4.
- Produces: `ScheduleDataSummaryExtractor::extract(string $filePath, string $format): array` with the same return shape as today; `MAX_JSON_PARSE_BYTES` constant removed.

- [ ] **Step 1: Update and add tests**

In `tests/Unit/ScheduleDataSummaryExtractorTest.php`, replace the test `skips parsing a json file over the size cap rather than risk the worker memory limit` (around line 102) with the two tests below (keep its helper style; reuse any temp-file helper the file already defines, otherwise inline as shown):

```php
it('reads a json file far larger than the old 20 MB parse cap', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');

    $handle = fopen($path, 'wb');
    fwrite($handle, '{"dsScheduleData":{"Input_Reference":{"datetime":"2026-09-10T12:26:05Z","dataset_id":"BIG"},"Activity":[');
    $row = '{"id":"A","description":"'.str_repeat('x', 200).'"},';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '{"id":"LAST"}],"Resources":[{"id":"R1"},{"id":"R2"}]}}');
    fclose($handle);

    try {
        expect(filesize($path))->toBeGreaterThan(20 * 1024 * 1024);

        $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

        expect($summary['dataset_id'])->toBe('BIG');
        expect($summary['activity_count'])->toBe(100001);
        expect($summary['resource_count'])->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('reads the 6.15+ ScheduleData wrapper', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');
    file_put_contents($path, json_encode(['ScheduleData' => [
        'Input_Reference' => [['datetime' => '2026-09-10T12:26:05Z', 'dataset_id' => 'NEWFMT']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
        'Resources' => [['id' => 'R1']],
    ]]));

    try {
        $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

        expect($summary['dataset_id'])->toBe('NEWFMT');
        expect($summary['activity_count'])->toBe(3);
        expect($summary['resource_count'])->toBe(1);
        expect($summary['input_reference_datetime'])->not->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('counts a legacy single-object entity as one row', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');
    file_put_contents($path, '{"dsScheduleData":{"Resources":{"id":"R1"}}}');

    try {
        expect(ScheduleDataSummaryExtractor::extract($path, 'json')['resource_count'])->toBe(1);
    } finally {
        @unlink($path);
    }
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Unit/ScheduleDataSummaryExtractorTest.php`
Expected: FAIL on the three new tests (cap returns empty; `ScheduleData` not read).

- [ ] **Step 3: Implement**

In `app/Support/ScheduleDataSummaryExtractor.php`: add `use App\Support\ScheduleData\JsonTokenReader;`; delete the `MAX_JSON_PARSE_BYTES` constant; update the class docblock paragraph about JSON to say "JSON is read with the same chunked token reader, so it is safe at any size too"; replace `extractFromJson()` with:

```php
    private static function extractFromJson(string $filePath): array
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return self::empty();
        }

        $depth = 0;
        $previous = '';
        $wrapperPending = false;
        $wrapperFound = false;
        $insideWrapper = false;
        $entityName = null;
        $entityIsArray = false;
        $wantedField = null;
        $datasetId = null;
        $datetime = null;
        $activityCount = 0;
        $resourceCount = 0;

        try {
            foreach (app(JsonTokenReader::class)->tokens($handle) as $token) {
                if (strspn($token[0], " \t\r\n") === 1) {
                    continue;
                }

                $before = $previous;
                $previous = $token;

                if ($wantedField !== null) {
                    if ($token === ':') {
                        continue;
                    }

                    if ($token[0] === '"') {
                        $text = json_decode($token);

                        if (is_string($text) && $text !== '') {
                            if ($wantedField === 'dataset_id') {
                                $datasetId ??= $text;
                            } else {
                                $datetime ??= $text;
                            }
                        }
                    }

                    $wantedField = null;

                    if ($token !== '{' && $token !== '[') {
                        continue;
                    }
                }

                if ($token === '{' || $token === '[') {
                    if ($wrapperPending && $token === '{' && $depth === 1) {
                        $insideWrapper = true;
                        $wrapperFound = true;
                        $wrapperPending = false;
                    } elseif ($insideWrapper && $depth === 2 && $entityName !== null) {
                        $entityIsArray = $token === '[';

                        if (! $entityIsArray) {
                            self::tally($entityName, $activityCount, $resourceCount);
                        }
                    } elseif ($insideWrapper && $depth === 3 && $entityIsArray && $token === '{') {
                        self::tally($entityName, $activityCount, $resourceCount);
                    }

                    $depth++;

                    continue;
                }

                if ($token === '}' || $token === ']') {
                    $depth--;

                    if ($insideWrapper && $depth === 1) {
                        $insideWrapper = false;
                    }

                    continue;
                }

                if ($token === ':' || $token === ',') {
                    continue;
                }

                if ($token[0] === '"' && ($before === '{' || $before === ',')) {
                    $key = json_decode($token);

                    if ($depth === 1 && ! $wrapperFound) {
                        $wrapperPending = in_array($key, ['ScheduleData', 'dsScheduleData'], true);
                    } elseif ($insideWrapper && $depth === 2) {
                        $entityName = is_string($key) ? $key : null;
                        $entityIsArray = false;
                    } elseif (
                        $insideWrapper
                        && $entityName === 'Input_Reference'
                        && (($depth === 3 && ! $entityIsArray) || ($depth === 4 && $entityIsArray))
                    ) {
                        $wantedField = in_array($key, ['dataset_id', 'datetime'], true) ? $key : null;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $wrapperFound) {
            return self::empty();
        }

        return [
            'dataset_id' => $datasetId,
            'input_reference_datetime' => self::parseDatetime($datetime),
            'activity_count' => $activityCount,
            'resource_count' => $resourceCount,
        ];
    }

    private static function tally(?string $entityName, int &$activityCount, int &$resourceCount): void
    {
        match ($entityName) {
            'Activity' => $activityCount++,
            'Resources' => $resourceCount++,
            default => null,
        };
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact tests/Unit/ScheduleDataSummaryExtractorTest.php tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: PASS (the existing single-element-array Input_Reference and malformed-JSON tests still pass; malformed input yields no wrapper and the empty summary).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Support/ScheduleDataSummaryExtractor.php tests/Unit/ScheduleDataSummaryExtractorTest.php
git add app/Support/ScheduleDataSummaryExtractor.php tests/Unit/ScheduleDataSummaryExtractorTest.php
git commit -m "fix: summary extractor reads ScheduleData and streams JSON of any size"
```

---

### Task 8: The verbose job flow

**Files:**
- Modify: `app/Jobs/SendPsoScheduleDataJob.php`
- Modify: `tests/Feature/SendPsoScheduleDataJobTest.php`

**Interfaces:**
- Consumes: `UploadRunLog` (Task 2), `BroadcastSettings`/`BroadcastInjection` (Task 3), `ScheduleDataRewriterFactory`/`RewriteReport` (Tasks 4-5), `WebhookEndpointProvisioner`/`WebhookProvisioningException` (Task 6), trait `authenticatePSO()`, `logoffPSO()`, `$session_error_message` (Task 1), the summary extractor (Task 7), and the `PsoGatewayUpload` columns (Task 2).
- Produces: a job whose constructor is unchanged (`psoGatewayUploadId, baseUrl, accountId, username, password`) and which, per row: reads `dataset_id_override`, `skip_webhook`, `broadcast_settings`; writes `webhook_url`, `webhook_view_url`, `response_status`, `response_body`, `wire_bytes`, `post_duration_ms`, `internal_id`; and records the run log.

- [ ] **Step 1: Write the failing job tests**

Append to `tests/Feature/SendPsoScheduleDataJobTest.php` (add `use Tests\Support\PsoServicesFake;` if not already imported by Task 1, and `use App\Support\ScheduleData\BroadcastSettings;`):

```php
function webhookJobUpload(string $path, string $contents, array $attributes = []): PsoGatewayUpload
{
    Storage::disk('r2')->put($path, $contents);

    return PsoGatewayUpload::factory()
        ->for(Environment::factory()->create(['name' => 'SaskTel TST']), 'environment')
        ->create([
            'stored_path' => $path,
            'original_filename' => basename($path),
            'skip_webhook' => false,
            ...$attributes,
        ]);
}

function runUploadJob(PsoGatewayUpload $upload): void
{
    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();
}

function fakeWebhookAndGateway(array $extra = []): void
{
    config([
        'services.webhook.base_url' => 'https://webhook.example.test',
        'services.webhook.alias_key' => 'alias-key-123',
    ]);

    Http::fake([
        ...PsoServicesFake::session(),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
        ...$extra,
    ]);
}

function sentPayload(): array
{
    $sent = null;

    Http::assertSent(static function ($request) use (&$sent) {
        if (str_contains((string) $request->url(), '/scheduling/data')) {
            $sent = json_decode(gzdecode((string) $request->body()), true, flags: JSON_THROW_ON_ERROR);

            return true;
        }

        return false;
    });

    return $sent;
}

it('injects the webhook broadcast and records the webhook and response', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Resources":[{"id":"R1"}]}}');

    runUploadJob($upload);

    $upload->refresh();
    $payload = sentPayload();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->webhook_url)->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($upload->webhook_view_url)->toBe('https://webhook.example.test/#!/uuid-1');
    expect($upload->response_status)->toBe(200);
    expect($upload->response_body)->toContain('1857054');
    expect($upload->wire_bytes)->toBeGreaterThan(0);
    expect($upload->post_duration_ms)->not->toBeNull();
    expect($payload['ScheduleData']['Broadcast'][0]['maximum_wait'])->toBe('PT5M');
    expect($payload['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
    expect(collect($payload['ScheduleData']['Broadcast_Parameter'])->firstWhere('parameter_name', 'url')['parameter_value'])
        ->toBe('https://webhook.example.test/pso-sasktel-tst');
    expect($payload['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('OLD');
});

it('uses the per-upload broadcast settings', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Resources":[]}}',
        ['broadcast_settings' => BroadcastSettings::fromArray(['id' => 'MY_HOOK', 'maximum_wait' => 'PT10M', 'minimum_plan_quality' => 90])->toArray()],
    );

    runUploadJob($upload);

    $broadcast = sentPayload()['ScheduleData']['Broadcast'][0];

    expect($broadcast['id'])->toBe('MY_HOOK');
    expect($broadcast['maximum_wait'])->toBe('PT10M');
    expect($broadcast['minimum_plan_quality'])->toBe(90);
});

it('sends the original bytes when the webhook is skipped and no dataset id is given', function () {
    fakeWebhookAndGateway();
    $original = '{"ScheduleData":{"Resources":[{"id":"R1"}]}}';
    $upload = webhookJobUpload('gateway-uploads/schedule.json', $original, ['skip_webhook' => true]);

    runUploadJob($upload);

    expect(json_encode(sentPayload()))->toBe(json_encode(json_decode($original, true)));
    expect($upload->refresh()->webhook_url)->toBeNull();
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), 'webhook.example.test'));
});

it('applies a dataset id override even when the webhook is skipped', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload(
        'gateway-uploads/schedule.json',
        '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}',
        ['skip_webhook' => true, 'dataset_id_override' => 'NEW'],
    );

    runUploadJob($upload);

    $upload->refresh();

    expect(sentPayload()['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($upload->dataset_id)->toBe('NEW');
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain("'OLD' -> 'NEW'");
});

it('injects into an xml upload', function () {
    fakeWebhookAndGateway();
    $xml = '<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd"><Resources><id>R1</id></Resources></dsScheduleData>';
    $upload = webhookJobUpload('gateway-uploads/schedule.xml', $xml);

    runUploadJob($upload);

    $sentXml = null;
    Http::assertSent(static function ($request) use (&$sentXml) {
        if (str_contains((string) $request->url(), '/scheduling/data')) {
            $sentXml = gzdecode((string) $request->body());

            return $request->hasHeader('Content-Type', 'application/xml');
        }

        return false;
    });

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($sentXml)->toContain('<Broadcast>')->toContain('<maximum_wait>PT5M</maximum_wait>');
});

it('fails the run and sends nothing to PSO when the webhook cannot be provisioned', function () {
    fakeWebhookAndGateway(['https://webhook.example.test/_alias/*' => Http::response('forbidden', 403)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('403');
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
    Http::assertNotSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl());
});

it('fails with the script message when the file has no schedule data wrapper', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"Other":{}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain("Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
    Http::assertNotSent(static fn ($request) => str_contains((string) $request->url(), '/scheduling/data'));
});

it('logs off through pso-services after a successful upload', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    Http::assertSent(static fn ($request) => $request->method() === 'DELETE'
        && $request->url() === PsoServicesFake::sessionUrl()
        && $request->hasHeader('token', 'tok-abc'));
});

it('still logs off when the gateway POST fails', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => Http::response(['Message' => 'Invalid Parameters'], 400)]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    Http::assertSent(static fn ($request) => $request->method() === 'DELETE' && $request->url() === PsoServicesFake::sessionUrl());
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain('HTTP status      : 400');
});

it('still logs off when the gateway POST times out', function () {
    fakeWebhookAndGateway(['*/scheduling/data' => static function () {
        throw new ConnectionException('Connection timed out');
    }]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    Http::assertSent(static fn ($request) => $request->method() === 'DELETE' && $request->url() === PsoServicesFake::sessionUrl());
});

it('treats a failing logoff as a warning, not a failed run', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        ...PsoServicesFake::session(logoffStatus: 500),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->logLines->where('level', GatewayUploadLogLevel::WARN)->pluck('text')->implode("\n"))->toContain('Logoff failed');
});

it('puts the pso-services reason in the run log when logon is refused', function () {
    config(['services.webhook.base_url' => 'https://webhook.example.test', 'services.webhook.alias_key' => 'alias-key-123']);
    Http::fake([
        PsoServicesFake::sessionUrl() => Http::response(['message' => 'Production URLs are not allowed', 'errors' => ['baseUrl' => ['Production URLs are not allowed']]], 422),
        'https://webhook.example.test/_alias/*' => Http::response(['uuid' => 'uuid-1', 'created' => true], 201),
    ]);
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->logLines->pluck('text')->implode("\n"))->toContain('Production URLs are not allowed');
});

it('writes the script-style steps to the run log and never a secret', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Broadcast":[{"id":"X"}]}}');

    runUploadJob($upload);

    $text = $upload->refresh()->logLines()->orderBy('id')->pluck('text')->implode("\n");

    expect($text)->toContain('PSO RESTful Gateway - Schedule Data Upload');
    expect($text)->toContain('Created new alias /pso-sasktel-tst');
    expect($text)->toContain('Stripped existing broadcast from the file: 1 Broadcast and 0 Broadcast_Parameter row(s) removed.');
    expect($text)->toContain("Injected Broadcast 'WEBHOOK_COMPLETE'");
    expect($text)->toContain('maximum_wait=PT5M');
    expect($text)->toContain('required_tables: Allocation,Plan,');
    expect($text)->toContain('Logon successful');
    expect($text)->toContain('Response body:');
    expect($text)->toContain('Done in');
    expect($text)->toContain('Webhook endpoint for this run: https://webhook.example.test/#!/uuid-1');
    expect($text)->not->toContain('secret-password');
    expect($text)->not->toContain('tok-abc');
    expect($text)->not->toContain('alias-key-123');
});

it('logs "nothing to strip" when the file has no broadcast rows', function () {
    fakeWebhookAndGateway();
    $upload = webhookJobUpload('gateway-uploads/schedule.json', '{"ScheduleData":{"Resources":[]}}');

    runUploadJob($upload);

    expect($upload->refresh()->logLines->pluck('text')->implode("\n"))->toContain('No existing Broadcast or Broadcast_Parameter in the file - nothing to strip.');
});
```

Add imports at the top of the file if missing: `use App\Enums\GatewayUploadLogLevel;`, `use Illuminate\Http\Client\ConnectionException;` (already imported).

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: the new tests FAIL; the Task 1 repointed tests still pass.

- [ ] **Step 3: Replace the job**

Replace the body of `app/Jobs/SendPsoScheduleDataJob.php` with the following (imports first; `downloadOriginalUpload()`, `mapGatewayError()` and `failed()` keep their current text, shown here in full so the file is complete):

```php
<?php

namespace App\Jobs;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\PsoGatewayUpload;
use App\Support\GatewayUpload\UploadRunLog;
use App\Support\GatewayUploadPath;
use App\Support\GzipFileCompressor;
use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\RewriteReport;
use App\Support\ScheduleData\ScheduleDataRewriterFactory;
use App\Support\ScheduleDataSummaryExtractor;
use App\Support\ScheduleDataZipExtractor;
use App\Support\Webhook\WebhookEndpointProvisioner;
use App\Traits\PSOInteractionsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class SendPsoScheduleDataJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PSOInteractionsTrait, Queueable, SerializesModels;

    // Download, unzip, stream-rewrite, gzip, logon, a POST of up to 240s and
    // logoff all run inside this. The queue connection's retry_after must be
    // larger than this value (see config/queue.php).
    public int $timeout = 900;

    public int $tries = 1;

    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

    private const int RESPONSE_BODY_LIMIT = 65536; // 64KB kept on the row and in the log

    public function __construct(
        public string $psoGatewayUploadId,
        public string $baseUrl,
        public string $accountId,
        public string $username,
        #[SensitiveParameter] public string $password,
    ) {}

    public function handle(): void
    {
        $upload = PsoGatewayUpload::findOrFail($this->psoGatewayUploadId);

        // The stored path originates in browser-supplied form state, so it is
        // re-checked here — before anything reads from or deletes on the
        // shared r2 bucket — rather than trusting the row.
        if (! GatewayUploadPath::isAllowed($upload->stored_path)) {
            Log::warning('Refused a PSO gateway upload with an unexpected stored path', [
                'upload_id' => $upload->id,
                'stored_path' => $upload->stored_path,
            ]);

            $this->failUpload($upload, 'The stored location of this upload is not a valid gateway upload path, so nothing was sent to PSO.');

            return;
        }

        $log = app(UploadRunLog::class, ['upload' => $upload]);
        $runStartedAt = microtime(true);

        $upload->update(['status' => PsoGatewayUploadStatus::COMPRESSING, 'started_at' => now()]);

        $workDir = storage_path('app/private/gateway-uploads/'.$upload->id);
        if (! is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $sessionToken = null;

        try {
            $log->step('=== PSO RESTful Gateway - Schedule Data Upload ===');
            $log->sub("Gateway base URL : {$this->baseUrl}");
            $log->sub("Account          : {$this->accountId}");
            $log->sub("User             : {$this->username}");
            $log->sub("File             : {$upload->original_filename}");

            $downloadedPath = $this->downloadOriginalUpload($upload, $workDir);
            $log->step('File found: '.$this->megabytes((int) filesize($downloadedPath)).' MB');

            $isZip = str_ends_with(strtolower($downloadedPath), '.zip');
            $rawPath = $isZip
                ? ScheduleDataZipExtractor::extract($downloadedPath, $workDir)
                : $downloadedPath;

            if ($isZip) {
                $log->sub('Extracted '.basename($rawPath).' from the zip ('.$this->megabytes((int) filesize($rawPath)).' MB)');
            }

            $format = str_ends_with(strtolower($rawPath), '.xml') ? 'xml' : 'json';
            $contentType = $format === 'xml' ? 'application/xml' : 'application/json';
            $log->sub("Content type     : {$contentType} (from extension)");

            $sendPath = $this->preparePayload($upload, $log, $rawPath, $format, $contentType, $workDir);

            // Display-only — never blocks or alters the upload itself. If the
            // file can't be parsed for these fields, they're simply left null;
            // the bytes are still gzipped and sent unchanged either way.
            $summary = ScheduleDataSummaryExtractor::extract($sendPath, $format);

            $upload->update([
                'dataset_id' => $summary['dataset_id'],
                'input_reference_datetime' => $summary['input_reference_datetime'],
                'activity_count' => $summary['activity_count'],
                'resource_count' => $summary['resource_count'],
            ]);

            $log->step('Step 2/3: Preparing request body...');
            $log->sub('Gzip-compressing request body...');

            $sendSize = (int) filesize($sendPath);
            $gzipPath = $workDir.'/payload.gz';
            $compressStartedAt = microtime(true);
            $compressedSize = GzipFileCompressor::compress($sendPath, $gzipPath);

            $ratio = $sendSize > 0 ? round((1 - $compressedSize / $sendSize) * 100, 1) : 0;
            $log->sub('Compressed to '.$this->megabytes($compressedSize).' MB in '.number_format(microtime(true) - $compressStartedAt, 2)."s ({$ratio}% smaller)");

            $upload->update([
                'compressed_size_bytes' => $compressedSize,
                'status' => PsoGatewayUploadStatus::UPLOADING,
            ]);

            $log->step('Step 1/3: Logging on...');
            $sessionToken = $this->authenticatePSO($this->baseUrl, $this->accountId, $this->username, $this->password);

            if (! $sessionToken) {
                $log->error('LOGON FAILED: '.($this->session_error_message ?? 'no detail from the PSO services API'));
                $this->failUpload($upload, 'Could not authenticate with the PSO gateway. Check the environment credentials.');

                return;
            }

            $log->ok('Logon successful. Session token acquired (length: '.strlen($sessionToken).').');

            $gzipStream = @fopen($gzipPath, 'rb');

            if ($gzipStream === false) {
                throw new RuntimeException("The compressed payload could not be opened for sending: {$gzipPath}");
            }

            $dataUrl = "{$this->baseUrl}/IFSSchedulingRESTfulGateway/api/v1/scheduling/data";
            $log->step("Step 3/3: POSTing to {$dataUrl} ...");

            // Laravel's HTTP client defaults to a 30s response-wait timeout,
            // which is nowhere near enough here: PSO validates and writes
            // the whole payload before it responds, and that can legitimately
            // take longer than 30s for a large load. Without this, the load
            // lands and succeeds on PSO's side while this job still times
            // out and reports a false failure.
            $postStartedAt = microtime(true);
            $response = Http::timeout(240)
                ->connectTimeout(10)
                ->withHeaders([
                    'apiKey' => $sessionToken,
                    'Content-Encoding' => 'gzip',
                ])->withBody($gzipStream, $contentType)
                ->post($dataUrl);
            $postSeconds = microtime(true) - $postStartedAt;

            $responseBody = mb_strcut($response->body(), 0, self::RESPONSE_BODY_LIMIT, 'UTF-8');

            $upload->update([
                'response_status' => $response->status(),
                'response_body' => $responseBody,
                'wire_bytes' => $compressedSize,
                'post_duration_ms' => (int) round($postSeconds * 1000),
            ]);

            $rate = $postSeconds > 0 ? round(($compressedSize / 1048576) / $postSeconds, 2) : 0;

            $log->sub("HTTP status      : {$response->status()}");
            $log->sub('Wire bytes sent  : '.$this->megabytes($compressedSize).' MB');
            $log->sub('POST duration    : '.$this->duration($postSeconds));
            $log->sub("Effective rate   : {$rate} MB/s (wire, post-compression)");

            if ($responseBody !== '') {
                $log->sub('Response body:');
                $log->sub($responseBody);
            }

            $this->logOff($log, $sessionToken);
            $sessionToken = null;

            if ($response->successful()) {
                $log->ok('Upload complete.');
                $log->step('=== Done in '.$this->duration(microtime(true) - $runStartedAt).' total ===');

                if (filled($upload->fresh()->webhook_view_url)) {
                    $log->sub('Webhook endpoint for this run: '.$upload->fresh()->webhook_view_url);
                }

                $upload->update([
                    'status' => PsoGatewayUploadStatus::SUCCEEDED,
                    'internal_id' => $response->json('InternalId'),
                    'completed_at' => now(),
                ]);

                return;
            }

            $log->error('UPLOAD FAILED');
            $this->failUpload($upload, $this->mapGatewayError($response));
        } catch (ConnectionException $exception) {
            Log::error('PSO gateway connection error', ['upload_id' => $upload->id, 'message' => $exception->getMessage()]);
            $this->failUpload($upload, 'Could not reach the PSO gateway (timed out or unreachable).');
        } catch (Throwable $exception) {
            Log::error('PSO gateway upload failed', ['upload_id' => $upload->id, 'message' => $exception->getMessage()]);
            $this->failUpload($upload, $exception->getMessage());
        } finally {
            if ($sessionToken !== null) {
                $this->logOff($log, $sessionToken);
            }

            $this->cleanup($upload, $workDir);
        }
    }

    /**
     * Provisions the webhook, then writes the modified copy (dataset id
     * override, old broadcast rows stripped, new Broadcast injected) and
     * returns its path. Returns the original path when there is nothing to do.
     */
    private function preparePayload(
        PsoGatewayUpload $upload,
        UploadRunLog $log,
        string $rawPath,
        string $format,
        string $contentType,
        string $workDir,
    ): string {
        $datasetIdOverride = filled($upload->dataset_id_override) ? trim((string) $upload->dataset_id_override) : null;
        $injection = null;

        if ($upload->skip_webhook) {
            $log->sub('Webhook broadcast skipped (Skip webhook broadcast is on).');
        } else {
            $endpoint = app(WebhookEndpointProvisioner::class)->provision(
                (string) $upload->environment?->name,
                $contentType,
                $log,
            );

            $upload->update([
                'webhook_url' => $endpoint->url,
                'webhook_view_url' => $endpoint->viewUrl,
            ]);

            $injection = new BroadcastInjection(
                BroadcastSettings::fromArray($upload->broadcast_settings ?? []),
                $endpoint->url,
            );
        }

        if ($datasetIdOverride === null && $injection === null) {
            $log->sub('No dataset ID override and no webhook - sending the file exactly as uploaded.');

            return $rawPath;
        }

        $destinationPath = "{$workDir}/payload_modified.{$format}";

        $log->step('Rewriting the file (streaming)...');
        $report = app(ScheduleDataRewriterFactory::class)
            ->forFormat($format)
            ->rewrite($rawPath, $destinationPath, $datasetIdOverride, $injection);

        $this->logRewrite($log, $report, $datasetIdOverride, $injection);
        $log->sub('Sending modified copy instead of the original: '.basename($destinationPath).' ('.$this->megabytes($report->bytesWritten).' MB)');

        return $destinationPath;
    }

    private function logRewrite(UploadRunLog $log, RewriteReport $report, ?string $datasetIdOverride, ?BroadcastInjection $injection): void
    {
        if ($report->datasetIds === []) {
            $log->sub('No Input_Reference.dataset_id in the file - nothing to keep or change.');
        } else {
            $current = implode(', ', array_map(static fn (string $id): string => $id === '' ? '(blank)' : $id, $report->datasetIds));

            if ($datasetIdOverride !== null && $report->datasetIdChanged) {
                $log->sub("Changed Input_Reference.dataset_id: '{$current}' -> '{$datasetIdOverride}'");
            } else {
                $log->sub("Keeping Input_Reference.dataset_id: {$current}");
            }
        }

        if ($injection === null) {
            return;
        }

        if ($report->strippedBroadcasts === 0 && $report->strippedBroadcastParameters === 0) {
            $log->warn('No existing Broadcast or Broadcast_Parameter in the file - nothing to strip.');
        } else {
            $log->warn("Stripped existing broadcast from the file: {$report->strippedBroadcasts} Broadcast and {$report->strippedBroadcastParameters} Broadcast_Parameter row(s) removed.");
        }

        $settings = $injection->settings;

        $log->sub("Injected Broadcast '{$settings->broadcastId}' (plan_type={$settings->planType}, once_only=true, minimum_plan_quality={$settings->minimumPlanQuality}, maximum_wait={$settings->maximumWait}, allocation_type={$settings->allocationType})");
        $log->sub('required_tables: '.implode(',', $settings->requiredTables));
    }

    private function logOff(UploadRunLog $log, string $sessionToken): void
    {
        $log->step('Logging off session...');

        if ($this->logoffPSO($this->baseUrl, $sessionToken)) {
            $log->sub('Session logged off.');

            return;
        }

        $log->warn('Logoff failed (non-fatal): '.($this->session_error_message ?? 'no detail from the PSO services API'));
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 2);
    }

    private function duration(float $seconds): string
    {
        return sprintf('%02d:%06.3f', intdiv((int) $seconds, 60), fmod($seconds, 60));
    }

    private function downloadOriginalUpload(PsoGatewayUpload $upload, string $workDir): string
    {
        $extension = strtolower(pathinfo($upload->original_filename, PATHINFO_EXTENSION));
        $localPath = "{$workDir}/upload.{$extension}";

        $source = Storage::disk('r2')->readStream($upload->stored_path);

        if (! is_resource($source)) {
            throw new RuntimeException("The uploaded file could not be read from storage: {$upload->stored_path}");
        }

        $destination = @fopen($localPath, 'wb');

        if ($destination === false) {
            fclose($source);

            throw new RuntimeException("A local working copy of the upload could not be opened for writing: {$localPath}");
        }

        // Every I/O result is checked so a partial download fails loudly
        // instead of being gzipped and POSTed to PSO as a truncated payload.
        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException("The uploaded file could not be read from storage: {$upload->stored_path}");
                }

                if (fwrite($destination, $chunk) === false) {
                    throw new RuntimeException("A local working copy of the upload could not be written: {$localPath}");
                }
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        return $localPath;
    }

    private function mapGatewayError(Response $response): string
    {
        $message = $response->json('Message');

        return match ($message) {
            'AUTHENTICATION_FAILED' => 'The PSO gateway rejected the provided credentials.',
            'Invalid Parameters' => 'The PSO gateway rejected the uploaded schedule data as invalid.',
            default => $message
                ? "The PSO gateway returned an error: {$message}"
                : "The PSO gateway returned HTTP {$response->status()} with no further detail.",
        };
    }

    private function failUpload(PsoGatewayUpload $upload, string $message): void
    {
        app(UploadRunLog::class, ['upload' => $upload])->error($message);

        $upload->update([
            'status' => PsoGatewayUploadStatus::FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * Laravel calls this whenever the job never reached its own catch/finally
     * blocks — the worker was killed, `$timeout` fired, or an exception
     * escaped `handle()` — which would otherwise leave the row parked in a
     * non-terminal status that the UI polls forever.
     */
    public function failed(?Throwable $exception): void
    {
        $upload = PsoGatewayUpload::find($this->psoGatewayUploadId);

        if (! $upload || $upload->status->isTerminal()) {
            return;
        }

        Log::error('PSO gateway upload job failed outright', [
            'upload_id' => $upload->id,
            'message' => $exception?->getMessage(),
        ]);

        $this->failUpload($upload, 'The upload did not finish — the queue worker timed out or stopped before it could be sent to PSO. Please try again.');
    }

    private function cleanup(PsoGatewayUpload $upload, string $workDir): void
    {
        // Recursive: a zip entry such as `subdir/data.json` extracts into a
        // sub-directory, which a flat unlink/rmdir pair cannot remove — and
        // that would leak the whole extracted payload (10-100MB) forever.
        File::deleteDirectory($workDir);

        Storage::disk('r2')->delete($upload->stored_path);
    }
}
```

Note for the executor: `$sessionToken` is set to `null` after the in-line logoff; the `finally` logs off only for paths that threw or returned between logon and that point. The `Log` facade (`Log::error`) and the `$log` variable are different things: `$log` is the `UploadRunLog`.

- [ ] **Step 4: Run to verify the job tests pass**

Run: `php artisan test --compact tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: PASS. If `sentPayload()` fails because `$request->body()` is empty for a stream body, change the helper to read the stream from the request instance: `(string) $request->toPsrRequest()->getBody()` and rewind first; keep the rest of the assertions.

- [ ] **Step 5: Check the queue timeout contract and measure a large file**

Run: `grep -n "retry_after\|'timeout'" config/queue.php config/horizon.php 2>/dev/null`. The `retry_after` of the connection the job uses (and any worker `--timeout`) must be greater than 900. If it is not, raise it in `config/queue.php` (`'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 960)` for the database connection, or the same for redis) and add the new default to the commit; a `retry_after` below the job timeout re-queues a still-running job.

Then measure, informationally, with a throwaway script run through tinker (do not commit it):

```bash
php artisan tinker --execute '
$path = sys_get_temp_dir()."/bench.json";
$h = fopen($path, "wb");
fwrite($h, "{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"OLD\"}],\"Activity\":[");
$row = "{\"id\":\"ACT-000000\",\"description\":\"".str_repeat("x", 200)."\",\"priority\":5},";
for ($i = 0; $i < 400000; $i++) fwrite($h, $row);
fwrite($h, "{\"id\":\"LAST\"}]}}"); fclose($h);
$start = microtime(true);
app(App\Support\ScheduleData\JsonScheduleDataRewriter::class)->rewrite($path, $path.".out", "NEW", null);
echo round(filesize($path)/1048576)." MB rewritten in ".round(microtime(true)-$start, 1)."s\n";
unlink($path); unlink($path.".out");
'
```

Expected: roughly 100 MB. Record the seconds in the commit message. If it exceeds 120 s, stop and raise it with the user (the tokenizer would need a coarser-grained fast path) before continuing; do not silently accept it.

- [ ] **Step 6: Full upload test files, format, commit**

Run: `php artisan test --compact tests/Feature/SendPsoScheduleDataJobTest.php tests/Feature/GatewayUploadTabTest.php tests/Unit`
Expected: PASS.

```bash
vendor/bin/pint app/Jobs/SendPsoScheduleDataJob.php tests/Feature/SendPsoScheduleDataJobTest.php
git add app/Jobs/SendPsoScheduleDataJob.php tests/Feature/SendPsoScheduleDataJobTest.php config/queue.php
git commit -m "feat: webhook broadcast injection, dataset id override and verbose run log in the upload job"
```

---

### Task 9: Form fields, validation and the status panel

**Files:**
- Modify: `app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php`
- Modify: `resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php`
- Test: `tests/Feature/GatewayUploadWebhookFormTest.php`

**Interfaces:**
- Consumes: `BroadcastSettings` (Task 3), `PsoGatewayUploadLogLine` (Task 2), the job's row contract (Task 8).
- Produces: form state keys under `gateway_upload_data`: `dataset_id_override`, `skip_webhook`, `broadcast_id`, `broadcast_plan_type`, `broadcast_minimum_plan_quality`, `broadcast_maximum_wait`, `broadcast_allocation_type`, `broadcast_required_tables`; page methods `pollGatewayUploadLog(): void` and `viewGatewayUpload(string $uploadId): void`; page properties `array $gatewayLogLines`, `#[Locked] int $gatewayLogLastId`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GatewayUploadWebhookFormTest.php`:

```php
<?php

use App\Enums\GatewayUploadLogLevel;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function webhookFormEnvironment(): Environment
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);
}

function submitWebhookForm(Environment $environment, array $formState = []): Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'gateway_upload_file' => UploadedFile::fake()->create('schedule.json', 100, 'application/json'),
            ...$formState,
        ], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));
}

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
});

it('defaults the broadcast settings to the script values', function () {
    $environment = webhookFormEnvironment();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->assertSchemaStateSet([
            'broadcast_id' => 'WEBHOOK_COMPLETE',
            'broadcast_plan_type' => 'COMPLETE',
            'broadcast_minimum_plan_quality' => 85,
            'broadcast_maximum_wait' => 'PT5M',
            'broadcast_allocation_type' => 1,
            'skip_webhook' => false,
            'dataset_id_override' => null,
        ], 'gatewayUploadForm');
});

it('stores the override, toggle and broadcast settings on the upload row', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, [
        'dataset_id_override' => ' SASK_UAT ',
        'broadcast_id' => 'MY_HOOK',
        'broadcast_maximum_wait' => 'PT10M',
        'broadcast_minimum_plan_quality' => 90,
    ]);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->firstOrFail();

    expect($upload->dataset_id_override)->toBe('SASK_UAT');
    expect($upload->skip_webhook)->toBeFalse();
    expect($upload->broadcast_settings)->toMatchArray([
        'id' => 'MY_HOOK',
        'maximum_wait' => 'PT10M',
        'minimum_plan_quality' => 90,
        'plan_type' => 'COMPLETE',
        'allocation_type' => 1,
    ]);

    Queue::assertPushed(SendPsoScheduleDataJob::class);
});

it('stores the skip toggle and ignores broadcast settings when skipped', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['skip_webhook' => true, 'broadcast_maximum_wait' => 'not a duration']);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->firstOrFail();

    expect($upload->skip_webhook)->toBeTrue();
    expect($upload->broadcast_settings)->toBeNull();
});

it('refuses an invalid maximum wait and creates no upload', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_maximum_wait' => '5 minutes']);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a minimum plan quality outside 0 to 100', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_minimum_plan_quality' => 150]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('refuses an empty required-tables list', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_required_tables' => []]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('shows the run log, response and webhook link of a viewed upload', function () {
    $environment = webhookFormEnvironment();

    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'status' => App\Enums\PsoGatewayUploadStatus::SUCCEEDED,
        'internal_id' => '1857054',
        'response_status' => 200,
        'response_body' => '{"InternalId":"1857054"}',
        'webhook_url' => 'https://webhook.example.test/pso-env',
        'webhook_view_url' => 'https://webhook.example.test/#!/uuid-1',
        'wire_bytes' => 2097152,
        'post_duration_ms' => 4000,
        'completed_at' => now(),
    ]);

    PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $upload->id,
        'level' => GatewayUploadLogLevel::STEP,
        'text' => 'Ensuring webhook alias',
        'created_at' => now(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $upload->id)
        ->assertSee('Ensuring webhook alias')
        ->assertSee('1857054')
        ->assertSee('https://webhook.example.test/pso-env')
        ->assertSee('https://webhook.example.test/#!/uuid-1')
        ->assertSee('{"InternalId":"1857054"}');
});

it('appends only new log lines on each poll', function () {
    $environment = webhookFormEnvironment();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create();

    $line = static fn (string $text) => PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $upload->id,
        'level' => GatewayUploadLogLevel::SUB,
        'text' => $text,
        'created_at' => now(),
    ]);

    $line('first');

    $component = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $upload->id);

    expect($component->get('gatewayLogLines'))->toHaveCount(1);

    $line('second');
    $component->call('pollGatewayUploadLog');
    $component->call('pollGatewayUploadLog');

    expect(collect($component->get('gatewayLogLines'))->pluck('text')->all())->toBe(['first', 'second']);
});

it('will not show another environment\'s upload', function () {
    $environment = webhookFormEnvironment();
    $foreign = PsoGatewayUpload::factory()->create();

    PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $foreign->id,
        'level' => GatewayUploadLogLevel::SUB,
        'text' => 'foreign secret line',
        'created_at' => now(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $foreign->id)
        ->assertDontSee('foreign secret line');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/GatewayUploadWebhookFormTest.php`
Expected: FAIL (fields and methods do not exist).

- [ ] **Step 3: Page properties, defaults and methods**

In `app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php`:

Add imports: `use App\Models\PsoGatewayUploadLogLine;`, `use App\Support\ScheduleData\BroadcastSettings;`, `use Filament\Forms\Components\TagsInput;`, `use Illuminate\Support\Facades\Validator;`, `use Illuminate\Validation\Rule;`, `use BackedEnum;`.

Next to `public ?string $gatewayUploadId = null;` add:

```php
    /**
     * Run-log lines of the tracked upload, appended to on each poll.
     *
     * @var array<int, array{level: string, text: string, at: string}>
     */
    public array $gatewayLogLines = [];

    #[Locked]
    public int $gatewayLogLastId = 0;
```

In `mount()`, replace `$this->gatewayUploadForm->fill($recordData);` with:

```php
        $this->gatewayUploadForm->fill([...$recordData, ...$this->gatewayUploadDefaults()]);
```

Add the methods (near `currentGatewayUpload()`):

```php
    /**
     * @return array<string, mixed>
     */
    private function gatewayUploadDefaults(): array
    {
        $settings = BroadcastSettings::defaults();

        return [
            'dataset_id_override' => null,
            'skip_webhook' => false,
            'broadcast_id' => $settings->broadcastId,
            'broadcast_plan_type' => $settings->planType,
            'broadcast_minimum_plan_quality' => $settings->minimumPlanQuality,
            'broadcast_maximum_wait' => $settings->maximumWait,
            'broadcast_allocation_type' => $settings->allocationType,
            'broadcast_required_tables' => $settings->requiredTables,
        ];
    }

    /**
     * Appends log lines written since the last poll. Cheap on purpose: the
     * panel polls this every two seconds while a run is in progress.
     */
    public function pollGatewayUploadLog(): void
    {
        $upload = $this->currentGatewayUpload();

        if ($upload === null) {
            return;
        }

        $newLines = PsoGatewayUploadLogLine::query()
            ->where('pso_gateway_upload_id', $upload->id)
            ->where('id', '>', $this->gatewayLogLastId)
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($newLines as $line) {
            $this->gatewayLogLines[] = [
                'level' => $line->level->value,
                'text' => $line->text,
                'at' => $line->created_at->format('H:i:s'),
            ];
            $this->gatewayLogLastId = $line->id;
        }
    }

    /**
     * Shows one of this environment's past uploads (its summary and stored
     * log) in the status panel. Any other id is ignored.
     */
    public function viewGatewayUpload(string $uploadId): void
    {
        $upload = PsoGatewayUpload::query()
            ->where('pso_environment_id', $this->record->id)
            ->whereKey($uploadId)
            ->first();

        if ($upload === null) {
            return;
        }

        $this->gatewayUploadId = $upload->id;
        $this->gatewayLogLines = [];
        $this->gatewayLogLastId = 0;

        $this->pollGatewayUploadLog();
    }

    /**
     * Validates the broadcast part of the form. Returns null (after raising a
     * notification) when it is invalid.
     *
     * @return array{id: string, plan_type: string, minimum_plan_quality: int, maximum_wait: string, allocation_type: int, required_tables: list<string>}|null
     */
    private function validatedBroadcastSettings(Get $get): ?array
    {
        $normalise = static fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value;

        $values = [
            'broadcast_id' => $get('broadcast_id'),
            'broadcast_plan_type' => $normalise($get('broadcast_plan_type')),
            'broadcast_minimum_plan_quality' => $get('broadcast_minimum_plan_quality'),
            'broadcast_maximum_wait' => $get('broadcast_maximum_wait'),
            'broadcast_allocation_type' => $normalise($get('broadcast_allocation_type')),
            'broadcast_required_tables' => $get('broadcast_required_tables'),
        ];

        $validator = Validator::make($values, [
            'broadcast_id' => ['required', 'string', 'max:255'],
            'broadcast_plan_type' => ['required', Rule::enum(BroadcastPlanType::class)],
            'broadcast_minimum_plan_quality' => ['required', 'integer', 'between:0,100'],
            'broadcast_maximum_wait' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! BroadcastSettings::isValidDuration((string) $value)) {
                        $fail('Maximum wait must be an ISO 8601 duration such as PT5M.');
                    }
                },
            ],
            'broadcast_allocation_type' => ['required', Rule::enum(BroadcastAllocationType::class)],
            'broadcast_required_tables' => ['required', 'array', 'min:1'],
            'broadcast_required_tables.*' => ['string', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        if ($validator->fails()) {
            $this->notifyPayloadSent('Upload Failed', (string) $validator->errors()->first(), false);

            return null;
        }

        return BroadcastSettings::fromArray([
            'id' => $values['broadcast_id'],
            'plan_type' => $values['broadcast_plan_type'],
            'minimum_plan_quality' => $values['broadcast_minimum_plan_quality'],
            'maximum_wait' => $values['broadcast_maximum_wait'],
            'allocation_type' => $values['broadcast_allocation_type'],
            'required_tables' => $values['broadcast_required_tables'],
        ])->toArray();
    }
```

In `submitGatewayUpload()`, after the `$decryptedPassword` try/catch and before `PsoGatewayUpload::create([...])`, add:

```php
        $datasetIdOverride = filled($get('dataset_id_override')) ? trim((string) $get('dataset_id_override')) : null;
        $skipWebhook = (bool) $get('skip_webhook');

        if (filled($datasetIdOverride) && mb_strlen($datasetIdOverride) > 255) {
            $this->notifyPayloadSent('Upload Failed', 'The dataset ID override can be at most 255 characters.', false);

            return;
        }

        $broadcastSettings = null;

        if (! $skipWebhook) {
            $broadcastSettings = $this->validatedBroadcastSettings($get);

            if ($broadcastSettings === null) {
                return;
            }
        }
```

and add three keys to the `PsoGatewayUpload::create([...])` array:

```php
            'dataset_id_override' => $datasetIdOverride,
            'skip_webhook' => $skipWebhook,
            'broadcast_settings' => $broadcastSettings,
```

After `$this->gatewayUploadId = $upload->id;` add:

```php
        $this->gatewayLogLines = [];
        $this->gatewayLogLastId = 0;
        $set('dataset_id_override', null);
```

- [ ] **Step 4: Form fields**

In `gatewayUploadForm()`, insert after the `FileUpload::make('gateway_upload_file')` component and before the `Hidden::make('gateway_upload_original_filename')`:

```php
                TextInput::make('dataset_id_override')
                    ->label('Dataset ID override')
                    ->helperText('Leave blank to keep the file\'s Input_Reference.dataset_id. When set, it replaces the value in every Input_Reference row of a modified copy; the original is never changed.')
                    ->maxLength(255)
                    ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                    ->columnSpanFull(),
                Toggle::make('skip_webhook')
                    ->label('Skip webhook broadcast')
                    ->helperText('Send the file as-is: no webhook endpoint is created and no Broadcast is injected.')
                    ->live()
                    ->disabled(fn (): bool => $this->gatewayUploadInProgress())
                    ->columnSpanFull(),
                Section::make('Broadcast settings')
                    ->description('Defaults match the PowerShell script. They apply to the injected webhook Broadcast only.')
                    ->collapsed()
                    ->hidden(static fn (Get $get): bool => (bool) $get('skip_webhook'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('broadcast_id')
                            ->label('Broadcast ID')
                            ->maxLength(255),
                        Select::make('broadcast_plan_type')
                            ->label('Plan Type')
                            ->native(false)
                            ->options(BroadcastPlanType::class),
                        TextInput::make('broadcast_minimum_plan_quality')
                            ->label('Minimum Plan Quality')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        TextInput::make('broadcast_maximum_wait')
                            ->label('Maximum Wait')
                            ->helperText('ISO 8601 duration, e.g. PT5M. The longest PSO waits for the minimum plan quality before broadcasting anyway.'),
                        Select::make('broadcast_allocation_type')
                            ->label('Allocation Type')
                            ->native(false)
                            ->options(BroadcastAllocationType::class),
                        TagsInput::make('broadcast_required_tables')
                            ->label('Required Tables')
                            ->helperText('Sent as the required_tables parameter, comma-joined.')
                            ->columnSpanFull(),
                    ]),
```

and extend the `View::make(...)->viewData(...)` array with `'logLines' => $this->gatewayLogLines,`.

- [ ] **Step 5: The status panel view**

Replace `resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php` with:

```blade
@php
    /** @var \App\Models\PsoGatewayUpload|null $upload */
    /** @var \Illuminate\Support\Collection $history */
    /** @var array<int, array{level: string, text: string, at: string}> $logLines */

    $levelClasses = [
        'step' => 'font-semibold text-gray-900 dark:text-gray-100',
        'sub' => 'text-gray-600 dark:text-gray-400',
        'warn' => 'text-warning-600',
        'error' => 'font-semibold text-danger-600',
        'ok' => 'text-success-600',
    ];
@endphp

<div
    @if ($upload && ! $upload->status->isTerminal())
        wire:poll.2s="pollGatewayUploadLog"
    @endif
    class="space-y-4"
>
    @if ($upload)
        <div class="rounded-lg border p-4">
            <p class="font-medium">{{ $upload->original_filename }}</p>

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div
                    class="h-2 rounded-full transition-all duration-500 ease-out {{ match (true) {
                        $upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED => 'bg-danger-500',
                        $upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED => 'bg-success-500',
                        default => 'bg-primary-500',
                    } }}"
                    style="width: {{ $upload->status->progressPercent() }}%"
                ></div>
            </div>

            @if ($upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                <p class="mt-2 text-success-600">✅ Success — Internal ID: {{ $upload->internal_id }}</p>
            @elseif ($upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                <p class="mt-2 text-danger-600">❌ Failed — {{ $upload->error_message }}</p>
            @else
                <p class="mt-2">🟡 {{ $upload->status->getLabel() }}…</p>
            @endif

            @if ($upload->dataset_id || $upload->input_reference_datetime || $upload->activity_count || $upload->resource_count)
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-4">
                    @if ($upload->dataset_id)
                        <div>
                            <dt class="font-medium">Dataset</dt>
                            <dd>{{ $upload->dataset_id }}</dd>
                        </div>
                    @endif
                    @if ($upload->input_reference_datetime)
                        <div>
                            <dt class="font-medium">Input Reference</dt>
                            <dd>{{ $upload->input_reference_datetime->format('Y-m-d H:i') }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-medium">Activities</dt>
                        <dd>{{ number_format($upload->activity_count ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Resources</dt>
                        <dd>{{ number_format($upload->resource_count ?? 0) }}</dd>
                    </div>
                </dl>
            @endif

            @if ($upload->webhook_url)
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <dt class="font-medium">Webhook endpoint</dt>
                        <dd>
                            <code class="break-all">{{ $upload->webhook_url }}</code>
                            <button
                                type="button"
                                class="ml-2 text-primary-600 underline"
                                x-on:click="navigator.clipboard.writeText(@js($upload->webhook_url))"
                            >Copy</button>
                        </dd>
                    </div>
                    @if ($upload->webhook_view_url)
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="font-medium">Dashboard</dt>
                            <dd>
                                <a href="{{ $upload->webhook_view_url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline break-all">{{ $upload->webhook_view_url }}</a>
                            </dd>
                        </div>
                    @endif
                </dl>
            @endif

            @if ($upload->response_status !== null)
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-4">
                    <div>
                        <dt class="font-medium">HTTP status</dt>
                        <dd>{{ $upload->response_status }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Wire size</dt>
                        <dd>{{ number_format(($upload->wire_bytes ?? 0) / 1048576, 2) }} MB</dd>
                    </div>
                    <div>
                        <dt class="font-medium">POST duration</dt>
                        <dd>{{ number_format(($upload->post_duration_ms ?? 0) / 1000, 2) }} s</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Effective rate</dt>
                        <dd>
                            @if (($upload->post_duration_ms ?? 0) > 0)
                                {{ number_format((($upload->wire_bytes ?? 0) / 1048576) / ($upload->post_duration_ms / 1000), 2) }} MB/s
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (filled($upload->response_body))
                    <div class="mt-3">
                        <p class="text-sm font-medium">PSO response</p>
                        <pre class="mt-1 max-h-64 overflow-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800">{{ $upload->response_body }}</pre>
                    </div>
                @endif
            @endif

            @if (count($logLines) > 0)
                <div class="mt-3">
                    <p class="text-sm font-medium">Run log</p>
                    <div
                        class="mt-1 max-h-96 overflow-auto rounded bg-gray-100 p-2 font-mono text-xs dark:bg-gray-800"
                        x-data
                        x-init="$el.scrollTop = $el.scrollHeight"
                        x-on:livewire:updated.window="$el.scrollTop = $el.scrollHeight"
                    >
                        @foreach ($logLines as $line)
                            <div class="{{ $levelClasses[$line['level']] ?? $levelClasses['sub'] }} whitespace-pre-wrap break-all">
                                <span class="text-gray-400">{{ $line['at'] }}</span>
                                {{ $line['level'] === 'sub' ? '  ' : '' }}{{ $line['text'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div>
            <p class="mb-2 font-medium">Recent uploads</p>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $item)
                    <li>
                        <button type="button" class="text-left underline" wire:click="viewGatewayUpload('{{ $item->id }}')">
                            {{ $item->original_filename }}
                        </button>
                        @if ($item->dataset_id)
                            ({{ $item->dataset_id }})
                        @endif
                        —
                        {{ $item->status->getLabel() }}
                        @if ($item->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                            (ID: {{ $item->internal_id }})
                        @elseif ($item->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                            ({{ $item->error_message }})
                        @endif
                        — {{ $item->created_at->diffForHumans() }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
```

- [ ] **Step 6: Run to verify the tests pass**

Run: `php artisan test --compact tests/Feature/GatewayUploadWebhookFormTest.php tests/Feature/GatewayUploadTabTest.php`
Expected: PASS. Notes: if `assertSchemaStateSet` is not available on this Filament version's testing helpers, use `->assertFormSet([...], 'gatewayUploadForm')`; if `broadcast_allocation_type` or `broadcast_plan_type` come back as enum instances, compare with `->value` in the assertion rather than changing the form.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php tests/Feature/GatewayUploadWebhookFormTest.php
git add app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php tests/Feature/GatewayUploadWebhookFormTest.php
git commit -m "feat: dataset id override, webhook toggle, broadcast settings and live run log in the Gateway Upload tab"
```

---

### Task 10: Configuration, docs and full verification

**Files:**
- Modify: `.env.example`
- Modify: `docs/superpowers/specs/2026-10-11-gateway-upload-webhook-broadcast-design.md` (step order in 4.5)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Document the new environment variables**

Append to `.env.example` next to the existing `PSO_SERVICES_API` lines:

```dotenv
# Self-hosted webhook.site that receives the plan PSO broadcasts back. Without
# WEBHOOK_ALIAS_KEY (the webhook server's ALIAS_API_KEY) the Gateway Upload tab
# falls back to a fresh one-off token per run.
WEBHOOK_BASE_URL=https://webhook.thetechnodro.me
WEBHOOK_ALIAS_KEY=

# Seconds allowed for a session logon/logoff through pso-services.
PSO_SESSION_TIMEOUT=20
```

- [ ] **Step 2: Align the spec's step order with what was built**

In section 4.5 of the spec, steps 3 to 5 become: "3. Webhook (new, unless skipped): provision the endpoint (alias created or found, endpoint, dashboard, canned response). 4. Rewrite (new): one streaming pass; log the dataset ID (current value and any change), rows stripped (`No existing Broadcast ... nothing to strip` when none), the injected broadcast and its required tables, and the modified copy's name and size." Renumber the later steps accordingly (summary and gzip 5, authenticate 6, POST 7, logoff 8, finish 9). The webhook is provisioned first because the rewrite needs its URL.

- [ ] **Step 3: Confirm nothing still calls PSO's session endpoint directly**

Run: `grep -rn "scheduling/session" app config routes`
Expected: no output.

- [ ] **Step 4: Run the whole suite**

Run: `php artisan test --compact`
Expected: PASS. Investigate any failure that touches `authenticatePSO()` callers (`SystemUsageFetchTest`, `EnvironmentToolsDatetimeTest`, and any test of `EditEnvironment`): they previously faked the PSO session URL or mocked the method; repoint them with `PsoServicesFake::session()` (fakes) or leave them if they mock the trait method.

- [ ] **Step 5: Format touched files and commit**

```bash
git status --short
vendor/bin/pint $(git diff --name-only main -- '*.php' | tr '\n' ' ')
git add .env.example docs/superpowers/specs/2026-10-11-gateway-upload-webhook-broadcast-design.md
git commit -m "docs: env vars and step order for the gateway upload webhook broadcast"
```

(`vendor/bin/pint` runs only on the files this branch changed, as per the project rule.)

---

## Self-Review

**Spec coverage**

| Spec | Task |
|---|---|
| 4.1 Webhook provisioner, config, alias/one-off, canned response | 6 |
| 4.2 Rewriter: dataset ID, strip, inject, XML and both JSON formats, streaming, unknown wrapper fails | 4, 5 |
| 4.3 Settings and injection value objects, defaults in config, ISO duration validation | 3 (validation used in 9) |
| 4.4 Run log table, levels, no secrets | 2, 8 (secret scan) |
| 4.5 Job flow, steps, logs, logoff in finally | 8 |
| 4.6 Form fields, polling log, summary card, history link | 9 |
| 4.7 Data model | 2 |
| 4.8 Extractor `ScheduleData` and streaming | 7 |
| 4.9 Sessions through pso-services | 1 (+ pso-services plan) |
| 5 Failure handling rows | 8 (webhook failure, bad wrapper, logon refused, logoff failure), 1 (services errors) |
| 7 Operations | 10 |
| 8 Out of scope | not built, by design |

**Types and names used across tasks:** `BroadcastSettings::fromArray/defaults/toArray/isValidDuration`, `BroadcastInjection::broadcastFields(bool)/parameterRows(string)`, `ScheduleDataRewriter::rewrite(...)`, `RewriteReport` properties, `ScheduleDataRewriterFactory::forFormat`, `WebhookEndpointProvisioner::provision(string, string, UploadRunLog)`, `UploadRunLog::step/sub/warn/error/ok`, `authenticatePSO/logoffPSO/$session_error_message`, `PsoServicesFake::session/sessionUrl`: names match across tasks 1-9.

**Known deviations from the spec, deliberate:** webhook is provisioned before the rewrite (Task 10 fixes the spec's order); the file the rewriter writes is named `payload_modified.<ext>` rather than `<name>_withBroadcast.<ext>` (user-supplied filenames never reach the filesystem path); log polling appends to a page property rather than re-rendering all lines.
