<?php

namespace App\Models;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Scopes\DatasetOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One identifier PSO will accept in a request (a region, skill, task type,
 * slot usage rule, appointment template or SLA type), scoped to a dataset.
 * Rows come from a PSO sync (origin pso) or are typed in (origin manual);
 * either kind is editable. A user edit on a pso row sets is_locally_modified
 * so the next sync leaves its fields alone.
 *
 * @mixin Builder
 */
class DatasetReferenceValue extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'dataset_id', 'entity_type', 'pso_id', 'label', 'attributes', 'origin', 'last_seen_at', 'is_locally_modified',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => ReferenceEntityType::class,
            'origin' => ReferenceValueOrigin::class,
            'attributes' => 'array',
            'last_seen_at' => 'datetime',
            'is_locally_modified' => 'boolean',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new DatasetOwnedModel);

        // A user edit on a PSO-sourced row protects it from the next sync.
        // The sync itself writes with saveQuietly(), so this never fires for it.
        static::updating(static function (self $row): void {
            if ($row->origin === ReferenceValueOrigin::Pso && $row->isDirty(['pso_id', 'label', 'attributes'])) {
                $row->is_locally_modified = true;
            }
        });
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function scopeOfType(Builder $query, ReferenceEntityType $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    /** True for a PSO row the last sync of its dataset did not see. */
    public function isMissingFromPso(): bool
    {
        $lastSynced = $this->dataset?->last_synced_at;

        return $this->origin === ReferenceValueOrigin::Pso
            && $lastSynced !== null
            && ($this->last_seen_at === null || $this->last_seen_at->lt($lastSynced));
    }

    /** Read one field of the attributes JSON (priority, base_value, base_duration). */
    public function attribute(string $key): mixed
    {
        return data_get($this->attributeValues(), $key);
    }

    private function attributeValues(): array
    {
        return (array) ($this->getAttribute('attributes') ?? []);
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['pso_id', 'label', 'attributes', 'origin', 'is_locally_modified']);
    }
}
