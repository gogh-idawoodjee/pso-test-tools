<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @method static SlotUsageRule create(array $attributes = [])
 * @mixin Builder
 */
class AppointmentTemplate extends Model
{
    use LogsActivity;

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
            Forms\Components\TextInput::make('id')
                ->label('Template ID')
                ->required(),
            Forms\Components\TextInput::make('name')
                ->required(),
        ];

    }

    public function getActivitylogOptions(): LogOptions
    {

        return LogOptions::defaults();
    }
}
