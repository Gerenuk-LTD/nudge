<?php

namespace Gerenuk\Nudge\Actions\Workspaces;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    public function handle(Invitation $invitation, NudgeUser $user): Membership
    {
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['invitation' => __('This invitation has expired.')]);
        }

        if (Contact::normalizeEmail($user->emailAddress()) !== $invitation->email) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation was sent to :email. Sign in with that address to accept it.', ['email' => $invitation->email]),
            ]);
        }

        $membership = DB::transaction(function () use ($invitation, $user): Membership {
            $membership = Membership::firstOrCreate(
                ['workspace_id' => $invitation->workspace_id, 'user_id' => $user->getAuthIdentifier()],
                ['role_id' => $invitation->role_id],
            );

            $invitation->delete();

            return $membership;
        });

        $user->forgetResolvedMemberships();
        $user->switchWorkspace($membership->workspace);

        return $membership;
    }
}
