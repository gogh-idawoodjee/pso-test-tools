# PSO Gateway Upload Tool ("Load from File") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Load from File" tab to `EnvironmentTools` that lets a scheduler upload a `dsScheduleData` JSON/XML file (or a `.zip` containing one), gzip-compresses it in a queued job without ever holding the full file in memory twice, and POSTs it directly to the IFS PSO RESTful Gateway's `/scheduling/data` endpoint — replacing `Send-PsoScheduleData.ps1`.

**Architecture:** A new `pso_gateway_uploads` audit table + `PsoGatewayUpload` model backs the whole flow. Two small, independently-testable support classes (`GzipFileCompressor`, `ScheduleDataZipExtractor`) handle the file-format concerns. `SendPsoScheduleDataJob` orchestrates: download from `r2` → (optional) unzip → gzip-stream → `authenticatePSO()` (existing trait, reused as-is) → stream-upload → status update. The Filament tab polls the `PsoGatewayUpload` row directly via `wire:poll` — no new Cache/broadcast layer, since the row is already the persisted source of truth.

**Tech Stack:** Laravel 13, Filament v5, Pest 4, MySQL, Cloudflare R2 (`r2` disk), `zlib` streaming, `ZipArchive`.

**Spec:** `docs/superpowers/specs/2026-09-10-pso-gateway-upload-tool-design.md`

## Global Constraints

- Never hold the full raw file and its gzip copy in memory at once — stream via chunked reads + `deflate_init`/`deflate_add` (`ZLIB_ENCODING_GZIP`), never `file_get_contents()` + `gzencode()`.
- The upload to the gateway must run in a queued job (`ShouldQueue`), never synchronously inside the web request.
- `SendPsoScheduleDataJob` must implement `Illuminate\Contracts\Queue\ShouldBeEncrypted` — it carries decrypted environment credentials (captured from the tab's live form overrides, which can differ from what's saved on the `Environment` record) as constructor properties, and those must not sit in plaintext in a queue/`failed_jobs` payload.
- Never log full credentials or full payload contents — metadata only, matching `PSOInteractionsTrait::redactSensitivePayload()`'s existing pattern.
- `pso_gateway_uploads` uses a UUID primary key (matches `Environment`'s convention), scoped by `initiated_by_user_id` — no `organization_id` (this app is not multi-tenant).
- Gateway contract (verified against `~/Herd/pso-services/app/Classes/V2/PsoClient.php`, not guessed): URL is `{base_url}/IFSSchedulingRESTfulGateway/api/v1/scheduling/data`, auth header is `apiKey: <SessionToken>`, error bodies are shaped `{"Message": "..."}`. No session cleanup (`DELETE /scheduling/session`) needed.
- No OIDC (`auth_mode`) support — out of scope, confirmed no existing `Environment` needs it.
- Accepted uploads: `.json`, `.xml`, `.zip` (containing exactly one `.json` or `.xml` entry).
- Disk `r2` (private) for the original upload, matching `FilterLoadFile.php`'s existing convention.

---

## Task 1: `PsoGatewayUpload` data model

**Files:**
- Create: `database/migrations/2026_09_10_000001_create_pso_gateway_uploads_table.php`
- Create: `app/Enums/PsoGatewayUploadStatus.php`
- Create: `app/Models/PsoGatewayUpload.php`
- Create: `database/factories/PsoGatewayUploadFactory.php`
- Test: `tests/Unit/PsoGatewayUploadStatusTest.php`

**Interfaces:**
- Produces: `PsoGatewayUploadStatus` enum (cases `QUEUED`, `COMPRESSING`, `UPLOADING`, `SUCCEEDED`, `FAILED`; method `isTerminal(): bool`). `PsoGatewayUpload` model with fillable `pso_environment_id`, `initiated_by_user_id`, `stored_path`, `original_filename`, `file_size_bytes`, `compressed_size_bytes`, `status`, `internal_id`, `error_message`, `queued_at`, `started_at`, `completed_at`; relations `environment(): BelongsTo` and `initiatedBy(): BelongsTo`.

- [ ] **Step 1: Write the failing test for the status enum**

```php
<?php

use App\Enums\PsoGatewayUploadStatus;

it('reports succeeded and failed as terminal states', function () {
    expect(PsoGatewayUploadStatus::SUCCEEDED->isTerminal())->toBeTrue();
    expect(PsoGatewayUploadStatus::FAILED->isTerminal())->toBeTrue();
});

it('reports queued, compressing, and uploading as non-terminal states', function (PsoGatewayUploadStatus $status) {
    expect($status->isTerminal())->toBeFalse();
})->with([PsoGatewayUploadStatus::QUEUED, PsoGatewayUploadStatus::COMPRESSING, PsoGatewayUploadStatus::UPLOADING]);
```

Save as `tests/Unit/PsoGatewayUploadStatusTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/PsoGatewayUploadStatusTest.php`
Expected: FAIL — `Class "App\Enums\PsoGatewayUploadStatus" not found`.

- [ ] **Step 3: Create the enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PsoGatewayUploadStatus: string implements HasLabel
{
    case QUEUED = 'queued';
    case COMPRESSING = 'compressing';
    case UPLOADING = 'uploading';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    public function getLabel(): string|null
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::COMPRESSING => 'Compressing',
            self::UPLOADING => 'Uploading',
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED], true);
    }
}
```

Save as `app/Enums/PsoGatewayUploadStatus.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/PsoGatewayUploadStatusTest.php`
Expected: PASS.

- [ ] **Step 5: Write the migration**

```php
<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pso_gateway_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('pso_environment_id')
                ->constrained('environments')
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class, 'initiated_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('stored_path');
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedBigInteger('compressed_size_bytes')->nullable();

            $table->string('status')->default('queued');
            $table->string('internal_id')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pso_gateway_uploads');
    }
};
```

Save as `database/migrations/2026_09_10_000001_create_pso_gateway_uploads_table.php`.

- [ ] **Step 6: Run the migration**

Run: `php artisan migrate`
Expected: `pso_gateway_uploads` table created with no errors.

- [ ] **Step 7: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\PsoGatewayUploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsoGatewayUpload extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'status' => PsoGatewayUploadStatus::class,
        'file_size_bytes' => 'integer',
        'compressed_size_bytes' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $fillable = [
        'pso_environment_id',
        'initiated_by_user_id',
        'stored_path',
        'original_filename',
        'file_size_bytes',
        'compressed_size_bytes',
        'status',
        'internal_id',
        'error_message',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'pso_environment_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
```

