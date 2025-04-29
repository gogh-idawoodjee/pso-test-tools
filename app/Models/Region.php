<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Forms;

class Region extends Model
{
    use HasFactory, HasUuids;

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    protected $guarded = [];

    public static function getForm(): array
    {

        return [
            Forms\Components\TextInput::make('name')
                ->required(),
        ];
    }
}
