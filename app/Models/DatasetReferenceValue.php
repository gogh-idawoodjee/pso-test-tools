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
 * either kind is editable. A user edit of the PSO ID, the label or a
 * PSO-sourced attribute sets is_locally_modified so the next sync leaves the
 * row alone; local-only attributes (ReferenceEntityType::localAttributeKeys)
 * can be edited without that, and a sync keeps them.
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

        // A user edit protects the row from the next sync, whichever origin it
        // has: a manual row that PSO later also returns keeps the typed label.
        // The sync itself writes with saveQuietly(), so this never fires for it.
        static::updating(static function (self $row): void {
            if ($row->isDirty(['pso_id', 'label']) || $row->psoAttributesChanged()) {
                $row->is_locally_modified = true;
            }
        });

        // A typed-in row whose label is more than its id was named on purpose.
        static::creating(static function (self $row): void {
            if ($row->origin === ReferenceValueOrigin::Manual && filled($row->label) && $row->label !== $row->pso_id) {
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

    /** [row uuid => "label (PSO ID)"] for selects that store the row. */
    public static function optionsFor(?string $datasetId, ReferenceEntityType $type): array
    {
        if (blank($datasetId)) {
            return [];
        }

        return static::query()->where('dataset_id', $datasetId)->ofType($type)->orderBy('label')->get()
            ->mapWithKeys(static fn (self $row): array => [$row->id => "{$row->label} ({$row->pso_id})"])
            ->all();
    }

    /** [PSO ID => "label (PSO ID)"] for selects whose value goes straight into a PSO request. */
    public static function psoIdOptionsFor(?string $datasetId, ReferenceEntityType $type): array
    {
        if (blank($datasetId)) {
            return [];
        }

        return static::query()->where('dataset_id', $datasetId)->ofType($type)->orderBy('label')->get()
            ->mapWithKeys(static fn (self $row): array => [$row->pso_id => "{$row->label} ({$row->pso_id})"])
            ->all();
    }

    /** Create a typed-in row for a dataset; the label defaults to the PSO ID. */
    public static function createManual(?string $datasetId, ReferenceEntityType $type, array $data): self
    {
        // The dataset id comes from form state, so confirm it is one of the user's.
        $dataset = Dataset::ownedByCurrentUser()->findOrFail($datasetId);

        return static::create([
            'dataset_id' => $dataset->id,
            'entity_type' => $type,
            'pso_id' => $data['pso_id'],
            'label' => filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'],
            'origin' => ReferenceValueOrigin::Manual,
        ]);
    }

    /** Appointment templates: whether a booking blocks the slot; PSO's default when unset is true. */
    public function blockingDefault(): bool
    {
        return (bool) ($this->attribute('blocking') ?? true);
    }

    /** True when an edit changed a PSO-sourced attribute key (local-only keys are ignored). */
    private function psoAttributesChanged(): bool
    {
        if (! $this->isDirty('attributes')) {
            return false;
        }

        $before = (array) ($this->getOriginal('attributes') ?? []);
        $after = $this->attributeValues();

        foreach ($this->entity_type?->attributeKeys() ?? [] as $key) {
            if (! self::sameAttributeValue($key, $before[$key] ?? null, $after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Form state can carry "5" where the row holds 5, and an unset blocking
     * means true, so compare what the values mean rather than their types.
     */
    private static function sameAttributeValue(string $key, mixed $before, mixed $after): bool
    {
        if ($key === 'blocking') {
            return (bool) ($before ?? true) === (bool) ($after ?? true);
        }

        $before = $before === '' ? null : $before;
        $after = $after === '' ? null : $after;

        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return $before === $after;
    }

    /** Read one field of the attributes JSON (priority, base_value, base_duration, blocking, booking_sla_type_id). */
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