Save as `app/Models/PsoGatewayUpload.php`.

- [ ] **Step 8: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsoGatewayUploadFactory extends Factory
{
    protected $model = PsoGatewayUpload::class;

    public function definition(): array
    {
        return [
            'pso_environment_id' => Environment::factory(),
            'initiated_by_user_id' => User::factory(),
            'stored_path' => 'gateway-uploads/'.fake()->uuid().'.json',
            'original_filename' => fake()->word().'.json',
            'file_size_bytes' => fake()->numberBetween(1000, 1000000),
            'status' => PsoGatewayUploadStatus::QUEUED,
            'queued_at' => now(),
        ];
    }
}
```

Save as `database/factories/PsoGatewayUploadFactory.php`.

- [ ] **Step 9: Verify the model + factory work together**

Run: `php artisan tinker --execute="dd(App\Models\PsoGatewayUpload::factory()->create()->status);"`
Expected: prints `App\Enums\PsoGatewayUploadStatus::QUEUED` — confirms the cast and factory both work against the real migration.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_09_10_000001_create_pso_gateway_uploads_table.php app/Enums/PsoGatewayUploadStatus.php app/Models/PsoGatewayUpload.php database/factories/PsoGatewayUploadFactory.php tests/Unit/PsoGatewayUploadStatusTest.php
git commit -m "feat: add pso_gateway_uploads data model"
```

---

## Task 2: `GzipFileCompressor` — streaming gzip support class

