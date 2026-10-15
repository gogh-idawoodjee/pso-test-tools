<?php

namespace App\Enums;

enum GatewayUploadLogLevel: string
{
    case STEP = 'step';
    case SUB = 'sub';
    case WARN = 'warn';
    case ERROR = 'error';
    case OK = 'ok';
}
