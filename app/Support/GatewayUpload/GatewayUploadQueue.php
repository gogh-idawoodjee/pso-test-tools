<?php

namespace App\Support\GatewayUpload;

use App\Enums\PsoGatewayUploadStatus;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Support\ScheduleData\BroadcastSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Queues a Gateway upload of a file the app made itself (a Dataset Builder
 * LOAD), the same way the environment's Gateway upload form does: the file
 * goes to r2 under gateway-uploads/, a PsoGatewayUpload row records it and
 * SendPsoScheduleDataJob sends it, so the run shows in that environment's
 * upload history with its log.
 */
class GatewayUploadQueue
{
    /**
     * @throws RuntimeException with a message for the user when the environment cannot be sent to
     */
    public function queue(Environment $environment, string $localPath, string $filename, bool $skipWebhook, ?int $userId): PsoGatewayUpload
    {
        if (blank($environment->base_url) || blank($environment->account_id) || blank($environment->username) || blank($environment->password)) {
            throw new RuntimeException("Base URL, Account ID, Username and Password are all required for {$environment->name}. Set them on the environment first.");
        }

        // Decrypted before anything is stored: an unreadable password would otherwise leave a row queued for good.
        try {
            $password = Crypt::decryptString($environment->password);
        } catch (DecryptException) {
            throw new RuntimeException("The stored password for {$environment->name} could not be read. Re-save the environment password and try again.");
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $storedPath = 'gateway-uploads/'.Str::ulid().'.'.$extension;
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("The file to send could not be read: {$filename}");
        }

        try {
            Storage::disk('r2')->writeStream($storedPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $upload = PsoGatewayUpload::create([
            'pso_environment_id' => $environment->id,
            'initiated_by_user_id' => $userId,
            'stored_path' => $storedPath,
            'original_filename' => $filename,
            'file_size_bytes' => (int) filesize($localPath),
            'status' => PsoGatewayUploadStatus::QUEUED,
            'queued_at' => now(),
            'dataset_id_override' => null,
            'skip_webhook' => $skipWebhook,
            'strip_existing_broadcast' => true,
            'broadcast_settings' => $skipWebhook ? null : BroadcastSettings::defaults()->toArray(),
        ]);

        SendPsoScheduleDataJob::dispatch($upload->id, $environment->base_url, $environment->account_id, $environment->username, $password);

        return $upload;
    }
}
