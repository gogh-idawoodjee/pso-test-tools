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
