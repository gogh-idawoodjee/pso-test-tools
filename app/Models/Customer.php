<?php

namespace App\Models;

use App\Enums\Status;
use App\Support\GeocodeHelper;
use Filament\Forms;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use HasFactory, HasUuids, LogsActivity;


    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
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

    #[Override] public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [

        'status' => Status::class,
    ];

    protected $guarded = [];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public static function getForm(): array
    {

        return [
            Forms\Components\Section::make('Customer Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->enum(Status::class)
                        ->default(Status::ACTIVE)
                        ->options(Status::class)
                        ->required(),
                ])->columns(),
            Forms\Components\Section::make('Location')
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->required()
                        ->columnSpan(2)->suffixAction(
                            Forms\Components\Actions\Action::make('geocode_address')
                                ->icon('heroicon-m-map-pin')
                                ->action(static function (Forms\Get $get, Forms\Set $set) {
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
                        ->relationship('region', 'name')
                        ->createOptionForm(Region::getForm())
                        ->editOptionForm(Region::getForm()),
                    Forms\Components\Group::make()
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


    #[Override] protected static function booted(): void
    {
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

    public function getActivitylogOptions(): LogOptions
    {

        return LogOptions::defaults();
    }
}
