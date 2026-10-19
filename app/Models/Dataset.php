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
            ...self::settingsSchema(),
        ];
    }

    /**
     * The per-dataset settings, grouped by the tool that reads them. Shared by the dataset form and the
     * Edit settings action on the dataset page. Settings no web tool reads any more (coverage radius,
     * end of day cut-off, Windows time zone, plural supervisor label, notes) stay in DatasetSettings so
     * old rows still load, but are not shown.
     */
    public static function settingsSchema(): array
    {
        $zones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
        $collapsed = static fn (?self $record): bool => $record === null;

        return [
            Section::make('Names and time zones')
                ->description('Read by the load audit, region availability, trace and travel tuning pages.')
                ->collapsible()
                ->collapsed($collapsed)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('settings.display_name')
                        ->label('Customer name on reports')
                        ->helperText('Shown in report titles, such as SaskTel. Blank means the environment name.'),
                    Forms\Components\TextInput::make('settings.dataset_display_name')
                        ->label('Dataset name on reports')
                        ->helperText('Shown next to the customer name, such as North. Blank means the dataset ID.'),
                    Forms\Components\Select::make('settings.time_zone')
                        ->label('Time zone')
                        ->helperText('PSO exports are in UTC. Reports show times in this zone. Blank means UTC.')
                        ->searchable()
                        ->options($zones),
                    Forms\Components\Select::make('settings.ifs_time_zone')
                        ->label('IFS event log time zone')
                        ->helperText('Only for the trace page: the zone an IFS event log export is written in. Blank means the time zone above.')
                        ->searchable()
                        ->options($zones),
                ]),
            Section::make('Region availability')
                ->description('How the region availability page filters and names things. Attribute labels are also used by the trace page.')
                ->collapsible()
                ->collapsed($collapsed)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TagsInput::make('settings.exclude_resource_types')
                        ->label('Resource types to leave out')
                        ->helperText('PSO resource type IDs that never count towards availability, such as LONGCYCLE. Press Enter after each one.')
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('settings.resource_type_names')
                        ->label('Resource type names')
                        ->helperText('What the page calls each PSO resource type, such as CONSUMER shown as consumer.')
                        ->keyLabel('PSO resource type')
                        ->valueLabel('Shown as')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('settings.region_pivot.red_below')
                        ->label('Region shows red below')
                        ->helperText('A region with fewer available resources than this on a day is red.')
                        ->numeric()->integer(),
                    Forms\Components\TextInput::make('settings.region_pivot.amber_below')
                        ->label('Region shows amber below')
                        ->helperText('Fewer than this is amber; at or above is green.')
                        ->numeric()->integer(),
                    Forms\Components\KeyValue::make('settings.region_labels')
                        ->label('Region names')
                        ->helperText('What the page calls each PSO region ID. Regions not listed show their ID.')
                        ->keyLabel('PSO region')
                        ->valueLabel('Shown as')
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('settings.attribute_labels')
                        ->label('Attribute labels')
                        ->helperText('Headings for resource or activity attributes, such as district shown as District.')
                        ->keyLabel('PSO attribute')
                        ->valueLabel('Shown as')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('settings.supervisor_label')
                        ->label('Supervisor label')
                        ->helperText('What the page calls the person a resource reports to, such as Team lead. Blank means Supervisor.'),
                    Forms\Components\TextInput::make('settings.region_label')
                        ->label('Region label')
                        ->helperText('What travel tuning calls a region, such as GB or Territory. Blank means Region.')
                        ->maxLength(20),
                ]),
            Section::make('Appointment booking')
                ->description('Which service level agreement (SLA) types the booking page uses for this dataset. Sync the dataset first so the list is filled.')
                ->collapsible()
                ->collapsed($collapsed)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Select::make('settings.appointment_sla_type_id')
                        ->label('SLA type for appointment offers')
                        ->helperText('The SLA type used when asking PSO for appointment slots.')
                        ->searchable()
                        ->options(static fn (?self $record): array => DatasetReferenceValue::psoIdOptionsFor($record?->id, ReferenceEntityType::SlaType)),
                    Forms\Components\Select::make('settings.default_booking_sla_type_id')
                        ->label('Default SLA type for booking')
                        ->helperText('Used when the task type has none.')
                        ->searchable()
                        ->options(static fn (?self $record): array => DatasetReferenceValue::psoIdOptionsFor($record?->id, ReferenceEntityType::SlaType)),
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
