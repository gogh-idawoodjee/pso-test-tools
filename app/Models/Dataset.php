<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Filament\Forms;

class Dataset extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */

    protected $guarded = [];

    protected $with = ['environment'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
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


}
