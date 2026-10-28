<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;

class ChangeMemberRole
{
    public function handle(Membership $membership, Role $role): void
    {
        if ($membership->role->is_owner && ! $role->is_owner) {
            EnsureWorkspaceKeepsAnOwner::check($membership);
        }

        $membership->update(['role_id' => $role->id]);
    }
}
