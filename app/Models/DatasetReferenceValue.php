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

        $row = static::create([
            'dataset_id' => $dataset->id,
            'entity_type' => $type,
            'pso_id' => $data['pso_id'],
            'label' => filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'],
            'origin' => ReferenceValueOrigin::Manual,
        ]);

        // The form's Blocking toggle saves through a relationship hook that a create-option form never runs.
        if ($type === ReferenceEntityType::AppointmentTemplate && array_key_exists('blocking_override', $data)) {
            $row->setBlockingOverride($data['blocking_override'] === null ? null : (bool) $data['blocking_override']);
        }

        return $row;
    }

    /**
     * Appointment templates: whether a booking blocks the slot. A manual
     * override wins over PSO's value; PSO's default when both are unset is true.
     */
    public function blockingDefault(): bool
    {
        return (bool) ($this->attribute('blocking_override') ?? $this->attribute('blocking') ?? true);
    }

    /**
     * Appointment templates: store a manual blocking value. Kept only when it
     * differs from PSO's own value, so saving an untouched form stores nothing;
     * an explicit true on a template PSO left unset is kept. Never freezes the row.
     */
    public function setBlockingOverride(?bool $value): void
    {
        $attributes = $this->attributeValues();
        unset($attributes['blocking_override']);
        if ($value !== null && $value !== $this->attribute('blocking')) {
            $attributes['blocking_override'] = $value;
        }

        if ($attributes !== $this->attributeValues()) {
            $this->update(['attributes' => $attributes]);
        }
    }

    /** Appointment templates: true when the blocking flag comes from a manual override. */
    public function hasBlockingOverride(): bool
    {
        return $this->attribute('blocking_override') !== null;
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
            if (! self::sameAttributeValue($before[$key] ?? null, $after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Form state can carry "5" where the row holds 5, so compare numbers as numbers. */
    private static function sameAttributeValue(mixed $before, mixed $after): bool
    {
        $before = $before === '' ? null : $before;
        $after = $after === '' ? null : $after;

        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return $before === $after;
    }

    /** Read one field of the attributes JSON (priority, base_value, base_duration, blocking, blocking_override, booking_sla_type_id). */
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
