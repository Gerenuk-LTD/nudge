<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class EnsureWorkspaceKeepsAnOwner
{
    /**
     * Fail when the given owner membership is the workspace's last owner. Must run inside the transaction that makes
     * the change: it locks the workspace row, so two owners changing each other at once can't both pass.
     */
    public static function check(Membership $ownerMembership): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureWorkspaceKeepsAnOwner::check() must run inside a transaction.');
        }

        Workspace::query()->whereKey($ownerMembership->workspace_id)->lockForUpdate()->first();

        $otherOwners = Membership::query()
            ->where('workspace_id', $ownerMembership->workspace_id)
            ->whereKeyNot($ownerMembership->id)
            ->whereHas('role', fn ($query) => $query->where('is_owner', true))
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages(['member' => __('A workspace must keep at least one owner.')]);
        }
    }
}
