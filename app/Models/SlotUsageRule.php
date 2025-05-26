<?php

namespace App\Models;

use App\Models\Scopes\UserOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;


/**
 * @method static SlotUsageRule create(array $attributes = [])
 * @mixin Builder
 */
class SlotUsageRule extends Model
{

    use LogsActivity;

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
        return [
            Forms\Components\TextInput::make('id')
                ->label('Rule Set ID')
                ->required(),
            Forms\Components\TextInput::make('name')
                ->required(),
        ];

    }

    public function getActivitylogOptions(): LogOptions
    {

        return LogOptions::defaults();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    #[Override] protected static function booted(): void
    {
        static::addGlobalScope(new UserOwnedModel());
    }


}
