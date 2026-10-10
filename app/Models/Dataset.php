<?php

namespace App\Models;

use App\Enums\ReferenceEntityType;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @method static Model|static create(array $attributes = [])
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class Dataset extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $with = ['environment'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_sync_summary' => 'array',
            'settings' => 'array',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function referenceValues(): HasMany
    {
        return $this->hasMany(DatasetReferenceValue::class);
    }

    public function referenceValuesOf(ReferenceEntityType $type): HasMany
    {
        return $this->referenceValues()->where('entity_type', $type)->orderBy('label');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Datasets whose environment belongs to the logged-in user. */
    public function scopeOwnedByCurrentUser(Builder $query): Builder
    {
        return $query->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', auth()->id()));
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required(),
            Forms\Components\TextInput::make('rota')
                ->required(),
            Forms\Components\Select::make('environment_id')
                ->relationship('environment', 'name')
                ->required(),
        ];
    }

    public function exportFiles(): MorphMany
    {
        return $this->morphMany(ExportFile::class, 'scope');
    }

    public function toolRuns(): MorphMany
    {
        return $this->morphMany(ToolRun::class, 'scope');
    }
}
