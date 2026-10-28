<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Notifications\WorkspaceInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteMember
{
    /**
     * Invite an email address to the workspace, replacing any earlier invitation for it.
     */
    public function handle(Workspace $workspace, MarketingUser $inviter, string $email, Role $role): Invitation
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invitation = Invitation::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'email' => $email],
            [
                'role_id' => $role->id,
                'invited_by' => $inviter->getAuthIdentifier(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(config('marketing.invitation_expiry_days')),
            ],
        );

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitation($workspace, $inviter->displayName(), $token));

        return $invitation;
    }
}
