<?php

namespace App\Models;

use App\Enums\PsoGatewayUploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsoGatewayUpload extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'status' => PsoGatewayUploadStatus::class,
        'file_size_bytes' => 'integer',
        'compressed_size_bytes' => 'integer',
        'skip_webhook' => 'boolean',
        'broadcast_settings' => 'array',
        'response_status' => 'integer',
        'wire_bytes' => 'integer',
        'post_duration_ms' => 'integer',
        'activity_count' => 'integer',
        'resource_count' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'input_reference_datetime' => 'datetime',
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
        'dataset_id',
        'input_reference_datetime',
        'activity_count',
        'resource_count',
        'queued_at',
        'started_at',
        'completed_at',
        'dataset_id_override',
        'skip_webhook',
        'broadcast_settings',
        'response_status',
        'response_body',
        'webhook_url',
        'webhook_view_url',
        'wire_bytes',
        'post_duration_ms',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'pso_environment_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function logLines(): HasMany
    {
        return $this->hasMany(PsoGatewayUploadLogLine::class, 'pso_gateway_upload_id');
    }
}
