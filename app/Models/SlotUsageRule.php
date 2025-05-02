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
class SlotUsageRule extends Model
{
    use HasUuids;

    // Tell Eloquent we're not using auto-incrementing IDs
    public $incrementing = false;

    // Keys are strings (UUIDs), not ints
    protected $keyType = 'string';


    // These fields can be mass-assigned
    protected $fillable = [
        'id',
        'name',
    ];

    public static function getForm(): array
    {
        return [Forms\Components\TextInput::make('name')
            ->required(),];
    }
}
