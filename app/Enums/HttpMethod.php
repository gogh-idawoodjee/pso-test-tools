<?php

namespace App\Enums;

enum HttpMethod: string
{

    /**
     * String values for HTTP Methods as defined in IETF RFC 5789 and RFC 7231.
     */

    case GET = 'get';
    case HEAD = 'head';
    case POST = 'post';
    case PUT = 'put';
    case DELETE = 'delete';
    case CONNECT = 'connect';
    case OPTIONS = 'options';
    case TRACE = 'trace';
    case PATCH = 'patch';

}
