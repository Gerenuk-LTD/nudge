<?php

namespace Gerenuk\Marketing\Http\Middleware;

use Closure;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the signed-in user's current workspace and shares it with every Inertia page.
 * Users without a workspace are sent to create one.
 */
class SetCurrentWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $user?->resolveCurrentWorkspace();

        if ($workspace === null) {
            return redirect()->route('marketing.workspaces.create');
        }

        $this->currentWorkspace->set($workspace);

        Inertia::share('workspace', fn () => [
            'current' => ['id' => $workspace->id, 'name' => $workspace->name],
            'all' => $user->workspaces()->orderBy('name')->get(['workspaces.id', 'workspaces.name'])
                ->map(fn (Workspace $item) => ['id' => $item->id, 'name' => $item->name])
                ->all(),
            'permissions' => collect(Permission::cases())
                ->filter(fn (Permission $permission) => $user->hasWorkspacePermission($workspace, $permission))
                ->map(fn (Permission $permission) => $permission->value)
                ->values()
                ->all(),
        ]);

        return $next($request);
    }
}
