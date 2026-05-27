<?php

namespace App\Models;

use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
