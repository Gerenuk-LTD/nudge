<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('marketing/workspaces/create');
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $user = $this->user($request);

        // A new workspace joins the account of the one the user is in, so billing stays together.
        $account = Workspace::find($user->currentWorkspaceId())?->account;

        $workspace = $createWorkspace->handle($user, $validated['name'], $account);
        $user->switchWorkspace($workspace);

        return to_route('marketing.contacts.index');
    }

    public function edit(CurrentWorkspace $currentWorkspace): Response
    {
        return Inertia::render('marketing/workspace/settings', [
            'name' => $currentWorkspace->getOrFail()->name,
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
