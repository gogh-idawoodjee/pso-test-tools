<?php

namespace App\Models;

use App\Enums\PsoGatewayUploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsoGatewayUpload extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'status' => PsoGatewayUploadStatus::class,
        'file_size_bytes' => 'integer',
        'compressed_size_bytes' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $fillable = [
        'pso_environment_id',
        'initiated_by_user_id',
        'stored_path',
        'original_filename',
        'file_size_bytes',
        'compressed_size_bytes',
        'status',
        'internal_id',
        'error_message',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'pso_environment_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
