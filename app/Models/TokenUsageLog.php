<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsageLog extends Model
{
    protected $fillable = [
        'personal_access_token_id',
        'user_id',
        'route',
        'method',
        'ip_address',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(ExternalSanctumToken::class, 'personal_access_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
