<?php

namespace App\Support\Tools;

use RuntimeException;

/** A pick of source runs the tool refused; the message is shown to the user as it is. */
class UnusableRunInputException extends RuntimeException
{
    /** @param  int  $row  the 1-based row on the Inputs step, 0 when the whole list is wrong */
    public function __construct(public readonly int $row, string $message)
    {
        parent::__construct($message);
    }
}
