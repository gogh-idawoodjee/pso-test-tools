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
        'lat' => 'decimal',
        'long' => 'decimal',
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
            Forms\Components\TextInput::make('address')
                ->required(),
            Forms\Components\TextInput::make('city')
                ->required(),
            Forms\Components\TextInput::make('country')
                ->required(),
            Forms\Components\TextInput::make('lat')
                ->numeric(),
            Forms\Components\TextInput::make('long')
                ->numeric(),
            Forms\Components\TextInput::make('name')
                ->required(),
            Forms\Components\TextInput::make('postcode')
                ->required(),
            Forms\Components\Select::make('region_id')
                ->relationship('region', 'name')
                ->createOptionForm(Region::getForm())
                ->editOptionForm(Region::getForm()),
            Forms\Components\Select::make('status')
                ->enum(Status::class)
                ->options(Status::class)
                ->required(),];

    }
}
