<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Filament\Forms;

/**
 * @method static SlotUsageRule create(array $attributes = [])
 * @mixin Builder
 */
class AppointmentTemplate extends Model
{
    use HasUuids;

    // Disable auto-incrementing (we're using UUIDs)
    public $incrementing = false;

    // Keys are stored as strings, not ints
    protected $keyType = 'string';

    // Allow mass-assignment
    protected $fillable = [
        'id',
        'name',
    ];

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required(),
        ];

    }
}
