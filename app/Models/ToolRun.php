<?php

namespace App\Models;

use App\Enums\Tool;
use App\Enums\ToolRunStatus;
use App\Models\Scopes\ScopeOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * One run of a tool against a set of export files. The result is the tool's data
 * file, encrypted at rest, and outlives the raw exports it was built from.
 *
 * @mixin Builder
 */
class ToolRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'scope_type', 'scope_id', 'tool', 'run_date', 'inputs', 'started_by_user_id', 'status',
        'result', 'warnings', 'progress', 'summary', 'error_message', 'queued_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'tool' => Tool::class,
            'status' => ToolRunStatus::class,
            'inputs' => 'array',
            'warnings' => 'array',
            'progress' => 'array',
            'summary' => 'array',
            'result' => 'encrypted:array',
            'run_date' => 'date',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeOwnedModel);

        static::creating(static function (self $run): void {
            $run->status ??= ToolRunStatus::Queued;
            $run->queued_at ??= now();
        });
    }

    /** Every column except `result`, which is encrypted and 1 to 2 MB a row; lists and polls must not load it. */
    public function scopeWithoutResult(Builder $query): void
    {
        $query->select([
            'id', 'scope_type', 'scope_id', 'tool', 'run_date', 'inputs', 'started_by_user_id', 'status', 'progress',
            'warnings', 'summary', 'error_message', 'queued_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ]);
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
