<?php

namespace App\Models;

use App\Models\Scopes\UserOwnedModel;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @method static Model|static create(array $attributes = [])
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class SlotUsageRule extends Model
{
    use HasUuids, LogsActivity;

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

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new UserOwnedModel);
    }
}
