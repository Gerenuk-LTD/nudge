<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\AcceptInvitation;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = Invitation::findByToken($token);

        return Inertia::render('marketing/invitations/show', [
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'email' => $invitation->email,
                'workspace' => Workspace::find($invitation->workspace_id)?->name,
                'role' => $invitation->role->name,
                'expired' => $invitation->isExpired(),
            ],
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = Invitation::findByToken($token);

        abort_if($invitation === null, 404);

        $acceptInvitation->handle($invitation, $this->user($request));

        return to_route('marketing.contacts.index');
    }
}
