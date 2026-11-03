<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Models\Account;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function create(Request $request): Response
    {
        $this->accountForNewWorkspace($this->user($request));

        return Inertia::render('marketing/workspaces/create');
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $user = $this->user($request);
        $account = $this->accountForNewWorkspace($user);

        $workspace = $createWorkspace->handle($user, $validated['name'], $account);
        $user->switchWorkspace($workspace);

        return to_route('marketing.home');
    }

    /**
     * The account a new workspace joins: none for a user's first workspace, which starts a new account, and otherwise
     * the account they own, so billing stays together. Members who own no account can't create workspaces, or anyone
     * invited anywhere could start their own sending setup.
     */
    private function accountForNewWorkspace(MarketingUser $user): ?Account
    {
        $userId = $user->getAuthIdentifier();

        if (! Membership::query()->where('user_id', $userId)->exists()) {
            return null;
        }

        return Account::query()->where('owner_id', $userId)->first()
            ?? abort(403, __('Only account owners can create workspaces.'));
    }

    public function edit(CurrentWorkspace $currentWorkspace): Response
    {
        // Not "name": that prop is shared by every page as the app's name.
        return Inertia::render('marketing/workspace/settings', [
            'workspaceName' => $currentWorkspace->getOrFail()->name,
        ]);
    }

    public function update(Request $request, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $currentWorkspace->getOrFail()->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace updated.')]);

        return to_route('marketing.workspace.edit');
    }
}
