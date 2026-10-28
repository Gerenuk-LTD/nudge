<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\Membership;

class RemoveMember
{
    public function handle(Membership $membership): void
    {
        if ($membership->role->is_owner) {
            EnsureWorkspaceKeepsAnOwner::check($membership);
        }

        $membership->delete();
    }
}
