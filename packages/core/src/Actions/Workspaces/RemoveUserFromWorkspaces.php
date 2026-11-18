<?php

namespace Gerenuk\Nudge\Actions\Workspaces;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Account;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a user out of every workspace before their user record is deleted, following the same rules as removing a
 * member: workspaces keep an owner, and the user's API tokens stop working. Account owners must be refused, because
 * deleting them would leave their workspaces without anyone responsible for them.
 */
class RemoveUserFromWorkspaces
{
    /**
     * @throws ValidationException when the user owns an account or is the last owner of a workspace
     */
    public function handle(NudgeUser $user): void
    {
        $userId = $user->getAuthIdentifier();

        if (Account::query()->where('owner_id', $userId)->exists()) {
            throw ValidationException::withMessages(['account' => __('You own workspaces, so your account can’t be deleted yet. Contact support to close them first.')]);
        }

        DB::transaction(function () use ($userId): void {
            $memberships = Membership::query()->with('role')->where('user_id', $userId)->get();

            foreach ($memberships as $membership) {
                if ($membership->role->is_owner) {
                    try {
                        EnsureWorkspaceKeepsAnOwner::check($membership);
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['account' => __('You’re the only owner of a workspace. Make someone else an owner first.')]);
                    }
                }
            }

            ApiToken::withoutGlobalScopes()->where('created_by', $userId)->delete();
            Invitation::withoutGlobalScopes()->where('invited_by', $userId)->delete();
            Membership::query()->where('user_id', $userId)->delete();
        });
    }
}
