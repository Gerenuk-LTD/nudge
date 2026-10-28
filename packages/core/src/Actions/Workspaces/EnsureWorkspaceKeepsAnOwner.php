<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\Membership;
use Illuminate\Validation\ValidationException;

class EnsureWorkspaceKeepsAnOwner
{
    /**
     * Fail when the given owner membership is the workspace's last owner.
     */
    public static function check(Membership $ownerMembership): void
    {
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
