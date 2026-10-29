<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    public function handle(Membership $membership, Role $role): void
    {
        if ($membership->role->is_owner && ! $role->is_owner) {
            EnsureWorkspaceKeepsAnOwner::check($membership);
        }

        DB::transaction(function () use ($membership, $role): void {
            $membership->update(['role_id' => $role->id]);

            if (! $role->is_owner) {
                ApiToken::withoutGlobalScopes()
                    ->where('workspace_id', $membership->workspace_id)
                    ->where('created_by', $membership->user_id)
                    ->get()
                    ->each(fn (ApiToken $token) => $token->update(['permissions' => array_values(array_intersect($token->permissions, $role->permissions))]));
            }
        });
    }
}
