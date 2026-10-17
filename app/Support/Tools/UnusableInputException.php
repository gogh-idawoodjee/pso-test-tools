<?php

namespace App\Support\Tools;

use App\Enums\ExportKind;
use InvalidArgumentException;
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

    /** @param list<ExportKind> $kinds */
    public static function oneOf(array $kinds): self
    {
        if ($kinds === []) {
            throw new InvalidArgumentException('A one-of input needs at least one export kind.');
        }

        $labels = array_map(static fn (ExportKind $kind): string => $kind->getLabel(), $kinds);
        $last = array_pop($labels);

        return new self($kinds[0], 'Choose one '.($labels === [] ? $last : implode(', ', $labels).' or '.$last).' export.');
    }
}
