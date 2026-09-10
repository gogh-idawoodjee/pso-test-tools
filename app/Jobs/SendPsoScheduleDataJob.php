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
