<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Nudge\Contracts\AccountCreationPolicy;
use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Account;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkspaceController extends Controller
{
    public function create(Request $request): Response
    {
        try {
            $this->accountForNewWorkspace($this->user($request));
            $allowed = true;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
            $allowed = false;
            $message = $exception->getMessage();
        }

        return Inertia::render('nudge/workspaces/create', ['allowed' => $allowed, 'message' => $message ?? null]);
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $user = $this->user($request);
        $account = $this->accountForNewWorkspace($user);

        $workspace = $createWorkspace->handle($user, $validated['name'], $account);
        $user->switchWorkspace($workspace);

        return to_route('nudge.home');
    }

    /**
     * The account a new workspace joins: none for a user's first workspace, which starts a new account, and otherwise
     * the account they own, so billing stays together. Members who own no account can't create workspaces, or anyone
     * invited anywhere could start their own sending setup.
     */
    private function accountForNewWorkspace(NudgeUser $user): ?Account
    {
        $userId = $user->getAuthIdentifier();

        if (! Membership::query()->where('user_id', $userId)->exists()) {
            // Someone who already owns an account keeps using it; anyone else needs the host's say-so to start one.
            return Account::query()->where('owner_id', $userId)->first()
                ?? (app(AccountCreationPolicy::class)->allows($user) ? null : abort(403, __('You don’t have access to a workspace yet. Ask a workspace owner to invite you.')));
        }

        return Account::query()->where('owner_id', $userId)->first()
            ?? abort(403, __('Only account owners can create workspaces.'));
    }

    public function edit(CurrentWorkspace $currentWorkspace): Response
    {
        // Not "name": that prop is shared by every page as the app's name.
        return Inertia::render('nudge/workspace/settings', [
            'workspaceName' => $currentWorkspace->getOrFail()->name,
        ]);
    }

    public function update(Request $request, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $currentWorkspace->getOrFail()->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace updated.')]);

        return to_route('nudge.workspace.edit');
    }
}
