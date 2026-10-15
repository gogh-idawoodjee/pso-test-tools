<?php

namespace App\Support\Tools;

use App\Enums\ExportKind;
use RuntimeException;

class UnusableInputException extends RuntimeException
{
    public function __construct(public readonly ExportKind $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function missing(ExportKind $kind): self
    {
        return new self($kind, 'A '.$kind->getLabel().' export is required.');
    }

    public static function unavailable(ExportKind $kind): self
    {
        return new self($kind, 'The '.$kind->getLabel().' export you chose is no longer available. Choose or upload another.');
    }
}
