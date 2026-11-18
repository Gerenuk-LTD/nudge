<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    public function handle(Membership $membership, Role $role): void
    {
        DB::transaction(function () use ($membership, $role): void {
            if ($membership->role->is_owner && ! $role->is_owner) {
                EnsureWorkspaceKeepsAnOwner::check($membership);
            }

            $membership->update(['role_id' => $role->id]);

            if (! $role->is_owner) {
                ApiToken::narrowForUser($membership->workspace_id, $membership->user_id, $role->permissions);

                // Invitations they sent for roles above their new one would hand out access they no longer have.
                Invitation::withoutGlobalScopes()
                    ->with('role')
                    ->where('workspace_id', $membership->workspace_id)
                    ->where('invited_by', $membership->user_id)
                    ->get()
                    ->reject(fn (Invitation $invitation) => $invitation->role->isWithin($role))
                    ->each(fn (Invitation $invitation) => $invitation->delete());
            }
        });
    }
}
