<?php

namespace Gerenuk\Nudge\Actions\Workspaces;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Notifications\WorkspaceInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteMember
{
    /**
     * Invite an email address to the workspace, replacing any earlier invitation for it.
     */
    public function handle(Workspace $workspace, NudgeUser $inviter, string $email, Role $role): Invitation
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invitation = Invitation::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'email' => $email],
            [
                'role_id' => $role->id,
                'invited_by' => $inviter->getAuthIdentifier(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(config('nudge.invitation_expiry_days')),
            ],
        );

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitation($workspace, $inviter->displayName(), $token));

        return $invitation;
    }
}
