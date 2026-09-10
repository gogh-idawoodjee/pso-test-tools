<?php

namespace App\Jobs;

use App\Enums\PsoGatewayUploadStatus;
use App\Models\PsoGatewayUpload;
use App\Support\GatewayUploadPath;
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

    public int $timeout = 300;

    public int $tries = 1;

    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

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

            $gzipStream = @fopen($gzipPath, 'rb');

            if ($gzipStream === false) {
                throw new RuntimeException("The compressed payload could not be opened for sending: {$gzipPath}");
            }

            $response = Http::withHeaders([
                'apiKey' => $token,
                'Content-Encoding' => 'gzip',
            ])->withBody($gzipStream, $contentType)
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
