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
 * environment). The object lives on R2 until expires_at; the row stays as history.
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
            if ($file->scope_type !== $kind->allowedScope()) {
                $label = $kind->getLabel();
                $scope = class_basename($kind->allowedScope());

                throw new InvalidArgumentException("A {$label} export belongs on a {$scope}, not a ".class_basename($file->scope_type).'.');
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
