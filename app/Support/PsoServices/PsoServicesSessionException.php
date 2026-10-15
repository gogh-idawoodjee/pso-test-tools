<?php

namespace App\Support\PsoServices;

use RuntimeException;
use Throwable;

class PsoServicesSessionException extends RuntimeException
{
    /**
     * @param  int  $statusCode  HTTP status from pso-services, 0 when it could not be reached
     */
    public function __construct(string $message, public int $statusCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
