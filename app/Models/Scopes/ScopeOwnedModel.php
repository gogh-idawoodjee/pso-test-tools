<?php

namespace App\Models\Scopes;

use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Rows hang off a Dataset or an Environment (owned by the environment's user), or off a
 * User (Unfiled, owned by that user). Applied to export files and tool runs.
 */
class ScopeOwnedModel implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        $builder->where(static function (Builder $query) use ($userId, $model): void {
            $query->whereHasMorph('scope', [Dataset::class], static fn (Builder $dataset) => $dataset
                ->whereHas('environment', static fn (Builder $environment) => $environment->where('user_id', $userId)))
                ->orWhereHasMorph('scope', [Environment::class], static fn (Builder $environment) => $environment->where('user_id', $userId))
                // scope_id is a char column: compare the user id as a string so MySQL keeps the index.
                ->orWhere(static fn (Builder $unfiled): Builder => $unfiled
                    ->where($model->qualifyColumn('scope_type'), (new User)->getMorphClass())
                    ->where($model->qualifyColumn('scope_id'), (string) $userId));
        });
    }
}
