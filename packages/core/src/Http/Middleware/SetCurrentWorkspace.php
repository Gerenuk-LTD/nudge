<?php

namespace Gerenuk\Nudge\Http\Middleware;

use Closure;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Models\Account;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the signed-in user's current workspace and shares it with every Inertia page.
 * Users without a workspace are sent to create one, unless the route uses `nudge.workspace:optional`,
 * as account pages do: those render for everyone and only gain the workspace when there is one.
 */
class SetCurrentWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        $workspace = $user?->resolveCurrentWorkspace();

        if ($workspace === null) {
            return $mode === 'optional' ? $next($request) : redirect()->route('nudge.workspaces.create');
        }

        $this->currentWorkspace->set($workspace);

        Inertia::share('workspace', fn () => [
            'current' => ['id' => $workspace->id, 'name' => $workspace->name],
            'role' => $user->membershipFor($workspace)?->role->name,
            'all' => $user->workspaces()->orderBy('name')->get(['workspaces.id', 'workspaces.name'])
                ->map(fn (Workspace $item) => ['id' => $item->id, 'name' => $item->name])
                ->all(),
            'permissions' => collect(Permission::cases())
                ->filter(fn (Permission $permission) => $user->hasWorkspacePermission($workspace, $permission))
                ->map(fn (Permission $permission) => $permission->value)
                ->values()
                ->all(),
            'sendingIssue' => $this->sendingIssue($workspace),
            'canCreateWorkspace' => Account::query()->where('owner_id', $user->getAuthIdentifier())->exists(),
        ]);

        return $next($request);
    }

    /**
     * The most pressing reason campaigns can't go out, for the sidebar to flag, or null when sending is healthy.
     *
     * @return 'paused'|'no_provider'|'no_postal_address'|null
     */
    private function sendingIssue(Workspace $workspace): ?string
    {
        return match (true) {
            $workspace->isSendingPaused() => 'paused',
            ! Provider::query()->exists() => 'no_provider',
            blank($workspace->postal_address) => 'no_postal_address',
            default => null,
        };
    }
}
