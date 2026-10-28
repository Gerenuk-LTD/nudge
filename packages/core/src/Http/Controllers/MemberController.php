<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\ChangeMemberRole;
use Gerenuk\Marketing\Actions\Workspaces\InviteMember;
use Gerenuk\Marketing\Actions\Workspaces\RemoveMember;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('marketing/workspace/members', [
            'members' => $workspace->memberships()->with(['user', 'role'])->orderBy('id')->get()
                ->map(fn (Membership $membership) => [
                    'id' => $membership->id,
                    'name' => $membership->user->displayName(),
                    'email' => $membership->user->emailAddress(),
                    'role_id' => $membership->role_id,
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

        return to_route('marketing.members.index');
    }

    public function revokeInvitation(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return to_route('marketing.members.index');
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

        return to_route('marketing.members.index');
    }

    public function destroy(Request $request, Membership $membership, RemoveMember $removeMember): RedirectResponse
    {
        $this->ensureInCurrentWorkspace($membership);
        $this->ensureCanAssign($request, $membership->role);

        $removeMember->handle($membership);

        return to_route('marketing.members.index');
    }

    /**
     * Only owners can give out, take away or change the owner role.
     */
    private function ensureCanAssign(Request $request, Role $role): void
    {
        if ($role->is_owner) {
            abort_unless($this->user($request)->membershipFor($this->currentWorkspace->getOrFail())?->role->is_owner, 403);
        }
    }

    /**
     * Memberships are not workspace-scoped, so route binding alone does not restrict them.
     */
    private function ensureInCurrentWorkspace(Membership $membership): void
    {
        abort_unless($membership->workspace_id === $this->currentWorkspace->id(), 404);
    }
}
