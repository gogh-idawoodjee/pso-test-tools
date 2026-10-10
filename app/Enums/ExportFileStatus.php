<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExportFileStatus: string implements HasColor, HasLabel
{
    case Uploading = 'uploading';
    case Summarising = 'summarising';
    case Ready = 'ready';
    case Failed = 'failed';
    case Purged = 'purged';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Purged => 'gray',
            default => 'warning',
        };
    }
}
