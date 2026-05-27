<?php

namespace App\Models;

use App\Models\Scopes\UserOwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
class Skill extends Model
{
    use HasFactory, LogsActivity;

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
