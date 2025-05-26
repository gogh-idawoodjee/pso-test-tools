<?php

namespace App\Models;

use App\Models\Scopes\UserOwnedModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Forms;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Region extends Model
{
    use HasFactory, HasUuids, LogsActivity;

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
