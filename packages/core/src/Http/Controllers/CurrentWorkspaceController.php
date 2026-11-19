<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentWorkspaceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'integer']]);

        $workspace = Workspace::find((int) $validated['workspace_id']);

        abort_unless($workspace !== null && $this->user($request)->switchWorkspace($workspace), 403);

        return to_route('nudge.home');
    }
}
