<?php

namespace App\Models\Scopes;

use App\Models\Dataset;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Rows hang off a Dataset or an Environment (morph "scope"); either way the
 * owner is the environment's user. Applied to export files and tool runs.
 */
class ScopeOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        $builder->where(static function (Builder $query) use ($userId): void {
            $query->whereHasMorph('scope', [Dataset::class], static fn (Builder $dataset) => $dataset
                ->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', $userId)))
                ->orWhereHasMorph('scope', [Environment::class], static fn (Builder $environment) => $environment->where('user_id', $userId));
        });
    }
}
