<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Membership;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public function handle(Membership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            if ($membership->role->is_owner) {
                EnsureWorkspaceKeepsAnOwner::check($membership);
            }

            // Tokens act for the person who made them, so they go when that person leaves.
            ApiToken::withoutGlobalScopes()
                ->where('workspace_id', $membership->workspace_id)
                ->where('created_by', $membership->user_id)
                ->delete();

            // So someone being removed can't invite a second address and come back through it.
            Invitation::withoutGlobalScopes()
                ->where('workspace_id', $membership->workspace_id)
                ->where('invited_by', $membership->user_id)
                ->delete();

            $membership->delete();
        });
    }
}
