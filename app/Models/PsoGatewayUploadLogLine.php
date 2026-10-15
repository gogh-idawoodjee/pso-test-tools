<?php

namespace App\Models;

use App\Enums\GatewayUploadLogLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsoGatewayUploadLogLine extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = [
        'level' => GatewayUploadLogLevel::class,
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'pso_gateway_upload_id',
        'level',
        'text',
        'created_at',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(PsoGatewayUpload::class, 'pso_gateway_upload_id');
    }
}
