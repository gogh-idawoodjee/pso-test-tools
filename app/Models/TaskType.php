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


class TaskType extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'id',
        'name',
        'priority',
        'base_duration',
    ];

    protected $guarded = [];


    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }


    public static function getForm(): array
    {
        return [

            Forms\Components\TextInput::make('name')
                ->required(),
            Forms\Components\TextInput::make('priority')
                ->numeric()
                ->minValue(1)
                ->maxValue(10)
                ->required(),
            Forms\Components\TextInput::make('base_duration')
                ->numeric()
                ->minValue(10)
                ->step(1)
                ->maxValue(7200)
                ->required(),

            Forms\Components\TextInput::make('base_value')
                ->minValue(1000)
                ->step(500)
                ->maxValue(100000)
                ->numeric()
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
