<?php

namespace Gerenuk\Marketing\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned queries to the current workspace. Fails closed:
 * querying without a current workspace throws instead of returning every tenant's rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id() ?? throw new MissingWorkspaceException;

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
