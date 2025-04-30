<?php

namespace App\Models;

use App\Enums\Status;
use Filament\Forms;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, HasUuids;

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
            Forms\Components\Section::make('Customer Customer')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->enum(Status::class)
                        ->options(Status::class)
                        ->required(),
                ])->columns(),
            Forms\Components\Section::make('Location')
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->required()
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('city')
                        ->required(),
                    Forms\Components\TextInput::make('country')
                        ->required(),
                    Forms\Components\TextInput::make('lat')
                        ->label('Latitude')
                        ->numeric(),
                    Forms\Components\TextInput::make('long')
                        ->label('Longitude')
                        ->numeric(),

                    Forms\Components\TextInput::make('postcode')
                        ->required(),
                    Forms\Components\Select::make('region_id')
                        ->relationship('region', 'name')
                        ->createOptionForm(Region::getForm())
                        ->editOptionForm(Region::getForm()),

                ])->columns(),
        ];

    }
}
