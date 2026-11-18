<?php

namespace Gerenuk\Marketing\Models\Concerns;

use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Gerenuk\Marketing\Tenancy\WorkspaceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: every query is scoped to the current
 * workspace and new rows are stamped with it.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', app(CurrentWorkspace::class)->getOrFail()->id);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
