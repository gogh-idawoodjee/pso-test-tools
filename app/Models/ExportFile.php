<?php

namespace App\Models;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Scopes\ScopeOwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Override;
use Throwable;

/**
 * One uploaded PSO export (LOAD, plan, EOD, audits zip on a dataset; SYS on an
 * environment; any kind Unfiled, on the user). The object lives on R2 until expires_at; the row stays as history.
 *
 * @mixin Builder
 */
class ExportFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'scope_type', 'scope_id', 'kind', 'uploaded_by_user_id', 'original_filename', 'stored_path',
        'file_size_bytes', 'content_type', 'summary', 'status', 'error_message', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'status' => ExportFileStatus::class,
            'summary' => 'array',
            'file_size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeOwnedModel);

        static::creating(static function (self $file): void {
            $file->expires_at ??= now()->addHours((int) config('exports.ttl_hours', 12));
            $file->status ??= ExportFileStatus::Uploading;
        });

        static::saving(static function (self $file): void {
            $kind = $file->kind instanceof ExportKind ? $file->kind : ExportKind::from($file->kind);
            if (! $kind->allows((string) $file->scope_type)) {
                throw new InvalidArgumentException("A {$kind->getLabel()} export cannot be stored on a ".class_basename((string) $file->scope_type).'.');
            }
        });
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', ExportFileStatus::Ready);
    }

    /**
     * Minutes after which a row still in Uploading counts as abandoned (browser closed, PUT failed):
     * the presigned slot's lifetime plus a grace margin, because a PUT started near the end of the
     * slot can still finish and complete only accepts an Uploading row.
     */
    public const UPLOAD_GRACE_MINUTES = 10;

    public static function uploadAbandonedAfterMinutes(): int
    {
        return (int) config('exports.slot_minutes', 15) + self::UPLOAD_GRACE_MINUTES;
    }

    /** Still arriving or being read: Summarising, or Uploading within the slot and its grace. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where(static fn (Builder $query): Builder => $query
            ->where('status', ExportFileStatus::Summarising)
            ->orWhere(static fn (Builder $query): Builder => $query
                ->where('status', ExportFileStatus::Uploading)
                ->where('created_at', '>=', now()->subMinutes(self::uploadAbandonedAfterMinutes()))));
    }

    /** An Uploading row older than the slot and its grace: the upload will never complete. */
    public function isAbandonedUpload(): bool
    {
        return $this->status === ExportFileStatus::Uploading
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::uploadAbandonedAfterMinutes()));
    }

    public function scopeOfKind(Builder $query, ExportKind $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === ExportFileStatus::Ready && ! $this->isExpired();
    }

    /**
     * The export's local date, from the summary, when known. Audit messages and IFS event logs carry no
     * export_date: their first message or event (kept in UTC) is dated in $timeZone, by default the
     * dataset's zone, so they line up with the LOAD of the same day.
     */
    public function exportDate(?string $timeZone = null): ?string
    {
        $date = data_get($this->summary, 'export_date');
        if ($date !== null) {
            return $date;
        }

        $first = data_get($this->summary, 'first_message_at') ?? data_get($this->summary, 'first_event');
        if (! is_string($first) || $first === '') {
            return null;
        }

        $timeZone ??= $this->scope instanceof Dataset ? $this->scope->timeZone() : (string) config('app.timezone', 'UTC');

        try {
            return CarbonImmutable::parse($first)->setTimezone($timeZone)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
