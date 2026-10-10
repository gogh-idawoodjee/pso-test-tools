<?php

namespace App\Models;

use App\Enums\ReferenceEntityType;
use App\Enums\Status;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Rules\ReferenceValueInDataset;
use App\Models\Scopes\DatasetOwnedModel;
use App\Support\GeocodeHelper;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @method static Model|static create(array $attributes = [])
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class Customer extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'dataset_id',
        'name',
        'slug',
        'address',
        'city',
        'country',
        'postcode',
        'lat',
        'long',
        'region_id',
        'status',
    ];

    #[Override]
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(DatasetReferenceValue::class, 'region_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public static function getForm(): array
    {

        return [
            Section::make('Customer Details')
                ->schema([
                    Forms\Components\Select::make('dataset_id')
                        ->label('Dataset')
                        ->options(static fn (): array => Dataset::ownedByCurrentUser()->with('environment')->get()
                            ->mapWithKeys(static fn (Dataset $dataset): array => [$dataset->id => "{$dataset->environment->name} / {$dataset->name}"])->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(static fn (Set $set) => $set('region_id', null))
                        ->disabledOn('edit')
                        ->dehydrated()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->enum(Status::class)
                        ->default(Status::ACTIVE)
                        ->options(Status::class)
                        ->required(),
                ])->columns(),
            Section::make('Location')
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->required()
                        ->columnSpan(2)->suffixAction(
                            Action::make('geocode_address')
                                ->icon(Heroicon::MapPin)
                                ->action(static function (Get $get, Set $set) {
                                    $addressToGeocode = GeocodeHelper::makeAddressFromParts($get);
                                    GeocodeHelper::geocodeFormAddress($get, $set, 'lat', 'long', $addressToGeocode, true);
                                }))
                        ->hint('click the map icon to geocode this!'),
                    Forms\Components\TextInput::make('city')
                        ->required(),
                    Forms\Components\TextInput::make('country')
                        ->required(),
                    Forms\Components\TextInput::make('postcode')
                        ->required(),
                    Forms\Components\Select::make('region_id')
                        ->label('Region')
                        ->options(static fn (Get $get): array => DatasetReferenceValue::optionsFor($get('dataset_id'), ReferenceEntityType::Region))
                        ->searchable()
                        ->disabled(static fn (Get $get): bool => blank($get('dataset_id')))
                        ->rules([static fn (Get $get) => new ReferenceValueInDataset($get('dataset_id'), ReferenceEntityType::Region)])
                        ->createOptionForm(static fn (Get $get): array => DatasetReferenceValueResource::formSchema(ReferenceEntityType::Region, lockDataset: true, datasetId: static fn (): ?string => $get('dataset_id')))
                        ->createOptionUsing(static fn (Get $get, array $data): string => DatasetReferenceValue::createManual($get('dataset_id'), ReferenceEntityType::Region, $data)->id),
                    Group::make()
                        ->schema([
                            Forms\Components\TextInput::make('lat')
                                ->label('Latitude')
                                ->numeric(),
                            Forms\Components\TextInput::make('long')
                                ->label('Longitude')
                                ->numeric(),

                        ])->columns(3)->columnSpan(2),

                ])->columns(),
        ];

    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new DatasetOwnedModel);
        static::creating(static function ($customer) {
            if (empty($customer->slug)) {
                $base = Str::slug($customer->name);
                $slug = $base;
                $i = 1;

                while (static::where('slug', $slug)->exists()) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }
                $customer->slug = $slug;
            }
        });
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
