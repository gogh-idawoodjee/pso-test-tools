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

    public function getLabel(): ?string
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

    /**
     * A stage-mapped percentage for the status bar, not a byte-level upload
     * progress — the gateway POST doesn't expose transfer progress through
     * the HTTP client, so this just gives each stage a sense of movement.
     */
    public function progressPercent(): int
    {
        return match ($this) {
            self::QUEUED => 10,
            self::COMPRESSING => 40,
            self::UPLOADING => 75,
            self::SUCCEEDED, self::FAILED => 100,
        };
    }
}
