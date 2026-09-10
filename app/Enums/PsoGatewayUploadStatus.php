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
}
