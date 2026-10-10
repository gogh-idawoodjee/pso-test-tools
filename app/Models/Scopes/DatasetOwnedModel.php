<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a dataset-owned model to datasets whose environment belongs to
 * the logged-in user. Replaces UserOwnedModel for everything scoped by dataset.
 */
class DatasetOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereHas('dataset.environment', static fn (Builder $environment) => $environment->where('user_id', auth()->id()));
    }
}
