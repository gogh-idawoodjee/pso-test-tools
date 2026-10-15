<?php

namespace App\Models;

use App\Casts\DatasetSettingsCast;
use App\Enums\ReferenceEntityType;
use DateTimeZone;
use Filament\Forms;
use Filament\Schemas\Components\Section;
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
            'settings' => DatasetSettingsCast::class,
        ];
    }

    /**
     * The zone the dataset's times are shown in. PSO exports are UTC, so a dataset with no zone
     * set shows UTC rather than the app's own zone (which once showed SaskTel times in Toronto time).
     */
    public function timeZone(): string
    {
        return $this->settings->timeZone ?? 'UTC';
    }

    public function hasTimeZone(): bool
    {
        return filled($this->settings->timeZone ?? null);
    }

    /** The zone IFS event log times are written in; blank means the dataset time zone. */
    public function ifsTimeZone(): string
    {
        return $this->settings->ifsTimeZone ?? $this->timeZone();
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
            Section::make('Settings')
                ->description('What the tools read for this customer: names, thresholds and the local time zone.')
                ->collapsible()
                ->collapsed(static fn (?self $record): bool => $record === null)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('settings.display_name')->label('Customer name on dashboards'),
                    Forms\Components\TextInput::make('settings.dataset_display_name')->label('Dataset name on dashboards'),
                    Forms\Components\TextInput::make('settings.coverage_km')->label('Coverage radius (km)')->numeric(),
                    Forms\Components\TextInput::make('settings.eod_cutoff_hour')->label('End of day cut-off hour')->numeric()->integer()->minValue(0)->maxValue(23),
                    Forms\Components\Select::make('settings.time_zone')
                        ->label('Time zone')
                        ->searchable()
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())),
                    Forms\Components\Select::make('settings.ifs_time_zone')
                        ->label('IFS event log time zone')
                        ->helperText('Zone the IFS event log times are written in. Blank means the dataset time zone.')
                        ->searchable()
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())),
                    Forms\Components\TextInput::make('settings.windows_time_zone')->label('Windows time zone'),
                    Forms\Components\TagsInput::make('settings.exclude_resource_types')->label('Resource types to leave out')->columnSpanFull(),
                    Forms\Components\KeyValue::make('settings.resource_type_names')
                        ->label('Resource type names')
                        ->keyLabel('PSO resource type')
                        ->valueLabel('Shown as')
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('settings.attribute_labels')->label('Attribute labels')->columnSpanFull(),
                    Forms\Components\TextInput::make('settings.region_pivot.red_below')->label('Region shows red below')->numeric()->integer(),
                    Forms\Components\TextInput::make('settings.region_pivot.amber_below')->label('Region shows amber below')->numeric()->integer(),
                    Forms\Components\KeyValue::make('settings.region_labels')->label('Region labels')->columnSpanFull(),
                    Forms\Components\TextInput::make('settings.supervisor_label')->label('Supervisor label'),
                    Forms\Components\TextInput::make('settings.supervisor_label_plural')->label('Supervisor label (plural)'),
                    Forms\Components\KeyValue::make('settings.notes')->label('Notes')->columnSpanFull(),
                ]),
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