**Files:**
- Create: `app/Support/GzipFileCompressor.php`
- Test: `tests/Unit/GzipFileCompressorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\Support\GzipFileCompressor::compress(string $sourcePath, string $destinationPath): int` (returns the compressed file's byte size). Used by Task 4/5's job.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\GzipFileCompressor;

afterEach(function () {
    foreach (glob(storage_path('framework/testing/gzip-*')) as $file) {
        @unlink($file);
    }
});

it('compresses a file such that gzdecode reproduces the original bytes', function () {
    $sourcePath = storage_path('framework/testing/gzip-source.json');
    $destinationPath = storage_path('framework/testing/gzip-dest.gz');
    $original = json_encode(['dsScheduleData' => array_fill(0, 1000, ['id' => 'ABC123', 'description' => str_repeat('x', 200)])]);
    file_put_contents($sourcePath, $original);

    $compressedSize = GzipFileCompressor::compress($sourcePath, $destinationPath);

    expect($compressedSize)->toBe(filesize($destinationPath));
    expect(gzdecode(file_get_contents($destinationPath)))->toBe($original);
    expect($compressedSize)->toBeLessThan(strlen($original));
});

it('does not load the whole source file into memory', function () {
    $sourcePath = storage_path('framework/testing/gzip-source-large.json');
    $destinationPath = storage_path('framework/testing/gzip-dest-large.gz');
    // ~20MB of repetitive JSON — big enough that file_get_contents()+gzencode()
    // would show up clearly as a memory spike proportional to file size.
    file_put_contents($sourcePath, str_repeat('{"resource_id":"ABC123","status":"active"},', 500_000));

    $before = memory_get_peak_usage(true);
    GzipFileCompressor::compress($sourcePath, $destinationPath);
    $after = memory_get_peak_usage(true);

    // A single chunk (1MB) plus overhead, not the ~20MB source file.
    expect($after - $before)->toBeLessThan(5 * 1024 * 1024);
});
```

Save as `tests/Unit/GzipFileCompressorTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/GzipFileCompressorTest.php`
Expected: FAIL — `Class "App\Support\GzipFileCompressor" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

class GzipFileCompressor
{
    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

    /**
     * Gzip-compresses $sourcePath to $destinationPath using streaming zlib
     * deflate, reading and writing in fixed-size chunks so neither the full
     * source nor the full compressed output is ever held in memory at once.
     *
     * @return int The compressed file's size in bytes.
     */
    public static function compress(string $sourcePath, string $destinationPath): int
    {
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');
        $context = deflate_init(ZLIB_ENCODING_GZIP);

        while (! feof($source)) {
            $chunk = fread($source, self::CHUNK_SIZE);
            $isFinalChunk = feof($source);
            fwrite($destination, deflate_add($context, $chunk, $isFinalChunk ? ZLIB_FINISH : ZLIB_NO_FLUSH));
        }

        fclose($source);
        fclose($destination);

        return filesize($destinationPath);
    }
}
```

Save as `app/Support/GzipFileCompressor.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/GzipFileCompressorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/GzipFileCompressor.php tests/Unit/GzipFileCompressorTest.php
git commit -m "feat: add streaming gzip compressor"
```

---

## Task 3: `ScheduleDataZipExtractor` — zip input support class

**Files:**
- Create: `app/Support/ScheduleDataZipExtractor.php`
- Test: `tests/Unit/ScheduleDataZipExtractorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\Support\ScheduleDataZipExtractor::extract(string $zipPath, string $destinationDirectory): string` (returns the extracted file's full path), throws `RuntimeException` with a human-readable message on anything but exactly one `.json`/`.xml` entry. Used by Task 5's job.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Support\ScheduleDataZipExtractor;

function makeTestZip(string $dir, array $entries): string
{
    $zipPath = $dir.'/input.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $zipPath;
}

beforeEach(function () {
    $this->dir = storage_path('framework/testing/zip-extract-'.uniqid());
    mkdir($this->dir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('extracts the single json entry', function () {
    $zipPath = makeTestZip($this->dir, ['dsScheduleData.json' => '{"dsScheduleData":{}}']);

    $extractedPath = ScheduleDataZipExtractor::extract($zipPath, $this->dir);

    expect($extractedPath)->toBe($this->dir.'/dsScheduleData.json');
    expect(file_get_contents($extractedPath))->toBe('{"dsScheduleData":{}}');
});

it('extracts the single xml entry', function () {
    $zipPath = makeTestZip($this->dir, ['dsScheduleData.xml' => '<dsScheduleData></dsScheduleData>']);

    $extractedPath = ScheduleDataZipExtractor::extract($zipPath, $this->dir);

    expect($extractedPath)->toBe($this->dir.'/dsScheduleData.xml');
});

it('throws when the zip has more than one entry', function () {
    $zipPath = makeTestZip($this->dir, [
        'dsScheduleData.json' => '{}',
        'readme.txt' => 'hi',
    ]);

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, 'must contain exactly one file');
});

it('throws when the single entry is not json or xml', function () {
    $zipPath = makeTestZip($this->dir, ['readme.txt' => 'hi']);

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, '.json or .xml');
});

it('throws when the zip file is corrupt', function () {
    $zipPath = $this->dir.'/corrupt.zip';
    file_put_contents($zipPath, 'not a real zip');

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, 'could not be opened');
});
```

Save as `tests/Unit/ScheduleDataZipExtractorTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/ScheduleDataZipExtractorTest.php`
Expected: FAIL — `Class "App\Support\ScheduleDataZipExtractor" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class ScheduleDataZipExtractor
{
    /**
     * Extracts the single .json or .xml entry from $zipPath into
     * $destinationDirectory, returning the extracted file's full path.
     *
     * Fails fast (before any compress/upload work happens) if the zip
     * can't be opened, or doesn't contain exactly one .json/.xml entry.
     */
    public static function extract(string $zipPath, string $destinationDirectory): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded zip file could not be opened. It may be corrupt.');
        }

        if ($zip->numFiles !== 1) {
            $zip->close();

            throw new RuntimeException('The uploaded zip file must contain exactly one file.');
        }

        $entryName = $zip->getNameIndex(0);
        $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'xml'], true)) {
            $zip->close();

            throw new RuntimeException("The file inside the zip must be .json or .xml, found: {$entryName}");
        }

        $zip->extractTo($destinationDirectory, [$entryName]);
        $zip->close();

        return rtrim($destinationDirectory, '/').'/'.$entryName;
    }
}
```

Save as `app/Support/ScheduleDataZipExtractor.php`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/ScheduleDataZipExtractorTest.php`
Expected: PASS (all 5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/ScheduleDataZipExtractor.php tests/Unit/ScheduleDataZipExtractorTest.php
git commit -m "feat: add zip-input support for schedule data uploads"
```

---

## Task 4: `SendPsoScheduleDataJob` — success path

**Files:**
- Create: `app/Jobs/SendPsoScheduleDataJob.php`
- Test: `tests/Feature/SendPsoScheduleDataJobTest.php`

**Interfaces:**
- Consumes: `PsoGatewayUpload` (Task 1), `GzipFileCompressor::compress()` (Task 2), `ScheduleDataZipExtractor::extract()` (Task 3), `PSOInteractionsTrait::authenticatePSO()` (existing, `app/Traits/PSOInteractionsTrait.php:23`).
- Produces: `App\Jobs\SendPsoScheduleDataJob::__construct(string $psoGatewayUploadId, string $baseUrl, string $accountId, string $username, string $password)`, dispatched via `SendPsoScheduleDataJob::dispatch(...)`. Consumed by Task 6's Filament tab.

- [ ] **Step 1: Write the failing test (success path)**

```php
<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
});

it('uploads a json file to the gateway and records the internal id on success', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{"Resources":[]}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857054'], 200),
    ]);

    (new SendPsoScheduleDataJob(
        $upload->id,
        'https://example.test',
        'acc-1',
        'test-user',
        'secret-password',
    ))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->internal_id)->toBe('1857054');
    expect($upload->compressed_size_bytes)->toBeGreaterThan(0);
    expect($upload->completed_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), '/scheduling/data')
            && $request->hasHeader('apiKey', 'tok-abc')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $request->hasHeader('Content-Type', 'application/json');
    });

    Storage::disk('r2')->assertMissing('gateway-uploads/schedule.json');
});

it('sets Content-Type to application/xml for an xml upload', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.xml', '<dsScheduleData></dsScheduleData>');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.xml',
        'original_filename' => 'schedule.xml',
        'status' => PsoGatewayUploadStatus::QUEUED,
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857055'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/scheduling/data')
        && $request->hasHeader('Content-Type', 'application/xml'));
});
```

Save as `tests/Feature/SendPsoScheduleDataJobTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: FAIL — `Class "App\Jobs\SendPsoScheduleDataJob" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Jobs;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\PsoGatewayUpload;
use App\Support\GzipFileCompressor;
use App\Support\ScheduleDataZipExtractor;
use App\Traits\PSOInteractionsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;
use Throwable;

class SendPsoScheduleDataJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PSOInteractionsTrait, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

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
        $upload->update(['status' => PsoGatewayUploadStatus::COMPRESSING, 'started_at' => now()]);

        $workDir = storage_path('app/private/gateway-uploads/'.$upload->id);
        if (! is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        try {
            $downloadedPath = $this->downloadOriginalUpload($upload, $workDir);

            $rawPath = str_ends_with(strtolower($downloadedPath), '.zip')
                ? ScheduleDataZipExtractor::extract($downloadedPath, $workDir)
                : $downloadedPath;

            $contentType = str_ends_with(strtolower($rawPath), '.xml')
                ? 'application/xml'
                : 'application/json';

            $gzipPath = $workDir.'/payload.gz';
            $compressedSize = GzipFileCompressor::compress($rawPath, $gzipPath);

            $upload->update([
                'compressed_size_bytes' => $compressedSize,
                'status' => PsoGatewayUploadStatus::UPLOADING,
            ]);

            $token = $this->authenticatePSO($this->baseUrl, $this->accountId, $this->username, $this->password);

            if (! $token) {
                $this->failUpload($upload, 'Could not authenticate with the PSO gateway. Check the environment credentials.');

                return;
            }

            $response = Http::withHeaders([
                'apiKey' => $token,
                'Content-Encoding' => 'gzip',
            ])->withBody(fopen($gzipPath, 'rb'), $contentType)
                ->post("{$this->baseUrl}/IFSSchedulingRESTfulGateway/api/v1/scheduling/data");

            if ($response->successful()) {
                $upload->update([
                    'status' => PsoGatewayUploadStatus::SUCCEEDED,
                    'internal_id' => $response->json('InternalId'),
                    'completed_at' => now(),
                ]);

                return;
            }

            $this->failUpload($upload, $this->mapGatewayError($response));
        } catch (ConnectionException $e) {
            Log::error('PSO gateway connection error', ['upload_id' => $upload->id, 'message' => $e->getMessage()]);
            $this->failUpload($upload, 'Could not reach the PSO gateway (timed out or unreachable).');
        } catch (Throwable $e) {
            Log::error('PSO gateway upload failed', ['upload_id' => $upload->id, 'message' => $e->getMessage()]);
            $this->failUpload($upload, $e->getMessage());
        } finally {
            $this->cleanup($upload, $workDir);
        }
    }

    private function downloadOriginalUpload(PsoGatewayUpload $upload, string $workDir): string
    {
        $extension = strtolower(pathinfo($upload->original_filename, PATHINFO_EXTENSION));
        $localPath = "{$workDir}/upload.{$extension}";

        $source = Storage::disk('r2')->readStream($upload->stored_path);
        $destination = fopen($localPath, 'wb');

        while (! feof($source)) {
            fwrite($destination, fread($source, 1024 * 1024));
        }

        fclose($source);
        fclose($destination);

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
        $upload->update([
            'status' => PsoGatewayUploadStatus::FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    private function cleanup(PsoGatewayUpload $upload, string $workDir): void
    {
        foreach (glob("{$workDir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($workDir);

        Storage::disk('r2')->delete($upload->stored_path);
    }
}
```

Save as `app/Jobs/SendPsoScheduleDataJob.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SendPsoScheduleDataJob.php tests/Feature/SendPsoScheduleDataJobTest.php
git commit -m "feat: add SendPsoScheduleDataJob (success path)"
```

---

## Task 5: `SendPsoScheduleDataJob` — failure paths and zip input

**Files:**
- Modify: `tests/Feature/SendPsoScheduleDataJobTest.php` (append new `it(...)` blocks)

**Interfaces:**
- Consumes: same as Task 4. No production code changes expected — this task proves the `catch`/`failUpload`/`mapGatewayError` branches already written in Task 4 behave correctly, and adds zip-input coverage. If a test reveals a bug, fix `app/Jobs/SendPsoScheduleDataJob.php` directly (see Step 3).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SendPsoScheduleDataJobTest.php`:

```php
it('fails cleanly when the gateway rejects credentials', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response([], 401),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'bad-user', 'bad-password'))->handle();

    $upload->refresh();

    expect($upload->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('Could not authenticate with the PSO gateway. Check the environment credentials.');
});

it('maps a gateway AUTHENTICATION_FAILED response to a plain-language error', function () {
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['Message' => 'AUTHENTICATION_FAILED'], 400),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('The PSO gateway rejected the provided credentials.');
});

it('records a plain-language error on a connection failure', function () {
    // Only the /scheduling/data call fails here — /scheduling/session must
    // still succeed, otherwise this exercises the auth-failure branch
    // instead (authenticatePSO() already catches ConnectionException
    // internally and returns null, so a global Http::fake(closure) would
    // never reach the job's own catch block).
    Storage::disk('r2')->put('gateway-uploads/schedule.json', '{"dsScheduleData":{}}');

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.json',
        'original_filename' => 'schedule.json',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        },
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toBe('Could not reach the PSO gateway (timed out or unreachable).');
});

it('extracts and uploads a zipped json file, and cleans up the extracted temp file', function () {
    $zip = new ZipArchive;
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gwzip').'.zip';
    $zip->open($tmpZipPath, ZipArchive::CREATE);
    $zip->addFromString('dsScheduleData.json', '{"dsScheduleData":{"Resources":[]}}');
    $zip->close();

    Storage::disk('r2')->put('gateway-uploads/schedule.zip', file_get_contents($tmpZipPath));
    unlink($tmpZipPath);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/schedule.zip',
        'original_filename' => 'schedule.zip',
    ]);

    Http::fake([
        '*/scheduling/session' => Http::response(['SessionToken' => 'tok-abc'], 200),
        '*/scheduling/data' => Http::response(['InternalId' => '1857099'], 200),
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::SUCCEEDED);
    expect($upload->internal_id)->toBe('1857099');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/scheduling/data')
        && $request->hasHeader('Content-Type', 'application/json'));

    expect(is_dir(storage_path('app/private/gateway-uploads/'.$upload->id)))->toBeFalse();
});

it('fails cleanly when the zip contains no json or xml entry', function () {
    $zip = new ZipArchive;
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gwzip').'.zip';
    $zip->open($tmpZipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'not schedule data');
    $zip->close();

    Storage::disk('r2')->put('gateway-uploads/bad.zip', file_get_contents($tmpZipPath));
    unlink($tmpZipPath);

    $environment = Environment::factory()->create();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'stored_path' => 'gateway-uploads/bad.zip',
        'original_filename' => 'bad.zip',
    ]);

    (new SendPsoScheduleDataJob($upload->id, 'https://example.test', 'acc-1', 'test-user', 'secret-password'))->handle();

    expect($upload->refresh()->status)->toBe(PsoGatewayUploadStatus::FAILED);
    expect($upload->error_message)->toContain('.json or .xml');
});
```

- [ ] **Step 2: Run tests to verify they fail or pass, and fix any real gaps**

Run: `php artisan test tests/Feature/SendPsoScheduleDataJobTest.php`
Expected: all tests PASS against the Task 4 implementation. If any fail, the most likely gap is `Http::fake()`'s connection-exception closure form — adjust `downloadOriginalUpload`/`handle` only if a genuine bug surfaces (e.g. `readStream` returning `false` for a missing key should already be impossible here since the fixture always puts the file first).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/SendPsoScheduleDataJobTest.php
git commit -m "test: cover SendPsoScheduleDataJob failure paths and zip input"
```

---

## Task 6: "Load from File" tab on `EnvironmentTools`

**Files:**
- Modify: `app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php`
- Create: `resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php`
- Test: `tests/Feature/GatewayUploadTabTest.php`

**Interfaces:**
- Consumes: `PsoGatewayUpload` + `PsoGatewayUploadStatus` (Task 1), `SendPsoScheduleDataJob` (Task 4).
- Produces: public method `EnvironmentTools::submitGatewayUpload(Get $get, Set $set): void`, public property `?string $gatewayUploadId`, methods `currentGatewayUpload(): ?PsoGatewayUpload` and `gatewayUploadHistory(): \Illuminate\Support\Collection`.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('creates a queued upload row and dispatches the job on submit', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => \Illuminate\Support\Facades\Crypt::encryptString('secret-password'),
    ]);

    $file = UploadedFile::fake()->create('schedule.json', 500, 'application/json');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'psoload')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'psoload'));

    expect(PsoGatewayUpload::query()->count())->toBe(1);

    $upload = PsoGatewayUpload::query()->first();
    expect($upload->status)->toBe(PsoGatewayUploadStatus::QUEUED);
    expect($upload->pso_environment_id)->toBe($environment->id);
    expect($upload->initiated_by_user_id)->toBe($user->id);

    Queue::assertPushed(\App\Jobs\SendPsoScheduleDataJob::class, function ($job) use ($upload) {
        return $job->psoGatewayUploadId === $upload->id
            && $job->baseUrl === 'https://example.test'
            && $job->accountId === 'acc-1'
            && $job->username === 'test-user'
            && $job->password === 'secret-password';
    });
});

it('rejects submission when no file is chosen', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'psoload'));

    expect(PsoGatewayUpload::query()->count())->toBe(0);
    Queue::assertNotPushed(\App\Jobs\SendPsoScheduleDataJob::class);
});
```

Save as `tests/Feature/GatewayUploadTabTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/GatewayUploadTabTest.php`
Expected: FAIL — the `gateway_upload_file` field / `submit_gateway_upload` action don't exist yet.

- [ ] **Step 3: Add the imports**

In `app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php`, add these `use` statements alongside the existing ones (after the `use App\Traits\PSOInteractionsTrait;` line):

```php
use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\PsoGatewayUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
```

(`Filament\Forms\Components\FileUpload`, `TextInput`, `Toggle`, etc. may already be imported individually — check for duplicates before adding; this file already imports `Forms` as a namespace alias in some spots and individual classes in others, so add whichever of the above aren't already present.)

- [ ] **Step 4: Add the `gatewayUploadId` property**

Add alongside the existing `public ?array $systemUsageGroups = null;` property (around line 56):

```php
    public ?string $gatewayUploadId = null;
```

- [ ] **Step 5: Add the new tab**

In the `psoload()` method, inside the `Tabs::make('activity_tabs')->tabs([...])` array, add a new `Tab` entry immediately after the closing of the `services_tab` Tab (i.e. right before the `]),` that closes the `tabs([...])` array, around line 562-564):

```php
                    Tab::make('gateway_upload_tab')
                        ->schema([
                            FileUpload::make('gateway_upload_file')
                                ->label('Schedule Data File')
                                ->helperText('Upload a dsScheduleData .json/.xml file, or a .zip containing one.')
                                ->disk('r2')
                                ->directory('gateway-uploads')
                                ->acceptedFileTypes([
                                    'application/json',
                                    'text/json',
                                    'application/xml',
                                    'text/xml',
                                    'application/zip',
                                    'application/x-zip-compressed',
                                ])
                                ->maxSize(204800) // 200MB in KB
                                ->live()
                                ->afterStateUpdated(function (Set $set, $state) {
                                    if ($state instanceof TemporaryUploadedFile) {
                                        $set('gateway_upload_original_filename', $state->getClientOriginalName());
                                    }
                                })
                                ->required()
                                ->columnSpanFull(),
                            Hidden::make('gateway_upload_original_filename')
                                ->dehydrated(false),
                            Actions::make([
                                Action::make('submit_gateway_upload')
                                    ->label('Upload to PSO')
                                    ->icon(Heroicon::OutlinedArrowUpOnSquare)
                                    ->action(function (Get $get, Set $set) {
                                        $this->submitGatewayUpload($get, $set);
                                    }),
                            ])->columnSpanFull(),
                            View::make('filament.resources.environment-resource.pages.partials.gateway-upload-status')
                                ->viewData(fn (): array => [
                                    'upload' => $this->currentGatewayUpload(),
                                    'history' => $this->gatewayUploadHistory(),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->columns()
                        ->icon(Heroicon::OutlinedArrowUpOnSquare)
                        ->label('Load from File'),
```

- [ ] **Step 6: Add `submitGatewayUpload()`, `currentGatewayUpload()`, `gatewayUploadHistory()`**

Add these methods after `fetchSystemUsage()` (after the closing brace around line 786):

```php
    public function submitGatewayUpload(Get $get, Set $set): void
    {
        $storedPath = $get('gateway_upload_file');
        $originalFilename = $get('gateway_upload_original_filename');

        if (blank($storedPath)) {
            $this->notifyPayloadSent('Upload Failed', 'Please choose a file to upload.', false);

            return;
        }

        $baseUrl = $get('base_url');
        $accountId = $get('account_id');
        $username = $get('username');
        $password = $get('password');

        if (blank($baseUrl) || blank($accountId) || blank($username) || blank($password)) {
            $this->notifyPayloadSent('Upload Failed', 'Base URL, Account ID, Username and Password are all required (see Environment Properties above).', false);

            return;
        }

        $upload = PsoGatewayUpload::create([
            'pso_environment_id' => $this->record->id,
            'initiated_by_user_id' => auth()->id(),
            'stored_path' => $storedPath,
            'original_filename' => filled($originalFilename) ? $originalFilename : basename((string) $storedPath),
            'file_size_bytes' => Storage::disk('r2')->size($storedPath),
            'status' => PsoGatewayUploadStatus::QUEUED,
            'queued_at' => now(),
        ]);

        SendPsoScheduleDataJob::dispatch(
            $upload->id,
            $baseUrl,
            $accountId,
            $username,
            Crypt::decryptString($password),
        );

        $this->gatewayUploadId = $upload->id;
        $set('gateway_upload_file', null);
        $set('gateway_upload_original_filename', null);

        $this->notifyPayloadSent('Upload Queued', "\"{$upload->original_filename}\" has been queued for upload to PSO.", true);
    }

    public function currentGatewayUpload(): ?PsoGatewayUpload
    {
        return $this->gatewayUploadId ? PsoGatewayUpload::find($this->gatewayUploadId) : null;
    }

    public function gatewayUploadHistory(): Collection
    {
        return PsoGatewayUpload::query()
            ->where('pso_environment_id', $this->record->id)
            ->latest('created_at')
            ->limit(10)
            ->get();
    }
```

- [ ] **Step 7: Write the status/history partial**

```blade
@php
    /** @var \App\Models\PsoGatewayUpload|null $upload */
    /** @var \Illuminate\Support\Collection $history */
@endphp

<div
    @if ($upload && ! $upload->status->isTerminal())
        wire:poll.3s
    @endif
    class="space-y-4"
>
    @if ($upload)
        <div class="rounded-lg border p-4">
            <p class="font-medium">{{ $upload->original_filename }}</p>

            @if ($upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                <p class="text-success-600">✅ Success — Internal ID: {{ $upload->internal_id }}</p>
            @elseif ($upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                <p class="text-danger-600">❌ Failed — {{ $upload->error_message }}</p>
            @else
                <p>🟡 {{ $upload->status->getLabel() }}…</p>
            @endif
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div>
            <p class="mb-2 font-medium">Recent uploads</p>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $item)
                    <li>
                        {{ $item->original_filename }} —
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

Save as `resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php`.

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/GatewayUploadTabTest.php`
Expected: PASS (both tests). If `TestAction::make('submit_gateway_upload')->schemaComponent(true, 'psoload')` can't locate the action (Filament v5 testing helper syntax mismatch), check how the existing `push_it` / `fetch_system_usage` actions are exercised in `tests/Feature/EnvironmentToolsDatetimeTest.php` and `tests/Feature/SystemUsageFetchTest.php` and match that exact calling convention instead.

- [ ] **Step 9: Manually verify in the browser**

This step is for you (the user) to run, not the agent — per your standing preference, no automated browser testing. Visit an environment's Tools page, confirm the "Load from File" tab appears, upload a small `.json` fixture, and confirm the row appears in "Recent uploads" with a status that updates live.

- [ ] **Step 10: Commit**

```bash
git add app/Filament/Resources/EnvironmentResource/Pages/EnvironmentTools.php resources/views/filament/resources/environment-resource/pages/partials/gateway-upload-status.blade.php tests/Feature/GatewayUploadTabTest.php
git commit -m "feat: add Load from File tab to EnvironmentTools"
```

---

## Task 7: Server config + quality gates

**Files:**
- Modify: `/Users/idawoodjee/Library/Application Support/Herd/config/php/85/php.ini` (outside the repo — local machine config, not committed)

- [ ] **Step 1: Raise upload limits for this site's PHP 8.5 Herd config**

Edit `/Users/idawoodjee/Library/Application Support/Herd/config/php/85/php.ini`, changing:

```ini
memory_limit=128M
upload_max_filesize=2M
post_max_size=2M
```

to:

```ini
memory_limit=256M
upload_max_filesize=200M
post_max_size=200M
```

`memory_limit` only needs headroom above the largest single chunk processed at once (1MB, per `GzipFileCompressor::CHUNK_SIZE`) plus normal Laravel/Filament request overhead — 256M is generous, not scaled to the 100MB+ payload size, because streaming means it doesn't need to be.

- [ ] **Step 2: Restart Herd's PHP service and verify**

Run: `herd restart` (or restart via the Herd menu bar app)
Run: `php -i | grep -E "upload_max_filesize|post_max_size|memory_limit"`
Expected: `upload_max_filesize => 200M`, `post_max_size => 200M`, `memory_limit => 256M`.

- [ ] **Step 3: Document the equivalent for non-Herd environments**

No code change — note here for whoever configures the deployment target outside local Herd: nginx needs `client_max_body_size 200M;` in the relevant `server`/`location` block, or Apache needs `LimitRequestBody 209715200` (200MB in bytes), matching the php.ini values above.

- [ ] **Step 4: Run the full quality gate**

Run: `vendor/bin/pint`
Expected: no style violations (or auto-fixed cleanly — review the diff if anything changes).

Run: `php artisan test`
Expected: full suite PASSES, including every test added in Tasks 1-6.

- [ ] **Step 5: Commit any Pint formatting fixes**

```bash
git add -A
git commit -m "style: pint formatting pass"
```

(Skip this step if Pint made no changes.)
