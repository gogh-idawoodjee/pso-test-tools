<?php

namespace App\Jobs;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\Scopes\UserOwnedModel;
use App\Models\User;
use App\Support\GatewayUpload\StoredText;
use App\Support\GatewayUpload\UploadRunLog;
use App\Support\GatewayUploadPath;
use App\Support\GzipFileCompressor;
use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\InputReferenceIdentity;
use App\Support\ScheduleData\MissingScheduleDataWrapperException;
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
use Illuminate\Queue\TimeoutExceededException;
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

    // Download, unzip, stream-rewrite, logon, gzip, a POST of up to 240s and
    // logoff all run inside this. The queue connection's retry_after must be
    // larger than this value (see config/queue.php).
    public int $timeout = 900;

    public int $tries = 1;

    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

    public const string KEPT_BROADCAST_WARNING = "The file's own Broadcast rows will be sent as they are. Check that their parameters (the url and required tables) are not pointed at a production environment before you upload.";

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

        $upload->update(['status' => PsoGatewayUploadStatus::PREPARING, 'started_at' => now()]);

        $workDir = storage_path('app/private/gateway-uploads/'.$upload->id);
        if (! is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $sessionToken = null;

        try {
            $log->step('=== PSO RESTful Gateway - Schedule Data Upload ===');
            $log->sub('Environment      : '.($this->environmentName($upload) ?? '(unknown)'));
            $log->sub("Gateway base URL : {$this->baseUrl}");
            $log->sub("Account          : {$this->accountId}");
            $log->sub("User             : {$this->username}");
            $log->sub("File             : {$upload->original_filename}");
            $log->sub('Gzip compression : on');

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

            $sourceName = $isZip ? basename($rawPath) : $upload->original_filename;
            $sendPath = $this->preparePayload($upload, $log, $rawPath, $sourceName, $format, $contentType, $workDir);

            // Display-only — never blocks or alters the upload itself. If the
            // file can't be parsed for these fields, they're simply left null;
            // the bytes are still gzipped and sent unchanged either way.
            $summary = ScheduleDataSummaryExtractor::extract($sendPath, $format);

            // `dataset_id` is the one sent (read from the copy); the rewrite has
            // already stored the file's own value when it changed anything.
            $upload->update([
                'dataset_id' => $summary['dataset_id'],
                'dataset_id_in_file' => $upload->dataset_id_in_file ?? $summary['dataset_id'],
                'input_reference_datetime' => $summary['input_reference_datetime'],
                'activity_count' => $summary['activity_count'],
                'resource_count' => $summary['resource_count'],
            ]);

            // Same order as the script: log on, then gzip, then POST. The
            // session is logged off in `finally` if anything below throws.
            $log->step('Step 1/3: Logging on through pso-services...');
            $sessionToken = $this->authenticatePSO($this->baseUrl, $this->accountId, $this->username, $this->password);

            if (! $sessionToken) {
                // The services' own reason (401, 422, 5xx or unreachable),
                // never the request itself: it carries the password.
                $reason = $this->session_error_message ?? 'no detail from the PSO services API.';
                $log->error('LOGON FAILED');
                $this->failUpload($upload, "Could not log on to the PSO gateway: {$reason}");

                return;
            }

            $log->ok('Logon successful. Session token acquired (length: '.strlen($sessionToken).').');

            $log->step('Step 2/3: Preparing request body...');
            $log->sub('Gzip-compressing request body...');
            $upload->update(['status' => PsoGatewayUploadStatus::COMPRESSING]);

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

            $responseBody = StoredText::cap($response->body(), StoredText::RESPONSE_BODY_BYTES);

            $upload->update([
                'response_status' => $response->status(),
                'response_body' => $responseBody,
                'wire_bytes' => $compressedSize,
                'post_duration_ms' => (int) round($postSeconds * 1000),
            ]);

            $rate = $postSeconds > 0 ? round(($compressedSize / 1048576) / $postSeconds, 2) : 0;

            // As the script: the outcome first, then the numbers and the raw body.
            if ($response->successful()) {
                $log->ok('Upload complete.');
            } else {
                $log->error('UPLOAD FAILED after '.$this->duration($postSeconds));
            }

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
                $log->step('=== Done in '.$this->duration(microtime(true) - $runStartedAt).' total ===');

                if (filled($upload->webhook_view_url)) {
                    $log->sub("Webhook endpoint for this run: {$upload->webhook_view_url}");
                }

                $upload->update([
                    'status' => PsoGatewayUploadStatus::SUCCEEDED,
                    'internal_id' => $response->json('InternalId'),
                    'completed_at' => now(),
                ]);

                return;
            }

            $this->failUpload($upload, $this->mapGatewayError($response));
        } catch (ConnectionException $exception) {
            Log::error('PSO gateway connection error', ['upload_id' => $upload->id, 'message' => $exception->getMessage()]);
            $log->error('UPLOAD FAILED');
            $log->sub("Error: {$exception->getMessage()}");
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
     * override, Input_Reference marked as sent from PSO Test Tools, old
     * broadcast rows stripped, new Broadcast injected) and returns its path.
     * Returns the original path when nothing changed.
     */
    private function preparePayload(
        PsoGatewayUpload $upload,
        UploadRunLog $log,
        string $rawPath,
        string $sourceName,
        string $format,
        string $contentType,
        string $workDir,
    ): string {
        $datasetIdOverride = filled($upload->dataset_id_override) ? trim((string) $upload->dataset_id_override) : null;
        $injection = null;

        if ($upload->skip_webhook) {
            $log->sub('Webhook broadcast skipped (Skip webhook broadcast is on).');
        } else {
            $environmentName = $this->environmentName($upload);

            if ($environmentName === null) {
                throw new RuntimeException("The upload's environment could not be found, so the webhook alias cannot be named. Turn on 'Skip webhook broadcast' to send the file without it.");
            }

            $endpoint = app(WebhookEndpointProvisioner::class)->provision(
                $environmentName,
                $contentType,
                $log,
            );

            // As the script: the compression parameter is only for the public
            // webhook.site, which can store gzipped bodies.
            $log->sub("Broadcast compression: none (self-hosted webhook can't store gzipped bodies)");

            $upload->update([
                'webhook_url' => $endpoint->url,
                'webhook_view_url' => $endpoint->viewUrl,
            ]);

            $injection = new BroadcastInjection(
                BroadcastSettings::fromArray($upload->broadcast_settings ?? []),
                $endpoint->url,
            );
        }

        // The column defaults to true; only an explicit false keeps the file's own rows.
        $stripExisting = $upload->strip_existing_broadcast !== false;

        // Always written, so the copy is always made: PSO shows where the load came from.
        $identity = $this->inputReferenceIdentity($upload);

        // The script's sibling-copy name: `<original>_withBroadcast.<ext>`.
        $destinationPath = $workDir.'/'.pathinfo(basename($sourceName), PATHINFO_FILENAME)."_withBroadcast.{$format}";

        $log->step('Rewriting the file (streaming)...');

        try {
            $report = app(ScheduleDataRewriterFactory::class)
                ->forFormat($format)
                ->rewrite($rawPath, $destinationPath, $datasetIdOverride, $injection, $stripExisting, $identity);
        } catch (Throwable $exception) {
            // A rewriter can stop part-way through; never leave a truncated
            // copy behind that a later step could pick up and send.
            File::delete($destinationPath);

            if ($exception instanceof MissingScheduleDataWrapperException) {
                throw new RuntimeException($exception->getMessage()." Turn on 'Skip webhook broadcast' to send the file as it is.", previous: $exception);
            }

            throw $exception;
        }

        if (! $report->wrapperFound) {
            // Only reachable without an injection: the rewriter refuses to
            // inject into a file with no wrapper. The script sends it as is.
            $log->warn("No top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - sending it unchanged.");
        }

        $this->logRewrite($log, $report, $datasetIdOverride, $injection, $stripExisting, $identity);

        if ($report->datasetIds !== []) {
            // VARCHAR(255): several or long ids must not overflow under strict mode.
            $upload->update(['dataset_id_in_file' => StoredText::cap(implode(', ', $report->datasetIds), StoredText::DATASET_ID_BYTES)]);
        }

        if ($injection === null && ! $report->datasetIdChanged && $report->inputReferencesMarked === 0
            && $report->strippedBroadcasts === 0 && $report->strippedBroadcastParameters === 0) {
            File::delete($destinationPath);
            $log->sub('Nothing changed - sending the file exactly as uploaded.');

            return $rawPath;
        }

        $log->sub('Sending modified copy instead of the original: '.basename($destinationPath).' ('.$this->megabytes($report->bytesWritten).' MB)');

        return $destinationPath;
    }

    private function logBroadcastRowsKept(UploadRunLog $log): void
    {
        $log->sub('Existing Broadcast rows: kept');
        $log->warn(self::KEPT_BROADCAST_WARNING);
    }

    private function logRewrite(UploadRunLog $log, RewriteReport $report, ?string $datasetIdOverride, ?BroadcastInjection $injection, bool $stripExisting, InputReferenceIdentity $identity): void
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

        if ($report->inputReferencesMarked > 0) {
            $log->sub("Input_Reference marked as sent from PSO Test Tools: user_id '{$identity->userId}', description '{$identity->description}'");
        } elseif ($report->wrapperFound) {
            $log->sub('No Input_Reference in the file - nothing to mark as sent from PSO Test Tools.');
        }

        if ($injection === null) {
            // With the webhook injected, the lines below report the stripping.
            if ($stripExisting && $report->strippedBroadcasts === 0 && $report->strippedBroadcastParameters === 0) {
                $log->sub('Existing Broadcast rows: none found');
            } elseif ($stripExisting) {
                $log->sub("Existing Broadcast rows: removed ({$report->strippedBroadcasts} Broadcast, {$report->strippedBroadcastParameters} Broadcast_Parameter)");
            } else {
                $this->logBroadcastRowsKept($log);
            }

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

    /**
     * Who sent what, from where: the user who queued the upload, the
     * environment, the file name and the time the upload was queued (UTC).
     */
    private function inputReferenceIdentity(PsoGatewayUpload $upload): InputReferenceIdentity
    {
        $userName = User::query()->whereKey($upload->initiated_by_user_id)->value('name');

        return InputReferenceIdentity::forLoadFromFile(
            filled($userName) ? (string) $userName : '(unknown user)',
            $this->environmentName($upload) ?? '(unknown environment)',
            (string) $upload->original_filename,
            $upload->queued_at ?? $upload->created_at ?? now(),
        );
    }

    /**
     * Read past the user-owned global scope: the queue worker has no signed-in
     * user, so `$upload->environment` would always come back null here.
     */
    private function environmentName(PsoGatewayUpload $upload): ?string
    {
        $name = Environment::query()
            ->withoutGlobalScope(UserOwnedModel::class)
            ->whereKey($upload->pso_environment_id)
            ->value('name');

        return filled($name) ? (string) $name : null;
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
        $message = StoredText::cap($message, StoredText::ERROR_MESSAGE_BYTES);

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
     *
     * The worker may stop at any point, including during or after the POST,
     * so the message never claims nothing reached PSO. A hard stop also skips
     * `finally`: the session is not logged off and the work directory stays.
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

        $message = $exception instanceof TimeoutExceededException
            ? 'The upload did not finish within '.intdiv($this->timeout, 60).' minutes; check PSO before resending.'
            : 'The upload stopped before it finished (the queue worker stopped or the job failed); check PSO before resending.';

        $this->failUpload($upload, $message);
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
