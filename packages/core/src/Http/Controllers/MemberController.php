<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Actions\Workspaces\ChangeMemberRole;
use Gerenuk\Nudge\Actions\Workspaces\InviteMember;
use Gerenuk\Nudge\Actions\Workspaces\RemoveMember;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace->getOrFail();
        $userId = $this->user($request)->getAuthIdentifier();

        return Inertia::render('nudge/workspace/members', [
            'members' => $workspace->memberships()->with(['user', 'role'])->orderBy('id')->get()
                ->map(fn (Membership $membership) => [
                    'id' => $membership->id,
                    'name' => $membership->user->displayName(),
                    'email' => $membership->user->emailAddress(),
                    'role_id' => $membership->role_id,
                    'is_you' => $membership->user_id === (int) $userId,
                ]),
            'invitations' => Invitation::query()->with('role')->orderBy('email')->get()
                ->map(fn (Invitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->name,
                    'expired' => $invitation->isExpired(),
                ]),
            'roles' => Role::query()->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function invite(Request $request, InviteMember $inviteMember): RedirectResponse
    {
        $workspace = $this->currentWorkspace->getOrFail();

        $validated = $request->validate([
            'email' => [
                'required', 'email:filter', 'max:254',
                function (string $attribute, mixed $value, \Closure $fail) use ($workspace): void {
                    $isMember = $workspace->memberships()
                        ->whereHas('user', fn ($query) => $query->where('email', mb_strtolower(trim((string) $value))))
                        ->exists();

                    if ($isMember) {
                        $fail(__('That person is already a member of this workspace.'));
                    }
                },
            ],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $workspace->id)],
        ]);

        $role = Role::findOrFail((int) $validated['role_id']);
        $this->ensureCanAssign($request, $role);

        $inviteMember->handle($workspace, $this->user($request), $validated['email'], $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('nudge.members.index');
    }

    public function revokeInvitation(Request $request, Invitation $invitation): RedirectResponse
    {
        $this->ensureCanAssign($request, $invitation->role);

        $invitation->delete();

        return to_route('nudge.members.index');
    }

    public function update(Request $request, Membership $membership, ChangeMemberRole $changeMemberRole): RedirectResponse
    {
        $this->ensureInCurrentWorkspace($membership);

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $membership->workspace_id)],
        ]);

        $role = Role::findOrFail((int) $validated['role_id']);
        $this->ensureCanAssign($request, $role);
        $this->ensureCanAssign($request, $membership->role);

        $changeMemberRole->handle($membership, $role);

        return to_route('nudge.members.index');
    }

    public function destroy(Request $request, Membership $membership, RemoveMember $removeMember): RedirectResponse
    {
        $this->ensureInCurrentWorkspace($membership);
        $this->ensureCanAssign($request, $membership->role);

        $removeMember->handle($membership);

        return to_route('nudge.members.index');
    }

    /**
     * People can only give out, take away or change roles whose permissions they hold themselves; only owners can touch the owner role.
     */
    private function ensureCanAssign(Request $request, Role $role): void
    {
        $actor = $this->user($request)->membershipFor($this->currentWorkspace->getOrFail())?->role;

        abort_unless($actor !== null && $role->isWithin($actor), 403);
    }

    /**
     * Memberships are not workspace-scoped, so route binding alone does not restrict them.
     */
    private function ensureInCurrentWorkspace(Membership $membership): void
    {
        abort_unless($membership->workspace_id === $this->currentWorkspace->id(), 404);
    }
}
