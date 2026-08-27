<?php

namespace App\Enums;

enum BroadcastParameterType: string
{
    case MEDIATYPE = 'mediatype';
    case EXTERNAL_REFERENCE = 'external_reference';
    case AUTHENTICATION = 'authentication';
    case WSID = 'wsid';
    case PASSWORD = 'password';
    case USERNAME = 'username';
    case URL = 'url';
    case COMPRESSION = 'compression';
    case AUTH_TOKEN_URL_CERT_THUMBPRINT = 'auth_token_url_cert_thumbprint';
    case AUTH_TOKEN_URL = 'auth_token_url';
    case ETAG = 'etag';
    case HTTPMETHOD = 'httpmethod';
    case TO_ADDRESS = 'to_address';
    case SMTP_SERVER = 'smtp_server';
    case FILE_PATH = 'file_path';
    case FILENAME_FORMAT = 'filename_format';
    case ADDRESS = 'address';
    case BINDING = 'binding';
    case METHOD = 'method';
    case DATA_TABLES = 'data_tables';
    case REQUIRED_TABLES = 'required_tables';
    case EXCLUDED_TABLES = 'excluded_tables';
    case APPLICATION_TYPE_ID = 'application_type_id';
    case CHECK_IN_EXPIRED_TIME = 'check_in_expired_time';
    case PING_RATE = 'ping_rate';
    case DATA_PING_RATE = 'data_ping_rate';
}
