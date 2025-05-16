<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class ExternalSanctumToken extends SanctumPersonalAccessToken
{
    protected $connection = 'shared_tokens'; // Use the shared Sanctum DBs
    protected $table = 'personal_access_tokens';
}
